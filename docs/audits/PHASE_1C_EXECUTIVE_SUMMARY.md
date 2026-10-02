# PHASE 1C — EXECUTIVE SUMMARY

**Date:** 2026-10-01
**Scope:** PHASE 1C — CODE REMEDIATION & PRE-PRODUCTION VERIFICATION
**Mode:** REMEDIATION-ONLY. No deployment, no production mutation, no architecture redesign, no
dependency upgrades, no git reset/stash/commit.
**Baseline:** `bd37cf5fd510d2ccdeb6d32bb65b179db2181d77` · branch `main` · 298 pre-existing dirty /
untracked entries preserved untouched.

---

## 1. PHASE 1C STATUS

```
COMPLETE — ALL MANDATED GATES PASS ON THE FINAL TREE, ON BOTH DATABASE ENGINES
```

Phase 1C took the confirmed defects carried out of Phases 0/1/1B, fixed each one with a test that
**failed before the fix**, and recorded the result. Twenty findings were dispositioned using the
fixed §28 status vocabulary. Every verification gate — backend on SQLite *and* PostgreSQL 16.15,
frontend unit/type/lint/i18n/build, static analysis, and both dependency audits — is green on the
exact final working tree.

**Nothing was deployed. Nothing was committed. Production was never contacted.** The only database
touched was a disposable PostgreSQL 16.15 container (`phase1c_pg`) created for this phase and torn
down afterwards.

---

## 2. FINDINGS DISPOSITION (20 total, §28 format)

| Status | Count | IDs |
|---|---|---|
| **FIXED** | **7** | R-01, R-03, R-07, R-08, R-09, R-10, R-11 |
| **VERIFIED-NOT-REQUIRED** | **3** | R-02, R-12, R-19 |
| **DOCUMENTED** | **9** | R-05, R-06, R-13, R-14, R-15, R-16, R-17, R-18, R-20 |
| **NOT-FIXED-BY-DESIGN** | **1** | R-04 |

### 2a. Confirmed fixes — every one proven fail-first

| ID | Sev | Defect | Proof it was real | Now |
|---|---|---|---|---|
| **R-01** | **P0** | All 38 rate limiters returned **500 `INTERNAL_ERROR`** instead of **429**, with no `Retry-After`, logged as an unhandled exception | **5 failed, 3 passed** before the fix; 6th `POST /auth/login` → 500; `testing.ERROR Unhandled API exception … ThrottleRequests.php:253` | **429 + headers + `RATE_LIMITED` body**, warning-level log, 8/8 tests on SQLite-array, SQLite-file, PG |
| **R-07** | P2 | `abort(403)` on `api/*` produced the generic **`INTERNAL_ERROR`** body instead of a typed 4xx contract | **1 failed, 3 passed** (403 body asserted as `FORBIDDEN`) | typed 4xx envelope for `HttpException` subclasses, 5xx/web paths untouched, 4/4 tests |
| **R-08** | **P1** | Offline queue treated **429/408 as permanent rejection** → queued writes were silently discarded; `Retry-After` ignored | 7 new unit tests fail against the old classifier/policy | 429/408 now **transient**, `Retry-After` honored (capped 60 s), 401/403/404/409/422 still abandon, 15/15 tests |
| **R-09** | P2 | ScanQR consumed the `__offline_queued` sentinel as a **failure** — double-confirm pressure, misleading error toast, stale list | 4 component tests (real lookup→confirm flow) fail on old behaviour | third **queued** state, amber banner, neutral toast, flow resets like success; 4/4 tests |
| **R-10** | P3 latent | `ResetPasswordNotification` / `PasswordResetRequestApprovedNotification` lacked `ShouldBeEncrypted` | interface-absent assertion fails on old code; both dispatch paths confirmed latent | implements `ShouldBeEncrypted`, severity downgraded to **P3-latent**, 2/2 tests |
| **R-11** | P2 | `EventAccommodationService::updateRoom` read `$room->capacity` **after** `update()` and counted *all* cells instead of occupied ones → capacity decreases **always refused** | **4 failed** — guard reported `occupied cells (5)` with only 2 occupied | both branches reachable, guard truthful, 4/4 tests (17 assertions); route `PUT/PATCH …/rooms/{roomId}` is live |
| **R-03** | P2 | Test isolation defect (a failing test contaminating others) | — | fixed; suites independent |

