# PHASE 0 — EXECUTIVE SUMMARY

**Audit date:** 2026-09-30
**Repository:** `D:\xampp\htdocs\CHproject` · branch `main` · HEAD `bd37cf5` · 144 commits · **0 tags**
**Method:** Inspect → Understand → Consult documentation where needed → Verify against actual code → Cross-reference → Classify → Document.
**Mode:** **DISCOVERY ONLY.** Nothing was fixed, refactored, migrated, or deployed.

---

## SYSTEM STATUS

```
DISCOVERY COMPLETE
```

The repository has been systematically mapped. This is **not** a claim of production readiness, and this document is deliberately written so the unknowns are impossible to miss.

---

## 1. WHAT WE ACTUALLY HAVE

A **multi-tenant church management system** on a real, coherent architecture:

| Layer | Detected (not claimed) |
|---|---|
| Backend | **Laravel v12.69.3**, PHP 8.2/8.3, Sanctum 4.3.2 — 224 routes, 37 controllers, 13 policies, 40 services, 32 models |
| Database | **PostgreSQL** (production) / SQLite (tests) — 108 migrations, 40 tables, 7 composite tenant foreign keys |
| Frontend | **React 19.2.7**, TypeScript 5.9.3, Vite 8.1.0, Tailwind 4.3.1 — 62 pages, 23 API modules, PWA with an offline write queue |
| Infra | Docker + Nginx + Supervisor (worker + scheduler), Railway config, Vercel config, GitHub Actions (3 jobs) |
| Tests | **542 passed / 6 skipped / 3202 assertions** (SQLite) · PHPStan level-max **0 errors** · Pint **passed** · Frontend **72 passed**, tsc clean, eslint clean, i18n parity exact |

**Executed and verified during this audit** — not read from a document:

```
php artisan test                                  542 passed, 6 skipped, 3202 assertions, 69.3s
php artisan test --filter=AttendanceConcurrencyTest  6 skipped (all require PostgreSQL)
vendor/bin/phpstan analyse --level=max           No errors
vendor/bin/pint --test                           passed
php scripts/scan-broken-migration-rollbacks.php  5 findings
php scripts/check-lang-parity.php                PASS  (EN 123 / AR 123)
npm test                                          8 files, 72 tests passed, 14.25s
npx tsc --noEmit                                 clean
npm run lint                                     clean
npm run check:i18n                               PASS
php artisan route:list                           224 routes / 218 API
frontend/dist/sw.js inspected directly           /api appears EXACTLY ONCE, inside a denylist
```

---

## 2. WHAT IS ACTUALLY GOOD — and should not be broken

This is not a courtesy. Each item below was verified against code or a built artefact.

