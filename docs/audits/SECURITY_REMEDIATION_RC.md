# Security Remediation Phase — Release Candidate

**Status:** VALIDATED WORKING TREE — **NOT DEPLOYED**
**Date:** 2026-10-04
**Base HEAD:** `765eea0eb224faa88d2103924e21ee3b7070c303` (`chore(release): prepare production release`)

---

## 1. Accepted Product Decision — `assistant_admin`

> **DECISION (ACCEPTED, NOT A VULNERABILITY)**

```text
assistant_admin == admin
```

`assistant_admin` occupies the **same permission tier as `admin`** and may perform the
same authorized operations, constrained to the same authorized Church scope.

### Rationale

- Explicit product decision made during the Multi-Tenant Authorization & Role Security Audit.
- Audit observation **O-1** (in-tier equivalence between `admin` and `assistant_admin`)
  is **closed as intentional** — it is not a privilege-escalation finding.

### Guarantees that remain in force

| Aspect | Guarantee |
|---|---|
| Permission tier | Identical to `admin` |
| Tenant scope | One Church — never cross-church |
| Promotion/demotion | In-tier promote/demote between `admin` and `assistant_admin` is intentional |
| Platform scope | `assistant_admin` has **no** platform-wide authority |

### Explicitly out of scope for this decision

- Do **not** create a separate permission tier for `assistant_admin`.
- Do **not** rename the role.
- Do **not** alter the hierarchy because of O-1.

---

## 2. Authoritative Role Model (Unchanged)

```text
platform_admin      → platform scope (only where explicitly authorized)
admin               → one Church
assistant_admin     → same permission tier as admin, one Church
stage_admin         → one Church + one Stage
servant             → one Church + explicitly authorized operational scope
member              → one Church + explicitly authorized scope
```

Hierarchy:

```text
Platform
  └── Church
       └── Stage
            └── Class
                 ├── Servants
                 └── Members
```

The **Church is the primary tenant boundary**. No role except explicitly authorized
`platform_admin` operations may bypass tenant isolation.

---

## 3. Security Findings & Fixes

### VULN-0001 — Login timing / account enumeration

| Field | Value |
|---|---|
| **Severity** | High |
| **Attack** | Measure login response time to distinguish existing vs. non-existing emails |
| **Root Cause** | The unknown-user branch returned immediately without performing bcrypt work, while the wrong-password branch performed a full bcrypt verification |
| **Fix** | Constant dummy bcrypt hash (`$2y$12$...`, same cost as real passwords) is verified in the unknown-user branch — `backend/app/Services/AuthService.php` |
| **Regression test** | `SecurityRegressionTest::test_login_failure_responses_are_identical_for_existing_and_non_existing_users` + generic-response tests |
| **Status** | ✅ FIXED — verified passing on SQLite and PostgreSQL |

Both `unknown email` and `wrong password` now perform equivalent password-hash work
and return identical status/code/message.

### VULN-0002 — Unauthenticated `GET /api/v1/verses/active`

| Field | Value |
|---|---|
| **Severity** | High |
| **Attack** | Anonymous enumeration of verse content |
| **Root Cause** | Route registered in the public (non-authenticated) group |
| **Fix** | Route moved into the `auth:sanctum` group; minimized projection via new `ActiveVerseResource` (`verse_text`, `reference` only — no `created_by`, `creator_name`, `id`, timestamps) |
| **Regression test** | `SecurityRegressionTest` — anonymous 401, authenticated 200, field-absence assertions |
| **Status** | ✅ FIXED |

### VULN-0003 — Unauthenticated `GET /api/v1/churches/active`

| Field | Value |
|---|---|
| **Severity** | High |
| **Attack** | Anonymous church enumeration; cross-tenant metadata exposure (address) |
| **Root Cause** | Route registered in the public group with an inline closure returning full church rows |
| **Fix** | Route moved into the `auth:sanctum` group; response projection reduced to `id`, `name`, `slug` (no `address`) |
| **Regression test** | `SecurityRegressionTest` — anonymous 401, address-absence assertions, header/query-param spoofing tests |
| **Status** | ✅ FIXED — anonymous church enumeration is **not** restored |

### AUD-0004 — Churchless principal received global user data

| Field | Value |
|---|---|
| **Severity** | Critical |
| **Attack** | Authenticated non-platform user with `church_id = null` reads the global user directory, resolves foreign QR tokens (PII disclosure), reads cross-tenant history |
| **Root Cause** | `User::byChurch()` fell through to an **unfiltered query** when no church was supplied, turning every caller into a cross-tenant read |
| **Fix** | Fail-closed guard in `User::scopeByChurch()` — an authenticated non-platform actor with no church receives `WHERE 1 = 0`. Platform admin keeps intentional global visibility; CLI/queue/seeders (no principal) keep existing behavior — `backend/app/Models/User.php` |
| **Regression test** | `MultiTenantAuthorizationAuditTest::test_churchless_admin_gets_an_empty_user_directory`, `..._cannot_resolve_a_foreign_attendance_qr` |
| **Status** | ✅ FIXED — **fail-closed**. Client-controlled `X-Tenant`, `X-Church-Id`, `church_id` never grant scope. |

