# PHASE 0 — RISK REGISTER

**Audit date:** 2026-09-30
**Every finding below is DOCUMENTED, not FIXED.** No application code, schema, configuration, dependency, permission or infrastructure was modified during Phase 0.

**Severity (per the Phase 0 brief):**

| P0 | Could cause cross-tenant data exposure, privilege escalation, authentication bypass, irreversible data corruption, or a production-wide outage |
|---|---|
| **P1** | Major security issue, serious data inconsistency, major business operation failure, unreliable production deployment |
| **P2** | Important but not immediately production-blocking |
| **P3** | Minor technical debt or improvement |
| **INFO** | No defect; informational observation |

**Confidence:** `VERIFIED` · `PARTIALLY VERIFIED` · `NOT VERIFIED`

---

## SUMMARY

| Severity | Count |
|---|---|
| **P0** | **0** |
| **P1** | 9 |
| **P2** | 24 |
| **P3** | 16 |
| **INFO** | 7 |

**No P0 was found.** The tenant-isolation layer, the composite database constraints, the platform/church authority split, the offline-queue session isolation, and the service-worker API exclusion are all genuinely well designed, and each is backed by a real test. That is a real finding, not a courtesy.

**The dominant risk is not a code defect. It is that the strongest work in this repository is uncommitted, untagged, and unverified against the production database engine.** → **R-01**

---

## P0 — CRITICAL

**NONE FOUND.**

No cross-tenant data exposure, no privilege escalation, no authentication bypass, no irreversible data corruption, and no production-wide outage was identified in the inspected code paths.

Explicitly noting what this does **not** mean:
- It does not mean the system is secure. It means no *P0-class* defect was found **in the code paths inspected, in the state of the working tree, on SQLite.**
- It does not cover the unverified surface (see §"Highest-risk unknowns").

---

## P1 — HIGH

### R-01 — The entire tenant-isolation hardening layer is uncommitted on a branch marked "NOT production ready"

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** |
| **Evidence** | `git log -1` → `bd37cf5 WIP: security hardening in progress (see conversation) - NOT production ready`. `git status --short` → **296 modified files, +9 066 / −3 584 lines, 30 untracked paths**. Untracked includes `backend/database/migrations/2026_09_29_000001_add_composite_tenant_foreign_keys.php`, `backend/app/Services/TenantConsistencyService.php`, `backend/app/Console/Commands/TenantAudit.php`, `TenantVerifySchema.php`, `backend/app/Http/Middleware/AssignRequestId.php`, 12 backend security test files, `frontend/src/lib/apiUrl.ts`, `frontend/vitest.config.ts`, `frontend/src/test/`. **Zero tags exist.** Remote is `origin/main`. |

**Impact.** The 542 passing tests prove something about a **working tree on one machine**, not about any commit. The composite tenant FKs — the single strongest tenant control in the system — are not on `origin/main`, are not in any release, and are not identified by any tag or artefact. If this machine is lost, the work is lost. If a deploy is cut from `origin/main`, it ships without the tenant constraints, the tenant audit command, the request-id middleware, and 12 security tests.

**Why P1 and not P0.** No data is currently exposed or corrupted; the risk is to the *integrity and reproducibility* of the verified state.

---

### R-02 — Tenant constraints are unverified against a live PostgreSQL engine

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** (that it is unverified) / **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** (the underlying state) |
| **Evidence** | `docker ps` → `failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine`. `Get-Command psql` → not found. No PostgreSQL in `D:\xampp`. `AttendanceConcurrencyTest` → `6 skipped`. `phpunit.postgres.xml` requires `127.0.0.1:5432`. |

**Impact.** The migration definitions are correct and are guarded at the source level (`TenantConsistencyTest:177` asserts the historical PostgreSQL syntax error is gone). But **whether `migrate` completes on PostgreSQL, and whether the 7 composite FKs exist in the production catalog, is unknown.** This exact question has already caused a shipped incident, documented in the repository's own CI comment: a composite-FK migration used `church_id IS NOT (SELECT …)`, which SQLite accepts and PostgreSQL rejects — so `migrate` aborted in production, **none of the tenant constraints were ever created**, and every SQLite test stayed green.

**The mitigation already exists and is good:** `php artisan tenant:verify-schema` queries the catalog, checks the constraints are attached to the right columns and are not `ON UPDATE CASCADE`, and exits non-zero on any violation. It is wired into CI against `postgres:16`. It simply was not run here.

---

### R-03 — Membership-request approval is the sole tenant boundary for an admin-only write, and has no test

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** |
| **Evidence** | `MembershipRequestController@approve` (`:94-103`) passes a bare `$id` + `$adminId` with **no controller-level ownership check**. `MembershipRequestService::approve` (`:93`) is the only place `$request->church_id === $admin->church_id` is evaluated; `reject` at `:156`. `MembershipRequestSubmitTest` contains **exactly 1 test** (`duplicate pending membership request is rejected`). Routes: `GET /membership-requests`, `GET /{id}`, `POST /{id}/approve`, `POST /{id}/reject` under `permission:manage_membership_requests` + `approved` + `throttle:sensitive`. |