| # | Finding | Evidence |
|---|---|---|
| 1 | **The tenant-isolation architecture is sound.** A real global scope that **fails closed** on both dangerous cases, never infers a tenant from a client header, backed by 7 composite DB foreign keys with **deliberately no `ON UPDATE CASCADE`**. | `ChurchScope.php:16-55`; `2026_09_29_000001`; confirmed against the official PostgreSQL documentation (MATCH SIMPLE, mandatory parent UNIQUE, `ON UPDATE NO ACTION` semantics all match). |
| 2 | **The platform/church authority split is deliberate and regression-protected.** Platform admin has **no** permission rows, so it gets 403 on church endpoints; a test exists specifically to stop anyone "fixing" that into a privilege escalation. | `Permission.php:131-161`; `PlatformAdminAuthorizationMatrixTest:141-146`, `:404` |
| 3 | **The service worker provably cannot cache API responses.** The generated `dist/sw.js` contains `/api` **exactly once** — inside a `NavigationRoute` **denylist**. | Direct inspection of the 9 561-byte built artefact. |
| 4 | **The offline-queue cross-tenant problem is properly solved, not worked around.** Three independent guards: full IndexedDB wipe on every session boundary, a token re-check before **every** send, and per-item credential replay. 40 tests. | `sync.ts:78-109`; 40 passing tests |
| 5 | **Account-enumeration risk is genuinely closed.** Unknown-email and wrong-password are indistinguishable; password-reset, verify-email and resend are asserted **byte-identical** across unknown/verified/unverified. | `ServantLoginLifecycleTest`; `EmailVerificationTokenSecurityTest` |
| 6 | **Email-verification tokens are handled well** — hashed at rest, encrypted in the queued payload, DB-dump-unreplayable, never logged, `failed()` logs metadata only. | 18 tests |
| 7 | **Some tests are exemplary and should be protected from refactoring.** `TenantOwnershipBoundaryTest:565/594` are the only tests that prove a **service** is safe when middleware does not run. `CompositeForeignKeyTest:235` pins that the database must **refuse** to silently re-home a class. `TenantOrphanRepairTest` pins that repair never writes `NULL` over a row's only tenant information. `pwaIsolation.test.ts` asserts against the **generated** artefact and CI builds before testing so it cannot be silently skipped. | read in full |
| 8 | **The PostgreSQL CI job is unusually well designed**, with a comment tying each step to the historical incident that motivated it. | `.github/workflows/ci.yml:52-69` |
| 9 | **`docker-entrypoint.sh` is a strong control** — hard-fails boot on 7 misconfigurations, on an unreachable DB, and on a failed migration. Never prints a secret. | read in full |
| 10 | **`AGENTS.md` and `TENANT_RULES.md` are accurate.** Every rule corresponds to real code. The PostgreSQL constraint analysis in `TENANT_RULES.md` §6 matches the official documentation exactly. | cross-checked |

---

## 3. THE CENTRAL PROBLEM

**The strongest engineering in this repository is uncommitted.**

```
git log -1  →  "WIP: security hardening in progress (see conversation) - NOT production ready"
git status  →  296 modified files (+9 066 / −3 584), 30 untracked paths
git tag     →  (none)
```

Untracked, among others: the composite tenant FK migration, the tenant audit/verify commands, `TenantConsistencyService`, `AssignRequestId` middleware, 12 security test files, `apiUrl.ts`, `vitest.config.ts`, the whole `frontend/src/test/` directory.

**Therefore the 542 green tests describe a working tree on one machine, not any deployable commit.** And the repository contains a documented incident proving the specific danger: a composite-FK migration once used syntax PostgreSQL rejects and SQLite accepts, so `migrate` aborted in production, **none of the tenant constraints were ever created**, and every SQLite test stayed green.

**No PostgreSQL was reachable during this audit** (`docker ps` → daemon not running; no `psql`). So the single most important question — *do the tenant constraints exist in the production database?* — is **NOT VERIFIED**. The tool that answers it (`php artisan tenant:verify-schema`) is excellent and simply was not run.

---

## 4. TOP RISKS

### P0 — CRITICAL
**NONE FOUND.** No cross-tenant data exposure, privilege escalation, authentication bypass, irreversible data corruption, or production-wide outage was identified in the inspected code paths. This does **not** mean the system is secure — it means no P0-class defect was found **in the code read, in the working tree, on SQLite**.

### P1 — HIGH (9)

| ID | Finding | Confidence |
|---|---|---|
| **R-01** | The entire tenant-isolation hardening layer is **uncommitted and untagged**; HEAD says "NOT production ready". | **VERIFIED** |
| **R-02** | Tenant constraints **unverified against a live PostgreSQL**; 6 concurrency tests skipped. This question already caused a shipped incident. | **VERIFIED** (that it is unverified) |
| **R-03** | **Membership-request approval's sole tenant boundary has no test.** `MembershipRequestSubmitTest` contains 1 test. Failure mode is a silent cross-church grant of membership. | **VERIFIED** |
| **R-04** | **No backup configuration, no restore procedure, no restore ever performed.** Every destructive operation assumes recoverability. | **VERIFIED** (absence) |
| **R-05** | Railway's health check probes a **static nginx `return 200`** — PHP/DB/queue/scheduler failure inside the container is invisible. | **VERIFIED** |
| **R-06** | **No CD.** CI gates merges; whether a passing commit is what deploys is external. | **VERIFIED** |
| **R-07** | Offline attendance replay is at-least-once with **no client idempotency key**; a permanently-abandoned write is never surfaced to the user. | **VERIFIED** |
| **R-08** | **5 migrations fail the repository's own rollback lint** — which is a CI gate. | **VERIFIED** — executed |
| **R-09** | No tags, no changelog, no release history; the audit trail is untracked. | **VERIFIED** |