### AUD-0005 — Churchless principal received global dashboard aggregates

| Field | Value |
|---|---|
| **Severity** | Critical |
| **Attack** | Authenticated non-platform user with `church_id = null` obtains global member/servant/attendance/point aggregates |
| **Root Cause** | `if ($churchId)` filters were skipped entirely for a null church, so every count ran unscoped; a global cache key could also be written |
| **Fix** | Early return of zeroed aggregates for `churchId === null && ! isPlatformAdmin()` — no aggregate query executes and **no global cache key is read or written** — `backend/app/Http/Controllers/Api/DashboardController.php` |
| **Regression test** | `MultiTenantAuthorizationAuditTest::test_churchless_admin_gets_zero_dashboard_aggregates` |
| **Status** | ✅ FIXED — **fail-closed** |

---

## 4. Removed Dead Code

### 4.1 `GET /users/{id}/servants` (audit observation O-3)

| Field | Value |
|---|---|
| **Finding** | The `{id}` segment was never used; scope was always derived from the authenticated caller — misleading contract |
| **Evidence of zero legitimate consumers** | Repository-wide search (`git grep` over backend routes, controllers, services, frontend API clients/hooks, tests, docs) found **no** caller passing an `{id}`. The single frontend consumer `getServants()` takes **no arguments** and always calls the supported `GET /users/servants`. |
| **Removed** | Route in `backend/routes/api.php` (replaced with an explanatory comment) |
| **Preserved** | `GET /users/servants` (supported, church-scoped) + `UserController::servants()` action + `UserService::servants()` — these have legitimate consumers (`frontend/src/pages/admin/Events.tsx`) |
| **Frontend** | `getServants(adminId?: number)` parameter removed (`frontend/src/api/users.ts`) |
| **Test** | `MultiTenantAuthorizationAuditTest::test_parameterized_servants_route_is_removed` asserts 404 on the old path; `..._servants_endpoint_is_scoped_to_own_church` is the positive control |
| **Status** | ✅ REMOVED |

### 4.2 `backend/app/Repositories/UserRepository.php`

| Field | Value |
|---|---|
| **Finding** | Dead/deleted-looking stub (contained only `// DELETED - removed per audit`) |
| **Evidence of no consumers** | No `use App\Repositories\UserRepository` imports anywhere; no service-container binding to it |
| **Actual wiring preserved** | `AppServiceProvider::register()` binds `UserRepositoryInterface::class` → `App\Modules\User\Repositories\UserRepository::class` (module repository, untouched) |
| **Test verification** | `ChurchScopeLoginTest`, `EmailVerificationOnProvisioningTest`, and the full suite resolve the interface to the module repository — all pass |
| **Action** | Permanently deleted; no replacement repository or new abstraction introduced |
| **Status** | ✅ DELETED |

---

## 5. Exception Handling Hardening

Audited boundaries: controllers, services, repositories, jobs, queue workers,
notifications, authentication, authorization, tenant resolution, offline/sync,
bulk operations, external providers.

### Findings remediated

| Item | File | Change |
|---|---|---|
| Pointless `try/catch` that rethrew | `EventScheduleController` | Removed dead catch |
| `ValidationException` responses returned generic `getMessage()` only | `EventAccommodationController` (5 sites), `EventReservationController` (2 sites) | Now return `errors` (field-level) alongside message — consistent 422 contract |
| `ValidationException` returned as **404** | `EventAccommodationController::showRoom` | Normalized to **422** |
| Implicit tenant scope in bulk path | `EventAccommodationController::findEvent()` | `Event::query()` → `Event::byChurch()` (explicit defense-in-depth) |

### Verified already safe (unchanged)

- `bootstrap/app.php` catch-all returns generic `Internal server error.` in production; debug detail is gated behind `config('app.debug') && app()->isLocal()`.
- `ThrottleRequestsException` and `HttpResponseException` handlers are registered **before** the catch-all — rate limits return **429** (never 500) with `Retry-After` / `X-RateLimit-*`.
- No auth/validation error is swallowed; no stack trace, SQL, credentials, tokens, or paths leak in production responses.

---

## 6. Transaction Safety

### Newly wrapped in `DB::transaction()`

| Operation | File | Risk closed |
|---|---|---|
| `UserService::promote()` | `app/Modules/User/Services/UserService.php` | role + stage_id + scope atomic |
| `UserService::demoteFromAdmin()` | same | role + stage/scope + auth-cache invalidation atomic |
| `ClasseService::assignServant()` | `app/Services/ClasseService.php` | stage sync + pivot attach atomic |
| `ClasseService::assignMember()` | same | stage sync + class_id write atomic |
| `EventRegistrationService::cancel()` | `app/Services/EventRegistrationService.php` | status update + waitlist promotion atomic (no lost seat) |
| `EventRegistrationService::remove()` | same | delete + waitlist promotion atomic |
| `EventRegistrationService::checkIn()` | same | attendance + waitlist promotion atomic |
| `EventAccommodationService::updateRoom()` | `app/Services/EventAccommodationService.php` | capacity update + cell create/delete atomic |
| `EventScheduleService::deleteBus()` | `app/Services/EventScheduleService.php` | registration detach + bus delete atomic |

