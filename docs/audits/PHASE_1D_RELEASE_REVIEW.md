# PHASE 1D — RELEASE REVIEW

Scope: read-only review of the **current working tree** against the Phase 1C verified
state. This document covers mandate §7–§23 (file review, rate limit, Axios, database,
migrations, tenant isolation, authorization, authentication, password reset, email/queue,
frontend/PWA, API contract, configuration, build, tests, reconciliation, diff audit).

**No file was modified by this review.** No finding required reopening Phase 1C.

Reviewed SHA: `bd37cf5fd510d2ccdeb6d32bb65b179db2181d77` + dirty working tree
(accounting: `PHASE_1D_WORKTREE_ACCOUNTING.md`).

---

## 7. PHASE 1C MODIFIED-FILE REVIEW

15 files carry Phase 1C edits (mtimes 10:12–12:14 on 2026-10-01). For each: what it fixed,
what changed, what proves it, and whether it is minimal / compatible.

### 7.1 Backend

| # | File | Problem fixed | Behaviour change | Proof | Minimal | API | DB | Auth | Migration needed |
|---|------|---------------|------------------|-------|---------|-----|----|------|------------------|
| 1 | `backend/bootstrap/app.php` | R-01: catch-all `Throwable` renderer preempted `HttpResponseException`, turning throttled requests into HTTP 500. R-07: `abort()`/generic `HttpException` on `api/*` fell through to HTML debug page | Added dedicated `HttpResponseException` render callback + generic `HttpException` callback (after typed callbacks, before catch-all) | `RateLimitResponseTest` (8), `HttpExceptionResponseShapeTest` (4) | ✅ two callbacks only | **CHANGED (intentional)**: `429` instead of `500`; `api/*` 4xx now JSON | none | none | none |
| 2 | `backend/app/Providers/AppServiceProvider.php` | R-01 response shape | `rateLimitResponse(Request, array $headers)` helper + 38 limiter `->response(...)` call sites; `Log::warning` instead of error-level "Unhandled API exception" | `RateLimitResponseTest` (8) | ✅ helper + delegation | **CHANGED (intentional)**: JSON body, `Retry-After`, `X-RateLimit-*` | none | none | none |
| 3 | `backend/app/Services/EventAccommodationService.php` | R-11: `updateRoom` read capacity **after** `update()` (compared new-to-new) and the shrink guard counted all cells instead of occupied cells | Capture `$currentTotal`/`$currentCells` before the write; guard uses occupied cells | `EventRoomCapacityResizeTest` (4 tests / 17 assertions) | ✅ single method | endpoint response unchanged | **data behaviour fixed** (cell inventory now syncs) | none | none |
| 4 | `backend/app/Notifications/ResetPasswordNotification.php` | R-10: reset token readable in `jobs.payload` | `implements ShouldBeEncrypted, ShouldQueue` + doc block | `ResetNotificationEncryptionTest` (2) | ✅ interface only | none | **queue payload now encrypted** | none | none |
| 5 | `backend/app/Notifications/PasswordResetRequestApprovedNotification.php` | R-10 (same) | `implements ShouldBeEncrypted, ShouldQueue` | `ResetNotificationEncryptionTest` (2) | ✅ | none | queue payload encrypted | none | none |

### 7.2 Frontend

| # | File | Problem fixed | Behaviour change | Proof | Minimal | API | Offline | User-visible |
|---|------|---------------|------------------|-------|---------|-----|---------|--------------|
| 6 | `frontend/src/lib/sync.ts` | R-08: `429`/`408` were classified as permanent rejections, so backoff stopped replaying; backoff ignored `Retry-After` | `isPermanentRejection()` excludes `429`/`408`; new `backoffDelayFor()` honours `Retry-After` (capped 60 s) | `sync.test.ts` — 15 sync tests incl. 7 new | ✅ two functions | none (client-side only) | **retry semantics fixed** | fewer lost offline writes |
| 7 | `frontend/src/lib/__tests__/sync.test.ts` | proof for #6 | tests | n/a | ✅ | — | — | — |
| 8 | `frontend/src/pages/servant/ScanQR.tsx` | R-09: queued-for-later offline attendance displayed as failure | `__offline_queued` consumed as a third (queued) state | `scanQROfflineQueue.test.tsx` (4 new) | ✅ state branch | none (local queue marker) | queued state displayed | **intentional**: queued vs failed message |
| 9 | `frontend/src/test/scanQROfflineQueue.test.tsx` | proof for #8 | tests | n/a | ✅ | — | — | — |
| 10 | `frontend/src/i18n/en.json` | R-09 new key | `attendance.queuedOffline` | `check:i18n` PASS (EN/AR parity) | ✅ one key | none | none | **intentional**: new string |
| 11 | `frontend/src/i18n/ar.json` | R-09 new key | `attendance.queuedOffline` | `check:i18n` PASS | ✅ one key | none | none | **intentional**: new string |

### 7.3 Tests created by Phase 1C (fail-first evidence)

