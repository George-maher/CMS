# PHASE 0 — ENDPOINT & SECURITY MATRIX

**Audit date:** 2026-09-30
**Source of truth:** `backend/routes/api.php` (870 lines), `backend/routes/web.php`, `backend/routes/console.php`, plus every controller, FormRequest, Policy and Middleware in `backend/app/`.
**Method:** static inspection with `php artisan route:list` used to confirm the resolved middleware stack per route.

> **Scope classification vocabulary**
> `PUBLIC` · `AUTHENTICATED` · `CHURCH-SCOPED` · `STAGE-SCOPED` · `PLATFORM-SCOPED` · `UNKNOWN`
> A route is only classified `CHURCH-SCOPED` if a *named layer* actually answers the ownership question. An `exists:` rule is **not** ownership.
> Frontend role gating is **never** treated as authorization.

---

## 0. ROUTE TOTALS (VERIFIED — `php artisan route:list`)

```
Showing [224] routes
  218 under api/
    214 under api/v1
    13  public (no auth:sanctum)   ← enumerated in §2
   1   sanctum/csrf-cookie
   3   web:  /  /health  /storage/{path}
   2   infra: /up (Laravel health), storage/{path}
```

**Correction to a common misreading:** `route:list --json`'s `middleware` field shows only *route-level* middleware and omits group-inherited middleware. Routes like `POST api/v1/auth/logout` therefore appear "unauthenticated" in that output but **do** carry `auth:sanctum` + `approval` from the group. This was re-verified with `route:list -v` (see §2). Do not use the `--json` field to classify routes.

---

## 1. GLOBAL API MIDDLEWARE STACK (`bootstrap/app.php:49-54`)

Every `/api/*` request, in order:

| # | Middleware | Purpose | Security impact |
|---|---|---|---|
| 1 | `AssignRequestId` | ULID `request_id`, echoed as `X-Request-Id`, published via `Log::withContext` | Correlation; first so nothing can log before the id exists |
| 2 | `ForceJsonResponse` | forces `Accept: application/json`; `Log::debug` on platform-login attempt | Prevents HTML error pages from leaking stack traces |
| 3 | `SetLocale` | `Accept-Language` ∈ {`en`,`ar`} only | Any other value (e.g. `ar-EG`, `en-US,en;q=0.9`) is **ignored** |
| 4 | `track.activity` → `TrackActivity` | revokes the current token after `SESSION_INACTIVITY_TIMEOUT` minutes of inactivity (default 60) | Idle-session kill. **Keys on `Cache::get('user-last-active-{id}')`, which with `CACHE_STORE=file` is per-container — not shared across replicas.** |
| 5 | `TrustProxies` (appended) | honours `X-Forwarded-*` | With `TRUSTED_PROXIES=*` a client can spoof its own IP, which is the limiter key |

Custom aliases (`bootstrap/app.php:36-44`): `role`, `permission`, `approved`, `approval`, `event.scope`, `track.activity`, `reauth`.

**`reauth` is registered but applied to ZERO routes** (`RequireReauth`). The same protection is re-implemented inside `DeleteChurchRequest::rules()`.

**`statefulApi()` is NOT registered.** Sanctum cookie/SPA session auth is inactive; the SPA is bearer-token only.

---

## 2. PUBLIC ENDPOINTS (13) — NO `auth:sanctum`

| # | Method | URI | Action | Middleware | Authorization inside | Scope |
|---|---|---|---|---|---|---|
| 1 | POST | `/api/v1/auth/login` | `AuthController@login` | `throttle:login` (5/min per IP+email) | none (public) | **PUBLIC** |
| 2 | POST | `/api/v1/auth/{PLATFORM_ADMIN_LOGIN_PATH}` | `AuthController@platformLogin` | `throttle:login` | `AuthService::platformLogin` rejects non-`platform_admin` (`AuthService.php:56`) | **PUBLIC** (path is a secret-by-config control, not a boundary) |
| 3 | POST | `/api/v1/auth/register` | `AuthController@register` | `throttle:register` (10/hr/IP) | invite token determines tenant (`AuthService.php:181-252`) | **PUBLIC** |
| 4 | POST | `/api/v1/auth/forgot-password` | `AuthController@forgotPassword` | `throttle:login` | none | **PUBLIC** |
| 5 | POST | `/api/v1/password-reset-requests` | `PasswordResetRequestController@submit` | `throttle:login` | none; `PasswordResetRequestService::submitRequest` | **PUBLIC** |
| 6 | POST | `/api/v1/auth/verify-email` | `AuthController@verifyEmail` | `throttle:verify-email` (10/min/IP) | token-bound | **PUBLIC** |
| 7 | POST | `/api/v1/auth/resend-verification` | `AuthController@resendVerification` | `throttle:verify-email` | token/email-bound | **PUBLIC** |
| 8 | GET | `/api/v1/qr/validate/{token}` | `QRInviteController@validateToken` | `throttle:invite-public` (10/min/IP) | token; explicitly removes `ChurchScope` then re-binds to `$invite->church_id` (`QRInviteController.php:99-121`) | **PUBLIC** |
| 9 | GET | `/api/v1/invite/{token}` | `QRInviteController@details` | `throttle:invite-public` | token; `used_by_users` PII deliberately omitted (`:185-187`) | **PUBLIC** |
| 10 | GET | `/api/v1/verses/active` | `DailyVerseController@getActive` | `throttle:verse-read` | none | **PUBLIC** |
| 11 | POST | `/api/v1/church-applications` | `ChurchApplicationController@store` | `throttle:register` | none | **PUBLIC** |
| 12 | POST | `/api/v1/church-applications/lookup` | `ChurchApplicationController@lookup` | `throttle:register` | PII gate: full resource only if `$authUser->church_application_id === $application->id`, else `status` + `contact_email` only (`:92-99`) | **PUBLIC** |
| 13 | GET | `/api/v1/churches/active` | inline closure `api.php:101-109` | `throttle:api` | none | **PUBLIC** — returns `id,name,slug,address` of every active, non-suspended church |

