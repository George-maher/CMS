# PHASE 1C — BASELINE

**Date:** 2026-10-01
**Scope:** REMEDIATION-ONLY pre-production verification. No deployment, no production mutation.
**Prepared before any code change**, from the actual working tree.

---

## 1. REPOSITORY STATE

| Item | Value |
|---|---|
| Git SHA | `bd37cf5fd510d2ccdeb6d32bb65b179db2181d77` |
| Commit subject | `WIP: security hardening in progress (see conversation) - NOT production ready` |
| Branch | `main` |
| Dirty / uncommitted | **YES** — 298 changed or untracked entries |
| Working-tree diff at baseline | 267 tracked files modified, ~30 untracked paths |

> The repository carried **substantial legitimate uncommitted work** from Phases 0/1/1B
> (tenant hardening, composite FK migration, offline-sync fixes, audit documentation).
> All of it was preserved. Nothing was reset, stashed, reverted or committed during 1C.

---

## 2. RUNTIME AND FRAMEWORK VERSIONS (determined, not assumed)

| Component | Version | How determined |
|---|---|---|
| PHP | **8.2.12** (ZTS, Windows) | `php -v` |
| Laravel Framework | **12.69.3** | `php artisan --version` |
| PostgreSQL (verification engine) | **16.15** (Debian 16.15-1.pgdg13+2) | `php artisan db:show --database=pgsql` |
| Node.js | **24.14.1** | `node -v` |
| npm | **11.11.0** | `npm -v` |
| Docker | **29.6.1** | `docker version` |
| `pdo_pgsql` | loaded | `php -m` |

> **Note on PHP version:** local development is **PHP 8.2**, while CI provisions **PHP 8.3**
> (`.github/workflows/ci.yml`). All 1C verification below was executed on PHP 8.2.12.
> This is recorded because it is a real environmental difference, not papered over.

### Frontend package versions (`frontend/package.json`)

| Package | Declared | Installed (lock) |
|---|---|---|
| react / react-dom | ^19.2.6 | 19.2.6 |
| typescript | ^5.9.3 | 5.9.3 |
| vite | ^8.0.12 | 8.0.12 |
| vitest | ^5.0.2 | 5.0.2 |
| **axios** | **^1.7.9** | **1.20.0** |
| vite-plugin-pwa | ^1.3.0 | 1.3.0 |
| react-router-dom | ^7.5.2 | 7.5.2 |
| eslint | ^10.3.0 | 10.3.0 |

---

## 3. TEST BASELINE (captured before any Phase 1C code change)

| Command | Result |
|---|---|
| `php artisan test` (SQLite) | **542 passed, 6 skipped, 3202 assertions** — 187.79s |
| `composer audit --locked --no-dev` | **No security vulnerability advisories found** |
| `npm audit --audit-level=high` | **found 0 vulnerabilities** — `axios@1.20.0` resolved |
| `npm audit` (all levels) | **found 0 vulnerabilities** |

The 6 skipped tests are `AttendanceConcurrencyTest` — skipped on SQLite by design because
they need a second independent PDO connection for real row-lock proofs.

### Baseline honesty notes

* **PHPStan was not captured before the first edit.** The first run (after 1C's own rate-limit
  change) reported 39 errors, all located on lines 1C had just written. They were fixed and the
  final result is 0 errors.
* **Pint was not captured before the first edit.** Its first run flagged *only* 1C's own new
  scratch/test files, implying every pre-existing file was already clean. Final result: pass.
* **At the time this baseline was captured, Phase 1C had not yet modified the frontend.**
  That changed during the phase: R-08 (`frontend/src/lib/sync.ts`) and R-09
  (`frontend/src/pages/servant/ScanQR.tsx`, `frontend/src/i18n/en.json`, `ar.json`) are
  frontend changes. The frontend baseline (72 tests) and its final result (83 tests) therefore
  differ by 11 tests — all added by 1C. See `PHASE_1C_TEST_RESULTS.md` §4.

---

## 4. KNOWN CONFIRMED DEFECTS CARRIED INTO PHASE 1C

| ID | Severity | Defect | Status at end of 1C |
|---|---|---|---|
| P1-02 | P0 (availability/observability) | Throttled requests return **500** instead of **429** | **FIXED + proven** (see remediation log R-01) |
| U-8 | P2 | `axios` HIGH advisory (1.18.1) | **Already resolved in tree** — verified 1.20.0, audit clean |
| — | P3 | 5 historical migrations with `down()` that cannot undo `up()` **on SQLite** | **DOCUMENTED**, not rewritten |
| — | INFO | CI migration-rollback lint reports findings but **exits 0** (advisory, not a gate) | **DOCUMENTED** |
| Gates A/E–J | P1/P2 | Production ground truth (deployed SHA, backup/restore, worker, scheduler, email) | **OUT OF SCOPE** — requires Railway/Supabase access and 1C forbids touching production |

### Important correction to the Phase 1B record

Phase 1B attributed the 500 to a **named-parameter arity mismatch**
(`fn () => …` invoked as `$responseCallback(request: …, headers: …)`).

**That stated root cause is DISPROVED.** Laravel invokes the callback **positionally**.
An isolated probe on the installed PHP 8.2.12 showed:

```
POSITIONAL OK: ok-429          ← how Laravel actually calls it (ThrottleRequests.php:253)
NAMED THROWN: Error: Unknown named parameter $request   ← how Phase 1B's probe called it
```

The 500 is real and was reproduced, but the mechanism is different (see R-01). Phase 1B's
*symptom* evidence was correct; its *mechanism* was not.

---

## 5. UNRESOLVED RISKS CARRIED INTO / OUT OF PHASE 1C

1. **Production database has never been verified.** The composite tenant FKs are proven on a
   disposable PostgreSQL, not on the production instance. Requires a Railway shell.
2. **Backup / restore, worker, scheduler and email delivery remain UNKNOWN** (Phase 1 Gates
   E, F, G, H, I). 1C cannot close them without touching production.
3. **No outbound mail channel exists** (Resend removed 2026-08-22); notifications are in-app only.
4. **At-least-once email/queue delivery** semantics remain documented residual behaviour.

---

## 6. FINDINGS INTENTIONALLY **NOT** FIXED IN THIS PHASE

| Finding | Reason |
|---|---|
| 5 historical broken `down()` methods | PostgreSQL (production) handles them; rewriting historical migrations changes rollback semantics without necessity (§16) |
| Migration-rollback lint exits 0 in CI | Advisory tooling, not a product defect; changing CI gates is infrastructure change (§16) |
| `axios` declared range `^1.7.9` | Lockfile pins 1.20.0; range already admits the fix. Narrowing it is an unrelated dependency change (§6) |
| Production identity / deployment gates | 1C explicitly forbids deployment and production access (§31) |
| Any architectural refactor | Explicitly prohibited (§1) |

Every such item is recorded in `PHASE_1C_RISK_REGISTER.md` rather than silently dropped.
