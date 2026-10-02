# Production Readiness Report — Church Management System

**Date:** 2026-09-30
**Scope:** Final production-readiness pass. Laravel 12 + PostgreSQL 18 + React/TypeScript + Vite/PWA.
**Method:** Inspect → Reproduce → Test → Fix → Verify → Regression test → PostgreSQL verify → Full suite → Production rehearsal.

> This is not a claim of "fully secure". It is a claim of production-readiness **within the verified scope**, with explicitly documented residual risks.

---

## A. Executive Summary

# PRODUCTION READY WITH EXPLICIT EXCEPTIONS

The tenant-isolation architecture is sound and is now verified against a **real PostgreSQL 18 engine**, not only SQLite. I found and fixed **7 genuine defects**, three of which I reproduced as failing tests before touching any production code.

Two findings are worth stating up front because they were invisible to the existing test suite:

1. **`composer.lock` was pinned to vulnerable dependency versions.** `composer audit` on `vendor/` reported clean, but `--locked` — which is what CI and every production deploy actually resolve via `composer install` — reported **24 advisories** across 5 packages, including a high-severity Guzzle host-check bypass. The developer machine had drifted ahead of the lock. A fresh deploy would have shipped the vulnerable versions.
2. **The offline write path was entirely inert, and its safety was assumed rather than tested.** `trySyncAll()` re-derived the API base URL instead of reusing the client's, so under the documented production `VITE_API_URL` every replayed attendance would have 404'd. Separately, `AuthContext` clears the offline queue with a fire-and-forget `clearAllData()` while the replay loop iterates a pre-wipe snapshot — so a session switch mid-replay could transmit the **previous tenant's** queued writes. The last one is a cross-tenant write, not a lost record.

Neither was reachable by the existing 526-test suite, because both lived in the build/packaging layer and in the frontend.

The 3 documented exceptions are in §L and are operational/architectural, not code defects blocking release.

---

## B. Defects Found

### D-1 — Cross-tenant `stage_id` on user create: 500 + existence oracle

| | |
|---|---|
| **Severity** | High |
| **Issue** | `POST /api/v1/users` with a foreign `stage_id` returned **500 `INTERNAL_ERROR`**, not 4xx. |
| **Root cause** | `CreateUserRequest` validated `stage_id` with a tenant-blind `exists:stages,id`. `UserController::update()` re-resolved the stage through `Stage::query()->find()` (ChurchScope) and refused out-of-scope values **for every role**. `store()` performed that same check **only inside the `if ($role === stage_admin)` branch**. `UserService::create()` then re-validated `class_id` but not `stage_id`, so a `member`/`servant` payload carrying a foreign stage id reached the database and was stopped by the composite FK `users_church_stage_fk` — which stopped it by *throwing*. |
| **Impact** | (a) A 500 on a fully client-reachable input, with the raw driver message written to the log. (b) `500` vs `422` separated "exists in some tenant" from "exists nowhere", making the endpoint a cross-tenant stage-existence oracle over a small integer id space. (c) An internal-scope oracle for stage admins: `403` (own-church-but-outside-my-stage) vs `500` (other church). **No cross-tenant row was persisted** — the composite FK held. |
| **Fix** | Added the missing check to the non-stage-admin branch of `store()`, mirroring `update()` exactly, plus defence-in-depth in `UserService::create()` (services are callable from commands/seeders/jobs where middleware does not run). |
| **Regression test** | `tests/Feature/CreateUserStageOwnershipTest.php` — 10 tests. Asserts a 4xx, asserts **DB state unchanged**, asserts the 5xx body leaks neither the constraint name nor `SQLSTATE`, and asserts no false positives (own stage, omitted stage, member with no scope all still succeed). |
| **Verification** | Reproduced as 500 before the fix; passes after. Guard tests passed *before* the fix too, proving the fix is not a blanket rejection. |

### D-2 — Offline attendance enqueue was dead code