Non-API public routes: `GET /`, `GET /health` (leaks DB connectivity), `GET /storage/{path}` (realpath-contained file server, by design), `GET /up`, `GET /sanctum/csrf-cookie`.

> **Information disclosure (P3):** #13 publishes the id, name, slug **and street address** of every church on the platform to an unauthenticated caller. This is required by the public join-request form, so it is a business decision rather than a defect — but the `address` field is not obviously necessary for that purpose.

---

## 3. FULL ENDPOINT MATRIX — AUTHENTICATED GROUP (base: `auth:sanctum`, `approval`, `throttle:api`)

`Auth` = `auth:sanctum` + `approval`. `Perm(x)` = `PermissionMiddleware` (OR semantics, comma-separated). `App` = `approved` (`CheckApproval`). `Ev` = `event.scope` (`EnsureEventScope`).

### 3.1 Any approved authenticated user

| Method | Endpoint | Controller@action | Validation | Authorization in-body | Scope |
|---|---|---|---|---|---|
| POST | `/auth/logout` | `AuthController@logout` | — | self (`$request->user()`) | AUTHENTICATED |
| GET | `/auth/me` | `AuthController@me` | — | self | AUTHENTICATED |
| PUT | `/profile` | `ProfileUpdateRequestController@updateOwnProfile` | `UpdateOwnProfileRequest` (admin\|assistant\|servant) | self | AUTHENTICATED |
| POST | `/profile-update-requests` | `…@store` | `StoreProfileUpdateRequest` (member\|servant) | self | AUTHENTICATED |
| GET | `/profile-update-requests/my` | `…@myRequests` | — | self | AUTHENTICATED |
| GET | `/application/status`, `/pending/status` | `PendingDashboardController@status` | — | self via `church_application_id` | AUTHENTICATED |
| GET | `/dashboard/stats` | `DashboardController@stats` | — | **NONE** — church-wide counts to any member | **AUTHENTICATED (no role gate)** |
| GET | `/points/balance`, `/points/history` | `PointController` | — | self | AUTHENTICATED |
| GET | `/points/leaderboard` | `PointController@leaderboard` | — | **NONE** — church-wide | AUTHENTICATED |
| GET | `/leaderboard/global`, `/leaderboard/my-class` | `LeaderboardController` | — | **NONE** (self-scoped for my-class) | AUTHENTICATED |
| GET | `/users/my-class-servants`, `/structure/my-class-servants` | `StructureController` | — | self | AUTHENTICATED |
| GET | `/attendances/history/{userId?}`, `/attendances/stats/{userId?}` | `AttendanceController` | — | member→self; servant/stage-admin→self if out of scope; **admins skip the check** | **AUTHENTICATED — admin reads any userId in own church** |
| GET | `/member-profile/{id}` | `MemberProfileController@getProfile` | — | delegated to `MemberProfileService::canViewProfile` | AUTHENTICATED |
| GET | `/stages`, `/stages/{id}`, `/stages/{id}/classes` | `StageController` | — | post-filter by `allowedStageIds`; `show` re-checks `canAccessStage` | **CHURCH-SCOPED** |
| GET | `/classes`, `/classes/{id}` | `ClasseController` | — | post-filter by `allowedClassIds`; `show` re-checks `canAccessClass` | **CHURCH-SCOPED** |
| GET | `/structure/classes`, `/structure/my-classes`, `/structure/stages-with-classes` | `StructureController` | — | post-filter | **CHURCH-SCOPED** |
| GET | `/events`, `/events/{id}`, `/events/my-registrations`, `/events/my-assigned` | `EventController`, `EventRegistrationController@my` | — | `Event::query()->find()` (ChurchScope) + `cannotAccessEvent` | **CHURCH-SCOPED** |
| POST | `/events/{id}/track-view` | `EventAnalyticsController@track` | — | **explicit role gate: non-member → 403** (`:92-94`) | CHURCH-SCOPED |
| POST | `/events/{id}/register-self` | `EventRegistrationController@selfRegister` | — | identity from `$user->id` (`:184`) | AUTHENTICATED (self) |
| POST | `/events/{id}/member-reservation-request` | `…@submitMemberReservationRequest` | manual `in_array` status; other fields read raw | identity from `$user->id` (`:149`) | AUTHENTICATED (self) |
| GET | `/events/{id}/accommodation/my-view` | `EventAccommodationController@memberView` | — | self (`:227`) | AUTHENTICATED (self) |
| POST | `/events/{id}/accommodation/select-cell` | `…@selectCell` | `cell_id exists:` **tenant-blind** | self (`:254`) | AUTHENTICATED (self) |
| GET | `/notifications`, `/notifications/unread-count` | `NotificationController` | — | `$user->id` | AUTHENTICATED |
| POST | `/notifications/{id}/mark-read` | `…@markAsRead` | — | **NONE in body** — `{id}` + `$user->id` passed to service; **ownership enforcement (if any) is unverified in `NotificationService`** | **UNKNOWN** |
| POST | `/notifications/mark-all-read` | `…@markAllAsRead` | — | `$user->id` | AUTHENTICATED |
| GET | `/attendance-contexts` | `AttendanceContextController@active` | — | **NONE** | AUTHENTICATED |
| GET | `/verses`, `/verses/{id}` | `DailyVerseController` | — | **NONE** | AUTHENTICATED |
| GET | `/feedback/mine`, POST `/feedback/{id}/mark-seen` | `FeedbackController` | — | `/mine` self; **`mark-seen` has no in-body ownership check** — passes `$user->id` to the service | AUTHENTICATED / **UNKNOWN** for mark-seen |
| GET/POST/DELETE | `/spiritual-records[...]` | `DailySpiritualRecordController` | `StoreDailySpiritualRecordRequest` | member override: explicit `where('church_id', $user->church_id)` → 404; servant additionally requires target class ∈ own classes. **Stage admin is absent from the override branch** | **CHURCH-SCOPED** (stage admin gap, see R-14) |
| POST | `/users/regenerate-qr-token` | `UserController@regenerateOwnQrToken` | — | self (`:615-620`) | AUTHENTICATED |