**Impact.** If that single service-level comparison is ever removed, refactored, or reordered, an admin of church A can approve or reject a membership request belonging to church B. The failure mode is **silent** — a cross-church grant of membership. `TenantIsolationMatrixTest` covers classes/users/stages/events but **not** membership requests. Compare `PasswordResetRequestTest`, which has the identical class of assertion ("church a admin cannot review church b request") for the sibling feature.

**Not claimed:** that the endpoint is currently exploitable. The check is present and, on inspection, correct.

---

### R-04 — No database backup configuration, no restore procedure, and no restore ever performed

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** (absence) |
| **Evidence** | No `pg_dump` cron, no backup script, no PITR configuration, no restore script, no backup or recovery runbook anywhere in the repository. `scripts/rehearse-production-migration.sh` exists but is a *migration* rehearsal that **truncates data** and warns to use a disposable database; no output, log, or artifact from it is committed. |

**Impact.** Every mitigation in this report that depends on data recovery — church hard-delete, `app:reset-data`, the `2025_07_02_000000` points deletion, `2026_09_12_000001` duplicate-stage deletion, `2026_06_16_000006`'s `down()` — assumes a restore is possible. **That assumption is unverified and the mechanism is not in the repository.** Backup and restore may be configured at the Supabase/Railway platform level; that is external.

**This audit makes no claim that a backup exists, and no claim that a restore works. No restore was performed.**

---

### R-05 — The platform health check cannot detect PHP, database, queue or scheduler failure

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** |
| **Evidence** | `backend/railway.json` → `"healthcheckPath": "/healthcheck.txt"`. `backend/production/nginx.conf:19-22` → `location = /healthcheck.txt { return 200 "OK\n"; }` — a static nginx response. `production/supervisord.conf` runs the queue worker and scheduler as child programs of the same container with `autorestart=true`. |

**Impact.** A wedged queue worker, a crashed scheduler, a database connection failure, or a PHP-FPM failure inside the container all leave `/healthcheck.txt` returning `200`. Railway will not restart the service. Failed background jobs accumulate in `failed_jobs` with **no alerting** and **no pruning**. `GET /health` — which *does* probe the database and returns `503` when degraded — is **not** the endpoint the platform uses, and it lives on the `web` stack with no auth.

---

### R-06 — No continuous delivery; CI gates merges, not deployments

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** (that no CD exists) / **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** (what actually deploys) |
| **Evidence** | `.github/workflows/` contains **only** `ci.yml`. No deploy job, no `workflow_dispatch`, no environment, no approval gate. Vercel and Railway are configured (`vercel.json`, `railway.json`) but their own Git integrations are not visible from the repository. |

**Impact.** Whether a commit that passed the 3 CI jobs is what actually ships is unverifiable from here. Combined with R-01 (296 uncommitted files), the mapping between "what was tested" and "what is running" is unknown.

---

### R-07 — The offline attendance queue performs writes that are only at-least-once, with no client idempotency key

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** |
| **Evidence** | `frontend/src/lib/sync.ts:112-124` sends the queued body verbatim with no idempotency key. `client.ts:198-202` stores `{qr_token, attendance_context_id, event_id?, method?}`. `lib/requestId.ts` generates a `crypto.randomUUID()` but is used **only** for QR invite creation (`admin/QRManagement.tsx:171`, `servant/QRInvites.tsx:187`) — never for attendance. The mitigation is server-side: 3 partial UNIQUE indexes + `lockForUpdate`. |

**Impact.** A worker crash after the server commits but before the client records success causes a replay. The server rejects the duplicate, so the outcome is *usually* correct. But the retry loop treats any 4xx as **terminal** (`sync.ts:134-143` → `markSyncAbandoned`), and the abandoned record is never surfaced to the user (`getActionableSyncCount` excludes it, `db.ts:129-135`). A write that the user believes succeeded may sit permanently invisible.

This is a **known, deliberate design**, documented at `client.ts:45-63` and `sync.ts:28-42`, and the server-side dedup is genuinely good. It is listed P1 because a silently-dropped attendance record is a real business outcome with no user-visible signal.

---

### R-08 — 5 migrations fail the repository's own rollback lint, which is a CI gate

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** — executed locally |
| **Evidence** | `php scripts/scan-broken-migration-rollbacks.php` → *"Scanned 108 migrations. 5 migration(s) with a down() that cannot deterministically undo up() on SQLite"*: `2025_01_01_000006_add_attendance_qr_token_to_users`, `2025_06_01_000001_add_class_year_id_to_events`, `2025_06_09_000001_add_user_id_to_feedback`, `2025_06_15_000002_add_context_id_to_attendances`, `2025_07_09_000001_add_member_id_to_users`. Each drops a column that `up()` indexed, without dropping the index first. This script is CI step `.github/workflows/ci.yml:50`. |