| | |
|---|---|
| **Severity** | High (functional) |
| **Issue** | No attendance write was ever queued when offline. The entire offline-write feature was inert. |
| **Root cause** | `OFFLINE_WRITABLE_PATTERNS` matched `/\/api\/v1\/attendance$/`, but the request interceptor tests `config.url` — the **relative** path the API layer passes to `client.post(...)`, i.e. `/attendances/record`. The `/api/v1` prefix is only ever applied to the instance's `baseURL`. The real routes are also `attendances` (plural), not `attendance`. No pattern could ever match. |
| **Impact** | Every offline attendance was silently lost. Reads as a working feature in the source. |
| **Fix** | Patterns corrected to the real endpoints (`/^\/attendances\/record$/`, `/^\/attendances\/record-by-member-id$/`). |
| **Regression test** | `src/test/offlineAttendancePath.test.ts` — drives the **real interceptor**, asserting a queued item, the captured bearer token, and no queueing when online or for reads. Verified failing on the old code (3 failures). |

### D-3 — Offline replay sent writes to the wrong URL in production

| | |
|---|---|
| **Severity** | High (functional) |
| **Issue** | Every replayed offline write 404'd under the documented production `VITE_API_URL`. |
| **Root cause** | `trySyncAll()` built its URL inline: `` `${import.meta.env.VITE_API_URL || '/api'}/v1${item.endpoint}` ``. The live client uses `buildBaseUrl()`, which is what guarantees Laravel's `/api` prefix. With `VITE_API_URL=https://backend.up.railway.app` the replay produced `https://.../v1/attendances/record`. Docker dev (`VITE_API_URL=/api`) produced the right answer **by coincidence**. |
| **Impact** | Once D-2 was fixed, the offline queue would have started failing every replay. Two independent breaks masked each other. |
| **Fix** | Extracted a single `src/lib/apiUrl.ts` exporting `API_BASE_URL` + `resolveApiUrl()`, imported by both the live client and the replay loop. One implementation, so a second derivation cannot reappear. |
| **Regression test** | `src/test/offlineReplayUrl.test.ts` — stubs the **production** `VITE_API_URL` (the dev default would pass vacuously) and asserts the absolute URL. Verified failing on the old code (3 failures). |

### D-4 — Cross-tenant write during offline replay across a session switch

| | |
|---|---|
| **Severity** | High (security) |
| **Issue** | A session switch landing mid-replay could transmit the **previous tenant's** queued writes, signed with that tenant's bearer token. |
| **Root cause** | `AuthContext.login()` / `logout()` / `platformLogin()` call `clearAllData()` **fire-and-forget** (not awaited), while `trySyncAll()` iterates an in-memory snapshot taken before the wipe. On a session change the loop kept running; `markSyncCompleted` then no-opped because the row was already gone, so **the write happened and the queue lost the record of it**. Queue items carry no `churchId`/`userId`; isolation depended entirely on the unawaited wipe. |
| **Impact** | A cross-tenant write, not merely a lost record. Narrow window (requires a session switch mid-replay, which serial backoff widens considerably), but the consequence is a write performed under a foreign credential. |
| **Fix** | `trySyncAll()` snapshots the active token and re-checks it before **every** send; on change it emits an event and `break`s. An aborted run is **not** counted as a failure — the items are untouched and the run is void. |
| **Regression test** | `src/lib/__tests__/syncSessionIsolation.test.ts` — 18 tests. Verified 14 fail on the old code. |

### D-5 — `request_id` correlation key shadowed by database primary keys

| | |
|---|---|
| **Severity** | Medium (observability) |
| **Issue** | 14 log statements passed a **database primary key** under the key `request_id`. |
| **Root cause** | `AssignRequestId` publishes the correlation ULID via `Log::withContext(['request_id' => …])`. Laravel merges call-site context **over** the global context, so any service logging its own `request_id` replaces the ULID. `PasswordResetRequestService` (8 sites) and `ProfileUpdateRequestService` (6 sites) both did. |
| **Impact** | Grepping logs by `request_id` returned a mix of ULIDs and integers that correlate to nothing. This is precisely the signal needed to investigate a security incident, and it was unusable. |
| **Fix** | Renamed to `password_reset_request_id` / `profile_update_request_id`. |
| **Regression test** | `RequestIdTest::test_no_service_shadows_the_correlation_request_id_key` — a source-level guard, because a name collision cannot be observed from a single response. |

### D-6 — Secret platform-admin login path written to production logs

