# PHASE 1 — EXECUTIVE SUMMARY

**Audit date:** 2026-09-30
**Target:** live production of the Church Management System
**Method:** read-only external probing, DNS/TLS inspection, git remote inspection.
**Mode:** **VERIFY → OBSERVE → RECORD → CLASSIFY → GATE.** No remediation was applied.

---

## 1. PHASE 1 STATUS

```
PARTIALLY COMPLETE
```

**A live production environment was found and probed.** It is real, it is serving real multi-tenant data, and it is reachable. That turned several Phase 0 "NOT VERIFIED" items into facts — and surfaced **one P0 and three new P1 findings that Phase 0 could not see.**

**What could not be done:** there is **no `psql`, no `railway` CLI, no `gh` CLI, no `supabase` CLI, and no production credential in this environment**, and the Docker daemon is not running (so not even a scratch PostgreSQL could be started). Every gate requiring a database, a provider dashboard, or an authenticated session remains open.

---

## 2. PRODUCTION IDENTITY

```
Backend:   https://cms-production-dafb.up.railway.app   [VERIFIED LIVE, 200]
Frontend:  https://cms-flame-eta.vercel.app             [VERIFIED LIVE, 200, region fra1]
Database:  reachable, "connected"                       [VERIFIED REACHABLE]
           version / schema / constraints               [NOT VERIFIED]
Deployed SHA:                                            [NOT ESTABLISHED]
```

**The live backend is `…dafb…`, not the `…7eb4…` hostname the repository documents.** `…7eb4…` resolves but 404s on every path with the header `x-railway-fallback` — Railway's response for a domain with no service attached. **It is dead.**

**Three of the four hostnames documented in the repository are wrong or non-existent** — including both domains in `.env.example`.

---

## 3. HARD GATES

| Gate | Result |
|---|---|
| **A — Production Identity** | **🔴 FAIL** — deployed SHA unestablished; live evidence proves the build predates the working tree |
| **B — PostgreSQL Connectivity** | **🟡 UNKNOWN** — DB is connected (verified) but no client, no version, no schema access |
| **C — Tenant Schema** | **🔴 UNKNOWN — strong evidence it is NOT in the deployed build** |
| **D — Tenant Data** | **🟡 UNKNOWN** — `tenant:audit` is absent from the deployed build; no data access |
| **E — Backup** | **🟡 UNKNOWN** — no evidence obtainable; no mechanism in the repository |
| **F — Restore** | **🔴 UNKNOWN** — never performed; **RPO/RTO = UNKNOWN** |
| **G — Worker** | **🟡 UNKNOWN** — no external signal exists |
| **H — Scheduler** | **🟡 UNKNOWN** — no external signal exists |
| **I — Email** | **🟡 UNKNOWN** — code path verified as largely dead; delivery unverified |
| **J — Deployment Identity** | **🔴 FAIL** — same as Gate A |

**4 gates FAIL. 6 are UNKNOWN. 0 PASS.**

**The two FAIL gates are the same root cause: nobody can say what code is running.**

---

## 4. 🔴 P0 FINDING

### P1-01 — The database-level tenant invariant is, with high confidence, NOT deployed

Four independent evidence legs:

1. The composite-FK migration is **uncommitted** (`git status`)
2. It is **absent from `origin/main`** (`git cat-file -e`) — as are `TenantConsistencyService`, `TenantAudit`, `TenantVerifySchema`, `AssignRequestId` and 12 security tests
3. It is **absent from the Railway bot branch** `647672f`
4. **Live corroboration:** production returns **no `X-Request-Id`**, which only the untracked `AssignRequestId` middleware produces

**The 7 composite tenant foreign keys are the only control that cannot be bypassed by an application bug.** Without them, isolation rests entirely on PHP-layer checks that are individually bypassable by a missed `ScopeResolver` call, a service invoked from a job or console command (`ChurchScope` applies **no filter at all** outside HTTP), or any of the **22 `DB::table()` sites** that bypass Eloquent entirely.

**The repository documents that this exact failure already happened once** — a composite-FK migration used PostgreSQL-invalid syntax, `migrate` aborted, the constraints were never created, and every SQLite test stayed green. **The fix for that incident is uncommitted.**

**Confidence:** VERIFIED that it is absent from `origin/main` · INFERRED (high) that it is absent from production · **NOT VERIFIED** against the live catalog.

**Confirm in 5 minutes, read-only:** `php artisan tenant:verify-schema`

---

## 5. P1 FINDINGS