| Test file | Red → Green recorded by Phase 1C | Re-run in Phase 1D |
|-----------|----------------------------------|--------------------|
| `RateLimitResponseTest` | 5 failed → 8 passed | ✅ pass (SQLite + PostgreSQL) |
| `HttpExceptionResponseShapeTest` | 1 failed / 3 passed → 4 / 4 | ✅ pass (SQLite + PostgreSQL) |
| `ResetNotificationEncryptionTest` | (R-10, 2 tests) | ✅ pass (SQLite + PostgreSQL) |
| `EventRoomCapacityResizeTest` | 4 failed → 4 passed (17 assertions) | ✅ pass (SQLite + PostgreSQL) |
| `frontend sync.test.ts` (7 new) | red first | ✅ 15/15 sync tests |
| `scanQROfflineQueue.test.tsx` (4 new) | red first | ✅ included in 83 |

### 7.4 Answers to the ten review questions

1. **Problem fixed** — see table: R-01, R-07, R-08, R-09, R-10, R-11 (+ R-03 isolation).
2. **Behaviour changed** — rate-limit status/body/headers, `api/*` 4xx body, offline retry
   classification, scan-QR queued state, room cell sync, encrypted queue payloads.
3. **Tests** — every change has a named test file; all pass on both engines (§21).
4. **Minimal?** — yes. Two callbacks, one helper, one method, two interfaces, two
   functions, one state branch, one i18n key. No file was refactored for style.
5. **API compatibility** — only two intentional contract changes (§18). No endpoint was
   added, removed or renamed; `route:list` still reports 224 routes (218 under `api/`).
6. **Database behaviour** — no schema change. Only `EventAccommodationService::updateRoom`
   now writes a correct cell inventory (data, not schema).
7. **Authentication** — untouched. No auth middleware, guard, token or policy changed.
8. **Offline behaviour** — improved (R-08, R-09); queue isolation rules unchanged
   (`AuthContext.login()/logout()/platformLogin()` still call `clearAllData()` — `sync.ts:142`).
9. **User-visible** — 429 message, queued-offline message, room capacity correctness.
10. **Migration/deployment requirement** — **none introduced by Phase 1C.** No migration
    was edited; the release still runs the existing 108-migration sequence (§11).

**Verdict: every Phase 1C change is minimal, tested, and contract-explicit. Nothing to
re-open (Rule 2 satisfied).**

---

## 8. RATE-LIMIT RELEASE REVIEW

Final implementation (read in this tree):

```php
// backend/app/Providers/AppServiceProvider.php
->response(fn (Request $request, array $headers) => self::rateLimitResponse($request, $headers));

private static function rateLimitResponse(Request $request, array $headers): JsonResponse
{
    $retryAfter = self::headerInt($headers['Retry-After'] ?? null) ?? 60;
    Log::warning('Rate limit exceeded', [request_id, ip, path, method, user_id, user_agent, retry_after]);
    $responseHeaders = ['Retry-After' => (string) $retryAfter];
    foreach (['X-RateLimit-Limit','X-RateLimit-Remaining','X-RateLimit-Reset'] as $n) { … }
    return response()->json(['success' => false, 'message' => 'Too many requests. …'], 429, $responseHeaders);
}
```

Checked against the installed Laravel **12.69.3**:

| Requirement | Result |
|-------------|--------|
| normal request → expected response | ✅ full suite (560 SQLite / 566 PG) exercises unthrottled paths |
| throttled request → HTTP 429 | ✅ `RateLimitResponseTest` |
| no accidental HTTP 500 | ✅ dedicated `HttpResponseException` callback precedes catch-all (`bootstrap/app.php`); `HttpResponseException` is a `RuntimeException`, so it cannot be swallowed by the `HttpException` callback registered after it |
| expected JSON error shape | ✅ `{success:false,message,…}` + 429 |
| expected rate-limit headers | ✅ `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` |
| logging level | ✅ `Log::warning` (no longer fires 5xx alerting) |
| callback signature | ✅ `(Request $request, array $headers)` matches Laravel 12's `Limit::response()` |

No regression demonstrated → **not modified** (Rule 2).

---

## 9. AXIOS RELEASE REVIEW

```text
$ npm ls axios
church-manager-frontend@1.0.0 D:\xampp\htdocs\CHproject\frontend
└── axios@1.20.0

$ npm audit --audit-level=high
found 0 vulnerabilities            (exit 0)
$ npm audit
found 0 vulnerabilities            (exit 0)
```

| Question | Result |
|----------|--------|
| version in `package.json` | ✅ `axios@1.20.0` (declared) |
| version in `package-lock.json` | ✅ resolved to `1.20.0` (single entry, no conflicting tree) |
| installed dependency tree | ✅ `npm ls` exit 0, one node |
| advisory status | ✅ the GHSA reported in Phase 1B is resolved; 0 vulns at every severity |
| unrelated dependency upgrades appeared? | ✅ **No.** `package.json` mtime 09-30 21:12, `package-lock.json` mtime 10-01 09:41 — both **before** Phase 1C's first edit (10:12) and untouched by Phase 1D (`npm audit`/`npm test`/`npm run build` did not rewrite the lock) |