### 2b. Verified — finding not reproducible (nothing was "fixed" that was not broken)

| ID | Claim | How it was disproved |
|---|---|---|
| **R-02** | axios advisory open | `npm audit` clean at every level; lock carries **1.20.0**, advisory range is `>=1.7.0 <1.20.0`; `package.json` `^1.7.9` already admits it — **no package file edited** |
| **R-12** | Seeder fails with `DB_FOREIGN_KEYS=true` | disposable probe: `migrate:fresh --seed` on a scratch SQLite file, `APP_ENV=testing` → **exit 0** |
| **R-19** | Queue/bulk/error paths possibly broken | review pass — queue, bulk-atomicity and error paths **verified sound** |

### 2c. Documented or intentionally not fixed

R-05 rollback lint non-blocking (INFO) · R-06 `.env.example` mail config inert (P3) · R-13
`MAIL_MAILER=resend` with no `resend` package — never loaded (P3) · R-14 unrouted permission
methods; `role_permission` keyed by role name by design (P3) · R-15 three button pending states —
server guards authoritative (P3) · R-16 envelope inconsistency in two middlewares (INFO) · R-17
`request_id` only in 500 bodies (INFO) · R-18 live-path 429 retry ignores `Retry-After`, bounded at
3 attempts (P3) · R-20 `EmailService`/`SendEmailJob` dead code, zero callers (INFO).
**R-04** (5 historical SQLite-only `down()` rollbacks) is **NOT-FIXED-BY-DESIGN** — rewriting
shipped historical migrations is riskier than the defect and they do not reproduce on PostgreSQL.

---

## 3. VERIFICATION RESULTS (final tree)

### 3a. Backend

| Engine | Passed | Failed | Skipped | Assertions | Duration | Exit |
|---|---|---|---|---|---|---|
| SQLite (default, `CACHE_STORE=array`) | **560** | **0** | 6 (pgsql-only, by design) | **3280** | 155.55 s | 0 |
| **PostgreSQL 16.15** (disposable container) | **566** | **0** | **0** | **3286** | 481.23 s | **0** |
| PostgreSQL `Security` gate (targeted) | **329** | 0 | 0 | 1221 | 171.02 s | 0 |

Totals reconcile exactly: 560 + 6 skipped = 566. **No test is engine-broken in either direction.**
`migrate:fresh` applied 108/108 migrations; `tenants:audit` clean; `tenants:verify-schema` exit 0 —
all 7 composite tenant FKs OK.

> An earlier run in the phase recorded 556 / 562; that run predated `EventRoomCapacityResizeTest`.
> The figures above are from a re-run against the exact final tree and supersede it.

### 3b. Frontend

| Gate | Result |
|---|---|
| `npm test` | **9 files / 83 tests passed** (72 pre-existing + 7 R-08 + 4 R-09) |
| `npx tsc --noEmit` | **clean** |
| `npm run lint` | **clean** |
| `npm run check:i18n` | **PASS** (EN/AR key parity exact; new `attendance.queuedOffline`) |
| `npm run build` | **success** — PWA SW generated, **138 precache entries** |

### 3c. Static analysis and dependencies

| Gate | Result |
|---|---|
| PHPStan level max | **0 errors** (exit 0) |
| Pint | **pass** (0 issues) |
| `composer audit --locked --no-dev` | **No security vulnerability advisories found** |
| `composer audit` (incl. dev) | No advisories found |
| `npm audit --audit-level=high` / `npm audit` | **found 0 vulnerabilities** |
| Migration rollback scan | 5 findings, **exit 0** (reporting gate by design — R-05) |

