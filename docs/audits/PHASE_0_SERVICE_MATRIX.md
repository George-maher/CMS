# PHASE 0 — SERVICE / BUSINESS LOGIC MATRIX

**Audit date:** 2026-09-30
**Scope:** `backend/app/Services/` (40 files), `backend/app/Jobs/`, `backend/app/Notifications/`, `backend/app/Listeners/`, `backend/app/Events/`, `backend/app/Observers/`, `backend/app/Mail/`, `backend/app/Console/Commands/`, `backend/routes/console.php`.
**Method:** read-only. Each service was read for: public methods, models touched, transaction boundary, external side effects, jobs, events, and **whether it performs its own authorization or assumes the caller did**.

> **The governing question for this section is not "is the controller gated?" but "is this service safe if called from a command, a job, a seeder, or a second controller?"** A service is a public class in the container. Middleware does not run for it.

---

## 1. CLASSIFICATION LEGEND

| Class | Meaning |
|---|---|
| `READ-ONLY` | no writes |
| `SINGLE-WRITE` | exactly one DB write, no transaction |
| `MULTI-WRITE` | ≥ 2 writes with no transaction → **partial-write risk** |
| `TRANSACTIONAL` | writes wrapped in `DB::transaction` |
| `NON-TRANSACTIONAL` | writes with no transaction |
| `EXT` | external side effects (HTTP, storage, filesystem) |
| `AUTHZ: SELF` | the service performs its own ownership check |
| `AUTHZ: ASSUMES` | the service trusts the caller — safe only when every caller is gated |
| `AUTHZ: NONE` | neither — it accepts tenant ids and writes them |

---

## 2. SERVICE INVENTORY (40 files, 38 real)