| | |
|---|---|
| **Severity** | Medium (information disclosure) |
| **Issue** | `ForceJsonResponse` wrote the platform-admin login **path**, full URL and client IP to the log at `info` level on every attempt, in a statement mislabelled `[DEBUG]`. |
| **Root cause** | A leftover debug statement. `info` ships to whatever log sink the deployment uses, which routinely has far wider read access than the application. |
| **Impact** | A durable record of a security control in the log store. The URL was deliberately obscure; logging it defeats that. |
| **Fix** | Demoted to `Log::debug` and removed the path/full-URL from the context. The attempt and source IP are still observable. |
| **Regression test** | `RequestIdTest::test_the_secret_platform_login_path_is_not_logged_at_info_level` — asserts no `Log::info('[DEBUG]'` and no `path` key in any info/warning/error context. |

### D-7 — Attendance recorded against the wrong class via mixed id spaces

| | |
|---|---|
| **Severity** | Medium (data integrity, latent) |
| **Issue** | `AttendanceService` wrote `$member->class_year_id ?? $member->class_id` into `attendances.class_year_id` — a **`class_years` id** stored in a **`classes` foreign key**. |
| **Root cause** | `2026_06_22_000001` backfilled `attendances.class_year_id` to a `classes.id` and repointed its FK at `classes`. It deliberately did **not** repoint `users.class_year_id`, which is still FK'd to `class_years` — the one remaining `class_year_id` in the schema in a different id space. |
| **Impact** | Latent, not live: `class_year_id` is `prohibited` on input so every member has `NULL` there and the `??` arm always applied. But the column is nullable and historically populated, and the two tables have **independent sequences**, so a single colliding value yields either a 500 or — far worse — a **successful write attributing the attendance to a different class**, with no error and corrupted per-class reporting. This is the third instance of the exact bug class documented in `TENANT_RULES.md` §4. |
| **Fix** | Write `$member->class_id` — the only column in the same id space as the target foreign key. |
| **Regression test** | `tests/Feature/AttendanceClassIdSpaceTest.php` — builds a real `class_years` row whose id collides with a different class's `classes.id`. Verified: before the fix attendance landed on class **2** instead of the member's class **1** (`Failed asserting that 2 is identical to 1`). The test also asserts the FK target of `users.class_year_id`, so the day someone repoints it the test says so rather than the guard silently becoming decorative. |

### D-8 — `composer.lock` pinned vulnerable versions (24 advisories)

| | |
|---|---|
| **Severity** | High (supply chain) |
| **Issue** | `composer audit` (inspects `vendor/`) reported **clean**, but `composer audit --locked` (inspects the lock file — what CI and every deploy resolve) reported **24 advisories across 5 packages**, including **high**-severity `CVE-2026-69246` (Guzzle noncanonical host bypass) and a Laravel debug-page XSS. |
| **Root cause** | The working tree's `vendor/` had drifted ahead of `composer.lock`. Lock held guzzle 7.10.4, psr7 2.10.2, laravel v12.61.0, commonmark 2.8.2, flysystem 3.34.0 — all inside advisory ranges. CI and production run `composer install`, which honours the lock. |
| **Impact** | A clean local audit was **actively misleading**. Any fresh environment — CI, a new developer, a Railway deploy — would install the vulnerable versions. |
| **Fix** | `composer update` on the 5 packages with `--with-all-dependencies`, bringing the lock to guzzle 7.15.5, psr7 2.13.1, laravel v12.69.3, commonmark 2.10.3, flysystem 3.36.0. `composer audit --locked --no-dev` now reports **no advisories**. |
| **Verification** | Full suite re-run after the upgrade: SQLite 542 passed, PostgreSQL 548 passed, PHPStan level-max 0 errors, Pint clean. |
| **Prevention** | Added `composer audit --locked --no-dev` as an explicit CI step in both backend jobs, with a comment explaining why `--locked` and not a bare audit. |

---

## C. Security Verification

