# PHASE 1D — TEST RESULTS

Every number below is a **fresh run performed in the Phase 1D session** on 2026-10-01.
No figure was copied from Phase 1C. Local runtime: PHP 8.2.12, Laravel 12.69.3,
Node 24.14.1, PostgreSQL 16.15 (disposable container `phase1d_pg`, image `postgres:16`,
port 55433), SQLite for the default suite.

> PowerShell writes some artisan/composer diagnostics to **stderr**; exit codes were read
> from `$LASTEXITCODE`, never from stream output.

---

## 1. BACKEND — SQLite (default `php artisan test`)

```text
$ php artisan test
  Tests:    6 skipped, 560 passed (3280 assertions)
  Duration: 232.91s
EXIT=0
```

| Metric | Value |
|--------|------:|
| passed | **560** |
| failed | **0** |
| skipped | **6** (PostgreSQL-only concurrency tests) |
| assertions | **3280** |
| duration | 232.91 s |
| exit code | **0** |

---

## 2. BACKEND — PostgreSQL 16.15 (disposable container)

```text
$ php artisan migrate:fresh --force
  … 2026_09_29_000001_add_composite_tenant_foreign_keys … 426.03ms DONE
MIGRATE_EXIT=0

$ php artisan test
  Tests:    566 passed (3286 assertions)
  Duration: 411.40s
TEST_EXIT=0
```

| Metric | Value |
|--------|------:|
| passed | **566** |
| failed | **0** |
| skipped | **0** |
| assertions | **3286** |
| duration | 411.40 s |
| exit code | **0** |
| `migrate:fresh` | **exit 0** (all 108 migrations) |

Environment used:

```text
DB_CONNECTION=pgsql  DB_HOST=127.0.0.1  DB_PORT=55433  DB_DATABASE=church_test
DB_USERNAME=postgres DB_PASSWORD=***    DB_SSLMODE=disable
APP_ENV=testing  CACHE_STORE=array  SESSION_DRIVER=array
QUEUE_CONNECTION=sync  MAIL_MAILER=array
```

---

## 3. RECONCILIATION

```text
SQLite:   560 passed + 6 skipped = 566
PostgreSQL:                      566 passed
                                 ------
                                  566   ✅ totals reconcile exactly
```

| Suite | Phase 1C | Phase 1D | Delta |
|-------|----------|----------|------:|
| SQLite | 560 / 6 / 3280 | **560 / 6 / 3280** | 0 |
| PostgreSQL | 566 / 0 / 3286 | **566 / 0 / 3286** | 0 |
| Frontend (Vitest) | 9 files / 83 | **9 files / 83** | 0 |

*Note on the mandate's §22 quote:* the line `Frontend: 329 passed / 1221 assertions` is
**not** a frontend figure — it is the PostgreSQL `Security` suite recorded by Phase 1C.
The actual frontend result is 9 files / 83 tests (this run). Corrected here so the record
is not misleading.

No test was added, edited, skipped or removed in Phase 1D.

---

## 4. FRONTEND

```text
$ npm test
 Test Files  9 passed (9)
      Tests  83 passed (83)
   Duration  70.32s
TEST_EXIT=0

$ npx tsc --noEmit
TSC_EXIT=0

$ npm run lint
LINT_EXIT=0

$ npm run check:i18n
check-i18n: PASS
I18N_EXIT=0
```

| Gate | Result |
|------|--------|
| Vitest | **9 files / 83 passed / exit 0** |
| TypeScript | **clean / exit 0** |
| ESLint | **0 errors / exit 0** |
| i18n EN/AR parity | **PASS / exit 0** |

---

## 5. STATIC ANALYSIS & STYLE

```text
$ vendor/bin/phpstan analyse --memory-limit=1G
 [OK] No errors
PHPSTAN_EXIT=0

$ vendor/bin/pint --test
{"tool":"pint","result":"passed"}
PINT_EXIT=0
```

PHPStan level-max: **0 errors**. Pint: **0 issues**.

---

## 6. BUILD