| ID | Finding | Confidence |
|---|---|---|
| **P1-01** | 🔴 Composite tenant FKs inferred **absent from production** | VERIFIED (code) / INFERRED (prod) |
| **P1-02** | 🔴 **NEW — rate limiting returns 500, not 429, on every limiter** (3 endpoints). Limit still enforced. | **VERIFIED** |
| **P1-03** | 🔴 Railway health check is a **static nginx 200** — cannot see DB, PHP, worker or scheduler failure | **VERIFIED** |
| **P1-04** | 🔴 Backup/restore unverified; **RPO/RTO UNKNOWN**; church hard-delete is live | **NOT VERIFIED** |
| **P1-05** | 🔴 Deployed commit **cannot be identified** — **STOP-7 MET** | **VERIFIED** |
| **P1-06** | 🔴 Four open deploy-chain bypasses; `main` may not be passing CI | **VERIFIED** / **NOT VERIFIED** |
| **P1-07** | 🔴 **No request correlation, no log shipping, no alerting** | **VERIFIED** |
| **P1-08** | 🔴 Worker / scheduler / `failed_jobs` entirely unobservable | **NOT VERIFIED** |
| **P1-09** | 🔴 `ids` storage bucket configured **public**; national-ID exposure undetermined — **STOP-4** | **NOT VERIFIED** |

---

## 6. P2 FINDINGS

| ID | Finding | Confidence |
|---|---|---|
| P2-01 | **3 of 4 documented hostnames wrong or dead**, including both in `.env.example` | VERIFIED |
| P2-02 | `offlineReplayUrl.test.ts:40` asserts against the **dead** `7eb4` host | VERIFIED |
| P2-03 | Public church **street addresses confirmed live** | VERIFIED |
| P2-04 | Multi-replica **concurrent migration** risk unresolved | NOT VERIFIED |
| P2-05 | `withoutOverlapping()` ineffective across replicas with `CACHE_STORE=file` | PARTIALLY VERIFIED |
| P2-06 | `TrackActivity` idle-timeout state is **per-container** | PARTIALLY VERIFIED |
| P2-07 | Email **non-functional by construction**; production `MAIL_MAILER` unknown | VERIFIED (code) |
| P2-08 | Storage backup not evidenced; does not follow from a DB restore | NOT VERIFIED |
| P2-09 | Dependency security **not verified** — audits not executed | NOT VERIFIED |

---

## 7. P3 FINDINGS

P3-01 manifest linked twice in deployed HTML · P3-02 `Allow-Credentials` inert · P3-03 deployed `sw.js` 2 bytes differs from local (property identical) · P3-04 SPA fallback serves `index.html` for missing assets (correct) · P3-05 `/up` leaks the default Laravel health page.

---

## 8. WHAT IS GENUINELY GOOD — verified live

| # | Finding | Evidence |
|---|---|---|
| 1 | **A real production system is working.** `/health` 200 with `database: connected`; Arabic content served correctly; protected routes correctly 401; 2 real churches. | live probes |
| 2 | **CORS is correctly configured.** Live frontend origin allowed; `evil.example.com` **not** reflected. | preflight probes |
| 3 | **The service worker cannot cache `/api/`.** Deployed `sw.js` has `/api` **once**, in a denylist. **Phase 0 R-73 confirmed in production.** | deployed artifact |
| 4 | **Security headers match `vercel.json` exactly**; `X-Powered-By` absent; TLS valid (86/58 days); HSTS correct; HTTP→HTTPS 301. | live responses |
| 5 | **Rate-limit enforcement works.** The defect is the status code, not the limit. No brute-force amplification possible. | `X-RateLimit-Remaining` decrementing |

---

## 9. CONFIRMED PHASE 0 RISKS

| Phase 0 | Phase 1 result | Evidence |
|---|---|---|
| **R-01** uncommitted hardening (P1) | **CONFIRMED — WORSE** | 296 modified + 30 untracked; `origin/main` has **none** of it; live build predates the tree |
| **R-02** tenant constraints unverified (P1) | **CONFIRMED — WORSE** | evidence now points to **absent from production**, not merely unverified |
| **R-05** health check is a static nginx 200 (P1) | **CONFIRMED IN PRODUCTION** | both endpoints observed live |
| **R-06** no CD (P1) | **CONFIRMED** | only `ci.yml`; Railway bot branch proves provider-initiated deploys |
| **R-04** no backup / no restore (P1) | **CONFIRMED** | no mechanism in repo; RPO/RTO UNKNOWN |
| **R-08** 5 migrations fail the rollback lint (P1) | **CONFIRMED** | re-executed; **may mean `main` is not passing CI** |
| **R-44** `sanctum:prune-expired` unscheduled (P2) | **CONFIRMED** | verified against the real auth model |
| **R-55** church addresses public (P3) | **CONFIRMED LIVE** | 2 real addresses returned |
| **R-73** SW cannot cache `/api/` (INFO) | **CONFIRMED IN PRODUCTION** | deployed artifact inspected |
| **U-4** replica count (unknown) | **STILL UNKNOWN** | — |
| **U-6** bucket visibility | **STILL UNKNOWN** | — |
| **U-7** email delivery | **STILL UNKNOWN** | — |