### 3.2 Storage

| Method | Endpoint | Action | Middleware | Note |
|---|---|---|---|---|
| POST | `/storage/upload/{bucket}` | `StorageController@upload` | `Perm(manage_users)` + `throttle:storage-upload` | bucket allowlist `['profiles','events','documents','ids','attachments']` (`:23`) |
| POST | `/storage/upload-profile-image` | `@uploadProfileImage` | `throttle:storage-upload` only | any authenticated user; bucket hardcoded |
| POST | `/storage/upload-event-image` | `@uploadEventImage` | `Perm(manage_events)` | |
| POST | `/storage/upload-document` | `@uploadDocument` | `Perm(manage_users)` | |
| POST | `/storage/replace/{bucket}` | `@replaceFile` | `Perm(manage_users)` | `old_url` is `url`-format only; **no check the URL belongs to the caller** (`:19` of request) |
| DELETE | `/storage/delete/{bucket}` | `@delete` | `Perm(manage_users)` | `url required string`; **no check the URL belongs to the caller or to the actor's tenant** |

`StorageEndpointAuthorizationTest` covers role denial and bucket mismatch, and asserts the stored extension is derived from the validated MIME. **Cross-tenant object deletion by URL is not covered and not refuted** — see R-20.

### 3.3 `Perm(view_users)` + `App` (44 endpoints)

| Method | Endpoint | Action | In-body authorization |
|---|---|---|---|
| POST/PUT/PATCH/DELETE | `/events`, `/events/{id}` | `EventController@store/update/destroy` | `targetsWithinScope()` resolves every `target_class_ids.*` through `Classe::query()->find()` + `canAccessClass` (`EventController.php:357-384`); `stageAdminTargetsInScope` |
| GET | `/events/{id}/analytics/summary|viewed|not-viewed` | `EventAnalyticsController` | servant class-id narrowing only; **no event-scope check in-body** (this group has **no `event.scope` middleware**) |
| POST/PUT/DELETE | `/verses[...]`, POST `/verses/{id}/activate` | `DailyVerseController` | **NONE** — `DailyVersePolicy` is registered but never invoked |
| POST/GET | `/qr/invites`, POST `/qr/invites/{id}/revoke|rotate` | `QRInviteController` | `store` re-derives `stage_id` from `ScopeResolver` for non-admins and checks `class_id ∈ allowedClassIds`; `revoke` checks creator/stage but **no `church_id`**; `rotate` **does** check `church_id` |
| GET | `/attendances/lookup/{qrToken}`, `/attendances/lookup-member-id/{memberId}` | `AttendanceController` | `User::byChurch()->…` + `canAccessMember`; identical "not found" for both failure modes |
| POST | `/attendances/record`, `/attendances/record-by-member-id` | `AttendanceController` | non-admin resolves member by QR token/member-id through `User::byChurch()` + `canAccessMember`; **admins skip the lookup entirely** |
| GET | `/attendances/today|by-class/{classYearId}|context-summary|context-details|filtered|absent-members` | `AttendanceController` | mixed — see R-13 |
| GET | `/users/members/{servantId?}`, `/users/member-detail/{id}`, `/users/servants` | `UserController` | `byChurch()->find()` + `canAccessUser`; **`{servantId?}` is ignored — `$servantId = $authUser->id` (`:312`)** |
| GET/PATCH/POST | `/feedback`, `/feedback/{id}`, `/feedback/{id}/resolve|reply` | `FeedbackController` | `Feedback::byChurch()->find($id)` + `in_array(class_year_id, scopeClassIds)`; **admins/assistant-admins skip the check entirely** |
| GET/POST/PUT/PATCH/DELETE | `/attendance-contexts/manage`, `/attendance-contexts/{id}` | `AttendanceContextController` | `findOrFail` (ChurchScope) + `AttendanceContextPolicy` — **the best-instrumented controller in the codebase** |
| GET/POST | `/profile-update-requests`, `/{id}`, `/{id}/approve|reject` | `ProfileUpdateRequestController` | `show` = church pre-filter + `can('view')`; `approve`/`reject` use the **unscoped** `ProfileUpdateRequest::find($id)` (`:209`, `:236`) and rely entirely on the policy's own `church_id` check (`:49-51`) |

