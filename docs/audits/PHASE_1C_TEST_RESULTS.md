# PHASE 1C — TEST RESULTS

All results are from the FINAL code state of Phase 1C. No production system was used;
PostgreSQL verification ran against a disposable container (`phase1c_pg`, PG 16.15, port 55433)
that is torn down after this phase.

---

## 1. Backend — Full Suite

### 1a. SQLite (default test engine, `CACHE_STORE=array`)

| Metric | Value |
|---|---|
| Command | `php artisan test` (from `backend/`) |
| Passed | **560** |
| Failed | **0** |
| Skipped | 6 (pgsql-only tests, skipped by design on SQLite) |
| Assertions | **3280** |
| Duration | **155.55 s** |
| Exit code | 0 |

> **Re-verified on the final tree.** An earlier full run in this phase recorded 556 passed /
> 3263 assertions; that run predated `EventRoomCapacityResizeTest` (R-11). The figures above are
> from a run executed against the exact final working tree and supersede them.

### 1b. PostgreSQL 16.15 (disposable container, `migrate:fresh` applied)

Environment: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=55433`, `DB_DATABASE=church_test`,
`DB_USERNAME=postgres`, `DB_PASSWORD=postgres`, `DB_SSLMODE=disable`, `APP_ENV=testing`,
`CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`.

| Metric | Value |
|---|---|
| Migrations (`migrate:fresh`) | **108/108 applied**, exit 0 |
| `tenants:audit` | clean (0 findings) |
| `tenants:verify-schema` | exit 0 — **all 7 composite tenant FKs OK** |
| Command | `php artisan test` (final code state — re-run on the exact final tree) |
| Passed | **566** |
| Failed | **0** |
| Skipped | **0** (the 6 pgsql-only tests execute here) |
| Assertions | **3286** |
| Duration | **481.23 s** |
| Exit code | **0** |

> The SQLite and PostgreSQL totals reconcile: 560 + 6 skipped = 566 total tests; on PostgreSQL all
> 566 run. No test is engine-broken in either direction.
> (A run earlier in the phase recorded 562; that predated `EventRoomCapacityResizeTest`. The
> figures above supersede it.)

---

## 1c. New test files added by Phase 1C (all fail-first, defect proven before each fix)

| File | Tests | Proves | Before fix | After fix | Engines |
|---|---|---|---|---|---|
| `RateLimitResponseTest` (R-01) | 8 | Rate limiting returns 429 with headers/JSON, never 500 | 5 failed, 3 passed | 8 passed | SQLite (array + file), PG |
| `HttpExceptionResponseShapeTest` (R-07) | 4 | `abort(403)` yields `FORBIDDEN`, subclasses still win, 5xx/web unchanged | 1 failed, 3 passed | 4 passed | SQLite, PG |
| `ResetNotificationEncryptionTest` (R-10) | 2 | Reset-bearing notifications implement `ShouldBeEncrypted` | n/a (interface absent → would fail) | 2 passed | SQLite, PG |
| `EventRoomCapacityResizeTest` (R-11) | 4 | Room resize actually syncs cells; occupied cells protected; guard truthful | **4 failed** | 4 passed (17 assertions) | SQLite, PG |
| `frontend/src/lib/__tests__/sync.test.ts` (R-08) | 15 (7 new) | 429/408 transient, 422 still permanent, `Retry-After` policy | — | 15 passed | vitest/jsdom |
| `frontend/src/test/scanQROfflineQueue.test.tsx` (R-09) | 4 (new) | Sentinel consumed: queued state, flow reset, no failure toast | — | 4 passed | vitest/jsdom |

---

## 2. P0 Verification Tests — `RateLimitResponseTest` (NEW, 8 tests)

Proves the ACTUAL HTTP behaviour of the real registered limiters (not a redefined test double).

| # | Test | SQLite/array | SQLite/`file` cache (production mechanism) | PostgreSQL |
|---|---|---|---|---|
| 1 | Request below the limit reaches the handler (401, not 429) | ✅ | ✅ | ✅ |
| 2 | Requests up to the configured limit still allowed (limit not weakened) | ✅ | ✅ | ✅ |
| 3 | Next throttled request returns **429 and not 500** | ✅ | ✅ | ✅ |
| 4 | JSON body matches the application error structure | ✅ | ✅ | ✅ |
| 5 | `Retry-After` + `X-RateLimit-*` headers preserved | ✅ | ✅ | ✅ |
| 6 | No internal exception details leaked | ✅ | ✅ | ✅ |
| 7 | A SECOND named limiter also returns 429 (not login-specific) | ✅ | ✅ | ✅ |
| 8 | The 429 is **not** logged as an unhandled exception | ✅ | ✅ | ✅ |

**Defect proven before the fix (reproduction-first):**

```text
Before fix: 5 failed, 3 passed
  6th  POST /api/v1/auth/login        -> 500
  11th POST /api/v1/auth/verify-email -> 500
  storage/logs/laravel.log:
    testing.ERROR: Unhandled API exception
      {"message":"","file":".../ThrottleRequests.php","line":253,...}