---

## 10. DISPROVED / CORRECTED PHASE 0 RISKS

| Phase 0 | Phase 1 result |
|---|---|
| **U-2** "backups may exist at the provider level" | **NOT disproven** — still unverifiable. But the *repository* contains no mechanism, confirmed. |
| **R-03** (CORS config risk, implicit) | **DISPROVED as a live risk** — CORS is correct in production. |
| Rate-limit bypass (not raised in Phase 0) | **DISPROVED** — enforcement verified working; the defect is the status code only. |
| `x-railway-fallback` on `7eb4` (not known in Phase 0) | **NEW** — proves the documented host is unattached, not merely "unreachable". |

---

## 11. NEW FINDINGS (not in Phase 0)

| ID | Finding | Severity |
|---|---|---|
| **P1-02** | Rate limiting returns **500 instead of 429** on every limiter, production-wide | **P1** |
| **P2-01** | The documented production host is a **dead Railway fallback domain**; the live one is a different hostname | P2 |
| **P2-02** | A test asserts against the **dead** hostname | P2 |
| **P2-03** | Public church addresses confirmed serving **real** data | P2 |
| **I-02** | CORS **correct in production** (positive) | INFO |

---

## 12. THE TWELVE UNKNOWNS

| # | Unknown | Answer | Confidence |
|---|---|---|---|
| **U-1** | Do the 7 composite tenant FKs exist in production? | **Probably NOT.** Migration absent from `origin/main`; live build predates the tree. | **NOT VERIFIED** (inferred NO) |
| **U-2** | Is there a working backup; has a restore succeeded? | **No evidence obtainable. No restore ever performed. RPO/RTO UNKNOWN.** | **NOT VERIFIED** |
| **U-3** | What commit is running? | **UNKNOWN.** A live build exists but its identity is unestablished. | **NOT VERIFIED** |
| **U-4** | Replica/concurrency topology? | **UNKNOWN.** Determines concurrent-migration and cache-coherence risk. | **NOT VERIFIED** |
| **U-5** | Is `role_permission` seeded? | **UNKNOWN.** Fallback to hard-coded defaults if empty. | **NOT VERIFIED** |
| **U-6** | Are Supabase buckets private? | **UNKNOWN.** Config says 4 of 5 public, incl. `ids`. | **NOT VERIFIED** |
| **U-7** | Is email delivered? | **UNKNOWN.** Send path verified largely dead. | **NOT VERIFIED** |
| **U-8** | Lock files free of high-severity advisories? | **UNKNOWN** — audits not executed (no network). | **NOT VERIFIED** |
| **U-9** | Is the worker draining; `failed_jobs` state? | **UNKNOWN** — no external signal. | **NOT VERIFIED** |
| **U-10** | Does CORS include the real frontend? | **YES — VERIFIED. Correctly configured; untrusted origins rejected.** | ✅ **VERIFIED** |
| **U-11** | Does production data satisfy tenant constraints? | **UNKNOWN.** No data access. | **NOT VERIFIED** |
| **U-12** | Which deployment config is live? | **Vercel + Railway.** The Docker/nginx frontend path is **not** in production. | **VERIFIED** |

**1 of 12 answered. 1 partially. 10 remain open — and 9 of those close in under an hour with read-only access.**

---

## 13. BACKUP / RESTORE RESULT

```
BACKUP   = NOT VERIFIED — NO ACCESS, NO MECHANISM IN REPOSITORY
RESTORE  = NOT PERFORMED
RPO      = UNKNOWN
RTO      = UNKNOWN
```

No `pg_dump`/`pgbackrest`/`baccabka`/`wal-g` reference, no backup script, no backup schedule, no restore script, no recovery runbook exists in the repository. `app:clean-audit-logs` archives one table to container-local disk and is **not** a backup.

**No claim of disaster-recovery readiness is made.**

---

## 14. POSTGRESQL RESULT