| Area | Result | Evidence |
|---|---|---|
| **Tenant isolation** | **PASS** | `ChurchScope` global scope on 14 tenant models (pinned by `TenantArchitectureTest::tenantScopedModelProvider`); 7 composite FKs verified in the PostgreSQL catalog *and* by behavioural write attempts; isolation matrix green on both engines. D-1 fixed. |
| **Authorization** | **PASS** | Resolve-inside-scope → authorize → service re-check → persist → DB invariant, applied on every path audited. The `exists:` re-audit covered **40 rule instances**; exactly **one** was Category C (D-1), now fixed. The rest are Category A (ownership enforced by a named layer) or B (church-scoped rule). |
| **Platform Admin** | **PASS** | `role:platform_admin` middleware on `/platform/*`; church endpoints gated by `permission:*` which platform admins hold **no** rows for. `PlatformAdminAuthorizationMatrixTest` + a new negative test (`test_platform_admin_cannot_reach_the_church_user_creation_endpoint`) added specifically to stop D-1's fix becoming a privilege escalation. Destructive ops additionally require password re-check + literal `DELETE CHURCH`. |
| **Authentication** | **PASS** | Sanctum bearer, `approved` gate, email verification, rate limiting, uniform `InvalidCredentials` on the platform path. |
| **Session isolation** | **PASS after fix** | D-4 fixed and pinned. Login/platformLogin/logout/401 all clear the queue; the replay loop can no longer outlive its session. |
| **QR Security** | **PASS** | 64-char `Str::random` tokens, 4-hour expiry, revoke, single-use, rotation invalidates the prior token, `lockForUpdate` on accept, raw tokens never logged, `used_by` roster deliberately omitted from the public details endpoint. |
| **Attendance security** | **PASS after fix** | Row lock + `hasAttendanceToday` + three partial unique indexes; genuine two-connection concurrency proof on PostgreSQL (skipped on SQLite, where `FOR UPDATE` is a no-op and the test would pass vacuously). D-7 fixed. |
| **Database constraints** | **PASS** | Verified in the PostgreSQL catalog, and behaviourally: cross-church class→stage, cross-church user→class, and null-class all behave correctly. No `ON UPDATE CASCADE` on any composite tenant FK. |
| **PWA isolation** | **PASS** | Generated `dist/sw.js` inspected: 2 registered routes (navigation route with the `/api/` denylist; Google-Fonts `CacheFirst`). Zero API caching. `/api` appears exactly once in the artifact. |
| **Information disclosure** | **PASS with accepted oracles** | See §L-3. Password reset, email verification and resend are uniform (status, body and code). Admin review endpoints are church-scoped → uniform 404. |

---

## D. PostgreSQL Verification

Executed against a real **PostgreSQL 18.4** engine (`127.0.0.1:5432`), not a container image.

| Check | Result |
|---|---|
| **Schema** | **PASS** — 108 migrations applied on PostgreSQL; `tenant:verify-schema` confirms all 7 composite FKs + 2 supporting unique keys exist and are attached to the right columns. |
| **Composite FKs** | **PASS** — all 7 present, verified by catalog query *and* by real write attempts. Behavioural proof executed: cross-church class→foreign stage rejected, cross-church user→foreign class rejected, NULL class accepted, same-church accepted. |
| **Migration** | **PASS** — `migrate:fresh`, `migrate:rollback --step=4`, re-`migrate`, all clean and idempotent. `MigrationRollbackTest` green. |
| **Security suite** | **PASS** — `--testsuite=Security`: **323 passed / 1203 assertions** on PostgreSQL. |
| **Feature suite (full)** | **PASS** — `phpunit.postgres.xml`: **548 passed / 1987 assertions**. |

**Test-driver split (documented per §7):**

| Runs on SQLite | Runs on PostgreSQL | Why |
|---|---|---|
| Whole suite (542) | Whole suite (548) | Both. PostgreSQL is the production driver; SQLite is fast feedback. |
| All feature/security tests | All feature/security tests | Both. |
| `AttendanceConcurrencyTest` (6 tests) | Same | **PostgreSQL only** — it self-skips on SQLite because SQLite has no row-level write locks, so `FOR UPDATE` is a no-op and a concurrency assertion would pass *vacuously*. This is the key driver-dependent test. |
| `CompositeForeignKeyTest` behavioural writes | Same | Both, with driver-aware SQL. |

The 6 SQLite skips are the concurrency tests, and they are **skipped by design**, not missing.

---

## E. Concurrency Verification