**Impact.** Per `TENANT_RULES.md` §13: *"SQLite refuses otherwise and PostgreSQL silently succeeds, so the bug is invisible until a rollback is attempted on SQLite."* Either the CI backend job is **currently red on `main`**, or the script does not fail the build on findings. `MigrationRollbackTest` only covers the 4 newest and the 1 newest migrations, so it does not catch these.

---

### R-09 — `docs/audits/` and the entire audit trail are untracked; there is no release history

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** |
| **Evidence** | **Zero git tags.** No changelog, no release workflow, no version file (`composer.json` has no `version`; `frontend/package.json` says `1.0.0`; `web.php` health payload hardcodes `"version":"1.0.0"`). 12 audit reports sit at the repository root, several untracked (`PRODUCTION_READINESS_REPORT_2026-09-30.md`, `RECOVERY_AUDIT_REPORT.md`), and `AUDIT_CHANGES.md` was last modified 2026-09-20 while the code has moved since. |

**Impact.** There is no way to determine which version of the code any prior audit actually examined, or to reproduce a prior verification. Compounded by R-01 and R-06, there is no chain from "verified" to "shipped".

---

## P2 — MEDIUM

| ID | Finding | Confidence | Evidence |
|---|---|---|---|
| **R-10** | **`User` is the tenant root and carries no `ChurchScope`.** `User::byChurch()` is opt-in and a **no-op when `church_id` is falsy** (`User.php:425-433`). Every tenant boundary for user data is a call-site convention plus `UserRepository`'s own `whereRaw('1 = 0')`. | **VERIFIED** | `User.php:91` (no `BelongsToChurch`), `UserRepository.php:104`; pinned deliberately by `ChurchScopeLoginTest:58` |
| **R-11** | **Event registration accepts any `user_id` platform-wide at the request layer.** `StoreEventRegistrationRequest` uses `Rule::exists('users','id')` — tenant-blind. The controller performs **no** check. `EventRegistrationService::register` re-checks church (`:59`) and `canAccessUser` (`:68`), so the boundary exists **at the service layer only**, and no test exercises the cross-church case. | **VERIFIED** | `StoreEventRegistrationRequest.php:19`; `EventRegistrationController@store:44`; `EventRegistrationService.php:59, 68` |
| **R-12** | **Event sub-resource ids are not verified against the event.** `event.scope` middleware reads **only** `route('id')` (`EnsureEventScope.php:22`) and passes through with no check when the id is non-numeric (`:23-25`) or the event is not found (`:29-31`). `{busId}`, `{roomId}`, `{sessionId}`, `{speakerId}`, `registration_id` and `cell_id` are `exists:`-validated **without** an event link. `EventBusController@update` (`:52`) and `EventAccommodationController@assign` (`:158-161`) do not check. | **VERIFIED** (controllers) / **UNKNOWN** (whether the services re-check) | `EnsureEventScope.php:22-31`; `EventBusController.php:52`; `EventAccommodationController.php:158-161` |
| **R-13** | **Several attendance read endpoints accept tenant ids without ownership checks for `admin`/`assistant_admin`.** `attendances/filtered` (`user_id` never verified for any role), `attendances/context-details` (`servant_id`, `context_id` never verified for any role), `attendances/absent-members` (`event_id`/`context_id` have **no `exists:` rule at all**). Admins correctly hold church-wide authority, but a servant's out-of-scope values are replaced while an admin's are not constrained by class/stage. | **VERIFIED** | `AttendanceController.php:387-395, 401-413, 438-439, 194-196` |
| **R-14** | **`DailySpiritualRecordController` omits `stage_admin` from the member-override branch.** The override applies to `isAdmin() \|\| isServant()` only. A stage admin cannot view a member's record even inside their own stage. Not a security hole — an authorization gap that produces a wrong 404. | **VERIFIED** | `DailySpiritualRecordController.php:32, 44-49, 84-104` |
| **R-15** | **`ClasseController::removeServant` has no church or scope check on `user_id`.** `assignServant` (`:191-198`) and `assignMember` (`:312-318`) both do `User::byChurch()->find()` + `canAccessUser`. `removeServant` (`:213-221`) does neither. | **VERIFIED** | `ClasseController.php:213-221` vs `:191-198` |
| **R-16** | **`ClassePolicy::reorder` is role-only.** `ordered_ids.*` is validated with `exists:classes,id` (tenant-blind) and the policy (`:64`) checks only `isAdmin() \|\| isStageAdmin()` — no per-class ownership. `ClasseController@updateOrder:235` invokes it. | **VERIFIED** | `ClassePolicy.php:64`; `ClasseController.php:233-240` |
| **R-17** | **Three `withoutGlobalScope(ChurchScope::class)` calls on `User` are inert.** `EmailVerificationService.php:88, 116, 182`. `User` does not use `BelongsToChurch`, so the scope is never registered on its builder. Verified against the installed framework source: `Builder::withoutGlobalScope()` does `unset($this->scopes[$scope])` unconditionally (`Illuminate/Database/Eloquent/Builder.php:213-224`). These calls express an intent the model does not implement, and would silently become real if `User` were ever scoped. | **VERIFIED** | framework source at the installed v12.69.3 |
| **R-18** | **In console and queue contexts, `ChurchScope` applies no tenant filter.** Branch 1 requires `request()->route() !== null`; branch 2 requires `Auth::check()`. Both are false in a worker, so branch 3 applies no `where`. Any job or command querying a `BelongsToChurch` model sees all tenants unless it adds its own predicate. Currently mitigated because the only live queued path is dead code. | **VERIFIED** | `ChurchScope.php:16-55` |
| **R-19** | **10 tables reach a tenant only through an unconstrained single-column FK chain.** The whole `event_*` management subtree plus `feedback_replies` have **no `church_id` and no composite FK to `events`**. Tenant isolation is transitive through `event_id` and is enforced only by `event.scope` middleware + `EventAuthorizationService` — neither of which runs from a command, a job, or a second controller. | **VERIFIED** | `2026_08_23_000002/000004`; model inventory; `EnsureEventScope` |
| **R-20** | **Storage delete/replace accept an arbitrary URL with no caller or tenant ownership check.** `StorageController@delete:169-180` takes `url required string`; `@replaceFile:130` takes `old_url required url` format only. Gated by `permission:manage_users`, and `StorageEndpointAuthorizationTest` covers role denial and bucket mismatch — but **not** "object belongs to another tenant". | **VERIFIED** (controller) / **UNKNOWN** (service behaviour for cross-tenant objects) | `StorageController.php:130-192` |
| **R-21** | **`profile_update_requests.old_values` / `new_values` store PII unmasked.** They are `json NOT NULL` with **no `AuditLogValues` cast and no PII masking**, unlike `audit_logs`. A profile-update request contains exactly the fields (`name`, `phone`, `email`, `address`) that `AuditService::maskPii()` masks elsewhere. | **VERIFIED** | `2026_09_01_000001_create_profile_update_requests:17-18`; `AuditService.php:11-20` |
| **R-22** | **No CHECK constraints anywhere in 108 migrations.** `users.role`, `users.scope`, `users.application_status`, `events.status`, `events.type`, `points.type`, `qr_invites.type`, `feedback.category`, `event_registrations.status`/`payment_status`/`attendance_status`, `event_payments.method` are all plain `string` with application-only validation. Per the official PostgreSQL documentation, `CHECK` is the correct and cheap tool. | **VERIFIED** | full grep of all 108 migrations |
| **R-23** | **`TenantArchitectureTest` pins 13 tenant models; 15 use `BelongsToChurch`.** `MembershipRequest` and `Notification` are **not** in the provider. Removing the trait from either would not fail any test. | **VERIFIED** | `TenantArchitectureTest:176` vs the model inventory |
| **R-24** | **The same `class_years → classes` backfill is reachable from two migrations** (`2026_06_18_000002` and `2026_06_22_000001`), behind `hasColumn`/`hasTable` guards whose outcome depends on the actual historical schema state. Which one executes is not determinable from source alone. | **UNKNOWN** — requires a populated PostgreSQL at each historical step |
| **R-25** | **5 migrations fail `scan-broken-migration-rollbacks.php`, which is a CI gate.** See R-08. | **VERIFIED** |
| **R-26** | **`ResetApplicationData` uses `SET session_replication_role = replica`** — a PostgreSQL-only superuser variable that disables **all** FK triggers and cascade/RESTRICT actions. It will fail on SQLite. The command also creates a platform admin with the literal password `password` and echoes it to stdout. | **VERIFIED** | `ResetApplicationData.php:76, 111, 17, 119, 134` |
| **R-27** | **`LeaderboardService` correlated subqueries have no `church_id` predicate.** `COALESCE((SELECT SUM(points) FROM points WHERE user_id = users.id),0)` (`:146`) and `COUNT(*) FROM attendances WHERE user_id = users.id` (`:147`). Safe today only because `user_id` is unique. Any cross-church import of points or attendances would leak a total. | **VERIFIED** | `LeaderboardService.php:146-147` |
| **R-28** | **`EventRegistrationFactory` cannot produce a valid row.** No `event_id`, no `user_id` — both NOT NULL FKs. | **VERIFIED** | `EventRegistrationFactory.php:19-26` |
| **R-29** | **`AdminUserSeeder::run()` is an empty method**, still called by `DatabaseSeeder`. | **VERIFIED** | `AdminUserSeeder.php:9` |
| **R-30** | **The container binds `UserServiceInterface` → the empty `app/Services/UserService.php` stub**, while the live controller injects the concrete `Modules\User\Services\UserService` by autowiring. Any future constructor injection of the interface resolves to an empty class. | **VERIFIED** | `AppServiceProvider.php:160`; `app/Services/UserService.php:3` |
| **R-31** | **`StageService::create` and `AttendanceContextService::create` derive `church_id` implicitly from `auth()`** (`:86`, `:105`). A direct call with no authenticated user writes `church_id = NULL` silently. `BelongsToChurch::creating` does the same and does not throw. | **VERIFIED** | `StageService.php:86`; `AttendanceContextService.php:105`; `BelongsToChurch.php:18-36` |
| **R-32** | **`PointService` has no transaction of its own.** `addPoints` is safe only because both production callers sit inside `AttendanceService`'s transaction. A third caller silently gets a non-transactional point write. | **VERIFIED** | `PointService.php:31, 72`; `AttendanceService.php:105-164` |
| **R-33** | **`AttendanceService` is the only business-critical service with no defence-in-depth authorization.** It trusts `$recordedBy` entirely. `TenantOwnershipBoundaryTest` proves this pattern for `ClasseService` and `UserService` but **not** for `AttendanceService`. | **VERIFIED** | `AttendanceService.php:105-164` |
| **R-34** | **`MembershipRequestService::approve`/`reject` is the sole tenant boundary for an admin-only write and has no test.** See R-03. | **VERIFIED** |
| **R-35** | **`EventLifecycleService` performs multi-step business state transitions (publish → close → complete → duplicate) with no transaction and no idempotency key.** A partial failure leaves an event in an intermediate state with no repair path. | **VERIFIED** | `EventLifecycleService.php` (no `DB::transaction`) |
| **R-36** | **Destructive scheduled and console operations leave no audit trail.** `AuditService::log` returns early when `app()->runningInConsole() && ! runningUnitTests()` (`:33-35`), so `CleanExpiredInvites` (daily), `CleanAuditLogs` (weekly) and `ResetApplicationData` produce **no** `audit_logs` rows. | **VERIFIED** | `AuditService.php:33-35`; `routes/console.php:20-30` |
| **R-37** | **8 of 13 registered policies are never invoked from any controller.** `UserPolicy`, `EventPolicy`, `AttendancePolicy`, `QRInvitePolicy`, `FeedbackPolicy`, `DailyVersePolicy`, `DailySpiritualRecordPolicy`, `ChurchDeletionPolicy`. Each has an inlined equivalent, so this is not currently a hole — but the policy layer is a **second, divergent, untested description of the authorization model**, and `ClassePolicy::reorder` is a live example of the divergence. | **VERIFIED** | grep across `app/Http/Controllers/**` |
| **R-38** | **`ChurchDeletionPolicy` is registered but never invoked; the 4 read-only platform church endpoints have no in-body authorization.** They rely entirely on `role:platform_admin` middleware — sufficient today. | **VERIFIED** | `ChurchDeletionPolicy.php`; `ChurchDeletionController.php:20, 35, 69` |
| **R-39** | **Four of five Supabase storage buckets are configured `public => true`**, including `ids`, which holds national-ID and church-permission scans. | **VERIFIED** | `config/supabase-storage.php` |
| **R-40** | **`TRUSTED_PROXIES=*` trusts every proxy.** `TrackActivity` and all 30+ rate limiters key on `$request->ip()`, so a client able to set `X-Forwarded-For` can bypass IP-keyed throttles — including `login` (5/min) and `register` (10/hr). | **VERIFIED** | `.env.example:104`; `AppServiceProvider.php:290-561`; `TrackActivity.php:19` |
| **R-41** | **The whole queued-mail pathway is dead code and 4 of 9 notifications are never dispatched.** `EmailService` — the only dispatcher of `SendEmailJob` — is injected into no controller. See the Service Matrix §4 for the full trace. | **VERIFIED** | grep across `app/Http/Controllers/` |
| **R-42** | **The default mailer is `log`, and only 1 of 9 notifications is guarded against a non-delivering transport.** `config/mail.php:17`. A production deploy that does not set `MAIL_MAILER` writes notification bodies to `storage/logs/laravel.log`. `MailConfigurationValidator::shouldEnforceAtBoot()` exists, is tested, and is **called from nowhere**. | **VERIFIED** | `config/mail.php:17`; `EmailVerificationService.php:228-239`; grep for `shouldEnforceAtBoot` |
| **R-43** | **`LOG_STACK=single` with no rotation and no shipping.** `storage/logs` is container-local. There is no log shipper, no error-reporting SaaS, no `->report()` override, no `dontReport()` allow-list. | **VERIFIED** | `.env.example`; `config/logging.php:57-64` |
| **R-44** | **`sanctum:prune-expired` is not scheduled** although `config/sanctum.php:48` sets a 1440-minute token expiration. `personal_access_tokens` grows without bound. No `queue:prune-failed` either. | **VERIFIED** | `routes/console.php`; `config/sanctum.php:48` |
| **R-45** | **`TrackActivity` stores session-activity state in `Cache`, which defaults to `file` — a per-container store.** With more than one replica, idle-session revocation is not enforced consistently. | **VERIFIED** | `TrackActivity.php:20, 33`; `config/cache.php:18` |
| **R-46** | **The 2026-06-25 Supabase bucket migration skips silently** when the env vars are unset (log warning, returns). `up()` and `down()` are asymmetric across environments, so a production deploy can run migrations forever and never create the buckets. | **VERIFIED** | `2026_06_25_000001:16-20, 29, 50` |
| **R-47** | **`backend/.env.docker` is tracked in git and contains a set `DB_PASSWORD`** (a local development value, not a production secret). The root `.gitignore` lists it; `backend/.gitignore` deliberately does not. | **VERIFIED** | `git ls-files`, `.gitignore:13`, `backend/.gitignore:4` |
| **R-48** | **PostgreSQL version is stated three different ways:** `postgres:15-alpine` (compose), `postgres:16` (CI), "PostgreSQL 18.4" (2026-09-30 report). No production pin exists in the repository. | **VERIFIED** — **CONFLICT** |
| **R-49** | **An `analytics:cache` command exists and does nothing** — `$this->warn(...)` then `return self::SUCCESS`. It is not scheduled, so the impact is confusion rather than behaviour. | **VERIFIED** | `SyncAnalyticsCache.php` |
| **R-50** | **A migration that is not a schema migration calls an external API.** `2026_06_25_000001_create_supabase_storage_buckets` creates and deletes Supabase buckets. Schema migrations that make network calls break `migrate:fresh` in any environment without credentials. | **VERIFIED** | migration `:29, 50` |
| **R-51** | **Two migrations share the numeric prefix `2026_09_01_000001`.** Order is currently deterministic (filename string sort) but it is a latent hazard. | **VERIFIED** | `2026_09_01_000001_add_status_to_attendances`, `2026_09_01_000001_create_profile_update_requests_table` |
| **R-52** | **4 Observers perform external storage deletion inside a model delete event with no `try`/`catch`.** A storage failure surfaces *after* the row is gone, producing a partially-completed delete with an exception raised. | **VERIFIED** | `UserObserver`, `EventObserver`, `ChurchApplicationObserver`, `MembershipRequestObserver` |
| **R-53** | **`CleanAuditLogs` silently truncates on a storage failure.** `chunk()` returns false when the archive write fails, ending the loop without an error. It runs weekly against a 90-day retention. | **VERIFIED** | `CleanAuditLogs.php:67-105`; `routes/console.php:26-30` |
| **R-54** | **`npm audit` and `composer audit` were not executed in this audit** (no network). The lock file was last updated as part of the 2026-09-30 remediation. Current advisory status is **NOT VERIFIED**. | **NOT VERIFIED** |