Also P1: membership-approval coverage, event-registration tenant verification, and 5 more in `PHASE_0_RISK_REGISTER.md`.

---

## 5. THE AUDIT DASHBOARD

| Domain | Status | Evidence | Main Gap | Priority |
|---|---|---|---|---|
| **Architecture** | **VERIFIED** | 37 controllers / 40 services / 13 policies / 46 contracts read; layered design confirmed | 8 of 13 policies never invoked; 2 service files are empty stubs; `Api/UserController.php` is a deleted comment | P2 |
| **Authentication** | **VERIFIED** | 45+ tests; Sanctum docs confirm `expiration=1440` is enforced; uniform failure responses; hashed + encrypted tokens | `sanctum:prune-expired` unscheduled; `statefulApi()` inactive so `SANCTUM_STATEFUL_DOMAINS` is inert | P2 |
| **Authorization** | **VERIFIED** | Exact role→permission map read from code; platform/church asymmetry proven by tests | 8 dead policies; `ClassePolicy::reorder` live and divergent (role-only) | P2 |
| **Tenant Isolation** | **VERIFIED** (design) / **NOT VERIFIED** (production) | `ChurchScope` + 7 composite FKs + 27-file security suite; official PostgreSQL docs confirm the semantics | **No live PostgreSQL**; `User` unscoped; 10 tables reach a tenant only via an unconstrained FK chain | **P1** |
| **Platform Admin** | **VERIFIED** | 45 call sites enumerated; dedicated positive + negative matrix tests | `ChurchDeletionPolicy` never invoked; 4 read endpoints have no in-body check | P2 |
| **PostgreSQL** | **NOT VERIFIED** | No engine reachable; `docker ps` failure captured; 6 tests skipped | Version stated 3 different ways (15 / 16 / "18.4") | **P1** |
| **Database Integrity** | **VERIFIED** (source) / **NOT VERIFIED** (live) | 40 tables, 108 migrations read, all constraints enumerated | 0 CHECK constraints; 10 tables tenant-by-chain only; `email_verification` unmasked in `profile_update_requests` | P2 |
| **Migrations** | **VERIFIED** | 108 classified: 66 SAFE / 25 REVIEW / 14 HIGH RISK / 3 UNKNOWN | **5 fail the rollback lint**; same backfill reachable from 2 migrations; 2 share a numeric prefix | **P1** |
| **Services** | **VERIFIED** | 38 real services classified by transaction + authorization posture | 17 with `AUTHZ: NONE`; `AttendanceService` trusts `$recordedBy`; `StageService::create` writes `church_id = NULL` when called unauthenticated | P2 |
| **Transactions** | **VERIFIED** | 12 services transactional, 8 multi-write **non**-transactional | `EventLifecycleService` multi-step with no transaction; `PointService` safe only by caller coincidence | P2 |
| **Email** | **VERIFIED** (code) / **NOT VERIFIED** (delivery) | End-to-end trace: 1 send site → 1 dispatcher → **that dispatcher has no caller** | 4 of 9 notifications never dispatched; default mailer is `log`; only 1 of 9 guarded | P2 |
| **Queue** | **VERIFIED** (config) / **NOT VERIFIED** (running) | `database` driver, `after_commit=true`, 1 job; Redis compiled but not deployed | **Zero queue tests** — no `Queue::fake` anywhere; no `failed_jobs` alerting or pruning | P2 |
| **Resend** | **VERIFIED** (config) / **NOT VERIFIED** (external) | Package, config, mailer and `.env.example` all present; code path inert | SPF/DKIM/DMARC, domain verification, delivery — all external | — |
| **Frontend** | **VERIFIED** | 62 pages, 23 API modules, 4 contexts; 72 tests pass; tsc + eslint clean | **Zero component/routing/guard tests**; token in plaintext `localStorage`; `/structure-management/...` is a dead call | P2/P3 |
| **PWA** | **VERIFIED** | Generated `dist/sw.js` inspected directly: `/api` appears **once**, in a denylist; 2 routes total | None material. Guarded by tests against the artefact, and CI builds first | INFO |
| **Offline Sync** | **VERIFIED** | 40 tests across 3 files; real `fake-indexeddb`; correct terminal-state machine | No client idempotency key; isolation is by session-boundary timing, not data keying | P1/P3 |
| **CI/CD** | **VERIFIED** (CI) / **NOT VERIFIED** (CD) | 1 workflow, 3 jobs, well designed; `composer audit --locked` gate | **No deploy job**; PHPStan/Pint not run on pgsql; `phpunit.postgres.xml` referenced by nothing | **P1** |
| **Infrastructure** | **VERIFIED** (config) / **NOT VERIFIED** (running) | Dockerfiles, compose, supervisord, nginx, railway.json, vercel.json all read | Health check is a static 200; `RUN_MIGRATIONS` on every replica; replica count unknown | **P1** |
| **Observability** | **VERIFIED** | ULID request-id on every request, echoed, in logs and in error bodies; 75 log sites | No shipping, no rotation, no error-reporting SaaS, no metrics; console operations leave **no audit rows** | P2 |
| **Backup/Recovery** | **NOT VERIFIED** | **Nothing exists.** No config, no script, no runbook, no drill | **Everything destructive assumes a restore is possible. It has never been performed.** | **P1** |
| **Performance** | **NOT VERIFIED** | Not measured. No profiling, no `EXPLAIN`, no load test, no query budget | 24 tenant indexes; no index on `users(church_id, class_id)` or `churches.deleted_at` | P3 |
| **Testing** | **VERIFIED** (SQLite) / **PARTIAL** (PostgreSQL) | 542 + 72 executed locally; PHPStan 0; Pint pass | **6 PostgreSQL-only tests skipped**; no queue/email/console tests; no component tests; points writes barely covered | **P1** |