### 3.4 `Perm(manage_event_registrations)` + `App` + `Ev` (33 endpoints)

`Ev` = `EnsureEventScope`: reads **only** `route('id')` (the event). Non-numeric id → `return $next()` with **no check at all** (`:23-25`). Event not found → `return $next()` with **no 404** (`:29-31`). Delegates to `EventAuthorizationService::assertCanAccess`, which *does* compare `church_id` (`:18`).

| Endpoint | Action | In-body authorization | Tenant ids resolved how |
|---|---|---|---|
| GET `/events/{id}/registrations` | `EventRegistrationController@index` | **NONE** — protection is entirely `Ev` | |
| POST `/events/{id}/registrations` | `@store` | **NONE** | `StoreEventRegistrationRequest::user_id` is `Rule::exists('users','id')` — **tenant-blind. No check the user is in the event's church, class, or target list.** → **R-11 (P1)** |
| POST `…/check-in-by-token` | `@checkInByToken` | `findEvent` only | `EventCheckInRequest::qr_token` 20–64 chars |
| POST `…/{regId}/check-in|undo-check-in|assign-bus|confirm|cancel|waitlist` | various | `resolveRegistration` scopes `regId` to `event_id` (`:397-400`) ✔ | `assignBus` hand-rolls `bus_id` with **no `exists:event_buses` and no event-ownership check** (`:366-376`) → **R-12 (P2)** |
| GET `/events/{id}/reservation-requests` | `@getEventReservationRequests` | **NONE** | |
| GET/POST/PUT/PATCH/DELETE `/events/{id}/buses[/{busId}]` | `EventBusController` | `findEvent` only | **`{busId}` is not verified to belong to `$event` in the controller** (`:52`) — the service resolves it, unverified here → **R-12 (P2)** |
| POST `/events/{id}/registrations/{regId}/approve|reject` | `EventReservationController` | `Event::query()->find` + registration scoped to event. **No role check in-body** (relies on `Ev` + `Perm`) | |
| GET/POST/PUT/PATCH/DELETE `/events/{id}/accommodation/rooms[/{roomId}]` | `EventAccommodationController` | `findEvent` only | **`roomId` not verified against `$event`** |
| POST `/events/{id}/accommodation/assign` | `@assign` | `findEvent` only | **`registration_id exists:event_registrations,id` + `cell_id exists:event_room_cells,id` — both tenant-blind; neither is checked to belong to `$event`** (`:158-161`) → **R-12 (P2)** |
| DELETE `/events/{id}/accommodation/registrations/{regId}` | `@removeAccommodation` | `findEvent` only | |
| GET `/events/{id}/accommodation/dashboard|unaccommodated` | `@dashboard`, `@unaccommodated` | `findEvent` only | |

### 3.5 `Perm(manage_event_payments)` + `App` + `Ev` (3 endpoints)

| Endpoint | Action | In-body authorization |
|---|---|---|
| GET `/events/{id}/payments` | `EventPaymentController@index` | `Event::query()->find()` only |
| POST `/events/{id}/registrations/{regId}/payments` | `@store` | find + registration scoped to event. **`EventPaymentService` has no authorization at all** |
| POST `…/payments/{paymentId}/refund` | `@refund` | **`{paymentId}` IS verified** to belong to the resolved registration (`:102-106`) ✔ |

### 3.6 `Perm(view_event_reports)` + `App` + `Ev` (4 endpoints)

`/events/{id}/dashboard`, `/reports/participants|financial|attendance` — `EventDashboardController`. **In-body: `findEvent` only.** `EventReportService` has no authorization and relies on the policy, which is never invoked. CSV export streams to `php://output`.

### 3.7 `Perm(manage_events)` + `App` + `Ev` (16 endpoints)

`publish`, `close-registration`, `reopen-registration`, `cancel`, `complete`, `duplicate` — all via `EventController::lifecycleResponse` → `Event::query()->find($id)` + `cannotAccessEvent` ✔
`/sessions[...]` and `/speakers[...]` CRUD — `EventScheduleController`. **`findEvent` only; `{sessionId}`/`{speakerId}` not verified against `$event` in the controller.** `StoreEventSessionRequest` exists but is unused; the controller duplicates its rules inline.

### 3.8 `Perm(manage_users)` + `App` (33 endpoints)