---

## P3 — LOW

| ID | Finding | Confidence |
|---|---|---|
| **R-55** | `GET /churches/active` publishes `id`, `name`, `slug` **and street address** of every active church to an unauthenticated caller (`api.php:101-109`). Required by the public join form, so a business decision — but `address` is not obviously necessary for that purpose. | **VERIFIED** |
| **R-56** | `GET /health` and `GET /storage/{path}` are unauthenticated. `/health` leaks database connectivity. | **VERIFIED** |
| **R-57** | `SetLocale` only accepts the exact strings `en` and `ar`. `ar-EG` and `en-US,en;q=0.9` are silently ignored, so locale negotiation only works for a client that sends a bare code. | **VERIFIED** |
| **R-58** | `statefulApi()` is never registered in `bootstrap/app.php`. `SANCTUM_STATEFUL_DOMAINS` and `supports_credentials: true` are therefore inert today, but are latent misconfiguration if cookie auth is ever enabled — the shipped `SANCTUM_STATEFUL_DOMAINS` default contains no production domain. | **VERIFIED** |
| **R-59** | `phpunit.postgres.xml` is referenced by no CI step and no composer script. The `postgres` job relies on job-level env vars instead. Effectively dead configuration. | **VERIFIED** |
| **R-60** | PHPStan and Pint run only in the SQLite CI job, never in the `postgres` job. | **VERIFIED** |
| **R-61** | `phpstan.neon.dist` **excludes** `app/Http/Controllers/Api/AttendanceContextController.php` from analysis. The 14 other controllers are analysed; this one is not. | **VERIFIED** |
| **R-62** | 22 models have no factory. 7 frontend hooks (`useApi`, `usePagination`, `useCache`, `useOnlineStatus`, `useRoleAccess`, `useGsap`, `useDeviceDetection`) have no importers. `src/utils/deviceDetection.ts` and `src/pages/servant/LocalAttend.tsx` are **0 bytes**. `useGsap.ts` is a 1-line stub. | **VERIFIED** |
| **R-63** | `useRoleAccess` implements a numeric role hierarchy (platform 100 → member 10) that is **inconsistent** with the route guard's flat `allowedRoles.includes()` allowlist. Two different authorization models coexist in the frontend; neither is a security boundary. | **VERIFIED** |
| **R-64** | Duplicate API implementations: `getMyClassServants` exists in both `api/structure.ts:48` and `api/users.ts:83`. `api/structure.ts:17` calls `/structure-management/stages-with-classes`, which **does not exist** in `routes/api.php` (the real path is `/structure/stages-with-classes`) — a dead call. | **VERIFIED** |
| **R-65** | `useRoleAccess`'s `canManage` and the sidebar's role ternary disagree: `memberNav` is the fallthrough default, so an unrecognised role string gets the member menu. `/assistant-admin/*` routes are registered but never linked from the sidebar. | **VERIFIED** |
| **R-66** | The frontend bearer token lives in `localStorage['auth_token']` in plaintext, alongside `localStorage['auth_user']` which contains email, phone, address and `attendance_qr_token`. Readable by any script on the origin. Mitigated by a strict CSP (`script-src 'self'`, no `unsafe-inline`/`unsafe-eval`) and by the complete absence of `dangerouslySetInnerHTML` — but the token is not in an `HttpOnly` cookie, and `AGENTS.md` already records that the repo has shipped a cross-tenant bug class before. | **VERIFIED** |
| **R-67** | 4 axios/tsx call sites bypass `src/api/` and call `client.*` directly: `VerifyEmail.tsx:18`, `Login.tsx:88-89`, `ChurchDeletion.tsx:72`. `src/lib/sync.ts` uses bare `axios` by design. No module uses raw `fetch` or `XMLHttpRequest`. | **VERIFIED** |
| **R-68** | No axios `timeout` is set on either instance; only nginx sets `proxy_read_timeout 60s`. The 429 retry (3 attempts, fixed 2 s) is the only retry, and only for 429. | **VERIFIED** |
| **R-69** | `check-i18n.mjs` reports 6 files with dynamic (template-literal) translation keys as `[INFO]` for manual PR review. They are not machine-verified. | **VERIFIED** |
| **R-70** | `GET /events/{id}/reports/*` is consumed via a plain `<a href>` (`eventRegistrations.ts:216-219`), which attaches **no `Authorization` header** and therefore depends entirely on cookie/credentialed navigation. With bearer-token-only auth (`statefulApi()` not registered), this is expected to fail against the API and has no test. | **VERIFIED** (code) / **UNKNOWN** (runtime) |

