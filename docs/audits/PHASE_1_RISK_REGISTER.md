# PHASE 1 — RISK REGISTER

**Audit date:** 2026-09-30
**Every finding is DOCUMENTED, not FIXED.** No production system, configuration, code, schema, or data was modified. All probing was read-only.

**Confidence:** `VERIFIED` · `PARTIALLY VERIFIED` · `NOT VERIFIED` · `UNKNOWN`

---

## SUMMARY

| Severity | Count |
|---|---|
| **P0 — Production blocker** | **1** (P1-01: composite tenant FKs inferred absent — **verification required immediately**) |
| **P1 — Critical** | **8** |
| **P2 — Important** | **9** |
| **P3 — Lower** | **5** |
| **INFO** | **5** |

---

# P0 — PRODUCTION BLOCKER

### P1-01 — 🔴 The database-level tenant invariant is (with high confidence) NOT deployed

| | |
|---|---|
| **Severity** | **P0 — production blocker** |
| **Confidence** | **VERIFIED** that it is absent from `origin/main` · **INFERRED (high confidence)** that it is absent from production · **NOT VERIFIED** against the live catalog |
| **STOP condition** | **STOP-1** (probable) · **STOP-7** (met) |

**Evidence chain — four independent legs:**

1. **The migration is uncommitted.** `git status` → `?? backend/database/migrations/2026_09_29_000001_add_composite_tenant_foreign_keys.php`
2. **It is absent from `origin/main`.** `git cat-file -e origin/main:<path>` fails. Same for `TenantConsistencyService`, `TenantAudit`, `TenantVerifySchema`, `AssignRequestId`, and 12 security tests.
3. **It is absent from the Railway bot branch** `647672f` (2026-07-12, `railway-app[bot]`).
4. **Live corroboration:** production returns **no `X-Request-Id`** on any request, while the working tree's untracked `AssignRequestId` middleware emits one on every request. ⇒ **the deployed build predates the working tree.**

**No alternative mechanism could have created the constraints.** They are defined in that one migration only.

**Impact.** The 7 composite tenant FKs are the **only** control that cannot be bypassed by an application bug. Without them, tenant isolation rests entirely on PHP-layer checks that are individually bypassable by:
- a missed `ScopeResolver` call in a new controller (`User` has no global scope; `canAccessUser` is opt-in)
- a service invoked from a job or console command — `ChurchScope` applies **no filter at all** outside HTTP (Phase 0 R-18)
- any of the **22 `DB::table()` sites** that bypass Eloquent, global scopes and casts
- the already-established pattern of **8 of 13 registered policies being never invoked**

**The repository documents that this exact failure already happened once** (`.github/workflows/ci.yml:52-64`): a composite-FK migration used PostgreSQL-invalid syntax, `migrate` aborted, the constraints were never created, and every SQLite test stayed green. **The fix for that incident is uncommitted.**

**Required to confirm (read-only, 5 minutes):**
```bash
php artisan tenant:verify-schema   # exits non-zero if absent
php artisan tenant:audit
php artisan migrate:status
```

**If confirmed absent: production has no database-level tenant isolation, and the remediation is to deploy the committed migration — after reviewing it, since it is currently unreviewed and untested in CI.**

---

# P1 — CRITICAL

### P1-02 — 🔴 Rate limiting returns 500 instead of 429 on every limiter

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** (defect + enforcement) · **NOT VERIFIED** (exact mechanism) |
| **First seen** | Phase 1 — **not** in Phase 0 |

| Endpoint | Limiter | Before limit | After limit |
|---|---|---|---|
| `POST /api/v1/auth/login` | 5/min | 401 ×5 | **HTTP 500** |
| `GET /api/v1/verses/active` | 60/min | 200 ×59 | **HTTP 500** ×5 |
| `GET /api/v1/qr/validate/{token}` | 10/min | 422 ×10 | **HTTP 500** ×2 |

Production body: `{"success":false,"message":"Internal server error.","code":"INTERNAL_ERROR"}` — no `Retry-After`, no `X-RateLimit-*`, no `request_id`.

**The limit IS still enforced** — subsequent requests returned `200` with `X-RateLimit-Remaining: 59…55`, proving limiter state is intact. **This is not a rate-limit bypass.**

**Root cause:** the working tree's callback returns **429** when invoked directly (verified by booting the real app and calling the real `login` limiter). Production returns 500. ⇒ **divergence between deployed and current code.** An older `bootstrap/app.php` exception path is the likely cause; confirming it needs production logs.