---

## 10. DATABASE RELEASE REVIEW

### 10.1 The seven composite tenant foreign keys — still present and correct

Read directly from `backend/database/migrations/2026_09_29_000001_add_composite_tenant_foreign_keys.php`
(`private const CONSTRAINTS`, lines 47–55) **and** confirmed live on PostgreSQL 16.15:

| # | Required relationship | Constraint name | Migration | Live DB |
|---|----------------------|-----------------|-----------|---------|
| 1 | classes → stages | `classes_church_stage_fk` | ✅ | ✅ OK |
| 2 | users → stages | `users_church_stage_fk` | ✅ | ✅ OK |
| 3 | users → classes | `users_church_class_fk` | ✅ | ✅ OK |
| 4 | events → classes | `events_church_class_fk` (`events.class_year_id`) | ✅ | ✅ OK |
| 5 | event_targets → classes | `event_targets_church_class_fk` | ✅ | ✅ OK |
| 6 | qr_invites → stages | `qr_invites_church_stage_fk` | ✅ | ✅ OK |
| 7 | qr_invites → classes | `qr_invites_church_class_fk` | ✅ | ✅ OK |

Supporting unique keys required for a composite FK to be satisfiable:

```text
stages.stages_church_id_id_unique   OK
classes.classes_church_id_id_unique OK
```

### 10.2 Constraint semantics

* DDL: `ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (church_id, %s) REFERENCES %s (church_id, id)%s`
  — **no `ON DELETE`/`ON UPDATE` clause at all** → PostgreSQL default `NO ACTION`.
  ✅ **no unsafe cascading.**
* Orphan handling: `guardAgainstUnreviewedCrossTenantRows()` runs first and **refuses the
  migration** if unreviewed cross-tenant rows exist; only then are constraints added, as
  `NOT VALID` when orphans were found (PostgreSQL validates existing rows at add time).
  ✅ no silent data rewrite, no destructive transform.
* `down()` drops the seven constraints and rebuilds the SQLite table where required.
  ✅ reversible (with the documented SQLite/PostgreSQL asymmetry — RR-05).
* `tenant:audit` on the disposable DB: *"No inconsistencies found. The composite tenant
  foreign keys can be applied safely."* (exit 0) — **no accidental data rewrite.**

### 10.3 Data-safety classification of every migration in the release

All 108 migration files **predate Phase 1C** (no file has `LastWriteTime > 09:45`), and
Phase 1D modified none. Existing safety inventory from Phases 0/1/1B/1C:

* historical destructive transforms are one-way by design (data cleanups) — their
  `down()` is SQLite-only/broken: **RR-05, NOT-FIXED-BY-DESIGN**;
* `scan-broken-migration-rollbacks.php` + `MigrationRollbackTest` keep that inventory
  current;
* `rehearse-production-migration.sh` + `database/rehearsal/production_like_fixture.sql`
  provide the production-like rehearsal path.

**Verdict: no schema change is proposed by this release beyond what Phase 1 verified;
nothing destructive or SQLite-only was added.**

---

## 11. MIGRATION RELEASE SAFETY (per-migration determination)

```text
$ php artisan migrate:status   (disposable PostgreSQL 16.15)
RAN: 108   PENDING: 0   exit 0
$ php artisan migrate:fresh --force   (before the suite)
… 2026_09_29_000001_add_composite_tenant_foreign_keys … DONE  exit 0
```

| Property | Result |
|----------|--------|
| Forward migration safe? | ✅ applied cleanly on PostgreSQL 16.15 and on SQLite (both suites pass) |
| PostgreSQL compatible? | ✅ driver-aware branches: `pgsql` → `ADD CONSTRAINT` (+`NOT VALID` when needed); `sqlite` → table rebuild |
| Repeat-safe where required? | ✅ Laravel records batch/run; guard re-checks orphans before adding |
| Destructive? | ✅ No — no `DROP TABLE`, no `TRUNCATE`, no data rewrite in the release-critical path |
| Data-transforming? | ✅ No (only constraint DDL) |
| Reversible? | ✅ `down()` drops constraints; documented asymmetry = RR-05 |
| Rollback limitations? | documented, not changed — historical broken `down()`s remain (RR-05, NOT-FIXED-BY-DESIGN) |
| Production prerequisites? | **Backup first**, `APP_ENV=production` requires `--force`, `tenant:audit` clean beforehand → captured in the runbook |

No migration was modified in this phase (Rule 3 / Rule 11 respected).

---

## 12. TENANT ISOLATION RELEASE REVIEW (targeted regression)

Hierarchy preserved exactly as specified:

```text
Platform → Church → Stage → Class → Servants / Members
```