| Endpoint | Action | In-body authorization |
|---|---|---|
| GET/POST `/password-reset-requests[/{id}]`, POST `/{id}/approve|reject|reset-password` | `PasswordResetRequestController` | **Best pattern in the codebase**: every method pre-filters with `findById($id, $user->church_id)` (`:60, :79, :102, :135`) **and** calls `Gate::authorize()`; the policy independently re-checks `church_id` |
| GET `/users`, GET `/users/{id}` | `UserController@index/@show` | `index` drops `class_id`/`stage_id` filters for non-admins; stage admin gets `allowedClassIds`; `show` = `byChurch()->find()` + `canAccessUser` |
| POST/PUT/PATCH/DELETE `/users[/{id}]` | `@store/@update/@destroy` | `store`: `classWithinScope` for **every** role, `stageWithinScope` for **every** role (this is the D-1 fix); `update`: escalation guards (admin targets `:199`, privileged role change `:203-210`, password change `:212-214`); `destroy`: cannot delete admin/platform-admin |
| POST `/users/{id}/promote|demote` | `@promote/@demote` | `byChurch()->find()` + `canAccessUser` |
| GET `/users/{id}/servants` | `@servants` | church from `$authUser`; stage-admin branch uses `User::byChurch()` |
| POST `/users/{id}/regenerate-qr-token` | `@regenerateAttendanceToken` | `byChurch()->find()` + `canAccessUser` |
| POST/PUT/PATCH/DELETE `/stages[/{id}]`, `/stages/bulk` | `StageController` | `Stage::query()->find()` + `StagePolicy` (church equality) ✔ |
| POST/PUT/PATCH/DELETE `/classes[/{id}]`, `/stages/{id}/classes/bulk` | `ClasseController` | `store`/`bulkCreate` authorize against the **parent Stage**, not `Classe` — so **`ClassePolicy::create` is never invoked**; `update`/`destroy` use `ClassePolicy` ✔ |
| GET `/classes/{id}/detail|members|servants` | `ClasseController` | `find` + `canAccessClass` ✔ |
| POST `/classes/{id}/assign-servant|remove-servant|assign-member` | | `assignServant`/`assignMember` = `byChurch()->find()` + `canAccessUser` ✔; **`removeServant` has no `byChurch`/scope check on `user_id`** (`:213-221`) → **R-15 (P2)** |
| POST `/classes/reorder` | `@updateOrder` | `ClassePolicy::reorder` — **role-only, no per-class tenant/scope verification of `ordered_ids.*`** (`ClassePolicy.php:64`) → **R-16 (P2)** |
| GET `/churches` | inline closure `api.php:739-758` | `if (! $user->isPlatformAdmin()) $query->where('id', $user->church_id)` ✔ |

**Controller methods with NO route** (defined, unreachable): `UserController@servantsMembers`, `@attendanceHistory`, `@availablePermissions`, `@updatePermissions`, `@bulkUpdatePermissions`. `@updatePermissions` in particular has no check that the requested permission keys are within the actor's own set — currently unreachable, so latent.

### 3.9 `Perm(manage_users, view_points)` + `App` (3 endpoints)

`GET /points/user/{userId}/balance|history` — `PointController::guardTargetUser` → `User::byChurch()->find()` + `canAccessUser` ✔
`POST /points/bonus` — `byChurch()->find()` + `isMember()` + `canAccessUser` ✔

### 3.10 Membership requests

| Method | Endpoint | Middleware | In-body authorization |
|---|---|---|---|
| POST | `/membership-requests` | `throttle:membership-request` (3/hr per IP+email) | **PUBLIC**; `Church::find($churchId)` → 404. **No check the church is active or not suspended.** `church_id exists:churches,id` is tenant-blind but the target is a public listing |
| GET | `/membership-requests`, `/{id}` | `Perm(manage_membership_requests)` + `App` | `churchId = $user->church_id`; `show` uses `findById(id, churchId)` ✔ |
| POST | `/{id}/approve|reject` | same + `throttle:sensitive` | **NONE in the controller** — passes bare `$id` + `$adminId`. `MembershipRequestService::approve` re-checks `$request->church_id === $admin->church_id` (`:93`, `:156`) ✔ **defence-in-depth, service-level only** |

### 3.11 Platform admin — `auth:sanctum`, `approval`, **`role:platform_admin`**, `throttle:api`

The **only** place `RoleMiddleware` is used (`api.php:819`).

| Method | Endpoint | Action | Extra in-body check |
|---|---|---|---|
| GET | `/platform/dashboard` | `PlatformController@dashboard` | **NONE** — middleware only |
| GET | `/platform/churches` | inline closure (`Church::withTrashed()`) | **NONE** |
| GET | `/platform/churches/deleted-history`, `/{id}/deleted-detail`, `/{id}/deletion-summary` | `ChurchDeletionController` | **NONE** |
| GET | `/platform/applications[/{id}]`, POST `/{id}/approve|reject` | `PlatformController` | status check only |
| POST | `/platform/churches/{id}/soft-delete|restore|hard-delete` | `ChurchDeletionController` | `DeleteChurchRequest::authorize()` = `role === PlatformAdmin` **+** `confirmation in:"DELETE CHURCH"` **+** `Hash::check($password, $user->password)` closure. `reauth` middleware is **not** used. |

`ChurchDeletionPolicy` is registered for `Church` but **never invoked** — the four read-only platform church endpoints have no in-body check at all.

---

## 4. POLICY INVENTORY — REGISTERED vs ACTUALLY INVOKED