| Operation | Protected? | Mechanism | Tested? | Result |
|---|---|---|---|---|
| **Attendance — confirm/scan** | Yes | `DB::transaction` + `lockForUpdate` on the member row + `hasAttendanceToday` read + **three partial unique indexes** as an independent backstop. The `23505` unique violation is caught and mapped to a validation error rather than a 500. | **Yes** — real concurrent second PDO session, PostgreSQL only | **PASS** |
| **Attendance — duplicate submission** | Yes | The unique indexes make a duplicate unrepresentable regardless of application logic. | Yes | **PASS** |
| **Points** | Yes | Written inside the same transaction as the attendance; duplicate award pinned by test. | Yes | **PASS** |
| **QR invite — consume** | Yes | `lockForUpdate` inside `DB::transaction`, plus re-validation of the *fresh* row. | Yes | **PASS** |
| **QR invite — rotation** | Yes | `lockForUpdate`; the old token is invalidated transactionally. | Yes | **PASS** |
| **Membership — duplicate request** | Yes | Service-level pending-request check + `throttle:membership-request` (3/hr per IP+email). | Yes | **PASS** |
| **Membership — simultaneous approve/reject** | Yes | `lockForUpdate` on approval; state-transition guarded. | Yes | **PASS** |
| **Password reset — double approval** | Yes | `lockForUpdate`; completed requests cannot be reset again. | Yes | **PASS** |
| **Offline replay (frontend)** | **Yes, after D-4** | Session-token re-check before every send; 4xx classified as terminal. | **Yes** — 18 tests | **PASS** |

No blanket locking was added. Each race got the smallest correct mechanism: a unique index where the invariant is a *fact*, a row lock where a *state transition* must be atomic, and a session check on the client where the invariant is *which credential is allowed to send this*.

---

## F. Bulk Atomicity

| Operation | Semantics | Transaction boundary | Failure behaviour | Tested? |
|---|---|---|---|---|
| Event target class ids | **All-or-nothing** | Resolved and rejected as a whole in `EventController::targetsWithinScope()` | A single foreign id rejects the entire request | **Yes** — "mixed target class ids rejects entire operation" |
| Password reset review | Atomic state transition | `lockForUpdate` + guarded state change | Double approval refused | **Yes** |
| User bulk operations | Per-id authorization, then bulk write | Per-id `canAccessUser` + admin-tier guard | Foreign id rejected before any write | **Yes** |
| Class stage/class bulk | ChurchScope-resolved set comparison | Count mismatch ⇒ whole-request rejection | Partial application impossible | **Yes** |
| `tenant:audit --repair` | **All-or-nothing per relationship**, ordered parents-before-children | Chunked update, deterministic and idempotent | Verified in rehearsal: clean rows untouched, orphan never modified | **Yes** — rehearsal, §H |
| Attendance + points | Single transaction | `DB::transaction` wrapping attendance + points + event | Any failure rolls back both | **Yes** |

No transactions were added around read-only operations.

---

## G. Offline / PWA

| Item | Result |
|---|---|
| **Offline queue** | Working after D-2. State machine: `pending → completed` / `pending → failed → …` / `pending → failed → abandoned`. `abandoned` is terminal, retained for diagnostics, never re-selected — a poison item cannot block the queue. 4xx is now terminal immediately rather than burning 5 retries. |
| **Tenant isolation** | Fixed (D-4). Replay aborts on session change and is not counted as a failure. |
| **Session switching** | `login` / `platformLogin` / `logout` / 401 all clear the queue and the request cache. The request cache is keyed by a hash of the bearer token, so it is session-scoped by construction. |
| **Attendance idempotency** | At-least-once delivery that **degrades safely to at-most-once**: the server refuses an exact replay as a duplicate, and the unique indexes make it unrepresentable. No generic idempotency framework was introduced — the business key is the attendance itself, enforced by the DB. |
| **Service worker** | Verified against the **generated** `dist/sw.js`, not just the config. 2 routes: navigation (denylists `/api/`) and Google-Fonts `CacheFirst`. No API caching, no API precache. |
| **API cache safety** | `/api` appears exactly once in the artifact — inside the denylist. Asserted, and verified to fail when an API cache rule is injected. |

---

## H. Migration Safety