**Impact:** clients treat 5xx as "server broken, retry now" rather than "back off" ⇒ **retry amplification against a limiter correctly rejecting them**. Every rate-limited response is logged at `error` level ⇒ **production 5xx alerting is already firing on normal throttling**, destroying outage signal.

---

### P1-03 — 🔴 The Railway health check cannot detect a database, PHP, worker or scheduler outage

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** — both behaviours directly observed in production |

| Endpoint | Probed by Railway? | Checks DB? | Degraded → non-2xx? |
|---|---|---|---|
| `/healthcheck.txt` | **YES** (`railway.json`) | **No** — static `OK`, 3 bytes | Never — always 200 |
| `/health` | no | **Yes** — real PDO probe | **Yes → 503** |
| `/up` | no | No | No |

**A total database outage leaves Railway reporting the service HEALTHY.** So does a dead PHP-FPM, a dead worker, or a dead scheduler.

`/health` is well designed (fail-closed, leaks nothing) — **it simply is not the probed path.**

---

### P1-04 — 🔴 Production backup and restore: unverified, with destructive capability live

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **NOT VERIFIED** — provider-side, inaccessible |

`RPO = UNKNOWN` · `RTO = UNKNOWN` · **No restore has ever been performed** (as far as any available evidence shows).

The repository contains **no** `pg_dump`/`pgbackrest`/`baccabka`/`wal-g` reference, **no** backup script, **no** backup schedule, **no** restore script, **no** recovery runbook. Supabase PITR status is not observable.

**Live, destructive capability in production:** `POST /platform/churches/{id}/hard-delete` deletes ~25 table trees. `app:reset-data` wipes 19 tables using `session_replication_role = replica`. **No restore has been demonstrated for any of it.**

---

### P1-05 — 🔴 The deployed commit cannot be identified

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** that it is untraceable · **VERIFIED** that the build predates the working tree |
| **STOP condition** | **STOP-7 — MET** |

`origin/main` = `bfb2c61` (2026-09-27, "last"). Local `HEAD` = `bd37cf5` (2026-09-28, **"NOT production ready"**) — 1 commit ahead, **not on the remote**. **0 tags.** 296 modified + 30 untracked files.

The live build lacks `X-Request-Id`, which only exists in the untracked tree ⇒ **production is not running the working tree, and cannot be running `bd37cf5`.**

**What CI proved applies to the working tree, not to any deployable commit.**

---

### P1-06 — 🔴 Four confirmed open bypasses in the deployment chain

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** (from repository) · **NOT VERIFIED** (branch protection) |

1. **Working tree ≠ any commit** — 326 files of uncommitted work
2. **No CD** — `.github/workflows/` contains only `ci.yml`; no deploy job. Deployment is provider-initiated
3. **No release tagging** — nothing identifies what was verified
4. **Migrations run at container start** — `migrate --force`, `RUN_MIGRATIONS` defaults `true`; Compose de-conflicts this, **Railway does not**; replica count unknown

**Branch protection / required checks: NOT VERIFIED** (no `gh` CLI, no GitHub API access).

**Related:** `scan-broken-migration-rollbacks.php` is a CI step and **currently reports 5 findings** (executed in Phase 0). If it exits non-zero, **`main` is not passing CI — and `main` is the likely deploy source.** NOT VERIFIED — one GitHub Actions page.

---

### P1-07 — 🔴 No request-ID correlation, no log shipping, no alerting

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **VERIFIED** (no `X-Request-Id` in production) · **NOT VERIFIED** (log destination) |

`AssignRequestId` is untracked ⇒ **production has no request correlation.** Live response headers contain only Railway's `x-railway-request-id` and `x-hikari-trace` — infrastructure-level, not application-level.

No error-reporting integration, no log shipper, no metrics, no alerting configuration exists in the repository. `LOG_STACK=single` is unbounded and container-local.

**Combined with P1-03 and P1-02, production currently has no mechanism by which an outage, a queue failure, or a real 5xx would be distinguished from routine throttling.**

---

### P1-08 — 🔴 Worker, scheduler and `failed_jobs` are entirely unobservable

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **NOT VERIFIED** — no container access |

Both are Supervisor children of the web container. The only external endpoint is a static nginx string. **No external signal of queue or scheduler health exists.**

`failed_jobs` count, age, and contents: **NOT VERIFIED**. No `queue:prune-failed`, no `sanctum:prune-expired` scheduled — so both tables grow unbounded.

**Partial mitigation:** the queue's only job (`SendEmailJob`) is dispatched only by `EmailService`, which has no production caller ⇒ **the queue is probably idle.** That is an inference from code, not an observation.

---

