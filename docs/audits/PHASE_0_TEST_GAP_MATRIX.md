# PHASE 0 — TEST GAP MATRIX

**Audit date:** 2026-09-30
**Executed during this audit (VERIFIED, locally):**

| Command | Result |
|---|---|
| `php artisan test` (SQLite `:memory:`, `phpunit.xml`) | **542 passed, 6 skipped, 3202 assertions, 69.3 s** |
| `php artisan test --filter=AttendanceConcurrencyTest` | **6 skipped** — all require PostgreSQL |
| `vendor/bin/phpstan analyse --level=max` | **No errors** |
| `vendor/bin/pint --test` | **passed** |
| `php scripts/scan-broken-migration-rollbacks.php` | **5 findings** (see §5) |
| `php scripts/check-lang-parity.php` | **PASS** — EN 123 keys / AR 123 keys |
| `npm test` (Vitest, jsdom) | **8 files, 72 tests, all passed, 14.25 s** |
| `npx tsc --noEmit` | **no output — clean** |
| `npm run lint` (ESLint 10.5) | **no output — clean** |
| `npm run check:i18n` | **PASS** |
| `php artisan route:list` | 224 routes, 218 under `api/`, 214 under `api/v1/` |
| `composer audit --locked --no-dev` | **NOT EXECUTED** — composer binary not attempted offline |
| `npm audit --audit-level=high` | **NOT EXECUTED** — no network |

> **What "the suite is green" does and does not prove.** Every result above is from **SQLite in-memory on Windows/PHP 8.2.12**. The production driver is PostgreSQL. The repository's own CI comment (`.github/workflows/ci.yml:52-69`) records a shipped incident where a migration that PostgreSQL rejects left every SQLite test green while the constraints were never created. A green SQLite suite is necessary and not sufficient.

---

## 1. WHAT THE 542 TESTS ACTUALLY VERIFY

### 1.1 Suite composition

| Suite | Files | Notes |
|---|---|---|
| `Unit` | 1 | `ExampleTest` — `assertTrue(true)`, and it does **not** extend the app `TestCase`. Effectively no unit tests exist. |
| `Feature` | 51 | Every non-`ExampleTest` feature file uses `RefreshDatabase`. **No test uses `DatabaseMigrations`.** |
| `Security` | 27 (named, explicit list in `phpunit.xml:27-55`) | Deliberately a named list, not a glob, so adding a security test is a conscious act |

`tests/TestCase.php` overrides `call()` to run `Auth::shouldUse('sanctum')` + `Auth::forgetGuards()` after every simulated request, and sets `$mockConsoleOutput = false` (a Windows workaround, per `AGENTS.md`).

### 1.2 The security suite is genuinely high-signal

These are **not** smoke tests. They assert database state, not just status codes:

| Test | Tests | What it actually proves |
|---|---|---|
| `TenantIsolationMatrixTest` | 2 churches × 2 stages × 2 classes | Cross-tenant writes → 404/403 **and** `assertDatabaseMissing` **and** an unchanged unscoped `DB::table()->count()`. A mixed `[own, foreign]` `target_class_ids` array is rejected **whole** — no partial event, no `event_targets` row for either id. Client-supplied `church_id` cannot override ownership. A member with a tampered `scope=church` still resolves to `Self`. |
| `TenantOwnershipBoundaryTest` | letters A–J | Every rejection asserts DB state. Critically: `:565` calls `ClasseService::create()` **directly** with a foreign stage and asserts it **throws**; `:594` calls `UserService::create()` directly and asserts `AuthorizationException`. These are the only tests that prove a service is safe when middleware does not run. |
| `CompositeForeignKeyTest` | raw `DB::table()->insert()` | All application layers bypassed. Proves cross-church `classes`/`users`/`events` inserts raise `QueryException`; same-church, multi-class-per-stage and NULL class/stage succeed; a stage **cannot be moved** to another church while a class references it (the `ON UPDATE CASCADE` regression guard); after a real bearer-token HTTP flow, three raw JOIN violation counts are 0. |
| `PlatformAdminAuthorizationMatrixTest` | 5 non-platform roles × 3 paths | All 403. A platform admin is **forbidden** on `/users`, `/churches`, `/password-reset-requests`, `/membership-requests`. `defaultRolePermissions()` has **no** `platform_admin` key. Soft-delete without credentials → 422 with `deleted_at` still null; with a **wrong** password + correct confirmation → still not deleted. An approved `admin` with `church_id = NULL` sees **zero** stages. A platform admin gets **403 on `POST /users`**. |
| `StageAdminScopeTest` | 44 | Own-class create, other-stage refusal, admin/assistant refusal, client `stage_id` ignored, list/read/update scoping, promote/demote, class create/update/reorder, servant/member assignment, invite forced to own stage, other-church access, **privilege escalation via `user update`** (grant admin, reset password, demote a peer), cross-stage points, event retargeting. |
| `TenantArchitectureTest` | source/reflection | 7 composite FKs exist with the exact column mapping; the 2 parent unique keys exist; **13** tenant models use `BelongsToChurch`; 9 tenant-sensitive FormRequest fields each have a **named** ownership check that still exists (class+method via reflection); `ScopeResolver` still declares all methods; `User::getScope()` ignores a tampered `scope` column (7 cases). |
| `CreateUserStageOwnershipTest` | 10 | The D-1 regression. Neither a nonexistent nor a foreign stage yields ≥500; the foreign one is exactly 403; the body contains neither `users_church_stage_fk` nor `SQLSTATE`; own-stage, derived-from-class and bare member creation still 201; `PATCH` with a foreign stage leaves the original `stage_id` intact; a platform admin gets 403. |
| `AttendanceConcurrencyTest` | 6, **PostgreSQL-only** | Second independent PDO session; asserts the concurrent insert is **blocked** (lock timeout) while the first is uncommitted; duplicate rows raise `23505`; recording twice creates 1 attendance + 1 point; duplicate reference raises `23505`; a second member and a second day are both allowed (no degenerate constraint). **Skipped locally.** |
| `AttendanceClassIdSpaceTest` | — | Asserts `users.class_year_id` is **still** FK'd to `class_years`; with a colliding legacy id, attendance lands on the member's **own** class, not the class that owns that id in the other id space. |
| `QRInviteCrossChurchScopingTest` | — | An invite cannot reference another church's `attendance_context_id`; the token lookup does not leak a foreign context; a user from another church cannot accept (422 `invite.church_mismatch`), `use_count` stays 0, role unchanged. |
| `EmailVerificationTokenSecurityTest` | 18 | Production rejects each non-delivering driver; blank `from` rejected; local/testing permit `log`; the token is **hashed at rest**; a database dump cannot replay it; the queued payload is **encrypted** (`ShouldBeEncrypted`); dispatch is refused when the transport cannot deliver; the raw token **never appears in the log**; `failed()` logs metadata only; verify and resend endpoints are **byte-identical** across unknown/verified/unverified. |
| `StorageEndpointAuthorizationTest` | — | Role denials; bucket allowlist; the stored extension is derived from the validated MIME, not the client filename; Supabase/local/replace all refuse a URL or bucket mismatch **before mutating**, and assert the object still exists. |
| `MigrationRollbackTest` | 2 | Rolling back 4 migrations drops the `stage_id` column **and its index** (the SQLite failure mode) and re-applying restores both; rolling back the newest drops all 7 composite constraints and re-applying recreates all 7. |
| `RequestIdTest` | 7 | ULID generated when absent; safe client ids preserved; 6 hostile values (newline, CRLF, 65 chars, space, semicolon, empty) all replaced and **not echoed back**; the id is on error responses; no service shadows the correlation key; the platform-login path is not written to info/warning/error. |
| `PasswordResetRequestTest` | 20 | Church A's admin can **neither review nor list** church B's requests; the public token-reset endpoint no longer exists; double approval prevented by locking. |
| `ChurchApplicationAccessTest` | — | An anonymous lookup returns **no PII key at all** (13 named keys asserted absent) and only `status` + `contact_email`. |
| `ChurchDeletionTest` | 15 | Cross-church registration regression, rollback on failure, password+confirmation required, non-platform-admin 403, Arabic messages. |
| `TenantConsistencyTest` | — | A user with a class but no church is detected as `unowned` and repaired to the class's church; the migration **throws** matching `/tenant:audit/` when a repairable row exists; the inspector reports the offending id and destination church; **the source contains no `IS NOT (SELECT …)`**. |
| `TenantOrphanRepairTest` | — | An orphan is counted as `orphaned`, **excluded** from `countRepairable()`, and after `repair()` **keeps its `church_id`** and its dangling `class_id`. This is the control that prevents the earlier subquery repair from writing `NULL` over the only tenant information a row carried. |
| `TenantScopeHeaderIsolationTest` | 2 | A forged `X-Church-ID` header cannot select rows and cannot assign a model tenant. |
| `BulkAtomicityTest` | — | A mid-batch authorization failure leaves the first user's permissions unchanged; a cross-tenant id is silently excluded; a failed bulk class creation leaves no rows. |
| `ChurchScopeLoginTest` | 3 | **`User` must not carry the `ChurchScope` global scope** — login must resolve before a tenant exists. This is a guard *against* a plausible future "fix". |