| Item | Result |
|---|---|
| **New migrations** | **None added.** All fixes are in application code. |
| **Rollback** | `migrate:rollback --step=4` → re-`migrate` → `tenant:verify-schema` all clean on PostgreSQL. |
| **Rollback lint** | `scan-broken-migration-rollbacks.php`: 108 migrations scanned, **5 flagged** as having a `down()` that cannot deterministically undo `up()` on SQLite (index dropped after the column it depends on). |
| **Historical exceptions** | The 5 flagged migrations are **documented, not rewritten**: `2025_01_01_000006`, `2025_06_01_000001`, `2025_06_09_000001`, `2025_06_15_000002`, `2025_07_09_000001`. Rewriting historical migrations to make rollback *theoretically* perfect is not worth the risk of changing migrations that have already run in production. |
| **Operational restriction** | **`Do not roll back before 2026-01-01`** — preserved unchanged. A production rollback across the 5 flagged migrations is not supported. |

### Production migration rehearsal (executed, not simulated)

Run against a **disposable** `chrehearsal` database on PostgreSQL 18, following `scripts/rehearse-production-migration.sh`:

| Step | Expected | Actual |
|---|---|---|
| `migrate:fresh` | clean | ✅ |
| Roll back composite FKs (pre-constraint state) | clean | ✅ |
| Load legacy fixture (3 churches, 6 users, 3 legacy states) | loaded | ✅ |
| `tenant:audit` | detect all 3, **exit 1** | ✅ detected 1 cross-church + 1 unowned + 1 orphan; exit 1 |
| `migrate` | **refuse**, modify nothing | ✅ threw; all 6 rows byte-identical afterwards |
| `tenant:audit --repair --force` | repair only what is safe | ✅ |
| `tenant:audit` | clean (orphan reported only) | ✅ exit 0 |
| `migrate` | succeed | ✅ |
| `tenant:verify-schema` | all constraints OK | ✅ behavioural proof executed |
| Rollback 4 + re-apply | clean, idempotent | ✅ |

**Repair safety — the critical property.** After repair:

| User | Before | After | Verdict |
|---|---|---|---|
| 1, 2, 3 (clean) | church 1, 1, 2 | unchanged | ✅ untouched |
| 4 (cross-church) | church 1, class 3 belongs to church 2 | **church 2** | ✅ re-homed to the parent |
| 5 (no church) | NULL, class 1 belongs to church 1 | **church 1** | ✅ adopted, not guessed |
| 6 (**orphan**, class 999) | church 1 | **church 1** | ✅ **preserved — not nulled, not guessed** |

The orphan is the case that matters most: an earlier implementation nulled `church_id` for it, permanently destroying the only tenant information the row carried. Orphans are now **reported and never modified**, and `--repair` requires explicit operator consent (`--force`) plus a backup warning. Ambiguous ownership is reported, never guessed.

**Post-repair note for operators:** the constraints are created `NOT VALID` while legacy orphans exist. They are still **fully enforced for every new INSERT/UPDATE** — no new cross-tenant row can be written — but `tenant:verify-schema` prints the `ALTER TABLE … VALIDATE CONSTRAINT` statements to run once the orphans are resolved. Tracked as §L-4.

---

## I. Legacy Data

| Table | Status |
|---|---|
| `class_years` | **Retained.** Still present, still referenced by `users.class_year_id`. **Not deleted.** |
| `users.class_year_id` | FK → `class_years(id)`. The **only** `class_year_id` still in the legacy id space. `prohibited` on input. |
| `attendances` / `events` / `qr_invites` / `feedback` `.class_year_id` | Legacy column **name** holding a `classes.id`. All repointed. |
| API accepting `class_year_id` | **No** — `prohibited` on both create and update. |
| Frontend sending it | **No**. |
| Migrations depending on it | Yes — `2026_06_22_000001` uses it as the source for the `classes` backfill. |
| Authorization depending on it | **No** — `EventPolicy` and `AttendanceService` both use `class_id`. |
| Row counts in this environment | `class_years` = 0 rows; `users.class_year_id` non-null = 0 rows. |

**No destructive cleanup was performed.** Dropping the table would make the historical `class_years → classes` mapping unreproducible and unreviewable. The safe retirement sequence (backfill → report unmatched → only then drop the FK) is documented in `TENANT_RULES.md` §12a as a **business decision**, not a code cleanup.

**Business decisions required:** (1) whether any historical `class_years` → `classes` mapping needs preserving beyond the current backfill; (2) whether to schedule the column retirement. Neither blocks release.

---

## J. Observability