```text
$ composer validate --no-check-publish        → ./composer.json is valid   exit 0
$ composer check-platform-reqs                → all requirements OK         exit 0
$ composer install --no-dev --prefer-dist --optimize-autoloader
                                               → exit 0
  classmap: 614 KB / 4930 entries;  php artisan --version → 12.69.3;
  route:list --json → 224 routes;   dev tools absent as documented
$ composer install (restore dev)              → exit 0; composer.lock unchanged

$ npm run build
  PWA v1.3.0 / mode generateSW
  precache 138 entries (1818.48 KiB)
  dist/sw.js, dist/workbox-dcde9eb3.js
  ✓ built in 3.39s
BUILD_EXIT=0
```

---

## 7. SECURITY SCANS

```text
$ composer audit --locked --no-dev
No security vulnerability advisories found.
COMPOSER_AUDIT_EXIT=0

$ npm audit --audit-level=high
found 0 vulnerabilities
AUDIT_EXIT=0

$ npm ls axios
└── axios@1.20.0        (single resolved node, exit 0)
```

Secret/PII pattern scan over `git diff` + `git diff --cached` + 70 untracked text files:
**0 credential hits** (see `PHASE_1D_RELEASE_REVIEW.md` §24 for the triage).

---

## 8. TENANT GATES (disposable PostgreSQL — final release tree)

```text
$ php artisan tenant:verify-schema
Supporting unique keys
  stages.stages_church_id_id_unique            OK
  classes.classes_church_id_id_unique          OK
Composite tenant foreign keys
  classes_church_stage_fk    OK  (classes.stage_id      -> stages)
  users_church_stage_fk      OK  (users.stage_id        -> stages)
  users_church_class_fk      OK  (users.class_id        -> classes)
  events_church_class_fk     OK  (events.class_year_id  -> classes)
  event_targets_church_class_fk OK (event_targets.class_id -> classes)
  qr_invites_church_stage_fk OK  (qr_invites.stage_id   -> stages)
  qr_invites_church_class_fk OK  (qr_invites.class_id   -> classes)
Behavioural proof (throwaway transaction, seeded 2 churches / 2 stages / 2 classes)
  cross-church class -> foreign stage      OK
  cross-church user  -> foreign class      OK
  NULL class_id (legitimate) is allowed    OK
  same-church user  -> own class           OK
All tenant isolation invariants are enforced by the database.
VERIFY_EXIT=0

$ php artisan tenant:audit
No inconsistencies found. The composite tenant foreign keys can be applied safely.
AUDIT_EXIT=0

$ php artisan migrate:status
RAN: 108   PENDING: 0     STATUS_EXIT=0
```

Safety of the two commands (verified in source before being recommended for production):

* `tenant:verify-schema` wraps its write probes in `DB::beginTransaction()` and calls
  `DB::rollBack()` in `finally` (lines 192 / 247-248) → **read-only in effect**.
* `tenant:audit` is **read-only unless `--repair` is passed** → the checklist runs it
  without `--repair`.

---

## 9. FAIL-FIRST LINEAGE (Phase 1C proofs, re-executed here)

| Test | Phase 1C red → green | Phase 1D |
|------|----------------------|----------|
| `RateLimitResponseTest` | 5 failed → 8 passed | pass (SQLite + PG) |
| `HttpExceptionResponseShapeTest` | 1 failed / 3 passed → 4 / 4 | pass (SQLite + PG) |
| `EventRoomCapacityResizeTest` | 4 failed → 4 passed (17 assertions) | pass (SQLite + PG) |
| `ResetNotificationEncryptionTest` | R-10 proof | pass (SQLite + PG) |
| `sync.test.ts` (7 new) | red first | pass (15/15 sync tests) |
| `scanQROfflineQueue.test.tsx` (4 new) | red first | pass |

---

## 10. GATE SUMMARY

| Gate | Status |
|------|--------|
| Backend tests (SQLite) | ✅ PASS |
| Backend tests (PostgreSQL 16.15) | ✅ PASS |
| Concurrency (PG-only tests execute) | ✅ PASS (0 skipped on PG) |
| PHPStan level max | ✅ PASS (0) |
| Pint | ✅ PASS |
| Frontend tests | ✅ PASS (83) |
| TypeScript | ✅ PASS |
| ESLint | ✅ PASS |
| Production build (backend, local) | ✅ PASS |
| Production build (frontend) | ✅ PASS |
| Composer audit | ✅ PASS |
| npm audit (≥ high) | ✅ PASS |
| Tenant schema gate | ✅ PASS |
| Tenant data gate | ✅ PASS |

**14 / 14 gates green.**