| Mechanism | Verified in this tree | Result |
|-----------|-----------------------|--------|
| `ChurchScope` global scope | `backend/app/Models/Scopes/ChurchScope.php` — unauthenticated web request → `whereRaw('1 = 0')`; authenticated user with **null church_id** → filtered out (explicit fail-closed comment recording that this absence *was* a real cross-tenant disclosure) | ✅ PRESERVED |
| Platform Admin separation | `ScopeResolver::canAccessStage/Class/User` return `true` **only** for `UserRole::PlatformAdmin`; `User::roleDefaultScope()` maps PlatformAdmin → `UserScope::Church` but `canAccessUser()` short-circuits `church_id` mismatch for platform admin **before** any scope check | ✅ PRESERVED |
| Church boundary | `canAccessStage()` / `canAccessUser()` return `false` when `church_id` differs (non-platform) | ✅ PRESERVED |
| Stage isolation | `allowedStageIds()` = `[own stage_id]` for `UserScope::Stage`; `allowedClassIds()` = own classes for `ClassScope`; `Self` → only own ids | ✅ PRESERVED |
| `exists:` ≠ ownership | `TENANT_RULES.md` §4 rule + `ScopeResolver` is always consulted in addition to `exists:` validation; a same-church-but-wrong-stage id still resolves `false` | ✅ PRESERVED |
| Composite FKs close the data layer | §10.1 — a class can only point at a stage of the same church; a user can only point at a class/stage of the same church | ✅ ENFORCED BY DB |
| Tenant-sensitive models carry `BelongsToChurch` | `Stage`, `Classe`, … (trait present on reviewed models) | ✅ PRESERVED |

**Behavioural proof executed this session on the final release tree**
(disposable PostgreSQL 16.15, fixture with 2 churches / 2 stages / 2 classes):

```text
cross-church class -> foreign stage      OK   (rejected by FK)
cross-church user  -> foreign class      OK   (rejected by FK)
NULL class_id (legitimate) is allowed    OK
same-church user  -> own class           OK
All tenant isolation invariants are enforced by the database.   exit 0
```

Test evidence re-run this session: `TenantIsolationMatrixTest`,
`TenantOwnershipBoundaryTest`, `PlatformAdminAuthorizationMatrixTest`,
`TenantArchitectureTest`, `TenantConsistencyTest`, `CompositeForeignKeyTest`,
`CreateUserStageOwnershipTest`, `AttendanceClassIdSpaceTest` — all pass on both engines.

**No tenant-isolation regression. Tenant architecture not rebuilt (Rule 3 respected).**

---

## 13. AUTHORIZATION MATRIX (final targeted pass)

Populated **only** where the application exposes the operation. Sources:
`ScopeResolver`, `ChurchScope`, `StagePolicy`, `User::roleDefaultScope()`, route
inventory (`route:list --json`, 224 routes / 218 API), and the authorization test suites.

Legend: **ALLOW** / **DENY** / **N/A** (not applicable) / **NV** (not verified).