```
GATE B — UNKNOWN        GATE C — UNKNOWN (inferred FAIL)        GATE D — UNKNOWN
```

| Item | Result |
|---|---|
| **Version** | **NOT VERIFIED** |
| **Migration state** | **NOT VERIFIED** |
| **7 composite FKs** | **NOT VERIFIED** — inferred absent |
| **`ON UPDATE CASCADE` escape** | **NOT VERIFIED** |
| **`convalidated`** (not `NOT VALID`) | **NOT VERIFIED** |
| **Tenant audit** | **NOT VERIFIED** — command absent from the deployed build |
| **Concurrency** | **NOT VERIFIED** — 6 PostgreSQL-only tests remain skipped; no engine available |

**What is verified:** the database is **connected** and serving real data (2 `churches` rows, 1 `daily_verses` row).

**The read-only queries and the four safe commands that would close this are prepared in `PHASE_1_POSTGRESQL_VERIFICATION.md`.**

---

## 15. INFRASTRUCTURE RESULT

| Component | Result |
|---|---|
| **Railway** | Live and serving. Deployed SHA, replica count, env vars **NOT VERIFIED**. Health check is a static nginx 200. |
| **Vercel** | Live, `fra1`, config matches `vercel.json` **exactly**. PWA assets deployed. |
| **PostgreSQL** | Connected; everything else unverified. |
| **Resend** | Package + config present; send path largely dead. Delivery unverified. |
| **Storage** | 4 of 5 buckets configured public, incl. `ids`. Actual state unverified. |
| **DNS** | 2 live hosts, 1 dead (`7eb4`), 2 non-existent (`churchmanager.app`, `api.…`). |
| **TLS** | **Healthy** — Let's Encrypt, 86 and 58 days remaining, HSTS with `includeSubDomains`, HTTP→HTTPS 301. |
| **CORS** | **Correct.** |

---

## 16. QUEUE RESULT

| Item | Result |
|---|---|
| **Worker running** | **NOT VERIFIED** — Supervisor child, no external signal |
| **Scheduler running** | **NOT VERIFIED** — same |
| **`failed_jobs`** | **NOT VERIFIED** — count, age, contents all unknown |
| **Queue draining** | **NOT VERIFIED** |
| Configuration | `database` driver, `after_commit=true` ✅, 1 job, no `failed()` alerting |
| Missing schedules | `sanctum:prune-expired`, `queue:prune-failed` — both unbounded growth |
| **Operational impact** | **P1-02** — rate limiting returns 500, so 5xx alerting is already firing on normal throttling |

**Partial mitigation:** the queue's only job is dispatched by a service with **no production caller**, so the queue is probably idle. That is an inference from code, not an observation.

---

## 17. DEPLOYMENT RESULT

```
Deployed SHA:  NOT ESTABLISHED
origin/main:   bfb2c61  (2026-09-27, "last")
local HEAD:    bd37cf5  (2026-09-28, "NOT production ready") — NOT on the remote
last tag:      NONE
working tree:  296 modified + 30 untracked
```

- **Git-driven deployment confirmed** — a `railway-app[bot]` branch exists from 2026-07-12
- **`origin/main` contains none of the tenant-isolation hardening**
- **Live build demonstrably predates the working tree** (no `X-Request-Id`)
- **Four open deploy-chain bypasses:** uncommitted tree · no CD linkage · no tags · migrations at container start
- **Branch protection: NOT VERIFIED**

---

## 18. DEPENDENCY SECURITY RESULT

```
composer audit --locked --no-dev   NOT EXECUTED
npm audit --audit-level=high       NOT EXECUTED
```

Both are CI steps. Neither was run in this audit. **Current advisory status: NOT VERIFIED.**

---

## 19. REMAINING UNKNOWNS

Everything in the §12 table except U-10 and U-12, plus:

- Whether any role can reach another role's data in production (no authenticated session was created)
- Whether `ChurchScope`'s fail-closed branches behave correctly in production
- Whether production data contains any cross-church row
- Whether the frontend `apiUrl.ts` single-source fix is in the deployed bundle (the live bundle does contain `/api/v1` path building, but the build is untraceable)

**The limiting factor is access, not analysis.** Every remaining question has a prepared read-only procedure.

---

## 20. REQUIRED REMEDIATION — prioritized

### Immediate (read-only, < 1 hour, no code change)

**1. Run the tenant gates from a Railway shell** — closes P0 and Gates C, D:
```bash
php artisan tenant:verify-schema    # 0 = PASS; non-zero = STOP-1 P0 confirmed
php artisan tenant:audit            # read-only; do NOT use --repair
php artisan migrate:status
php artisan db:show
```