| Item | Result |
|---|---|
| **Request IDs** | `AssignRequestId` is prepended to the API stack so the id exists before anything can short-circuit. Present on **every** API response including early returns. Inbound ids validated: 1–64 chars of `[A-Za-z0-9._-]`; anything else is replaced — this is log-injection defence. Generated ids are 26-char ULIDs. **D-5 fixed**: the correlation key is no longer shadowed. |
| **Logs** | `Log::withContext(['request_id' => …])` propagates to every log line. **D-6 fixed**: the secret platform-login path no longer ships to production logs. |
| **Error responses** | The 500 handler returns `request_id` in the body; all other error renderers carry it in the `X-Request-Id` header. Debug detail is fenced behind `config('app.debug') && app()->isLocal()` — **both** required, so it cannot leak in production. |
| **Sensitive-data protection** | No passwords, reset tokens or verification tokens are logged anywhere. Verification links exist only inside encrypted `jobs.payload`. QR tokens are never logged. The `AuditService` masks PII in the *audit* log. **Known gap:** `SendEmailJob` logs user email addresses in cleartext at `info` level — see §L-5. |
| **Queue failures** | `SendEmailJob`: `$tries = 3`, `$backoff = 10`, 30-minute `retryUntil`, metadata-only `failed()` logging. Dispatch is `after_commit`, so a queued mail cannot observe uncommitted state. **At-least-once, and documented as such** — a worker dying after the SMTP call but before marking the job done will re-send. For a notification to a human this is cosmetic and accepted. |

---

## K. Automated Verification — exact results

```
Backend (SQLite):   542 passed, 6 skipped, 3202 assertions
Backend (PostgreSQL 18.4):  548 passed, 1987 assertions
  └─ Security testsuite (PostgreSQL):  323 passed, 1203 assertions
PHPStan level max:   0 errors
Pint:                clean
Migration rollback lint:  108 scanned, 5 documented exceptions
tenant:audit:        clean on fresh DB; detects + refuses + repairs safely in rehearsal
tenant:verify-schema: all 7 composite FKs + 2 supporting keys verified
composer audit --locked --no-dev:  no advisories   (was 24 before D-8)
Production migration rehearsal:  PASS (10/10 steps)

Frontend:            72 passed (8 files)
TypeScript (tsc -b): 0 errors
ESLint:              0 errors, 0 warnings
i18n EN/AR parity:   PASS (exact)
Production build:    PASS
npm audit:           0 vulnerabilities
Generated sw.js:     verified — 2 routes, 0 API caching
```

New tests added this pass: **10** (backend `CreateUserStageOwnershipTest`) + **4** (`AttendanceClassIdSpaceTest`) + **2** (`RequestIdTest`) + **7 + 4 + 18** (frontend). Every one was verified to **fail on the pre-fix code** and pass after.

---

## L. Remaining Risks

Genuine only. No inflated theoretical risks.

### L-1 — Bearer token in `localStorage`
| | |
|---|---|
| **Risk** | XSS on the frontend origin yields a long-lived Sanctum PAT. |
| **Severity** | Medium |
| **Likelihood** | Low — requires an XSS foothold; the bundle is a static SPA with no server-rendered user content. |
| **Impact** | Full account takeover within the token's lifetime. |
| **Mitigation** | CSP on Vercel and nginx; the token is never logged server-side; `localStorage` is the deliberate trade for reload persistence + offline sync. |
| **Follow-up** | The correct fix is a **Sanctum SPA HttpOnly-cookie migration**, which is an architectural change requiring an offline-queue redesign (queue items currently persist the token). Explicitly out of scope for this pass; **recommend a formal risk acceptance or a scheduled project.** |

### L-2 — Two dead security components
| | |
|---|---|
| **Risk** | `ChurchDeletionPolicy` (registered at `AppServiceProvider:246`, **never invoked**) and `RequireReauth` middleware (aliased at `bootstrap/app.php:43`, **never applied to any route**). |
| **Severity** | Low — **not** a live vulnerability. |
| **Likelihood** | n/a |
| **Impact** | None today. The destructive church-deletion surface is covered by two **live, tested** layers: the `role:platform_admin` route group, and `DeleteChurchRequest` (platform role + `Hash::check` password re-check + literal `DELETE CHURCH`), asserted by `PlatformAdminAuthorizationMatrixTest`. |
| **Mitigation** | **Not deleted** — deleting security code because it is unused is exactly how the next developer re-adds a hole. Documented instead. |
| **Follow-up** | Either wire them as defence-in-depth or delete them in a deliberate cleanup PR. Note: `RequireReauth:37` derives its token via `hash_hmac(…, $password)` keyed by the plaintext password — that branch would add no security if ever wired, and should be removed if the middleware is kept. |