**Assessment: the tenant-isolation and platform-authorization test coverage is genuinely strong and unusually good.** This is the most credible part of the repository.

### 1.3 Frontend tests (72) — all real, all narrow

`vitest.config.ts` uses `jsdom`, `globals: true`, `fake-indexeddb/auto` in setup, `testTimeout: 20 s` (raised because the retry backoff is timer-driven), and is **deliberately separate** from `vite.config.ts` so the PWA build is untouched.

| File | Tests | What it proves |
|---|---|---|
| `src/lib/__tests__/syncSessionIsolation.test.ts` | 15 | Session change after the first send → **exactly 1** axios call; changes between items → 1 call carrying tenant A's token; an aborted run reports `synced:1, failed:0`; `[400,401,403,404,409,422]` → abandoned after exactly **1** attempt, never re-selected; duplicate attendance 422 → actionable count 0; `[500,502,503]` still retried; transport error still retried; `abandoned` excluded from the actionable count. |
| `src/lib/__tests__/db.test.ts` | 11 | The real `db.ts` over a spec-compliant in-memory IndexedDB. Queue state machine; `abandoned` excluded **and retained**; `clearAllData()` empties both stores; `cacheClearChurch(1)` leaves church 2 intact. |
| `src/lib/__tests__/sync.test.ts` | 8 | Real timers (faking `setTimeout` deadlocks against fake-indexeddb). Replay uses the token captured at enqueue time; a transient failure survives one run and completes on the next; **`abandoned` never re-selected**; 3 items → exactly 3 sends; **a poison item does not block healthy items in the same run**. |
| `src/lib/__tests__/requestCache.test.ts` | 7 | TTL hit; full `invalidateCache()` removes entries across tenants; pattern invalidation; the SWR write-back generation guard; TTL expiry with fake timers. |
| `src/test/authSession.test.tsx` | 9 | Tenant A queues a write → login as B → queue length 0 **and** cached API response gone; the same for `platformLogin`; logout clears all three localStorage keys **and** the queue; **network failure during revalidation keeps the session** (offline-first); 401 clears it; a session validated < 5 min ago is trusted without an API call. |
| `src/test/offlineAttendancePath.test.ts` | 6 | Drives the **real interceptor** and the **real replay loop** against a capturing axios mock. Both endpoints queue; the bearer token is captured; no queueing when online; no queueing for reads; the replay URL carries the same base as the live client; `/v1` is never emitted without `/api`. |
| `src/test/offlineReplayUrl.test.ts` | 4 | Stubs the **production** `VITE_API_URL` (the dev default would pass vacuously) and asserts the absolute replay URL. |
| `src/test/pwaIsolation.test.ts` | 4 + 4 | 4 read `vite.config.ts`; 4 read the **generated `dist/sw.js`** and are `it.skipIf(!built)`. CI runs `npm run build` **before** `npm test` precisely so these cannot be silently skipped. |

---

## 2. TEST COVERAGE GAP MATRIX

`YES` = tested and passing · `PARTIAL` = some cases covered, meaningful cases missing · `NO` = no test · `UNKNOWN` = cannot be determined