| # | Service | Class | Txn | Side effects | Authorization posture |
|---|---|---|---|---|---|
| 1 | `AttendanceService` | TRANSACTIONAL, `AttendanceRecorded` event | ✅ `:105-164` | event dispatch `:158` | **ASSUMES** — `$recordedBy` is trusted; does not re-check that the recorder may record this member |
| 2 | `PointService` | MULTI-WRITE, **NON-TRANSACTIONAL** | ❌ | `NotificationService::createForBonusPoints` `:80` | **MIXED** — `addPoints` uses **`User::find()` unscoped** (`:24`); `addBonusPoints` uses `User::byChurch()` (`:52`). No actor check. |
| 3 | `QRInviteService` | TRANSACTIONAL ×3 | ✅ `:79, :302, :423` | none | **SELF** — token-authorized; re-verifies church at `:330` |
| 4 | `UserService` (`Modules/User/`) | MULTI-WRITE, partly transactional | only `bulkUpdatePermissions` `:498` | none | **SELF** — the **most defensively-written service**: re-checks via `ScopeResolver` at `:90, 112, 128, 243, 292, 340, 388, 453, 500` |
| 5 | `UserService` (`app/Services/`) | — | — | — | **NOT PRESENT** — 3-line comment stub. The container binds `UserServiceInterface` → this **empty** class (`AppServiceProvider.php:160`), while the live controller injects the concrete `Modules\User\Services\UserService` by autowiring. → **R-30 (P3)** |
| 6 | `ChurchDeletionService` | TRANSACTIONAL ×3 | ✅ `:166, :233, :346` | cache invalidation `:452`; `AuditService` | **ASSUMES** — `User $admin` is trusted |
| 7 | `ChurchApplicationService` | TRANSACTIONAL ×4 | ✅ `:80, :140, :263, :335` | **file upload/delete** `:196-241`; 3 notifications | **SELF** — `authorizeApplicationUpdate` `:56-74` (owner session **or** password) |
| 8 | `MembershipRequestService` | MULTI-WRITE, partly transactional | only `approve` `:99` | file upload `:53`; notification `:124` **outside** the txn | **PARTIAL** — `approve`/`reject` check `$request->church_id === $admin->church_id` (`:93, :156`); `submit` takes `churchId` from the caller |
| 9 | `StorageService` | — | — | — | **NOT PRESENT** — 3-line comment stub (replaced by `SupabaseStorageService`) |
| 10 | `SupabaseStorageService` | EXT (HTTP) | ❌ | Supabase REST: `deleteFile` `:107`, `fileExists` `:186`, `getFileMetadata` `:208`, `createBucket` `:234/242`, `deleteBucket` `:279`, `uploadRaw` `:306` | **NONE** — relies only on a bucket-name containment check `:96, :98` |
| 11 | `LocalStorageService` | EXT (filesystem) | ❌ | `Storage::disk()->putFileAs/delete/exists/path` | **NONE** — same bucket containment check `:98` |
| 12 | `FileUploadService` | EXT (delegating) | ❌ | delegates to `StorageServiceInterface` | **NONE** — path→bucket map `:12-19` |
| 13 | `EventRegistrationService` | TRANSACTIONAL ×3 | ✅ `:45, :220, :352` | 10 notification calls | **PARTIAL** — `register` re-checks church `:59` and `canAccessUser` `:68`; `confirm`/`cancel`/`remove` **assume** |
| 14 | `EventPaymentService` | TRANSACTIONAL ×2 | ✅ `:23, :99` | notification `:84` (inside txn) | **NONE** — `recorded_by` taken from the caller `:79` |
| 15 | `EventAccommodationService` | TRANSACTIONAL / MULTI-WRITE | ✅ `:74, :225, :308, :412` | none | **NONE** — event-scoped via the `Event` object only |
| 16 | `TenantConsistencyService` | MULTI-WRITE (bulk UPDATE), **NON-TRANSACTIONAL** | ❌ | none | **NONE** — CLI/ops tool, `public const RELATIONSHIPS` `:56` |
| 17 | `AuthService` | TRANSACTIONAL | ✅ `:176` (register) | cache | **SELF** — re-verifies invite + church + stage/class binding `:181-252`; `platformLogin` rejects non-platform `:56`; `login` rejects platform admins `:123` |
| 18 | `EmailService` | EXT (queued mail only) | ❌ | **dispatches `SendEmailJob` ×10** `:20, 32, 44, 54, 73, 85, 101, 116, 128, 143` | **NONE** — pure dispatch facade. **HAS NO PRODUCTION CALLER** (see §4) |
| 19 | `EmailVerificationService` | MULTI-WRITE + EXT | ❌ | `$user->notify(VerifyEmailNotification)` `:252` | **SELF** — token-bound. Three `withoutGlobalScope(ChurchScope)` calls are **inert** (User is unscoped) |
| 20 | `PasswordResetRequestService` | TRANSACTIONAL ×3 | ✅ `:138, :182, :231` | **no email leg** (documented `:21-25`); in-app notifications | **PARTIAL** — `listRequests`/`findById` filter by `church_id` `:325, :349`; `approve`/`reject`/`resetPassword` take `$adminId` with **no verification** |
| 21 | `UserProvisioningService` | SINGLE-WRITE, NON-TRANSACTIONAL | ❌ | none | **NONE by design** — documented `:22-30`. Creates users with no actor. |
| 22 | `ScopeResolver` | READ-ONLY | — | — | **IS the authorization primitive** |
| 23 | `CacheService` | READ-ONLY | — | `Cache::` facade | **NONE** — keys namespaced by `churchId` `:31-34` |
| 24 | `AuditService` | SINGLE-WRITE, NON-TRANSACTIONAL | ❌ | none | **NONE** — derives `userId`/`churchId` from `auth()` `:37-47`. **Writes nothing in console context** (`:33-35`) |
| 25 | `NotificationService` | MULTI-WRITE, **NON-TRANSACTIONAL** | ❌ | cache; `DB::table('notifications')->insert()` `:91` — **bypasses the `BelongsToChurch` creating hook, so `church_id` must be supplied explicitly** | **NONE** — trusts `userId`/`churchId` from the caller |
| 26 | `EventService` | TRANSACTIONAL | ✅ `:144` | cache; notification `:264` | **PARTIAL** — `list` narrows by role `:38-66`; `update`/`delete` **assume** |
| 27 | `EventLifecycleService` | MULTI-WRITE, **NON-TRANSACTIONAL** | ❌ | notification `:75` | **NONE** — status-gate validation only |
| 28 | `EventAuthorizationService` | READ-ONLY | — | — | **IS an authorization service** — `assertCanAccess` `:16`; the only `Event` layer that compares `church_id` `:18` |
| 29 | `EventScheduleService` | MULTI-WRITE, partly transactional | only `assignBus` `:111` | none | **NONE** — event-scoping via `findSession`/`findSpeaker`/`findBus` `:162/175/188` |
| 30 | `EventReportService` | READ-ONLY | — | **streams CSV to `php://output`** `:194-206` | **NONE** — relies on `EventPolicy`, which is never invoked |
| 31 | `EventReservationService` | TRANSACTIONAL ×2 | ✅ `:39, :97` | 3 notifications | **SELF** — `authorizeAction` `:145-170` (church + admin/responsible-servant + event match) |
| 32 | `ClasseService` | TRANSACTIONAL (bulk) + MULTI-WRITE | ✅ `:121` | none | **SELF** — `ScopeResolver` `:71, 112, 292, 386`; `TenantOwnershipBoundaryTest:565` asserts it throws when called directly |
| 33 | `StageService` | TRANSACTIONAL (bulk) | ✅ `:105` | none | **ASSUMES** — `create` uses `auth()->user()->church_id` `:86` implicitly; **a direct call with no auth writes `church_id = NULL`** → **R-31 (P2)** |
| 34 | `AttendanceContextService` | SINGLE-WRITE / READ-ONLY | ❌ | none | **ASSUMES** — `create` uses `auth()->user()?->church_id` `:105`; `listActiveForChurch(int $churchId)` `:78` takes a raw church id with no check |
| 35 | `MemberProfileService` | READ-ONLY | — | none | **SELF** — `canViewProfile` `:141-160` |
| 36 | `LeaderboardService` | READ-ONLY | — | cache | **NONE** — tenant from `auth()->user()?->church_id` `:158` |
| 37 | `FeedbackService` | MULTI-WRITE, **NON-TRANSACTIONAL** | ❌ | 3 notifications | **ASSUMES** — but `markAsSeen` **does** verify `$feedback->user_id === $userId` `:140` |
| 38 | `VerseService` | MULTI-WRITE, **NON-TRANSACTIONAL** | ❌ | cache — `invalidateVerse(0)` hardcodes church 0 `:67/93/124` | **NONE** |
| 39 | `DailySpiritualRecordService` | SINGLE-WRITE / READ-ONLY | ❌ | none | **NONE** — trusts `memberId` from the caller |
| 40 | `ProfileUpdateRequestService` | TRANSACTIONAL ×3 | ✅ `:27, :142, :248` | 2 notifications; `AuditService` | **SELF** — `canReview` `:455-482` (church + role + servant membership) |
| 41 | `MailConfigurationValidator` | READ-ONLY (config guard) | — | reads `config('mail.*')` | `NONE` — `shouldEnforceAtBoot()` is **called from nowhere** |