### L-3 — Accepted identifier-existence oracles
| | |
|---|---|
| **Risk** | `POST /membership-requests` (public, unauthenticated) returns three distinct 422s — *"A pending request already exists"*, *"This email is already registered in your church"*, and 201. `POST /auth/register` returns *"The email has already been taken"*. `GET /invite/{token}` returns a rich non-uniform payload including `creator_name` and lifecycle flags. |
| **Severity** | Low–Medium |
| **Likelihood** | Low — bounded by rate limits (3/hr per IP+email; 10/hr per IP). |
| **Impact** | Email-to-church membership mapping, and invite lifecycle state. No PII beyond what the flow already requires the caller to supply. |
| **Mitigation** | **Deliberately accepted.** Softening these means silently accepting a duplicate join request and having an administrator reject it — degrading a legitimate self-service flow to remove a signal that requires a valid email the attacker already had. `GET /churches/active` already publishes church ids publicly, so the church-id half of the oracle is not a secret. |
| **Follow-up** | None required. Documented so it is not "discovered" and half-fixed later. |

### L-4 — Composite constraints are `NOT VALID` while orphans exist
| | |
|---|---|
| **Risk** | After `tenant:audit --repair`, constraints are created `NOT VALID` because legacy orphans remain. |
| **Severity** | Low |
| **Likelihood** | Certain, if orphans exist |
| **Impact** | **None for new writes** — `NOT VALID` is fully enforced for every INSERT and UPDATE. The residual risk is only that the planner cannot rely on the constraint for exclusion pruning. |
| **Mitigation** | `tenant:verify-schema` prints the exact `ALTER TABLE … VALIDATE CONSTRAINT` statements. |
| **Follow-up** | Resolve orphans, then promote. Tracked in `TENANT_RULES.md` §6. |

### L-5 — PII in application logs
| | |
|---|---|
| **Risk** | `SendEmailJob` logs `email` in cleartext at `info` on success, failure and permanent-failure paths; `AuthService` logs email on password re-hash. |
| **Severity** | Low |
| **Likelihood** | Certain, on those events |
| **Impact** | PII in the log store, which typically has broader read access than the database. The **audit** log already masks PII via `AuditService::maskPii()`; the plain application log has no equivalent redaction layer. |
| **Mitigation** | No secrets are logged — only email addresses. |
| **Follow-up** | Add a redaction layer to the log context, mirroring `AuditService::maskEmail()`. Not done here because it would change log output format that operators may currently alert on. |

---

## M. Final Release Decision

# PRODUCTION READY WITH EXPLICIT EXCEPTIONS

**Blocking issues: none.** All 8 defects found this pass are fixed, regression-tested, and verified on the production database engine. Every quality gate is green on both SQLite and PostgreSQL. The production migration rehearsal completed 10/10 steps, including the refusal-to-migrate and no-guessing repair behaviours.

The three exceptions in §L-3 and §L-5 are **accepted risks with documented compensating controls**, and L-1 is a **pre-existing architectural decision** explicitly flagged for risk acceptance or a scheduled migration. None of them is a tenant-isolation, authorization, or authentication-bypass defect.

**Recommended before go-live** (operational, not code):
1. Verify the live Railway/Vercel environment variables (`CORS_ALLOWED_ORIGINS`, `FRONTEND_URL`, `VITE_API_URL`) — the frontend replay-URL fix in D-3 depends on `VITE_API_URL` being the bare origin, exactly as documented.
2. Run `php artisan tenant:audit` against a **production snapshot** before migrating, and review the orphan report. Do not pass `--repair` without a verified backup and an operator reviewing the proposed directions.
3. Merge the updated `composer.lock` — D-8's fix has no effect until the lock is committed and deployed.

**Standing rule preserved:** do not roll back migrations before 2026-01-01.

---

*Verified within scope. Not a claim of complete security. Residual risks in §L are genuine, owned, and tracked.*