Notifications are deliberately **outside** the transaction (best-effort; a notification
failure must not roll back business state). 30+ other multi-write operations were
already transaction-wrapped (verified by audit).

### Tests

`TransactionSafetyTest` (6 tests) forces a mid-operation failure and asserts rollback
on SQLite **and** PostgreSQL.

---

## 7. Concurrency

**No new parallel execution was introduced.** Justified: Laravel/PHP request handling
has no shared-memory multithreading, and no workload in this phase benefits from
dispatching independent parallel work (email/notification dispatch is already queued).

Existing synchronization strategies were preserved and re-validated:

| Mechanism | Location |
|---|---|
| `lockForUpdate()` on attendance row | `AttendanceService::processAttendance()` |
| `lockForUpdate()` on event row | `EventRegistrationService::checkInByToken()` |
| `lockForUpdate()` on invite acceptance | `QRInviteService::acceptInvite()` |
| Duplicate-key catch (23505 / `UniqueConstraintViolationException`) | `AttendanceService`, `QRInviteService` |
| Unique DB constraints | composite tenant keys, invite tokens, attendance uniqueness |
| `withoutOverlapping()` | scheduled cleanup commands |
| Atomic single-statement updates | points/attendance counters |

Race-condition coverage: duplicate attendance prevention, duplicate invite creation,
double approval via locking (`PasswordResetRequestTest::approve_prevents_double_approval_via_locking`),
mixed-tenant bulk rejection. Each concurrent path operates inside the same trusted
tenant scope resolved from the database.

---

## 8. PWA / Offline Isolation

Preserved and verified:

- Token-partitioned request cache (`hashScope(token)` prefixes every cache key).
- Logout purge (memory + localStorage + IndexedDB); 401 purge.
- Session-bound offline queues — replay aborts if the session token changes.
- Error responses (401/403/404/500) are **never cached** — only 2xx GETs enter the cache.
- `navigateFallbackDenylist: [/^\/api\//]` excludes API routes from the SW fallback.

### Fixed in this phase

| Item | File | Change |
|---|---|---|
| Silent 401 on background stale-while-revalidate refresh | `frontend/src/api/client.ts` | 401 now triggers full session invalidation (cache clear, token removal, redirect) instead of being swallowed |

---

## 9. Validation Results

### Backend

| Suite | Result | Notes |
|---|---|---|
| Full suite (SQLite) | **651 passed, 6 skipped, 0 failed** (3482 assertions) | exit 0 |
| Full suite (PostgreSQL, `phpunit.postgres.xml`) | **657 passed, 0 failed** (2267 assertions) | exit 0 |
| Security regression + multi-tenant audit (SQLite) | **75 passed** (171 assertions) | exit 0 |
| Security suites (PostgreSQL): `SecurityRegressionTest`, `MultiTenantAuthorizationAuditTest`, `ExceptionHandlingTest`, `TransactionSafetyTest` | **91 passed** (202 assertions) | exit 0 |
| `TransactionSafetyTest` | **6 passed** (6 assertions) | exit 0 |
| `ExceptionHandlingTest` | **10 passed** (10 assertions) | exit 0 |
| PHPStan level max | **0 errors** (327 files) | exit 0 |
| Pint | **passed** (0 issues) | exit 0 |

### Frontend

| Check | Result |
|---|---|
| `npx tsc --noEmit` | **0 errors** (exit 0) |
| `npm run lint` | **0 errors** (exit 0) |
| `npm test` (vitest) | **83 passed / 83** (9 files, exit 0) |
| `npm run check:i18n` | **PASS** — 1385 EN == 1385 AR keys, parity exact (exit 0) |
| `npm run build` | **built in 1.07s** — PWA precache 138 entries (exit 0) |

### Dependency audits

| Audit | Result |
|---|---|
| `composer audit` | **No security vulnerability advisories found** (2 abandoned dev packages: `sebastian/code-unit`, `sebastian/code-unit-reverse-lookup` — non-security) |
| `npm audit` | **0 vulnerabilities** |

---

## 10. Deployment Status

```text
NOT DEPLOYED
```

- No commits, pushes, or tags created.
- No production migrations run; no production data modified.
- No Railway/Vercel configuration or secrets changed.

### Remaining conditions before deployment

1. Human review of the working tree diff.
2. Commit + CI run on the release branch.
3. Standard release process (not part of this phase).
4. Advisory only: replace/remove the 2 abandoned Composer dev packages.