---

## 3. SERVICE FLOW MAPS — the mutation paths that matter

### 3.1 Attendance record (the business core)

```
POST /api/v1/attendances/record
 └ auth:sanctum, approval, throttle:api, permission:view_users, approved, throttle:attendance-record
 └ AttendanceController@record
    ├ RecordAttendanceRequest::authorize() → true
    ├ non-admin: User::byChurch()->byAttendanceQrToken() + canAccessMember
    └ AttendanceService::processAttendance()
       └ DB::transaction {                                    ← the only transactional attendance path
          ├ lockForUpdate() on the member                     :108-111
          ├ hasAttendanceToday() duplicate check              :113
          ├ Attendance::create()                              :120
          ├ PointService::addPoints()   (inherits the txn)    :149
          └ event AttendanceRecorded::dispatch                :158
          }
       └ catch QueryException SQLSTATE 23505 → duplicate       :166
 └ Listener InvalidateAttendanceCache (registered in AppServiceProvider:262-265)
 └ DB backstop: 3 partial UNIQUE indexes
```

**Gaps in this chain (documented, not fixed):**
- `PointService::addPoints` has **no transaction of its own**. It is safe *only* because its two production callers both sit inside a transaction. A third caller would silently get a non-transactional point write. → **R-32 (P2)**
- `AttendanceService` does **not** re-verify that `$recordedBy` may record this member. It is the one business-critical service with `AUTHZ: ASSUMES` and no defence in depth. → **R-33 (P2)**
- **No idempotency key** in the request. Deduplication is entirely `(church,user,context,day)` via the index. Two *different* legitimate attendances for the same member, context and day are indistinguishable from a client retry. This is a deliberate trade documented in `client.ts:57-60`.