---

## 6. HIGHEST-RISK UNKNOWNS

These are the questions where being wrong does the most damage. **None was answered by this audit.**

| # | Unknown | Why it matters |
|---|---|---|
| **U-1** | **Does the production PostgreSQL actually have the 7 composite tenant FKs?** | If not, the entire tenant guarantee rests on PHP-layer checks alone — and this failure has already shipped once. |
| **U-2** | **Is there a working backup, and has a restore ever succeeded?** | Every destructive operation in the system assumes recovery. |
| **U-3** | **What is actually running in production right now?** | 296 uncommitted files, no tags, no CD. The mapping between "verified" and "shipped" is unknown. |
| **U-4** | **Is the replica count > 1, and is concurrent migration safe?** | Every replica runs `migrate --force` at boot. |
| **U-5** | **Is production `role_permission` seeded as expected?** | If not, authorization silently falls back to hard-coded defaults. |
| **U-6** | **Are the Supabase bucket `public` flags actually applied?** | 4 of 5 are configured public, including the national-ID bucket. |
| **U-7** | **Is any email actually delivered?** | 8 of 9 notification classes are unguarded against the default `log` mailer. |
| **U-8** | **Are the lock files free of advisories?** | `composer audit` and `npm audit` were **not run** (no network). |
| **U-9** | **Is the worker draining, and is anything in `failed_jobs`?** | The health check is a static 200; nothing alerts or prunes. |
| **U-10** | **Does `CORS_ALLOWED_ORIGINS` in production include the real frontend domain?** | A miss means the SPA gets no API at all — a total outage, not a subtle one. |
| **U-11** | **Does production data currently satisfy the tenant constraints?** | `tenant:audit` was not run. |
| **U-12** | **Which Vercel/Railway config is actually live?** | `vercel.json` and the Docker nginx are mutually exclusive; which one runs is external. |