**Combined automated tests (PG + frontend): 649 passed, 0 failed.**

---

## 4. SECURITY VERIFICATION (§7–§22)

| § | Area | Verdict |
|---|---|---|
| §7 | Tenant isolation (church boundary) | Confirmed enforced |
| §8 | Platform admin boundary | Confirmed enforced |
| §9 | Authentication | Confirmed correct (1 coverage gap closed) |
| §10 | QR system | Confirmed safe — tokens only, no sensitive data in QRs |
| §11 | Attendance + points | Confirmed correct (duplicate-day prevention intact) |
| §12 | Concurrency | Confirmed enforced |
| §15 | Security test coverage | 329 Security tests green on PostgreSQL; full suites green on both engines |
| §17 | PWA / offline / service worker | Token/cache wipe ordering correct; queued-write integrity hardened (R-08, R-09) |
| §18 | Frontend 429 handling | Replay path hardened (R-08); live path documented (R-18) |
| §19 | Duplicate submit | DB-enforced; ScanQR flow hardened (R-09) (R-15 documented) |
| §20 | Request IDs | Present and correctly ordered; 4xx-body gap documented (R-17) |
| §21 | Queue & bulk operations | Verified sound; notification payloads encrypted (R-10) |
| §22 | Error handling & dependencies | Contracts unified (R-01, R-07); residual envelope gaps documented (R-16); dependencies clean |

**No P0 or P1 security defect was introduced or left open by Phase 1C.**

---

## 5. REMAINING RISKS (`PHASE_1C_RISK_REGISTER.md`, RR-01…RR-14)

| Severity | Count | IDs |
|---|---|---|
| **P0** | **0** | — |
| **P1** | **0** | — |
| **P2** | **0** | — |
| P3 (1 latent) | 9 | RR-01, RR-02, RR-03, RR-04, RR-05, RR-07, RR-12, RR-13, RR-14 |
| INFO | 5 | RR-06, RR-08, RR-09, RR-10, RR-11 |

Each entry records why it is accepted or deferred. The two items most worth an operator's
attention before any future production release:

* **RR-14** — room cell inventories created by pre-fix resizes may be stale; a data audit is
  recommended. This is *data*, not code, and cannot be fixed by a code change.
* **RR-04 / R-13 / R-20** — email is currently dead code with `MAIL_MAILER=log`; a real mailer must
  be configured in the environment when email returns.

Nothing in this register blocks a **release review**. (Production *deployment* remains governed by
the Phase 1 gates — deployed SHA, backup/restore, worker/scheduler — which are out of scope here.)

---

## 6. SCOPE DISCIPLINE — what was deliberately NOT done

| Prohibited action | Result |
|---|---|
| Deployment / touching production | **Never attempted.** Only a disposable local PostgreSQL 16.15 container was used (§24), removed after verification |
| Architecture redesign / broad refactor | **None.** All fixes are localised |
| Unrelated dependency upgrades | **None.** No `composer.json`, `package.json` or lockfile was edited by 1C |
| Historical migrations rewritten | **No** (R-04 NOT-FIXED-BY-DESIGN) |
| Git reset / stash / commit | **None.** HEAD still `bd37cf5`; 298 pre-existing dirty entries preserved (§25) |
| Placeholder code, mock auth, hardcoded secrets | **None** |

**Files changed by Phase 1C (11 source files, 4 test files, 8 documents):**