| Policy | Model | Registered | **Invoked from** | Enforces church/stage/class? |
|---|---|---|---|---|
| `Modules\User\UserPolicy` | `User` | ✅ | **NOTHING** | yes — but dead code |
| `StagePolicy` | `Stage` | ✅ | `StageController:47,61,103,122` | **YES** |
| `ClassePolicy` | `Classe` | ✅ | `ClasseController:132,151,185,219,235,306` (`create` never used) | **YES** except `reorder` |
| `AttendanceContextPolicy` | `AttendanceContext` | ✅ | `AttendanceContextController:21,40,58,72,91,103` | **YES** |
| `PasswordResetRequestPolicy` | `PasswordResetRequest` | ✅ | `PasswordResetRequestController:66,85,108,141` | **YES** on approve/reject/resetPassword |
| `ProfileUpdateRequestPolicy` | `ProfileUpdateRequest` | ✅ | `ProfileUpdateRequestController:193,215,242` | **YES** |
| `EventPolicy` | `Event` | ✅ | **NOTHING** | **NO** — no church check; dead |
| `AttendancePolicy` | `Attendance` | ✅ | **NOTHING** | **NO** — dead |
| `QRInvitePolicy` | `QRInvite` | ✅ | **NOTHING** | stage/creator only, no church — dead |
| `FeedbackPolicy` | `Feedback` | ✅ | **NOTHING** | class/owner only, no church — dead |
| `DailyVersePolicy` | `DailyVerse` | ✅ | **NOTHING** | none (takes no model argument) — dead |
| `DailySpiritualRecordPolicy` | `DailySpiritualRecord` | ✅ | **NOTHING** | owner/class, no church — dead |
| `ChurchDeletionPolicy` | `Church` | ✅ | **NOTHING** | role-only — dead |
| `App\Policies\UserPolicy` | — | ❌ | — | **empty stub class, 8 lines** |

**8 of 13 registered policies are never invoked from any controller.** Every one of them has an inlined equivalent — so this is *not* currently a hole, but it means the policy layer is a **second, divergent, untested description of the authorization model**. `ClassePolicy::reorder` is a live example of the divergence: it is role-only, and the controller calls it.

**Models with NO policy and NO registration:** `EventRegistration`, `EventPayment`, `EventBus`, `EventBusSheet`, `EventSession`, `EventSpeaker`, `EventRoom`, `EventRoomCell`, `EventAccommodation`, `Notification`, `MembershipRequest`, `MemberProfile`, `FeedbackReply`, `AuditLog`, `PasswordResetRequest` (has one), `ChurchApplication`.

---

## 5. TENANT-SENSITIVE INPUT CLASSIFICATION

`exists:` proves **existence somewhere on the platform**. It never proves ownership. Categories per `TENANT_RULES.md` §4:
**A** = existence only, but independently protected by a named layer · **B** = structurally protected (composite FK / church-scoped rule) · **C** = potentially unprotected · **UNKNOWN** = insufficient evidence.