---

## 7. WHAT PHASE 0 DID **NOT** DO

- No application code, route, middleware, policy, service, model, migration or seed was modified.
- No schema change. No migration created or run against any database.
- No permission, role mapping or authorization rule changed.
- No environment variable, `.env`, deployment config or CI workflow modified.
- No dependency installed, updated, downgraded or removed. `composer audit` / `npm audit` **not run**.
- No Docker container started. **No PostgreSQL started or contacted.**
- No production system, Vercel project, Railway service, Supabase project or Resend account accessed.
- No git reset, rebase, stash, force-push or commit. **The working tree is exactly as found.**
- No email sent. No backup taken. **No restore attempted.**
- **No secret value was printed.** Environment files were read with values redacted; only PRESENT/MISSING/REFERENCED status is reported.
- **No bug was fixed.** Every finding is documented and left in place.

The only files created are the nine documents in `docs/audits/`.

---

## 8. RECOMMENDED PHASE 1 — IN ORDER

**Phase 1 is verification-before-remediation.** The single most valuable action available is not a code change; it is answering **U-1** and **U-2**. Both are read-only operations.

### Step 1 — Establish ground truth about production (READ-ONLY, ~1 day)

Do nothing else until this completes. Everything downstream depends on it.

1. Get **read-only** access to the production PostgreSQL. Run:
   - `php artisan db:show` — the real version
   - `php artisan tenant:verify-schema` — **answers U-1.** Does it exit 0?
   - `php artisan tenant:audit` — **answers U-11.** Any violations?
   - `\d+` on `classes`, `users`, `events`, `event_targets`, `qr_invites` — confirm the 7 composite FKs exist, are on the right columns, and have **no** `ON UPDATE CASCADE`
2. Run the suite against that engine: `php artisan test` and `--testsuite=Security`. This answers **U-2**'s sibling — the 6 currently-skipped concurrency tests.
3. **Take a backup and perform an actual restore into a scratch instance.** This is the first time a restore will have ever been done. Record the wall-clock time — you now have a real RPO/RTO instead of an assumption.
4. Record from Railway/Vercel: replica count, the deployed commit SHA, the env-var **name** list (values are not needed for this audit), worker/scheduler log availability, `failed_jobs` count and `max(failed_at)`.
5. Run `composer audit --locked --no-dev` and `npm audit --audit-level=high` with network. **Answers U-8.**

> **Gate:** if `tenant:verify-schema` does not exit 0, **stop**. That is a P0-class finding and every other remediation is downstream of it.

### Step 2 — Make the verified state reproducible (no behaviour change)

6. Commit the 296 modified + 30 untracked files on a branch, with the Phase 0 audit documents alongside. Tag the exact commit that passed CI.
7. Add a **release process**: a tag, a changelog, a version source. Delete the 12 stale root-level audit reports or move them to `docs/audits/archive/` with dates — several claim "PRODUCTION READY" for a tree whose HEAD says otherwise.
8. Add a **CD** step, or document explicitly that deployment is manual and record who runs it.

### Step 3 — Close the verified operational gaps (still no app logic change)

9. Point the Railway health check at `GET /health` (which already returns `503` on a degraded DB) instead of the static `/healthcheck.txt`. **R-05.**
10. Schedule `sanctum:prune-expired`, `queue:prune-failed`, and add `failed_jobs` alerting. **R-44.**
11. Set `LOG_STACK=daily` and ship logs off-container. **R-43.**
12. Fix the 5 broken migration rollbacks. **R-08.** (Add a `NOT NULL`-relevant note: this also unblocks whether CI is currently red.)
13. Make the 5 suppressed Notifications' dispatch explicit or delete them, and decide — deliberately and in writing — whether email is a supported feature. Right now `AGENTS.md`, `README.md`, `.env.example` and the code give four different answers. **R-41, R-42.**