### P1-09 — 🔴 National-ID documents: `ids` bucket configured `public => true`, exposure unverified

| | |
|---|---|
| **Severity** | **P1** |
| **Confidence** | **NOT VERIFIED** — no Supabase access |
| **STOP condition** | **STOP-4 — NOT DETERMINABLE** |

`config/supabase-storage.php` sets **4 of 5 buckets `public => true`**, including **`ids`**, which holds church-application **national ID and church-permission document scans**.

`SupabaseStorageService` uses the **service-role key** and returns plain URLs. If the bucket is public, those URLs are world-readable. No signed-URL usage was found. `StorageEndpointAuthorizationTest` covers *deletion* authorization only, not *read* exposure.

**I could neither confirm nor refute this. It is listed here because the repository's own configuration says the exposure should exist and nothing in the codebase compensates for it.**

**Required:** a read-only bucket listing with visibility flags.

---

# P2 — IMPORTANT

| ID | Finding | Confidence | Evidence |
|---|---|---|---|
| **P2-01** | **Three of four documented production hostnames are wrong or dead.** `churchmanager.app` and `api.churchmanager.app` are `NXDOMAIN`; `cms-production-7eb4.up.railway.app` 404s on every path with `x-railway-fallback`. `.env.example` ships the non-resolving pair as if they were production URLs. | **VERIFIED** | DNS + live probes |
| **P2-02** | **`offlineReplayUrl.test.ts:40` asserts against the dead `7eb4` host.** The test only asserts URL construction, not reachability, so it passes. | **VERIFIED** | file + probe |
| **P2-03** | **Public church street addresses confirmed live.** `/churches/active` returns real addresses for 2 churches. Design decision now operating on real data. | **VERIFIED** | live probe |
| **P2-04** | **Multi-replica concurrent-migration risk unresolved.** If Railway runs >1 replica, several containers run `migrate --force` simultaneously. | **NOT VERIFIED** | entrypoint + Compose comparison; replica count unknown |
| **P2-05** | **`withoutOverlapping()` is ineffective across replicas** with `CACHE_STORE=file` (per-container). Scheduled cleanup would run once per replica. | **PARTIALLY VERIFIED** | config verified; replica count unknown |
| **P2-06** | **`TrackActivity` idle-timeout state is per-container.** With >1 replica, idle revocation is unreliable — a user may never be logged out, or may be logged out early. | **PARTIALLY VERIFIED** | `TrackActivity.php:19-33`; `CACHE_STORE=file` |
| **P2-07** | **Production email is non-functional by construction.** One send site → one dispatcher → **no production caller**; 4 of 9 notifications never dispatched; default mailer `log`; guard covers 1 of 9 and is never enforced. | **VERIFIED** (code) · **NOT VERIFIED** (production `MAIL_MAILER`) | Phase 0 trace |
| **P2-08** | **Storage backup is not evidenced** and does not follow from a database restore — observers delete files outside the DB transaction, so DB and storage can diverge irreconcilably. | **NOT VERIFIED** | `ChurchApplicationObserver` etc. |
| **P2-09** | **Dependency security not verified.** `composer audit --locked --no-dev` and `npm audit --audit-level=high` are CI steps but were **not executed** in this audit. | **NOT VERIFIED** | not run |

---

# P3 — LOWER

| ID | Finding | Confidence |
|---|---|---|
| **P3-01** | Frontend `manifest.webmanifest` is linked **twice** in the deployed `index.html` (once in `<head>`, once before `</head>`). Cosmetic; browsers use the first. | **VERIFIED** |
| **P3-02** | `Access-Control-Allow-Credentials: true` is currently inert (bearer-token auth, `statefulApi()` not registered). Harmless, but unnecessary surface if cookie auth is ever enabled. | **VERIFIED** |
| **P3-03** | Deployed `sw.js` is 9 561 bytes vs local 9 559. 2-byte difference, cause not established; `/api` isolation property identical. | **VERIFIED** |
| **P3-04** | Vercel SPA fallback returns `index.html` with `Content-Type: text/html` for a non-existent hashed asset. Correct `rewrites` behaviour — noted so it is not misread as caching. | **VERIFIED** |
| **P3-05** | `GET /up` renders Laravel's default HTML health page, which leaks the framework version surface and is not machine-readable. The correct endpoint is `/health`. | **VERIFIED** |

---

# INFO