---

## INFO — NO DEFECT

| ID | Observation | Confidence |
|---|---|---|
| **R-71** | **The tenant-isolation architecture is genuinely sound.** `ChurchScope` is a real global scope that **fails closed** on both unauthenticated routed requests (`whereRaw('1 = 0')`) and authenticated users with no church (`church_id = 0`), it never infers a tenant from a client header, and 15 models use it. 7 composite tenant foreign keys back it at the database layer with **deliberately no `ON UPDATE CASCADE`** — a decision documented, justified, and regression-tested. | **VERIFIED** |
| **R-72** | **The platform/church authority split is deliberate and load-bearing.** `platform_admin` has no key in `defaultRolePermissions()`, so it gets 403 on church endpoints; `/platform/*` is gated by `role:platform_admin`, the only use of `RoleMiddleware`. A platform admin is also forbidden on `POST /users`. `PlatformAdminAuthorizationMatrixTest:141-146` exists specifically to stop anyone "fixing" this into a privilege escalation. | **VERIFIED** |
| **R-73** | **The service worker provably cannot cache API responses.** The generated `frontend/dist/sw.js` contains the string `/api` **exactly once**, inside `NavigationRoute({denylist:[/^\/api\//]})`. Two registered routes total: that navigation route and a Google-Fonts `CacheFirst`. `globPatterns` excludes `json` and any `api` match. `pwaIsolation.test.ts` asserts against the generated artifact and CI builds before it tests so the assertion cannot be skipped. | **VERIFIED** — artifact inspected directly |
| **R-74** | **The offline-queue session-isolation problem is solved properly, not worked around.** Three independent guards: a full IndexedDB wipe on login/platformLogin/logout/401, a token re-check before **every** send that voids the run on change, and per-item credential replay. 15 tests in `syncSessionIsolation.test.ts` plus 9 in `authSession.test.tsx` plus 11 in `db.test.ts`. The reasoning is documented in-place at `sync.ts:78-92`. | **VERIFIED** |
| **R-75** | **`GET /storage/{path}` is correctly containment-checked.** `realpath()` + `str_starts_with($fullPath, $base.DIRECTORY_SEPARATOR)` refuses `..` traversal, absolute paths and symlink escapes before the file is opened. | **VERIFIED** |
| **R-76** | **The production entrypoint is a genuinely strong control.** It hard-fails boot on 7 misconfigurations, refuses to start on an unreachable database, refuses to start on an unknown schema state after a failed migration, and never prints a secret. | **VERIFIED** |
| **R-77** | **The PostgreSQL CI job is unusually well designed.** It provisions the real engine, migrates from scratch, runs a tenant audit that must be clean, verifies the schema catalog directly, rolls back and re-applies, then runs a named 27-file security suite and the full suite — with a comment explaining exactly which historical incident motivated each step. | **VERIFIED** |