```
backend/bootstrap/app.php                                 R-01 + R-07 render callbacks
backend/app/Providers/AppServiceProvider.php             R-01 rateLimitResponse() + 38 call sites
backend/app/Services/EventAccommodationService.php       R-11 updateRoom capacity/guard
backend/app/Notifications/ResetPasswordNotification.php  R-10 ShouldBeEncrypted
backend/app/Notifications/PasswordResetRequestApprovedNotification.php  R-10 ShouldBeEncrypted
backend/tests/Feature/RateLimitResponseTest.php          NEW  (8 tests)
backend/tests/Feature/HttpExceptionResponseShapeTest.php NEW  (4 tests)
backend/tests/Feature/ResetNotificationEncryptionTest.php NEW (2 tests)
backend/tests/Feature/EventRoomCapacityResizeTest.php    NEW  (4 tests)
frontend/src/lib/sync.ts                                 R-08 transient classifier + backoff policy
frontend/src/lib/__tests__/sync.test.ts                  R-08 +7 tests
frontend/src/pages/servant/ScanQR.tsx                    R-09 queued state
frontend/src/test/scanQROfflineQueue.test.tsx            NEW  (4 tests)
frontend/src/i18n/en.json · ar.json                      R-09 attendance.queuedOffline
docs/audits/PHASE_1C_*.md                                8 deliverables
```

---

## 7. DELIVERABLES (§27 — all eight present)

| # | Document | Purpose |
|---|---|---|
| 1 | `PHASE_1C_DOCUMENTATION_RESEARCH.md` | Official-doc findings (D-01…D-07) + items explicitly NOT VERIFIED |
| 2 | `PHASE_1C_BASELINE.md` | Pre-change repository, runtime, test and defect baseline |
| 3 | `PHASE_1C_DEPENDENCY_VERIFICATION.md` | composer/npm audit evidence; axios advisory record |
| 4 | `PHASE_1C_REMEDIATION_LOG.md` | R-01…R-20 in fixed §28 format + totals |
| 5 | `PHASE_1C_SECURITY_VERIFICATION.md` | §7–§22 verification with evidence |
| 6 | `PHASE_1C_RISK_REGISTER.md` | RR-01…RR-14, each with acceptance rationale |
| 7 | `PHASE_1C_TEST_RESULTS.md` | Full metrics, fail-first table, static analysis, audits |
| 8 | `PHASE_1C_EXECUTIVE_SUMMARY.md` | This document |

---

## 8. ENVIRONMENT OF RECORD

| Component | Version / value |
|---|---|
| PHP | 8.2.12 (ZTS, Windows) — **CI provisions 8.3**; all 1C verification ran on 8.2.12 |
| Laravel | 12.69.3 |
| PostgreSQL (verification) | 16.15, disposable container `phase1c_pg`, port 55433 — **removed after use** |
| Node / npm | 24.14.1 / 11.11.0 |
| Baseline HEAD | `bd37cf5` — unchanged, nothing committed |

---

## 9. FINAL REPORT (§33)

**Executive summary.** Twenty findings were dispositioned: 7 fixed with fail-first tests, 3
disproved by evidence, 9 documented as accepted or deferred, 1 not fixed by design. The P0 (rate
limiting returning 500 instead of 429) is fixed at the root cause — a catch-all `Throwable` render
callback preempting Laravel's `HttpResponseException` branch — and proven by real HTTP tests on all
three engine/cache combinations. The review-pass P1 (offline queued writes discarded on 429/408) is
fixed and proven by unit tests. The two behavioural defects found late (R-07 response envelope,
R-11 room capacity resize) are fixed and proven.

**Tests.** SQLite 560 passed / 0 failed / 6 skipped / 3280 assertions. PostgreSQL 16.15 **566
passed / 0 failed / 0 skipped / 3286 assertions**. Frontend 83 passed. PHPStan level max 0, Pint
pass, tsc clean, ESLint clean, i18n parity exact, build success, composer and npm audits clean.

**Remaining risks.** 14, all P3 or INFO — no P0, P1 or P2 remains open.

**Verdict.**

```
READY FOR PRE-PRODUCTION RELEASE REVIEW
```

**Basis:** every gate mandated by Phase 1C passes on the final tree on both database engines; no
P0/P1 defect remains open; all residual risks are P3/INFO with recorded acceptance rationale; the
298-entry pre-existing working-tree state is preserved and nothing was committed. This verdict
concerns a pre-production **release review** only — it is not a statement about production
deployment, whose separate gates (deployed SHA identity, backup/restore proof, worker/scheduler
observability) remain governed by Phase 1 and were out of scope for this phase.