| ID | Observation | Confidence |
|---|---|---|
| **I-01** | **A real, working, multi-tenant production system exists** — `/health` 200 with `database: connected`, Arabic content served correctly, protected routes correctly 401, 2 real churches. | **VERIFIED** |
| **I-02** | **CORS is correctly configured in production.** Live frontend origin allowed; `https://evil.example.com` **not** reflected. **U-10 = PASS.** | **VERIFIED** |
| **I-03** | **The service worker in production cannot cache `/api/`.** Deployed `sw.js`: `/api` appears once, in a denylist. **Phase 0 R-73 confirmed in production.** | **VERIFIED** |
| **I-04** | **Security headers match `vercel.json` exactly** on the live frontend; `X-Powered-By` absent; TLS valid (86 / 58 days); HSTS with `includeSubDomains`; HTTP→HTTPS 301. | **VERIFIED** |
| **I-05** | **Rate-limit enforcement works.** The defect is the status code, not the limit. No brute-force amplification is possible. | **VERIFIED** |

---

# STOP CONDITIONS

| # | Condition | Status |
|---|---|---|
| **STOP-1** | Composite tenant FKs missing/incorrect in production | **PROBABLY TRIGGERED — NOT VERIFIED.** Migration absent from `origin/main`; inferred absent. |
| **STOP-2** | Cross-church rows in production data | **UNKNOWN** — no data access |
| **STOP-3** | Authorization allows cross-church access | **UNKNOWN** — no session |
| **STOP-4** | Sensitive documents publicly readable | **NOT DETERMINABLE** — `ids` bucket configured public; unverified (P1-09) |
| **STOP-5** | No recovery + imminent destructive op | **NOT formally triggered** — no backup evidence, but no destructive op observed or scheduled |
| **STOP-6** | Production credential exposed | **NOT TRIGGERED** — no secret found in tracked files; `RESEND_API_KEY`/`SUPABASE_*` empty locally |
| **STOP-7** | Unknown/untraceable build; security-critical code unidentifiable | **🔴 TRIGGERED** (P1-05, P1-01) |
| **STOP-8** | Verification action could mutate production | **NOT TRIGGERED** — all probes GET/OPTIONS except `/auth/login` with a reserved `.invalid` address |

**Per §60: `STOP → DOCUMENT → ESCALATE → DO NOT PATCH`.** Nothing was patched.

---

# HIGHEST-RISK UNKNOWNS REMAINING

Ordered by damage-if-wrong. Each is closable by a **read-only** action.

| # | Unknown | Closes with | Time |
|---|---|---|---|
| 1 | **Do the composite tenant FKs exist in production?** | `php artisan tenant:verify-schema` | 5 min |
| 2 | **Does production data satisfy tenant invariants?** | `php artisan tenant:audit` | 5 min |
| 3 | **What is the deployed SHA?** | Railway → Deployments | 5 min |
| 4 | **Is there a working backup, and has a restore ever succeeded?** | Supabase/ Railway backup config; then a real restore drill | hours |
| 5 | **Are national-ID documents publicly readable?** | Bucket listing with visibility flags | 10 min |
| 6 | **Is `main` currently passing CI?** | GitHub Actions page | 2 min |
| 7 | **What is the replica count?** | Railway → service settings | 1 min |
| 8 | **Is `MAIL_MAILER` set in production?** | Railway env var list | 2 min |
| 9 | **Is `role_permission` seeded?** | `SELECT count(*) FROM role_permission` | 5 min |
| 10 | **What is in `failed_jobs`?** | `php artisan queue:failed` | 2 min |
| 11 | **Do the lock files carry high-severity advisories?** | `composer audit --locked --no-dev` + `npm audit` | 5 min |
| 12 | **Is branch protection enabled on `main`?** | GitHub → Settings → Branches | 5 min |

**Nine of the twelve close in under an hour with read-only access to a Railway shell and two dashboards.**

---

# WHAT PHASE 1 DID **NOT** DO

- No production data created, modified, or deleted. No user, church, class, attendance, event, or membership record touched.
- **No authenticated request** to any endpoint. No credential created, guessed, or used.
- No email sent. No queue job injected, retried, or purged.
- No migration run. No `migrate:fresh`, no `migrate:rollback`, no destructive Artisan command.
- No backup taken, no restore performed, no `pg_dump`.
- No provider dashboard accessed. No configuration changed on Railway, Vercel, Supabase, Resend, or GitHub.
- No DNS modified. No certificate touched. No secret printed.
- **No fix applied.** Every finding is documented and left in place.

**The only POSTs issued were to `POST /api/v1/auth/login` with a reserved RFC-2606 `.invalid` address, purely to trip the rate limiter. No real mailbox could receive those.**

**The only files created are the 11 documents in `docs/audits/`.**