---

## HIGHEST-RISK UNKNOWNS

Ordered by how much damage being wrong would cause.

| # | Unknown | Why it matters | How to close it |
|---|---|---|---|
| **U-1** | **Does the production PostgreSQL actually have the 7 composite tenant FKs?** | If not, the entire tenant-isolation guarantee rests on PHP-layer checks only — and this exact failure has already shipped once. | Read-only DB access + `php artisan tenant:verify-schema` |
| **U-2** | **Is there a working database backup, and has a restore ever succeeded?** | Every destructive operation in the system assumes recovery is possible. R-04. | Platform backup config + an actual restore drill into a scratch instance |
| **U-3** | **What is actually running in production right now?** | R-01 + R-06: 296 uncommitted files, HEAD says "NOT production ready", no tags, no CD. The mapping between the verified working tree and the deployed code is unknown. | `railway deployments`, `vercel deployments`, and a diff against `origin/main` |
| **U-4** | **Does the replica count exceed 1, and is concurrent-migration safe?** | Every replica runs `migrate --force` at boot (`RUN_MIGRATIONS` defaults to `true`). Docker Compose de-conflicts this; Railway may not. | Railway service replica count |
| **U-5** | **Does production `role_permission` contain the expected rows?** | `Permission::userHasPermission` falls back to hard-coded defaults when the table is empty or a role's mapping resolves empty. If the seed never ran, **authorization behaviour differs from what the code says it is**. | `SELECT role_name, count(*) FROM role_permission GROUP BY 1` |
| **U-6** | **Are the Supabase bucket `public` flags actually applied?** | 4 of 5 are configured public, including `ids` (national-ID scans). R-39. | Supabase API — bucket list with flags |
| **U-7** | **Is any email actually delivered?** | 8 of 9 notification classes are unguarded against the default `log` mailer; `EmailService` is dead code. R-41, R-42. | Send one probe message and read the delivery log |
| **U-8** | **Are the current lock files free of advisories?** | `composer audit` and `npm audit` were **not run** in this audit. R-54. | `composer audit --locked --no-dev` and `npm audit --audit-level=high` with network |
| **U-9** | **Is the queue worker actually draining, and is anything in `failed_jobs`?** | The platform health check is a static nginx 200 (R-05); nothing prunes or alerts on failures. | Railway worker logs + `SELECT count(*), max(failed_at) FROM failed_jobs` |
| **U-10** | **Is `nginx.conf`'s `proxy_pass http://nginx:80` in `frontend/nginx.conf` correct in production?** | The frontend nginx proxies `/api/` to a host literally named `nginx` — the Docker Compose service name. Under Vercel this config is unused, but if the frontend image is ever run standalone it will not resolve. | Confirm which frontend host is actually in use |
| **U-11** | **Does `CORS_ALLOWED_ORIGINS` in production include the real frontend domain?** | `config/cors.php` falls back to `FRONTEND_URL` then `http://localhost:3000`. A miss means the SPA gets no API at all — a total outage, not a subtle one. | Railway env var list |
| **U-12** | **Is there any data currently violating a tenant constraint?** | `tenant:audit` reports without repairing. Production state is unknown. | `php artisan tenant:audit` against production |

---

## WHAT WAS **NOT** DONE IN PHASE 0

Explicit, because the absence of a fix is not evidence of an audit.

- No application code, route, middleware, policy, service, model, migration or seed was modified.
- No schema was changed. No migration was created or run against any database.
- No permission, role mapping or authorization rule was changed.
- No environment variable, `.env` file, deployment configuration or CI workflow was modified.
- No dependency was installed, updated, downgraded or removed. `composer audit` and `npm audit` were **not** run.
- No Docker container was started. No PostgreSQL server was started or contacted.
- No production system, API, Vercel project, Railway service, Supabase project or Resend account was accessed.
- No git history was reset, rebased, stashed or force-pushed. No commit was created. The working tree is exactly as found: 296 modified files, 30 untracked paths.
- No email was sent. No backup was taken. No restore was attempted.
- **No secret value was printed.** Environment files were inspected with values redacted; only PRESENT/MISSING/REFERENCED status is reported.

The only files created are the nine documents in `docs/audits/`.