| Area | Code exists | Tests exist | PostgreSQL tested | Security tested | Concurrency tested | Production verified |
|---|---|---|---|---|---|---|
| **Authentication** | YES | YES (24+21 tests) | PARTIAL (suite-wide) | YES | NO | **UNKNOWN** |
| **Authorization (role/permission)** | YES | YES | PARTIAL | YES | NO | **UNKNOWN** |
| **Tenant isolation** | YES | YES (strong) | **UNKNOWN** | YES | NO | **UNKNOWN** |
| **Platform admin** | YES | YES | **UNKNOWN** | YES | NO | **UNKNOWN** |
| **Users** | YES | YES | PARTIAL | YES | NO | **UNKNOWN** |
| **Churches** | YES | YES | PARTIAL | YES | NO | **UNKNOWN** |
| **Stages** | YES | YES (44-test `StageAdminScopeTest`) | PARTIAL | YES | NO | **UNKNOWN** |
| **Classes** | YES | YES | PARTIAL | YES | NO | **UNKNOWN** |
| **Events** | YES | YES (49-test `EventManagementTest`) | PARTIAL | PARTIAL | NO | **UNKNOWN** |
| **Attendance** | YES | YES | **PARTIAL — 6 concurrency tests SKIPPED on SQLite** | YES | **PARTIAL — only attendance + points** | **UNKNOWN** |
| **QR** | YES | YES (both files) | PARTIAL | YES | **NO** — `client_request_id` idempotency is tested **sequentially only** | **UNKNOWN** |
| **Membership** | YES | **PARTIAL — 1 test** | NO | **NO** — `approve()`'s church check untested | NO | **UNKNOWN** |
| **Assignments** | YES | YES | PARTIAL | YES | NO | **UNKNOWN** |
| **Points** | YES | **PARTIAL — `PointTest` has 2 tests, both read-only** | NO | NO | only via the attendance index | **UNKNOWN** |
| **Email** | YES (mostly inert) | **PARTIAL — 36 tests on the verification path** | NO | YES (token hashing, encryption, no-log) | NO | **NOT VERIFIED — no delivery ever observed** |
| **Queue** | YES | **NO** — no `Queue::fake` exists anywhere in `tests/` | NO | NO | NO | **UNKNOWN** |
| **`SendEmailJob`** | YES | **NO** — 0 references in any test file | NO | NO | NO | **UNKNOWN** |
| **Password reset** | YES | YES (20 tests) | PARTIAL | YES | PARTIAL (locking tested sequentially) | **UNKNOWN** |
| **PWA** | YES | YES (source + generated artifact) | n/a | YES | n/a | **UNKNOWN** |
| **Offline sync** | YES | YES (4 files, 40 tests) | n/a | YES | n/a | **UNKNOWN** |
| **IndexedDB** | YES | YES (11 tests over real fake-indexeddb) | n/a | YES | n/a | **UNKNOWN** |
| **Migrations** | YES | **PARTIAL** — only the 4 newest + the 1 newest | PARTIAL (CI job) | n/a | n/a | **UNKNOWN** |
| **Database constraints** | YES | YES (behavioural, raw inserts) | **UNKNOWN — the actual catalog was not queried in this audit** | YES | NO | **UNKNOWN** |
| **Frontend components** | YES (44 components) | **NO** — 0 component tests | n/a | n/a | n/a | n/a |
| **Frontend routing / guards** | YES | **NO** | n/a | n/a | n/a | n/a |
| **Frontend contexts (Offline/Sync)** | YES | **NO** | n/a | n/a | n/a | n/a |
| **i18n** | YES | YES (`check:i18n`, 6 files listed as dynamic-key INFO) | n/a | n/a | n/a | n/a |
| **HTTP 429 retry interceptor** | YES | **NO** | n/a | n/a | n/a | n/a |
| **Cache invalidation rules** | YES | PARTIAL (the cache is tested; the 21 mutation rules are not) | n/a | n/a | n/a | n/a |
| **Console commands** | YES (8) | **NO** except `app:verify-invited-accounts` | NO | NO | NO | **UNKNOWN** |
| **Observability** | PARTIAL | **NO** | n/a | n/a | n/a | **UNKNOWN** |
| **Backup / restore** | **NO** | **NO** | n/a | n/a | n/a | **NO** |

---

## 3. THE GAPS THAT MATTER, RANKED

### 3.1 P1 — Membership request approval is an unverified tenant boundary

`MembershipRequestSubmitTest` contains **one** test. `MembershipRequestService::approve()` and `reject()` are the **sole** enforcement of `$request->church_id === $admin->church_id` (`:93`, `:156`) for an admin-only, write-capable, rate-limit-protected endpoint — and neither has a test.

Contrast: `PasswordResetRequestTest` has an explicit *"church A admin does not see church B requests in list"* and *"church a admin cannot review church b request"*. The same class of assertion is simply missing here. → **R-34**

### 3.2 P1 — The whole PostgreSQL story is unverified in this environment

`AttendanceConcurrencyTest` skipped 6/6. The `postgres` CI job exists and is well designed, but **no PostgreSQL was reachable during this audit**, so:

- the 7 composite tenant FKs were **not** confirmed present in any live catalog
- `migrate` was **not** confirmed to complete on the production driver
- `tenant:audit` was **not** confirmed clean on production data
- row-lock concurrency was **not** verified