### 3.2 User create (after defect D-1)

```
POST /api/v1/users
 └ permission:manage_users, approved
 └ CreateUserRequest   (class_year_id PROHIBITED; class_id/stage_id exists: only)
 └ UserController@store
    ├ stage_admin: role limited to member|servant            :86-91
    ├ classWithinScope()   — ALL roles                      :109   ← the D-1 fix
    ├ stageWithinScope()   — ALL roles                      :127   ← the D-1 fix
    └ UserService::create()
       ├ re-checks class via ScopeResolver                   :90
       └ no re-check of stage
 └ DB backstop: users_church_stage_fk, users_church_class_fk
 └ Pinned by CreateUserStageOwnershipTest (10 tests) and
   TenantOwnershipBoundaryTest:594 (direct service call throws AuthorizationException)
```

This is the **best-instrumented** path in the codebase and the only one where the "controller → service → database" chain is fully tested at all three layers. It is the reference pattern.

### 3.3 Church deletion (destructive, platform-scoped)

```
POST /api/v1/platform/churches/{id}/hard-delete
 └ auth:sanctum, approval, role:platform_admin, throttle:api, throttle:sensitive
 └ DeleteChurchRequest::authorize()  → role === PlatformAdmin
                                  +  confirmation in:"DELETE CHURCH"
                                  +  Hash::check($password, $user->password)
 └ ChurchDeletionService::hardDelete()
    └ DB::transaction {          :346
       ├ ~25 table trees deleted
       ├ class_servant rows
       ├ class_years rows                    ← the only live writer of this legacy table
       └ raw audit_logs INSERT  (bypasses the model, injects request()->ip()/userAgent())  :412, :426-427
       }
```

**`ChurchDeletionPolicy` is registered for `Church` but never invoked.** The four read-only platform church endpoints (`deletion-summary`, `deleted-history`, `deleted-detail`, `/platform/churches`) have **no in-body authorization at all** — they rely entirely on `role:platform_admin` middleware. That is sufficient *today*; the policy is a second, unused description. `reauth` middleware is registered but not used; the password re-check lives in the FormRequest instead.

### 3.4 Membership request approve

```
POST /api/v1/membership-requests/{id}/approve
 └ auth:sanctum, approval, permission:manage_membership_requests, approved, throttle:sensitive
 └ MembershipRequestController@approve
    └ passes bare $id + $adminId          ← NO controller-level ownership check
 └ MembershipRequestService::approve
    └ DB::transaction {                    :99
       ├ $request->church_id === $admin->church_id     :93   ← the ONLY boundary
       ├ writes
       └ notification (:124 is OUTSIDE the transaction)
       }
```

`MembershipRequestSubmitTest` contains exactly **1 test** (duplicate pending → 422). `approve()` — including the church-mismatch branch at `:93` that is the sole tenant boundary for this endpoint — has **no test**. → **R-34 (P1)**: this is an unverified Category-A boundary on a sensitive, write-capable, admin-only endpoint.

---

## 4. QUEUE, JOBS, EMAIL — the actual state

### 4.1 One job

`app/Jobs/SendEmailJob.php`:

| Property | Value |
|---|---|
| `$tries` | 3 |
| `$backoff` | 10 (seconds) |
| `$timeout` | **NOT SET** |
| `retryUntil` | `now()->addMinutes(30)`, set in the constructor |
| queue | **default** (no `onQueue()` anywhere in the codebase) |
| `failed()` | `Log::error` only — no rethrow, no alert |
| Idempotency | **NONE.** No `ShouldBeUnique`, no dedup key, no state check. A retry re-sends. |
| Missing-email guard | `Log::warning` + early `return` — **counts as a successful job** |

Per `TENANT_RULES.md` §12, at-least-once is accepted for a cosmetic notification. That reasoning is sound **for a live job**. It is currently moot.

### 4.2 Is email actually sent? — the decisive trace

| Question | Answer | Evidence |
|---|---|---|
| Where does mail physically leave? | **One site:** `app/Jobs/SendEmailJob.php:48` → `Mail::send(new SystemMail(...))` | grep `Mail::` across `backend/` returns only this line |
| What dispatches `SendEmailJob`? | `EmailService` only (10 call sites) | `app/Services/EmailService.php` |
| **Does anything call `EmailService`?** | **NO** | grep for `EmailService\|sendNotification\|sendInviteEmail\|sendWelcomeEmail\|sendFeedbackReply\|sendEventNotification\|sendAttendanceNotification\|sendApplicationApproved\|sendApplicationRejected` across `app/Http/Controllers/` → **no matches** |
| ⇒ | **The entire queued-mail pathway is dead code.** | |
| What *is* live? | 9 `ShouldQueue` Notification classes via `->notify()` | `VerifyEmailNotification`, `ResetPasswordNotification`, 3 church-application notifications, `NewChurchApplicationNotification` |
| How many of those 9 are actually dispatched? | **5 of 9** | `PasswordChangedNotification`, `PasswordResetRequestSubmittedNotification`, `PasswordResetRequestApprovedNotification`, `PasswordResetRequestRejectedNotification` have **no dispatcher anywhere in the codebase** |
| Is even the live path guaranteed to send? | **NO** | `EmailVerificationService::dispatch()` (`:228-239`) refuses when the mailer is unset or is `log`/`array`/`null`/`fail`, or `mail.from.address` is blank — it logs `verification_dispatch_refused / transport_cannot_deliver` and returns false. Rationale: *"Never hand a token to a transport that will write it to a log."* — a genuinely good control. |
| Is that guard enforced at boot? | **NO** | `MailConfigurationValidator::shouldEnforceAtBoot()` is implemented and unit-tested but **called from nowhere in `app/`** |
| Is the *default* mailer safe? | **NO** | `config/mail.php:17` → `env('MAIL_MAILER','log')`. `.env.example` sets `resend`; a deploy that does not, writes to the log. **Only the verification notification is guarded against this. The other 8 are not.** |
| Queue driver | `database`, `retry_after` 300, `after_commit` true | `config/queue.php` |
| Redis | compiled into the image, configured, **not deployed** | no redis service in compose; entrypoint allows `database` or `redis` |
| RabbitMQ / SQS / Horizon / Telescope / Pulse | **NOT PRESENT** | |
| `failed_jobs` alerting or pruning | **NOT PRESENT** | |
| `sanctum:prune-expired` | **NOT SCHEDULED** although `expiration=1440` | |

**Answer to "does email work?" — NOT VERIFIED, and structurally the system does not currently attempt to send most of what its templates suggest.** No email was sent or observed during this audit. The templates exist; the plumbing is largely inert.

---

## 5. TRANSACTION BOUNDARY MAP