After fix:  8 passed (all three engine/cache combinations)
```

**Targeted re-runs after final test edits:**

| Run | Result |
|---|---|
| `RateLimitResponseTest` on PostgreSQL | 8 passed |
| `Security` suite on PostgreSQL | **329 passed, 1221 assertions** |

---

## 3. Static Analysis & Code Style

| Tool | Command | Result |
|---|---|---|
| PHPStan | `vendor/bin/phpstan analyse --level=max` | **0 errors** |
| Pint | `vendor/bin/pint --test` | **pass** (0 issues) |
| Frontend TypeScript | `npx tsc --noEmit` | **clean** (0 errors) |
| Frontend ESLint | `npm run lint` | **clean** (0 errors) |
| i18n key parity | `npm run check:i18n` | **pass** (EN/AR exact parity) |

---

## 4. Frontend

| Check | Command | Result |
|---|---|---|
| Unit tests | `npm test` | **9 files / 83 tests passed** (72 pre-existing + 7 sync retry-policy + 4 ScanQR sentinel) |
| Type check | `npx tsc --noEmit` | clean |
| Lint | `npm run lint` | clean |
| i18n key parity | `npm run check:i18n` | pass (new `attendance.queuedOffline` in EN + AR) |
| Production build | `npm run build` | **success** — PWA service worker generated, **138 precache entries** |

---

## 5. Dependency / Advisory Verification

| Layer | Command | Result |
|---|---|---|
| Backend (locked, prod only) | `composer audit --locked --no-dev` | **No advisories found** |
| Backend (incl. dev) | `composer audit` | **No advisories found** |
| Frontend (all levels) | `npm audit` | **found 0 vulnerabilities** |
| Frontend (high+) | `npm audit --audit-level=high` | **found 0 vulnerabilities** |
| axios resolved version | `npm ls axios` | **axios@1.20.0** (patched; advisory range was `>=1.7.0 <1.20.0`) |

---

## 6. Migration Rollback Lint (informational)

| Check | Result |
|---|---|
| `php scripts/scan-broken-migration-rollbacks.php` | 5 findings, **exit 0** (reporting gate by design — see R-05) |
| `MigrationRollbackTest` | passes within both full suites |
| `migrate:fresh` on PostgreSQL 16.15 | **108/108 OK** (historical SQLite-only `down()` issues do not reproduce on the production driver — see R-04) |

---

## 7. Totals Summary

| Suite | Passed | Failed | Skipped | Assertions | Duration |
|---|---|---|---|---|---|
| Backend — SQLite | 560 | 0 | 6 | 3280 | 155.55 s |
| Backend — PostgreSQL 16.15 | **566** | **0** | **0** | **3286** | 481.23 s |
| PostgreSQL `Security` gate (targeted) | 329 | 0 | 0 | 1221 | 171.02 s |
| Frontend — Vitest | 83 | 0 | 0 | — | — |
| **Combined automated tests** (PG + frontend) | **649** | **0** | — | — | — |

**Static analysis:** PHPStan level max 0 · Pint clean · tsc clean · ESLint clean · i18n parity exact.
**Advisories:** composer clean (`--locked --no-dev`) · npm clean (all levels, `axios@1.20.0`).
**Verdict:** every gate required by the mandate passes on the final code state, on BOTH database engines.
