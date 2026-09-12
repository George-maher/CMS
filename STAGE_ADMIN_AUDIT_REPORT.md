# Stage Admin Scope Enforcement — Audit & Impact Report

Date: 2026-09-12
Scope: Church → Stage → Class → Member hierarchy. Role ("what") is separated from Scope ("where"). Backend authorizes every write on the server; the stored `users.scope` column is only a denormalized annotation and is never trusted for access decisions.

## A. Executive Summary

The Stage Admin role was provisioned end-to-end in both the backend and the frontend. This audit closed five real isolation gaps (events, feedback, profile-update requests, leaderboard, and a critical controller parse error) and added the full `/stage/*` frontend surface (routes, sidebar, preheat, redirects, i18n). Verification baseline after all fixes:

- PHPStan `--level max`: **0 errors**
- Laravel Pint: **clean**
- Full test suite: **202 passed / 625 assertions**
- Frontend: `tsc -b` clean, ESLint **0 warnings**, production Vite build succeeds

## B. Roles & Scope Model

| Role | Effective scope (`User::getScope()`) | Permission set |
|---|---|---|
| platform_admin | Self (church bypass) | all |
| admin / assistant_admin | Church | `adminPermissionKeys()` |
| **stage_admin** | **Stage** (via `stage_id`; falls back to Self if unassigned) | `adminPermissionKeys()` minus `manage_church_settings` |
| servant | ClassScope (`class_servant` pivot) | servant keys |
| member | Self | member keys |

`ScopeResolver` is the single authorization oracle:

- `allowedStageIds(User)` / `allowedClassIds(User)` — derived purely from role + `stage_id` (never the stored `scope` column)
- `canAccessStage` / `canAccessClass` / `canAccessUser` / `userStageId` / `userClassId` — used by every protected flow
- `User::getScope()` is role-derived (`roleDefaultScope()`); the historical `scope`-column-wins behavior and the obsolete `isSameOrNarrowerThan()` / `scopeRank()` helpers were removed. This also closed a privilege-escalation vector and fixed 2 AttendanceTest failures (factory-created servants carry a legacy `scope='self'`).

### Permission matrix

`Permission::defaultRolePermissions()` grants StageAdmin every admin permission **except** `manage_church_settings`. Rule middleware (`permission:view_users`, `permission:manage_users`, `permission:manage_events`, `permission:manage_event_registrations`, `manage_attendance`, `manage_invites`, …) therefore permits StageAdmins onto the management routes; scope boundaries are enforced **inside** services/controllers/policies, never trusted from the client.

## C. Backend Scope Enforcement by Module