| Operation | Transaction? | Partial-write risk |
|---|---|---|
| Attendance record | ✅ | none — the reference implementation |
| QR invite create / consume / rotate | ✅ ×3 | none |
| Church soft-delete / restore / hard-delete | ✅ ×3 | none |
| Profile update request approve/reject | ✅ ×3 + `lockForUpdate` | none |
| Event reservation approve/reject | ✅ ×2 | none |
| Event payment record / refund | ✅ ×2 | none |
| Event accommodation assign/remove | ✅ ×4 | none |
| Bulk stage create / bulk class create | ✅ | none |
| User create / update / delete | ❌ | low — single entity |
| **`bulkUpdatePermissions`** | ✅ `:498` only | pinned by `BulkAtomicityTest` |
| **`PointService::addPoints` / `addBonusPoints`** | ❌ | **medium** — safe only because both callers are inside a transaction |
| `MembershipRequestService::approve` | ✅ `:99`; **notification outside at `:124`** | low |
| `EventLifecycleService` (publish/cancel/complete/duplicate) | ❌ | **medium** — multi-step state transition, no rollback |
| `EventScheduleService` sessions/speakers/buses | ❌ except `assignBus` | medium |
| `NotificationService` bulk insert | ❌ | low |
| `FeedbackService` reply/resolve | ❌ | low |
| `VerseService` | ❌ | low |
| `AttendanceContextService::create` | ❌ | low |
| `UserProvisioningService` | ❌ | low |
| `AuditService::log` | ❌ | low — a failed audit write should not roll back the business write, which is the right call |
| `TenantConsistencyService::repair` | ❌ — **chunked bulk UPDATE** | **high by design** — an ops tool with a `--repair` flag and an interactive confirm |

→ **R-35 (P2):** `EventLifecycleService` performs multi-step, business-critical state transitions (publish → close → complete → duplicate) with no transaction and no idempotency key. A partial failure leaves an event in an intermediate state that no code path can repair.

---

## 6. CONCURRENCY-SENSITIVE OPERATIONS

| Operation | Mechanism | Verified? |
|---|---|---|
| Attendance duplicate | `lockForUpdate` + `hasAttendanceToday` + 3 partial UNIQUE indexes | **VERIFIED on SQLite** (index path). **Row-lock path NOT VERIFIED** — `AttendanceConcurrencyTest` skipped 6/6 locally; requires PostgreSQL. |
| Points duplicate award | partial UNIQUE `(reference_type, reference_id)` | index path verified; concurrent path not |
| QR invite idempotency | `unique(created_by, client_request_id)` + `markAsUsed` optimistic `where('use_count', $this->use_count)` | verified sequentially (`QRInviteTest:136-179`); **not under a real race** |
| QR invite consume | `DB::transaction` `:302` | sequential only |
| Password-reset double approval | `lockForUpdate` | verified sequentially (`PasswordResetRequestTest:513`) |
| Profile-update double review | `lockForUpdate` | sequential only |
| Church delete double-run | `DB::transaction` + status check → 409 | verified (`ChurchDeletionTest`) |
| Event registration | `unique(event_id, user_id)` | **no concurrency test at all** |
| Event waitlist promotion | none found | **no mechanism, no test** |
| Class ordering (`/classes/reorder`) | none | no mechanism; `ClassePolicy::reorder` is role-only |

---

## 7. EXTERNAL SIDE EFFECTS

| Side effect | Site | Failure behaviour |
|---|---|---|
| Supabase Storage HTTP | `SupabaseStorageService` (6 methods) | `Log::error` at 5 sites; no retry, no compensation |
| Local filesystem | `LocalStorageService` | Laravel `Storage` exceptions propagate |
| Supabase bucket create/delete | `CreateStorageBuckets` command + the `2026_06_25_000001` migration | migration **skips silently** when env vars are unset |
| File deletion on model delete | 4 Observers (`User`, `Event`, `ChurchApplication`, `MembershipRequest`) | **No `try`/`catch`.** A storage failure surfaces *inside* the delete — a partially-completed delete with an exception thrown after the row is gone |
| CSV stream to `php://output` | `EventReportService:194-206` | stream failure mid-write returns a truncated CSV with a 200 |
| Email | see §4 | dead path |

---

## 8. CONSOLE COMMANDS & SCHEDULE

`routes/console.php`:

| Command | When | Destructive? |
|---|---|---|
| `app:clean-expired-invites --days=7` | `dailyAt('03:00')`, `withoutOverlapping`, `runInBackground` → `storage/logs/scheduler-cleanup.log` | **YES** — `withoutGlobalScope(ChurchScope::class)` then bulk `delete()` of expired **and all revoked** invites |
| `app:clean-audit-logs --days=90 --force` | `weeklyOn(0, '04:00')`, same guards → `scheduler-audit.log` | **YES** — chunked (500) archive to JSON then `DELETE`. `chunk()` returns false on a storage failure, **silently truncating the cleanup** |
| `inspire` | manual | no |
| `app:reset-data` | manual, **not scheduled** | **YES — deletes rows in 19 tables + all users**; uses `SET session_replication_role = replica`; **creates a fresh platform admin with the literal password `password` and echoes it to stdout** |

All 8 commands: `CleanAuditLogs`, `CleanExpiredInvites`, `CreateStorageBuckets`, `ResetApplicationData`, `SyncAnalyticsCache` (**`analytics:cache` is a stub** — `$this->warn(...)` and return `SUCCESS`, it does nothing), `TenantAudit`, `TenantVerifySchema`, `VerifyInvitedAccounts`.

`tenant:audit` is read-only unless `--repair`, and **exits `FAILURE`** when repairable rows exist. `tenant:verify-schema` writes-and-rolls-back inside `DB::beginTransaction` with per-probe SAVEPOINTs, and **exits non-zero on any violation** — a genuine, well-built schema gate. Neither is scheduled in production; both are run manually and in CI.

**Not scheduled and not present anywhere:** `sanctum:prune-expired`, `queue:prune-failed`, `queue:retry`, `model:prune`, `telescope:prune`.

---

## 9. OBSERVABILITY OF THE SERVICE LAYER

| Signal | Present? | Detail |
|---|---|---|
| `request_id` correlation | **YES** | `AssignRequestId` prepends a ULID to every API request; `Log::withContext` publishes it; echoed as `X-Request-Id`; present in every error body. `RequestIdTest:110` guards against a service shadowing the key. |
| Service-level logging | 75 `Log::*` sites | Distributed across Email, Storage, ChurchDeletion, PasswordReset, ProfileUpdate, QRInvite, Auth. |
| Transaction / business-event tracing | **NO** | no metrics, no spans |
| Queue failure alerting | **NO** | `SendEmailJob::failed()` logs and stops |
| Notification dispatch logging | **PARTIAL** | `EmailVerificationService` logs a uniform `event`/`reason` shape and **never the raw token** (pinned by `EmailVerificationTokenSecurityTest:239`) — a good control. The other 8 notifications log nothing. |
| Audit trail | **YES, for models** | `AuditableTrait` on 8 models → `AuditService` → `audit_logs` with PII masking. **But writes nothing in console context** (`:33-35`), so migrations, seeders, console commands and the queue produce **no audit rows**. |
| Error reporting SaaS | **NO** | no Sentry/Bugsnag/Datadog/OpenTelemetry |

→ **R-36 (P2):** destructive operations performed by the scheduler and by console commands leave **no audit trail**, because `AuditService` short-circuits in console context. A `CleanExpiredInvites` run that deletes the wrong rows is invisible in `audit_logs`.

---

## 10. CONFIDENCE

| Claim | Status | Evidence |
|---|---|---|
| Service classification per §2 | **VERIFIED** | every file read |
| `EmailService` has no production caller | **VERIFIED** | grep across `app/Http/Controllers/` |
| 4 of 9 notifications have no dispatcher | **VERIFIED** | grep |
| `shouldEnforceAtBoot()` is called from nowhere | **VERIFIED** | grep |
| `User` is not scoped, so `withoutGlobalScope(ChurchScope)` on it is a no-op | **VERIFIED** | framework source `Builder.php:213-224` |
| `AttendanceService` is the only transactional attendance path | **VERIFIED** | source |
| `PointService` is non-transactional | **VERIFIED** | source |
| Row-lock concurrency behaviour under PostgreSQL | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** | 6 tests skipped locally; no PG available |
| Whether the queue worker and scheduler actually run in production | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** | |
| Whether any email is delivered in production | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** | no delivery attempted or observed |
| Whether `tenant:audit` reports clean on production data | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** | |