| Actor | Own Church | Other Church | Own Stage | Other Stage |
| --- | --- | --- | --- | --- |
| **Platform Admin** | ALLOW (cross-church *by design* — explicitly separate, 12 `RoleMiddleware:platform_admin` routes) | ALLOW (by design — the only actor permitted) | ALLOW | ALLOW |
| **Church Admin** (`admin`) | ALLOW (church-wide: all stages, classes, members of own church; `UserScope::Church`) | **DENY** (`ScopeResolver` church check + `ChurchScope`) | ALLOW | ALLOW **within own church** (all stages of own church); **DENY** for another church's stage |
| **Assistant Admin** | ALLOW (same `UserScope::Church`; `isAdmin()` includes `assistant_admin`) | **DENY** | ALLOW | ALLOW within own church; **DENY** cross-church |
| **Stage Admin** | partial — own stage's data only (not church-wide writes) | **DENY** | ALLOW (`canAccessStage`, `manageClasses` for own stage) | **DENY** (other stage of same church: `allowedStageIds` is a single id; cross-church: denied earlier) |
| **Servant** | partial — own class's data only (`UserScope::ClassScope`) | **DENY** | ALLOW (own class's stage, read paths) | **DENY** |
| **Member** | partial — **self only** (`UserScope::Self`; `StagePolicy::viewAny` = DENY for members) | **DENY** | ALLOW (view own stage; `canAccessStage` resolves own class's stage) | **DENY** |

Notes (no permission invented):

* `StagePolicy::update` / `delete` require `isAdmin() && stage->church_id === user->church_id`,
  i.e. **Church Admin / Assistant Admin only, own church only** — Platform Admin is
  deliberately *not* `isAdmin()` and cannot edit stage records; Stage Admin cannot either.
* 142 API routes additionally carry `PermissionMiddleware`, 12 carry
  `RoleMiddleware:platform_admin`; 64 routes carry neither and rely on
  authentication + `EnsureApproval` + in-controller/policy checks + `ChurchScope`
  (all are self-scoped or read paths: `auth/me`, `notifications/mine`, `feedback/mine`,
  `events/register-self`, …).
* `Permission::userHasPermission()` **fails closed**: un-seeded DB → built-in
  `defaultRolePermissions()`; seeded-but-empty mapping → falls back to defaults (never
  "allow everything"). Empty `role_permission` therefore cannot open a privilege hole.
* Population is bounded by tests: `PlatformAdminAuthorizationMatrixTest`,
  `TenantIsolationMatrixTest`, `TenantOwnershipBoundaryTest`, `Security` suite (329 tests
  / 1221 assertions on PostgreSQL).

**No privilege change was introduced by Phase 1C or Phase 1D.**

---

## 14. AUTHENTICATION RELEASE REVIEW

| Capability | Preserved? | Evidence (re-run this session) |
|------------|-----------|-------------------------------|
| login | ✅ | `AuthTest` etc. — full suite green on both engines |
| logout | ✅ | suite green; `auth/logout` route present with Sanctum |
| email verification | ✅ | `EmailVerificationTokenSecurityTest`, `VerifyEmailNotification` call site `EmailVerificationService:252` |
| password reset | ✅ | `PasswordResetRequestTest`, `ResetNotificationEncryptionTest` |
| token handling | ✅ | Sanctum `Authenticate:sanctum` on protected routes; invite-token rotation logs contain **ids only, never token values** (`QRInviteService:390,446`) |
| inactive-user behaviour | ✅ | `EnsureApproval`/`CheckApproval` middleware present on the route stack; suite green |
| role assignment | ✅ | `UserRole` enum + `RoleMiddleware`; no change in this phase |
| 401 behaviour | ✅ | JSON unauthenticated envelope (framework callback) — suite green |
| 403 behaviour | ✅ | `HttpExceptionResponseShapeTest`, `AccessDeniedHttpException` callback — suite green |
| 422 behaviour | ✅ | `ValidationException` callback precedes generic `Throwable` — suite green |

No security feature was disabled to simplify release. **Authentication intact.**

---

## 15. PASSWORD RESET / NOTIFICATION REVIEW

| Check | Result |
|-------|--------|
| encryption / protection | ✅ both reset-bearing notifications implement `ShouldBeEncrypted`; framework confirms `SendQueuedNotifications::$shouldBeEncrypted = $notification instanceof ShouldBeEncrypted` (exact-version source, D-1D-03) |
| token confidentiality | ✅ token lives only in the notification payload, now encrypted at rest in `jobs.payload` |
| URL generation | ✅ built from configured frontend URL + token/email query params (existing behaviour, unchanged by 1C) |
| expiry behaviour | ✅ token expiry enforced by the framework reset flow; the notification doc block records the dispatch path (`User::sendPasswordResetNotification` → only callers are Laravel's broker) |
| notification content | ✅ no token, no URL, no secret is logged — `Log::` grep over both notification classes returns **zero** hits |
| frontend reset URL | ✅ frontend reset route unchanged; i18n additions were unrelated (`attendance.queuedOffline`) |
| no accidental token logging | ✅ the only token-adjacent log calls are `QRInviteService:390/446`, which log `invite_id`, `user_id`, `role`, `expires_at` — **no raw token** |
| no plaintext secret persistence | ✅ verified by `ResetNotificationEncryptionTest` (asserts the stored payload is not readable) |

**No real email was sent in this phase** — verification is test- and local-only
(`MAIL_MAILER=array` in test runs).

---

## 16. EMAIL / QUEUE RELEASE REVIEW (RR-04, RR-13, RR-20, `MAIL_MAILER=log`)

New evidence collected this session (Rule 2: a conclusion may be corrected when new
evidence appears — no code was changed).

### 16.1 Facts

| Fact | Evidence |
|------|----------|
| The package **`resend/resend-laravel` v1.4.0 IS installed** | `composer show resend/resend-laravel` (name, version v1.4.0, released 2026-05-06); present in `composer.lock`; `vendor/resend/` exists (mtime 09-30 18:29 — **pre-Phase 1C**) |
| It is package-discovered and registers a transport | `bootstrap/cache/packages.php` → `Resend\Laravel\ResendServiceProvider`; `boot()` calls `Mail::extend('resend', …)` |
| `config/mail.php` defines a `resend` mailer | line 64–65: `'resend' => ['transport' => 'resend']`; default mailer = `env('MAIL_MAILER', 'log')` |
| It fails **closed** if the key is missing | `bindResendClient()` throws `ApiKeyIsMissing` when `config('resend.api_key') ?? config('services.resend.key')` is not a string — at **send time**, not at boot |
| The default transport is `log` | `config/mail.php:17` `'default' => env('MAIL_MAILER', 'log')` |
| **5 live notification call sites exist** | `User:469`, `ChurchApplicationService:313/363/394`, `EmailVerificationService:252` |
| `EmailService` / `SendEmailJob` are dead code (R-20) | 0 callers outside their own files + the `AppServiceProvider` binding |
| Queue `after_commit = true` for database driver; failed jobs table configured | `config/queue.php:44`, `:123` |
| Scheduler runs `app:clean-expired-invites --days=7` daily 03:00 and `app:clean-audit-logs --days=90` | `routes/console.php` |

### 16.2 Determination

| Question | Answer |
|----------|--------|
| Is `MAIL_MAILER=log` expected for local/test? | ✅ Yes — test runs explicitly set `MAIL_MAILER=array`; config fallback is `log` for local dev |
| Is it incorrectly configured for production? | **PRODUCTION-ONLY — cannot be known pre-deploy.** Production value is an environment variable never read by this phase |
| Dead code? | Partly — `EmailService`/`SendEmailJob` are dead (R-20, unchanged); **the notification pathway is live** (5 call sites) |
| Release blocker? | **No.** Worst case: notifications are written to the log (silently undelivered) or fail at send time and land in `failed_jobs` — the application itself is unaffected |
| Production-only verification item? | **Yes — Gate I (email) + Gate G (failed_jobs)** |

### 16.3 Correction of a Phase 1C evidence line (conclusion unchanged)

Phase 1C R-13 stated *"Resend was removed from the dependency set … `composer show
resend/resend` -> not found"*. The command checked package name `resend/resend`; the
installed package is **`resend/resend-laravel`**. The *evidence line* was therefore wrong
and the premise ("Resend removed") is incorrect for the transport package.

**R-13's conclusion stands unchanged:** `.env.example` is never loaded by the
application, so `MAIL_MAILER=resend` there is inert → severity P3, status
**DOCUMENTED**, non-blocker. Recorded as `DOC-CORRECTION` (see `PHASE_1D_RISK_REVIEW.md`).

**Post-deploy requirement:** confirm which mailer production uses and, if `resend`, that
`RESEND_API_KEY`/`services.resend.key` is present — otherwise every queued notification
throws `ApiKeyIsMissing` and accumulates in `failed_jobs`.

---

## 17. FRONTEND / PWA RELEASE REVIEW

| Check | Result |
|-------|--------|
| tenant/session isolation | ✅ `AuthContext.login()/logout()/platformLogin()` call `clearAllData()`; `sync.ts:142-143` documents that the replay loop must not outlive its session (`clearAllSyncData()` exposed) |
| queue state transitions | ✅ pending → completed (`markSyncCompleted`) / failed (`markSyncFailed`, with `backoffDelayFor`) / abandoned (`markSyncAbandoned`); R-08 fixed the 429/408 classification |
| no duplicate processing | ✅ `markSyncCompleted` + `clearCompletedSyncItems()`; suite covers replay |
| no cross-user queue reuse | ✅ queue cleared on login/logout/platform login; `TENANT_RULES.md` §11 is the governing rule |
| logout clears sensitive state | ✅ `clearAllData()` on logout |
| login switching clears prior session | ✅ `clearAllData()` on login |
| API responses not cached by service worker | ✅ `navigateFallbackDenylist: [/^\/api\//]`; `runtimeCaching` limited to Google Fonts; precache `globPatterns` = `js/css/html/woff2/svg/png/jpg/jpeg/gif/ico`; **`dist/sw.js` contains no `/api/v1` URL** |
| build generates expected PWA assets | ✅ `dist/sw.js`, `dist/workbox-dcde9eb3.js`, `manifest.webmanifest`, `offline.html`, icons; `PWA v1.3.0 / generateSW / precache 138 entries (1818.48 KiB)` |
| manifest sane | ✅ `scope:/`, `start_url:/`, `display:standalone`, i18n keys present |
| offline queue tests | ✅ `scanQROfflineQueue.test.tsx` + `sync.test.ts` pass |

PWA not rewritten (Rule 3 respected).

---

## 18. API CONTRACT REVIEW

| Change | Classification | Notes |
|--------|----------------|-------|
| Throttled request: **500 → 429** with `{success:false, message}` + `Retry-After`/`X-RateLimit-*` | **INTENTIONAL** | R-01; mandated by Phase 1C; proven by `RateLimitResponseTest` |
| `api/*` generic `abort()`/`HttpException` 4xx now returns the JSON envelope instead of an HTML debug page | **INTENTIONAL** | R-07; proven by `HttpExceptionResponseShapeTest` |
| Offline sync retry policy for 429/408 (client-side) | **INTENTIONAL** | R-08; no server contract change |
| Scan-QR offline queued state (client-side rendering) | **INTENTIONAL** | R-09; no server contract change |
| Notification queue payloads encrypted | **INTENTIONAL** | R-10; invisible to the API |
| Room resize now syncs cell inventory | **INTENTIONAL** | R-11; endpoint response schema unchanged |
| i18n key `attendance.queuedOffline` added (EN+AR) | **INTENTIONAL** | R-09 |
| Any other status / JSON structure / validation response / auth response / endpoint change | **NOT APPLICABLE** | no controller, FormRequest, Resource or route file was touched by Phase 1C/1D; `route:list` = 224 routes |

**UNINTENTIONAL contract changes: none.**

---

## 19. CONFIGURATION RELEASE CHECKLIST

Values were **never printed**; only presence is recorded.

### Backend (`backend/.env.example` / runtime config)

| Variable | `.env.example` | Runtime requirement for release | Status |
|----------|----------------|----------------------------------|--------|
| `APP_ENV` | PRESENT | must be `production` | PRODUCTION-ONLY |
| `APP_DEBUG` | PRESENT | must be `false` | PRODUCTION-ONLY |
| `APP_URL` | PRESENT | PRESENT (local value is a placeholder) | PRODUCTION-ONLY |
| `APP_KEY` | ABSENT/EMPTY (correct — never committed) | PRESENT | PRODUCTION-ONLY |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` | PRESENT | PRESENT (PostgreSQL) | PRODUCTION-ONLY |
| `DB_PASSWORD` | ABSENT/EMPTY (correct) | PRESENT | PRODUCTION-ONLY |
| `DB_SSLMODE` | PRESENT | PRESENT | PRODUCTION-ONLY |
| `CACHE_STORE` | PRESENT | PRESENT (RR-02 rate-limiter store must be shared) | PRODUCTION-ONLY |
| `SESSION_DRIVER` | PRESENT | PRESENT | PRODUCTION-ONLY |
| `QUEUE_CONNECTION` | PRESENT | PRESENT (`database` expected; config fallback `sync`) | PRODUCTION-ONLY |
| `MAIL_MAILER` | PRESENT (`resend`) | PRESENT — see §16 | PRODUCTION-ONLY |
| `RESEND_API_KEY` | ABSENT/EMPTY (correct) | OPTIONAL — required only if mailer = `resend` | PRODUCTION-ONLY |
| `FRONTEND_URL` | PRESENT | PRESENT (CORS fallback + reset URL) | PRODUCTION-ONLY |
| `CORS_ALLOWED_ORIGINS` | ABSENT | PRESENT on Railway (Phase 1 finding) | PRODUCTION-ONLY |
| `SANCTUM_*` | uses config defaults | config-driven | PRESENT (config) |
| storage (`FILESYSTEM_DISK`) | PRESENT | PRESENT | PRODUCTION-ONLY |

Secrets detected in the repository: **0** (§24).

### Frontend

| Variable / file | Status |
|-----------------|--------|
| `VITE_API_URL` (API base) | PRESENT — `frontend/.env` (values hidden); consumed by `frontend/src/lib/apiUrl.ts` with `/api` fallback |
| production build variable | PRODUCTION-ONLY (Vercel env) |
| `vercel.json` | PRESENT — SPA rewrites `/(.*)` → `/index.html`, `/api/(.*)` → `/api/$1` |
| PWA configuration | PRESENT — `vite.config.ts` (`vite-plugin-pwa`), build output verified in §17 |

### Infrastructure files located

* `backend/railway.json` — present (healthcheck/start configuration).
* `docker-compose.yml` + `docker-compose.override.yml` (+ `.example`) — local/dev only;
  the only credential-shaped literals are `${DB_PASSWORD:-postgres}` fallbacks
  (local development defaults, **not** production credentials).

---

## 20. BUILD ARTIFACT REVIEW

### 20.1 Backend (run locally, disposable — never deployed)

```text
$ composer validate --no-check-publish
./composer.json is valid                                exit 0
$ composer check-platform-reqs
all requirements satisfied (PHP 8.2.12 local runtime)   exit 0
$ composer install --no-dev --prefer-dist --optimize-autoloader
… packages installed                                     exit 0
$ php artisan --version
Laravel Framework 12.69.3                               exit 0
$ php artisan route:list --json | count
224 routes                                              exit 0
autoloader classmap: 614 KB / 4930 classmap entries (optimised)
prod deps present: laravel/framework, resend/resend-laravel
dev deps removed as documented: phpunit, pint, phpstan  ✅ expected
$ composer install            (restore dev tooling)      exit 0
composer.lock mtime/content unchanged (still 09-30 18:29)
$ composer validate --no-check-publish                  exit 0
```

* No API payload is produced by a backend build.
* No debug artifact or source map is emitted (Laravel ships no source maps).
* No secret is baked into an image layer (`.env` is gitignored; `docker-compose` uses
  `${DB_PASSWORD:-postgres}` interpolation, and the validation script prints
  *"No secrets were displayed."*).

### 20.2 Frontend

```text
$ npm run build
… PWA v1.3.0 / mode generateSW
  precache 138 entries (1818.48 KiB)
  files generated: dist/sw.js, dist/workbox-dcde9eb3.js
✓ built in 3.39s                                         exit 0
```

| Check | Result |
|-------|--------|
| build succeeds | ✅ |
| no API payloads in precache | ✅ 138 entries are local static assets; `dist/sw.js` has no `/api/v1` reference |
| service worker does not cache authenticated API responses | ✅ `navigateFallbackDenylist: [/^\/api\//]` + font-only `runtimeCaching` |
| no debug artifacts | ✅ production minified build |
| no source-map/secrets leakage | ✅ no `*.map` emitted; no secret in build output |

Deployment infrastructure untouched (Rule 1 respected).

---

## 21. FINAL TEST RE-RUN (this session — not reused from Phase 1C)

See `PHASE_1D_TEST_RESULTS.md` for full command output.

| Gate | Command | Result |
|------|---------|--------|
| Backend SQLite | `php artisan test` | **560 passed / 0 failed / 6 skipped / 3280 assertions / 232.91 s — exit 0** |
| Backend PostgreSQL 16.15 | `migrate:fresh --force` then `php artisan test` | **566 passed / 0 failed / 0 skipped / 3286 assertions / 411.40 s — exit 0** |
| Static analysis | `phpstan analyse --memory-limit=1G` (level max) | **No errors — exit 0** |
| Style | `pint --test` | **`{"tool":"pint","result":"passed"} ` — exit 0** |
| Frontend tests | `npm test` | **9 files / 83 passed — exit 0** |
| TypeScript | `npx tsc --noEmit` | **exit 0** |
| ESLint | `npm run lint` | **exit 0** |
| i18n parity | `npm run check:i18n` | **PASS — exit 0** |
| Frontend build | `npm run build` | **exit 0** (138 precache) |
| Composer audit | `composer audit --locked --no-dev` | **No security vulnerability advisories found — exit 0** |
| npm audit | `npm audit --audit-level=high` | **0 vulnerabilities — exit 0** |

---

## 22. TEST COUNT RECONCILIATION

Phase 1C reported (and one line in the mandate quotes "Frontend: 329 / 1221", which is
actually the **PostgreSQL Security suite**, not the frontend):

| Engine / suite | Phase 1C | Phase 1D re-run | Delta | Reconciliation |
|----------------|----------|-----------------|-------|----------------|
| SQLite | 560 / 6 skipped / 3280 assertions | **560 / 6 / 3280** | 0 | identical |
| PostgreSQL | 566 / 0 skipped / 3286 assertions | **566 / 0 / 3286** | 0 | identical |
| PG `Security` suite | 329 / 1221 assertions | (subset of the 566) | 0 | the mandate's "Frontend: 329 / 1221" line is this suite — corrected here |
| Component/unit (frontend) | 9 files / 83 passed | **9 files / 83 passed** | 0 | identical |

* No test file was added, removed, skipped or edited in Phase 1D.
* 560 + 6 (engine-specific skips) = 566 ✅ totals reconcile exactly.
* Combined PostgreSQL + frontend: **566 + 83 = 649 passed, 0 failed.**
* The 6 SQLite skips are the PostgreSQL-only concurrency tests (`RR-09`); they execute on
  PostgreSQL (0 skipped there).

**No count was manipulated to preserve a historical number — every figure above is a
fresh run from this session.**

---

## 23. RELEASE DIFF AUDIT

```text
$ git diff                 271 files, +9405 / -3644
$ git diff --cached        (empty)
$ git ls-files --others --exclude-standard    34 entries
```

Classification of all 305 dirty entries:

| Class | Count | Definition used |
|-------|------:|-----------------|
| `PHASE_1C` | 7 | 4 new test files + the tracked files that became dirty in the Phase 1C window (all 15 touched files verified by mtime 10:12–12:14) |
| `PRE-EXISTING` | 298 | everything with mtime **before** 2026-10-01 09:45 (Phases 0/1/1B), including all 108 migrations, `composer.lock`, both `package*.json`, `vitest`/`tsconfig` wiring, tenant commands/services, tenant tests, and the `docs/` directory entry |
| `EXPECTED RELEASE` | — | covered by the two rows above (the whole dirty tree *is* the intended release state) |
| `PHASE_1D` | 9 documents (inside the pre-existing `docs/` entry) | documentation only |
| `UNEXPECTED` | **0** | nothing modified after 12:14 except gitignored generated caches |
| `UNKNOWN` | **0** | every entry attributed to a phase with mtime evidence |

```text
0 UNKNOWN
0 UNEXPECTED        → release-diff gate satisfied
```

Secret/PII scan (§24) is recorded in the release-review blockers document.

---

## 24. SECRET / PII SAFETY REVIEW

Method: a pattern scan over (a) `git diff`, (b) `git diff --cached`, (c) all 70 text
files reachable through `git ls-files --others` — patterns for AWS keys, PEM blocks,
GitHub/Railway/OpenAI-style tokens, JWTs, connection URLs with credentials, Bearer
literals, and `password|secret|api_key|token = "…"` assignments. Only pattern **names**
and counts were reported; no value was printed.

| Pattern group | Hits | Triage |
|---------------|-----:|--------|
| AWS access key / PEM / GitHub / Railway / OpenAI tokens / JWT / connection URLs / Bearer literal | **0** | — |
| `POSTGRES_PASSWORD`, `DB_PASSWORD` | 1 + 1 | `${DB_PASSWORD:-postgres}` in `docker-compose.override.yml` — local dev default, not a production credential |
| `password … = "…"` assignments | 6 | test fixtures (`Hash::make(...)`, validation rule arrays, `password_confirmation`) and one `password_reset_tokens` schema entry |
| `MAIL_PASSWORD` / `SMTP_PASS` / `RESEND_API_KEY` | 0 values | only **variable names** documented as empty in `PHASE_0_INFRASTRUCTURE_MAP.md` |
| Shell scripts | 1 | presence checks only — the validator explicitly prints *"No secrets were displayed."* |

**0 secrets, 0 personal production data, 0 hardcoded credentials.**
No BLOCK RELEASE condition triggered.