| Module | StageAdmin behavior after today's audit |
|---|---|
| **Users (module)** | `CreateUserRequest`/`UpdateUserRequest`/`RoleRequest` accept `stage_id` (nullable, int, `exists:stages,id`). `UserService::create/update/promote/demoteFromAdmin` persist `stage_id` + derived `scope`; a StageAdmin without `stage_id` is rejected with 422. `UserController` enforces `stageWithinScope()` on `store/update/promote`; `update` additionally verifies the target's class remains in scope. `UserPolicy` blocks StageAdmin from granting Admin/AssistantAdmin/StageAdmin roles. |
| **Stages / Classes** | Repositories + controllers filter via the global `ChurchScope`; no StageAdmin data leak (stage admin sees their own stage + classes through the same church-scoped query). |
| **Events** | **Fixed.** `EventService::list()` now feeds `class_year_ids` from `allowedClassIds()` for StageAdmins (mirrors the servant branch; only their stage's classes + all-classes events). `EventController` gained `stageAdminCannotAccessEvent()` + a unified `cannotAccessEvent()`; applied to `show`, `update`, `destroy`, `duplicate`, and **all 5 lifecycle actions** (publish/close/reopen/cancel/complete now receive the request user). `store()` rejects church-wide (`is_all_classes`) events and any target class outside the stage via `stageAdminTargetsInScope()`. |
| **Event sub-resources** | ⚠️ **Gap (recommended follow-up).** `EventRegistrationController`, `EventPaymentController`, `EventBusController`, `EventScheduleController`, `EventDashboardController`, `EventReservationController`, `EventAccommodationController` fetch events with church scope only (`Event::query()->find($id)`); a StageAdmin with `manage_event_registrations` could still register/check-in/edit sub-resources of an event targeting another stage's classes. Recommend a shared guard (e.g. trait method `assertCanManage(Event)` calling `cannotAccessEvent()`) wired into these controllers' event lookups. |
| **Feedback** | **Fixed.** `index()` scopes listings by stage classes for StageAdmins (empty list when no classes). `resolve()` / `reply()` / `show()` now apply the stage class scope via shared `isScopedReviewer()` + `scopedReviewerClassIds()` helpers (servant behavior unchanged). |
| **Profile Update Requests** | **Fixed.** New `ProfileUpdateRequestService::listRequestsForStageAdmin()` (stage-scoped via `user.stage_id` OR `user.class_id` in allowed set). `index()` routes StageAdmins to it. Service `canReview()` gained a StageAdmin branch (`canAccessUser`). `ProfileUpdateRequestPolicy` now admits StageAdmin to `viewAny`/`view`/`approve`/`reject` when `canAccessUser` passes. (Previously StageAdmins got 403 on the page and fell through to the whole-church list.) |
| **Leaderboard** | **Fixed.** `byClass()` now uses `ScopeResolver::canAccessClass()` for every role (StageAdmin restricted to their stage's classes); `myClasses()` uses `allowedClassIds()` for StageAdmins instead of the servant pivot. `global()` and `stages()` remain church-wide — stage admins see top-5/global stage labels only (no member data) — flagged as acceptable/minor. |
| **Attendance** | Already StageAdmin-aware (class-scoped list/record/lookup/stats/absent-members via `allowedClassScope()`) — no change required. |
| **QR invites / attendance contexts / verses** | No stage data model; church-scoped as designed, consistent with the permission set. |

## D. Backend Authentication & Roles

- `User::isStageAdmin()` existed; used consistently across the new branches.
- No new middleware; scope checks are service/controller/policy-local by design (role middleware stays coarse, scope stays fine).
- `ProfileUpdateRequestPolicy` now injects `ScopeResolverInterface` from the container (Gate resolves policies via the container).

## E. Critical Bug Fixed

`backend/app/Http/Controllers/Api/AttendanceController.php` had an orphaned `return $allowed !== []; }` outside any method plus a duplicate `canAccessMember` — a parse/syntax-lifetime hazard. Removed; `php -l` and the 3 AttendanceTest cases confirm the restored file parses and scopes correctly.

## F. Frontend — StageAdmin experience (`/stage/*`)

- **Routes** (`App.tsx`): lazy `StageAdminDashboard` + `<AppLayout allowedRoles={['stage_admin']}>` group with 14 children incl. nested `/:id` detail routes.
- **Redirection**: `useRoleAccess` (hierarchy 60 + `/stage` redirect), `AppLayout.redirectMap`, `Header.getHomePath`, `Login.roleRedirect`, `ApplicationStatus.roleRedirect`.
- **Sidebar**: new `stageNav` (12 items) selected for `stage_admin`; role label + badge added in `roles.ts`.
- **Pages**: `pages/stage/Dashboard.tsx` (attendance stats + stages/classes + events); shared admin pages reused for structure, users, events, attendance, leaderboard, QR, absent members, contexts, profile, profile-update-requests.
- **Prefetch**: `dataPrefetch.ts` stage_admin branch preheats the exact params each page uses on first mount.
- **i18n**: `roleStageAdmin`, `stageAdminDashboard`, `stageClasses` in EN + AR.
- **Types**: `UserRole` includes `stage_admin`; `User.stage_id`; `CreateUserPayload.stage_id`.

## G. Frontend request cache (Requirement 24)

Already satisfied by the 2026-09-12 client hardening: in-memory cache keys are prefixed by a deterministic FNV-1a hash of the `auth_token` (`client.ts` `buildCacheKey`), `clearRequestCache()` runs on logout and on 401, and `requestCache.invalidateCache()` matches by `includes()` so prefixed keys still invalidate. No per-user data can cross account boundaries or sessions.

## H. Remaining Gaps & Recommendations

1. **Event sub-resource scope** (medium) — add the `cannotAccessEvent` guard to the 7 event sub-resource controllers (see §C.4). Suggested: extract into a trait/base helper and call at event lookup.
2. **`global` / `stages` leaderboard** (low) — StageAdmins currently read church-wide top-5/global stage labels; filter with `allowedStageIds()` if product wants stricter isolation.
3. **`manage_membership_requests` & `manage_feedback`/** — StageAdmin inherits these church-scoped admin permissions; confirm product intent (StageAdmins managing church-wide membership requests). No data-level change made.
4. **`ProfileUpdateRequestService` notification routing** — new requests notify the responsible *servant*; a StageAdmin-only stage (no servants) would still get servant lookup attempt → falls back to null reviewer (no notification). Consider notifying StageAdmin(s) of the member's stage when no servant is found (follow-up).
5. **Reproduce/port stage-scoping tests** — existing suite covers attendance + events isolation; recommend adding Feature tests for: StageAdmin cannot view/edit another stage's event, cannot create all-classes event, profile-update-request stage listing/approve, feedback resolve out-of-scope, leaderboard byClass out-of-scope.

## I. Verification (2026-09-12)

- `vendor\bin\phpstan analyse --level max` → **OK, 0 errors**
- `vendor\bin\pint --test` → **passed** (6 previously-flagged files fixed)
- `php artisan test` → **202 passed (625 assertions)**
- `npx tsc -b` → clean; `npx eslint src --max-warnings 0` → clean; `npm run build` → succeeds
- All edited backend files `php -l` clean.

## J. Deployment Notes

- No migrations/changes required to deploy the backend scope fixes (no schema change; the `stage_id` column already exists via `2026_09_12_000001_add_stage_scope_to_users`).
- Re-run `php artisan db:seed --class=PermissionSeeder --force` **only if** the permission matrix changed (not changed today) — otherwise the existing `role_permission` rows already cover StageAdmin.
- Frontend: rebuild + redeploy the SPA (new `/stage/*` routes and preheat). No service-worker API-cache entries involved (already removed).