| Field | Where accepted | `exists:` rule | Ownership owner | Cat |
|---|---|---|---|---|
| `church_id` (user create/update) | `CreateUserRequest`, `UpdateUserRequest` | not accepted as input | derived server-side; `TenantOwnershipBoundaryTest:322` pins it | **A** |
| `class_year_id` (user) | same | **`prohibited`** | n/a | **B** |
| `class_id` (user) | same | `exists:classes,id` | `CreateUserRequest::classWithinScope` + `UserController::store:109` + `UserService::create` re-check (`TenantOwnershipBoundaryTest:594` asserts it throws) | **A** |
| `stage_id` (user) | same | `exists:stages,id` | `CreateUserRequest::stageWithinScope` (D-1 fix, **outside** the stage-admin branch) + `UserController::store:127` + composite FK `users_church_stage_fk` | **A/B** |
| `stage_id` (classe) | `StoreClasseRequest` | `exists:stages,id` | `ClasseController::store` resolves `Stage::query()->find($stageId)` (ChurchScope) → 404, then `authorize('create', $stage)`; composite FK `classes_church_stage_fk` | **B** |
| `class_id`, `stage_id` (order filter) | `ClasseController@index` | — | dropped for non-admins (`:38-39`); stage admin gets `allowedClassIds` | **A** |
| `ordered_ids.*` | `ClasseController@updateOrder` | `exists:classes,id` | **`ClassePolicy::reorder` is role-only** | **C** |
| `user_id` (`removeServant`) | `ClasseController@removeServant` | `exists:users,id` | **none in the controller** | **C** |
| `class_id`, `stage_id`, `attendance_context_id` (QR invite) | `CreateQRInviteRequest` | **`Rule::exists()->where('church_id', $user->church_id)`** | tenant-scoped rule + `allowedInviteTypesFor` + stage/class override + `QRInviteCrossChurchScopingTest` | **B** |
| `class_year_id`, `target_class_ids.*` (event) | `EventRequest` | `exists:classes,id` **tenant-blind** | `EventController::targetsWithinScope` resolves every element through `Classe::query()->find()` + `canAccessClass`; a mixed array is rejected **whole** (`TenantIsolationMatrixTest:277`); composite FK `events_church_class_fk` | **A/B** |
| `responsible_servant_id` (event) | `EventRequest` | `exists:users,id` | `EventRequest::withValidator` requires an **active servant in the same church** (`:116-134`) | **B** |
| `user_id` (event registration) | `StoreEventRegistrationRequest` | `Rule::exists('users','id')` **tenant-blind** | `EventRegistrationService::register` re-checks church match (`:59`) and `canAccessUser` (`:68`) — **but the controller does not, and no test covers the cross-church case** | **A (service only)** |
| `user_id` (bonus points) | `AddBonusPointsRequest` | `exists:users,id` | `byChurch()->find()` + `isMember()` + `canAccessUser` | **A** |
| `userId` (points read) | route param | — | `guardTargetUser` → `byChurch()->find()` + `canAccessUser` | **A** |
| `classYearId` (`attendances/by-class`) | route param | — | servant/stage-admin 403 if not allowed (`:331-337`); **admins skip** | **A** |
| `class_id` (`attendances/filtered`, `context-details`, `context-summary`, `absent-members`) | query | `exists:classes,id` **tenant-blind** | servant/stage-admin: out-of-scope value unset and replaced by the allowed list; **non-servant non-platform-admin: `Classe::find()` (ChurchScope) + `canAccessClass`**; **admins skip** | **A** |
| `user_id` (`attendances/filtered`) | query | `exists:users,id` | **never verified for any role** | **C** |
| `servant_id`, `context_id` (`attendances/context-details`) | query | `exists:` **tenant-blind** | **never verified for any role** | **C** |
| `event_id`, `context_id` (`attendances/absent-members`) | query via `$request->integer()` | **no `exists:` at all** | **never verified** | **C** |
| `event_id`, `attendance_context_id` (attendance record) | `RecordAttendanceRequest` | `exists:events,id`, `exists:attendance_contexts,id` **tenant-blind** | `AttendanceService` — the request is verified but no in-body check; dedup relies on 3 partial unique indexes | **A** |
| `registration_id`, `cell_id` (accommodation assign) | inline | `exists:…` **tenant-blind** | **neither checked against `$event`** | **C** |
| `bus_id` (assign bus) | manual numeric check | none | **not checked against `$event`** | **C** |
| `{roomId}`, `{sessionId}`, `{speakerId}`, `{busId}` | route params | — | **`event.scope` reads only `{id}`**; sub-resource ownership unverified in the controller | **C** |
| `church_id` (membership request submit) | `MembershipRequestSubmitRequest` | `exists:churches,id` | the target is the public church list; `Church::find()` → 404. **No active/suspended check** | **A** (by design) |
| `{id}` (profile update approve/reject) | route param | — | **unscoped `ProfileUpdateRequest::find($id)`**; the policy's own `church_id` check is the sole boundary | **A** |
| `{id}` (password reset) | route param | — | church pre-filter **and** policy, both | **A** |
| `{id}` (notifications mark-read) | route param | — | **unverified** | **UNKNOWN** |
| `{url}` (storage delete/replace) | body | `url` format only | **no caller/tenant ownership check** | **UNKNOWN** |
| `church_id` on every `BelongsToChurch` model | **mass-assignable in `$fillable` on all 15** | — | `BelongsToChurch::creating` sets it only when absent; a caller-supplied value **short-circuits** the hook. No controller was found passing it through, and `TenantOwnershipBoundaryTest:322` pins derivation — but the guard is a convention, not a constraint | **INFO** |

---

## 6. SCOPE-BYPASS POINTS (31 total)

**`withoutGlobalScope(ChurchScope::class)` — 26 targeted.** The majority are legitimate and documented: public token flows that explicitly re-bind to `$invite->church_id` (`QRInviteController:99-121`, `QRInviteService:305`, `AuthService:181,232`, `QRInvite:163-164,198`), seeders, and the `CleanExpiredInvites` cron.

| Location | Assessment |
|---|---|
| `app/Services/EmailVerificationService.php:88, 116, 182` | **INERT.** `User` does **not** use `BelongsToChurch`, so `ChurchScope` is never registered on `User`'s builder. Verified against the installed framework source: `Builder::withoutGlobalScope()` does `unset($this->scopes[$scope])` unconditionally (`Illuminate/Database/Eloquent/Builder.php:213-224`), so calling it with an unregistered scope is a no-op. These three calls express an intent the model does not implement. → **R-17 (P3)** |
| `app/Http/Controllers/Api/QRInviteController.php:99` | Legitimate; re-bound to `$invite->church_id` at `:106`, `:119` |
| `app/Console/Commands/CleanExpiredInvites.php:26` | Cron, no `auth()`; deletes expired **and all revoked** invites (`Schedule::dailyAt('03:00')`) |
| `database/seeders/AttendanceContextSeeder.php:30` | Required — creates global `church_id = null` defaults |

**`withoutGlobalScopes()` — 4.** Three in `2026_06_16_000006_migrate_class_years_to_stages_classes.php` (`:38`, `:43` re-apply a `where('church_id', $churchId)` tenant filter manually; **`:54` in `down()` deletes every stage named `'Default Stage'` in every church with no tenant filter**). One in a test.

**`newQuery()` / `newQueryWithoutScopes()` on a tenant model: NOT PRESENT.** `ScopeResolver` uses `Stage::query()` / `Classe::query()`, which retain all global scopes.

**`#[ScopedBy]`: NOT PRESENT.** The single `addGlobalScope` in the entire codebase is `BelongsToChurch.php:16`.

---

## 7. `ChurchScope` — THE PRIMARY TENANT BOUNDARY

`backend/app/Models/Scopes/ChurchScope.php` — `apply()` has three branches:

| # | Condition | Action | Line |
|---|---|---|---|
| 1 | `! Auth::check() && app()->bound('request') && request()->route() !== null` | `whereRaw('1 = 0')` — **fail closed** | 16-23 |
| 2 | `Auth::check() && ! isPlatformAdmin() && resolveChurchId() === null` | `where(table.church_id, 0)` — **fail closed** (0 can never match a real serial PK) | 43-47 |
| 3 | otherwise | `where(church_id, $churchId)`, or **no filter at all** when `null` | 49-55 |

`resolveChurchId()` returns `null` for platform admin (72-74) and for an authenticated user with `church_id = NULL` (79). Branch 2 catches the latter. Branch 1 is skipped in console/queue (no route bound) and branch 2 is skipped (`Auth::check()` false) — so **in a queue worker or console command, a `BelongsToChurch` query is unfiltered across all tenants.** → **R-18 (P2)**, mitigated today because the only live queued mail path is dead code.

The scope **never** infers a tenant from a client header (`:82-85` comment), and `TenantScopeHeaderIsolationTest` pins that a forged `X-Church-ID` cannot select rows.

**Models deliberately NOT scoped:** `User` (documented and pinned by `ChurchScopeLoginTest:58` — login must resolve before a tenant exists), `AuditLog`, `Church`, `Permission`, `ChurchApplication`, `PasswordResetRequest`, and the entire `event_*` management subtree (10 models) plus `FeedbackReply`. The event subtree's tenant isolation is transitive through `event_id → events.church_id`, which **the database does not enforce** — there is no composite FK from any `event_*` table to `events`. → **R-19 (P2)**.

---

## 8. PERMISSION MODEL (VERIFIED)

Resolution chain: `PermissionMiddleware` → `Permission::userHasPermission($user, $key)` (`Permission.php:61-86`):
1. `$roleName = $user->role->value`
2. `$defaults = defaultRolePermissions()[$roleName] ?? null`
3. If the `role_permission` table has **zero rows at all** → use defaults
4. Else read the DB pivot join (cached 3600 s)
5. If the DB mapping resolves empty but defaults exist → clear cache, use defaults
6. Else `in_array($key, $mapped)`

**No `Gate::before`, no `Gate::define`, no per-user permission grants in the resolution path.** `UserController::updatePermissions` exists but has **no route**.

| Role | Permissions (code) |
|---|---|
| `platform_admin` | **no key in `defaultRolePermissions()`** — pinned by `PlatformAdminAuthorizationMatrixTest:141-146` |
| `admin` / `assistant_admin` | the 27 `adminPermissionKeys()` |
| `stage_admin` | the same 27 **minus** `manage_church_settings` |
| `servant` | 16 keys: `view_users, view_events, manage_events, manage_event_registrations, view_event_reports, view_class_years, record_attendance, view_attendance, manage_invites, view_invites, view_feedback, view_verses, manage_verses, manage_attendance_contexts, view_points` |
| `member` | 6 keys: `view_events, view_class_years, view_attendance, view_verses, view_points, submit_feedback` |

`PermissionMiddleware` and `RoleMiddleware` both use **OR** semantics on comma-separated arguments. `permission:manage_invites,record_attendance` therefore means *either*.

---

## 9. SCOPE CLASSIFICATION SUMMARY

| Scope | Count | Notes |
|---|---|---|
| `PUBLIC` | 13 API + 5 non-API | §2 |
| `PLATFORM-SCOPED` | 12 | `role:platform_admin` middleware, the only `RoleMiddleware` usage |
| `CHURCH-SCOPED` (named ownership layer) | ~60 | Stage, Classe, Event, QRInvite, PasswordReset, ProfileUpdate, Points, AttendanceContext, User |
| `AUTHENTICATED` (self-scoped or read-only) | ~55 | |
| `STAGE-SCOPED` | **0 routes** | Stage isolation is enforced **inside** `ScopeResolver::allowedStageIds/allowedClassIds` and `EventAuthorizationService`, not by any route-level gate. `StageAdminScopeTest` (44 tests) covers it. |
| `UNKNOWN` | 6 | `notifications/{id}/mark-read`, `feedback/{id}/mark-seen`, `storage/delete`, `storage/replace`, `attendances/context-details` (`servant_id`/`context_id`), `attendances/absent-members` (`event_id`/`context_id`) |

---

## 10. CONFIDENCE

| Claim | Status | Evidence |
|---|---|---|
| Route inventory and middleware stacks | **VERIFIED** | `php artisan route:list -v` |
| Tenant-scope mechanics of `ChurchScope` | **VERIFIED** | source read + `TenantScopeHeaderIsolationTest`, `TenantArchitectureTest` |
| Composite tenant FKs exist in the migration source | **VERIFIED** | `2026_09_29_000001` §CONSTRAINTS |
| Composite tenant FKs exist in a live PostgreSQL | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** | no PostgreSQL reachable; `tenant:verify-schema` not run |
| `withoutGlobalScope(ChurchScope)` on `User` is a no-op | **VERIFIED** | framework source `Builder.php:213-224` at the installed v12.69.3 |
| 8 of 13 policies are never invoked | **VERIFIED** | grep across `app/Http/Controllers/**` |
| Ownership of `{roomId}/{sessionId}/{speakerId}/{busId}` | **UNKNOWN** | the controller does not check; the service was not exhaustively traced for these paths |
| `NotificationService::markAsRead` ownership enforcement | **UNKNOWN** | not traced to the query |
| Production `role_permission` seeding state | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** | the entrypoint runs the seeder idempotently but the DB state is external |