The repository contains a specific, well-designed tool for every one of these (`tenant:verify-schema`, `tenant:audit`, `AttendanceConcurrencyTest`) and CI runs all of them. They simply were not run here.

### 3.3 P2 — The queue has no tests at all

`QUEUE_CONNECTION=sync` in both phpunit configs. There is **no `Queue::fake`, `Mail::fake`, `Notification::fake`, `Http::fake` or `Storage::fake` anywhere in `backend/tests/`**. So:
- `SendEmailJob`'s `$tries=3`, `$backoff=10`, `retryUntil`, missing-email guard, `failed()` handler and non-idempotency are **entirely untested**
- `VerifyEmailNotification`'s `ShouldBeEncrypted` payload is asserted via the class's **flags**, not by running a worker
- `failed_jobs` is never exercised

### 3.4 P2 — Points writes are barely covered

`PointTest` has **2 tests**, both read-only (`GET /points/balance`, `GET /points/history` — status + JSON shape). There is no test for `addBonusPoints` directly, none for the points→notification path, none for `getLeaderboard`, and none for cross-tenant points writes.

### 3.5 P2 — Event coverage has named holes

Well covered by `EventManagementTest` (49) and `ResponsibleServantFlowTest` (9). **Not covered:** `EventReportService` (all 3 CSV exports + `dashboard()`), `EventScheduleService` sessions/speakers CRUD (only `assignBus` capacity is touched), `EventLifecycleService::duplicate()`, and `EventAuthorizationService` used directly.

### 3.6 P2 — Zero frontend component, routing or context tests

All 8 frontend test files target `lib/` and `api/client.ts`. There is no test for the `AppLayout` role guard, no test for `Sidebar` role-based navigation, no test for the 429 retry interceptor, and no test for the 21 cache-invalidation rules.

This matters less than it would elsewhere, because the frontend guard is explicitly documented as UX-only. But it means a regression in the guard is caught by nothing.

### 3.7 P3 — Unit tests are effectively nonexistent

`tests/Unit/ExampleTest.php` is one `assertTrue(true)` and does not extend the app `TestCase`. Every one of the 542 tests is a feature test through the HTTP kernel.

### 3.8 P3 — 5 migrations fail the rollback lint

`scan-broken-migration-rollbacks.php` reports 5 migrations whose `down()` cannot deterministically undo `up()` on SQLite (index not dropped before the column). This lint **is a CI gate**. Either the CI backend job is currently red, or the script does not fail the build. `MigrationRollbackTest` only covers the 4 newest and the 1 newest, so it does not catch these. → **R-25**

### 3.9 The 4 tests worth calling out as exemplary

Whatever else Phase 1 finds, these should not be weakened:

1. `TenantOwnershipBoundaryTest:565/:594` — the only tests that prove a **service** is safe when middleware does not run. This is the single most important test pattern in the repository and it is applied to exactly two services.
2. `CompositeForeignKeyTest:235` — the `ON UPDATE CASCADE` regression guard. It pins a *negative*: that the database must **refuse** to silently re-home a class when its stage changes church.
3. `TenantOrphanRepairTest` — pins that repair **never writes `NULL` over the only tenant information an orphan row carries.** This is a fix for a real, previously-shipped data-destruction bug, and it is now protected.
4. `pwaIsolation.test.ts` — asserts against the **generated `dist/sw.js`**, not just the source config, and CI builds before it tests so the assertion cannot be skipped. A source-text check cannot see what workbox actually emitted.

---

## 4. CONFIDENCE

| Claim | Status |
|---|---|
| 542 passed / 6 skipped / 3202 assertions on SQLite | **VERIFIED** — executed |
| PHPStan level-max: no errors | **VERIFIED** — executed |
| Pint: passed | **VERIFIED** — executed |
| Frontend: 72 tests passed, tsc clean, eslint clean, i18n parity exact | **VERIFIED** — executed |
| 6 skipped tests are all PostgreSQL-only concurrency tests | **VERIFIED** — filter run |
| 5 migrations fail the rollback lint | **VERIFIED** — script executed |
| EN/AR backend lang parity exact (123 keys each) | **VERIFIED** — script executed |
| PostgreSQL test results | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** |
| Production data satisfies the tenant constraints | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** |
| Dependency vulnerability status | **NOT EXECUTED** — no network; `composer audit --locked` and `npm audit` were not run |