**2. Identify the deployed SHA** (Railway → Deployments) — closes Gates A, J.

**3. Check whether `main` is passing CI** (GitHub Actions) — determines whether the deploy candidate is a failing commit.

**4. Check the replica count** (Railway → service settings) — closes U-4 and the concurrent-migration risk.

**5. List Supabase buckets with visibility flags** — closes STOP-4 / P1-09.

**6. Read production env var *names and presence*** (Railway) — especially `MAIL_MAILER`, `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`.

**7. Run the dependency audits** — closes U-8.

**8. Run `composer audit`/`npm audit` and the rollback lint exit-code check.**

### Then (requires authorization — not done in Phase 1)

**9. If Gate C fails — deploy the composite-FK migration.** It is currently uncommitted. **Review it first:** it is 485 lines, has a SQLite table-rebuild path, adds constraints `NOT VALID` when orphans exist, and has never run in CI against PostgreSQL. Its own docblock records that a previous version aborted on PostgreSQL.

**10. Fix the rate-limit 500 (P1-02).** Deploy the current `bootstrap/app.php` exception path, or correct the deployed one. **Verify with a single 6th request to `POST /auth/login`.**

**11. Point `railway.json` `healthcheckPath` at `/health`.** One-line change; `/health` already returns 503 on a degraded database.

**12. Establish and prove a backup.** Take one, restore into a scratch PostgreSQL, measure RTO, and **schedule the drill to recur.**

**13. Commit and tag the hardening**, then wire CI to gate deploys.

**14. Fix the 5 broken migration rollbacks** so CI can pass.

**15. Correct the documented hostnames** in `.env.example` and the stale test/report references.

**16. Schedule `sanctum:prune-expired` and `queue:prune-failed`.**

**17. Deploy `AssignRequestId`** to restore request correlation, and add log shipping.

### Not recommended until the above are done

Performance work, further refactoring, the Category C authorization list, the 8 dead policies, and any feature development. **None of it matters if the running build cannot be identified and the database has no tenant constraint.**

---

## 21. RECOMMENDED PHASE 2

The evidence determines this, and it is **not** a normal hardening phase.

```
NEXT PHASE:

Emergency Tenant Integrity & Deployment Verification Remediation
```

**Scope, in order:**

1. **Resolve the P0.** Run the tenant gates. If the composite FKs are absent, review and deploy that migration as a controlled, verified change — against a **restored copy of production data first**, never blind.
2. **Establish a restore path** before touching any destructive capability. Take a backup, restore it, prove it works, measure RTO.
3. **Make the running build identifiable.** Commit and tag; wire CI to gate deploys; establish branch protection.
4. **Restore observability.** Fix the rate-limit 500; repoint the health check; deploy request correlation; ship logs.
5. **Only then** resume the Phase 0 remediation plan (authorization gaps, dead policies, Category C list, frontend tests).

**Rationale:** Phase 0 found a well-architected system whose safety work is uncommitted. Phase 1 found that **the safety work is also not deployed**, and that a live production system is running a build nobody can identify, with no database-level tenant invariant, a health check that cannot see a database outage, and no demonstrated backup. **Verifying and restoring the tenant invariant is the only work that should precede everything else.**

---

## 22. PRODUCTION READINESS STATEMENT

```
PRODUCTION VERIFICATION INCOMPLETE
```

**4 gates FAILED, 6 UNKNOWN, 0 PASSED. 1 P0, 8 P1.**

**What is verified:** a live, working, multi-tenant production system with correct CORS, correct security headers, healthy TLS, a service worker that cannot cache API responses, working rate-limit enforcement, and correctly 401-ing protected routes.

**What is not verified:** the deployed build's identity, the database version, the migration state, the presence of the 7 composite tenant foreign keys, the integrity of production tenant data, the existence of a backup, the ability to restore, worker and scheduler health, email delivery, storage bucket visibility, and dependency security.

**This document does NOT assert that production is insecure. It asserts that the two questions which matter most — *what code is running* and *is the tenant invariant enforced at the database layer* — cannot currently be answered by anyone with the access described here, and that the available evidence points to the answer being "no".**

**A backup that has never been restored is not verified disaster recovery. A migration that exists only in a working tree is not a constraint. A health check that cannot see a database outage is not monitoring. And a deployment that cannot be named cannot be trusted.**

**Phase 1 succeeded in establishing reality. Reality is: this system is live, and the protections that were built for it are not deployed.**