### Step 4 — Close the Category C authorization gaps (the security work)

14. **Add the missing membership-approval tenant test first** (R-03/R-34) — it is cheap, and it either passes (the check is correct) or it is a live cross-church membership grant.
15. Work the Endpoint Matrix §5 **Category C** list in dependency order, starting with the ones on `view_users`-gated endpoints: `/classes/reorder` (R-16), `removeServant` (R-15), `attendances/filtered` `user_id`, `context-details` `servant_id`/`context_id`, `absent-members` `event_id`/`context_id` (R-13).
16. Decide the **sub-resource ownership policy** for `event.scope`, which today reads only `route('id')` (R-12). Options: extend the middleware to verify `busId`/`roomId`/`sessionId`/`speakerId`/`cellId`/`registrationId` against the event, **or** add composite FKs from the `event_*` subtree to `events (church_id, id)` (R-19). The second is the durable one and matches the existing design language; the first is a smaller change with a smaller guarantee.
17. Extend the `TenantArchitectureTest` model provider from 13 to all 15 tenant models (R-23), and the named `Security` testsuite accordingly.
18. Add a **service-level authorization test per mutation service** following the `TenantOwnershipBoundaryTest:565/594` pattern. Only 2 of 38 services have one today; 17 are `AUTHZ: NONE` and 4 are `ASSUMES` — the business core among them.

### Step 5 — Close the data-integrity and schema gaps

19. Add `CHECK` constraints for the 11 enum-like columns (R-22) — a new migration only, no code change.
20. Mask PII in `profile_update_requests.old_values`/`new_values` using the existing `AuditLogValues` cast and `AuditService::maskPii()` (R-21) — the mechanism already exists and is already tested for `audit_logs`.
21. Add the missing indexes: `users (church_id, class_id)`, `churches (deleted_at)` (R-23 in the DB matrix).
22. Resolve the `class_years` question **as a data decision, not a cleanup** — follow `TENANT_RULES.md` §12a's own 3-step sequence. Backfill first, report non-matches, and only then consider dropping the FK.
23. Establish a **policy for dead policies**: either call them or delete them. 8 registered-but-unused policies are a second, divergent authorization model (R-37). `ClassePolicy::reorder` is a live example of the divergence.

### Step 6 — Frontend

24. Add tests for the `AppLayout` role guard and `Sidebar` — the guard is UX-only, so its correctness **as UX** still matters and is currently unverified.
25. Fix the dead call `api/structure.ts:17` → `/structure-management/stages-with-classes` (R-64).
26. Decide whether the report-download links should carry the bearer token rather than relying on credentialed navigation (R-70).
27. Reconcile `useRoleAccess`'s numeric hierarchy with the route guard's flat allowlist, or delete the unused hook (R-63).
28. Consider moving the bearer token out of plaintext `localStorage` — ideally an `HttpOnly` cookie with Sanctum SPA session auth, which would also make the currently-inert `SANCTUM_STATEFUL_DOMAINS` and `supports_credentials` meaningful (R-66, R-58).

### Step 7 — Then, and only then, consider Phase 2 hardening

Only after steps 1–6: performance measurement (`EXPLAIN`, query budget, load test), `profile_update_requests` and `audit_logs` retention, the `github` Actions hardening (pin third-party actions to SHA, add a dependency-review action), and the ~20 undocumented environment variables.

---

## 9. THE ONE-SENTENCE VERSION

> **The engineering quality of the tenant-isolation, authorization, offline-sync and PWA work in this repository is genuinely high, and several of its tests are exemplary — but the strongest of it is uncommitted and untagged, the production database engine was never available to verify any of it, there is no backup and no restore has ever been performed, and the health check cannot see a failure inside the container.**
>
> **Phase 1 should read production and take a backup before it changes a single line of code.**
