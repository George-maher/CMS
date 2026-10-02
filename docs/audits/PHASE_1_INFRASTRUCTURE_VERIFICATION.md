# PHASE 1 — INFRASTRUCTURE VERIFICATION

**Audit date:** 2026-09-30
**Scope:** Railway, Vercel, PostgreSQL, storage, DNS, TLS, CORS, Sanctum, observability

**Method:** read-only HTTPS probes + DNS + TLS inspection + git remote inspection. **No provider dashboard was accessed. No credential was used. No configuration was changed.**

---

## 1. LIVE INFRASTRUCTURE TOPOLOGY (VERIFIED from outside)

```
                    ┌──────────────────────────────────────────────┐
                    │  cms-flame-eta.vercel.app      (VERIFIED 200)│
                    │  Server: Vercel · region fra1 (Frankfurt)    │
                    │  CSP/HSTS/XFO/nosniff all present            │
                    │  /sw.js 200 · /manifest.webmanifest 200      │
                    └────────────────────┬─────────────────────────┘
                                         │ VITE_API_URL baked into bundle
                                         ▼
                    ┌──────────────────────────────────────────────┐
                    │  cms-production-dafb.up.railway.app  (200)   │
                    │  Server: railway-hikari · x-railway-edge     │
                    │  /health → 200 "database":"connected"        │
                    │  /healthcheck.txt → 200 "OK" (static)        │
                    │  CSP default-src 'none' (API profile)        │
                    └────────────────────┬─────────────────────────┘
                                         │  DATABASE_URL (unverified)
                                         ▼
                    ┌──────────────────────────────────────────────┐
                    │  PostgreSQL            NOT VERIFIED          │
                    │  Storage (Supabase)    NOT VERIFIED          │
                    │  Worker / Scheduler    NOT VERIFIED          │
                    │  Resend                NOT VERIFIED          │
                    └──────────────────────────────────────────────┘
```

### 1.1 Dead / stale infrastructure discovered

| Host | State | Evidence |
|---|---|---|
| `cms-production-7eb4.up.railway.app` | **DEAD** | resolves (69.46.46.25) but **404 on every path**; header `x-railway-fallback` = Railway's "no service attached to this domain" response. `/`, `/health`, `/up`, `/healthcheck.txt`, `/api/v1/verses/active` all 404. |
| `churchmanager.app` | **NONEXISTENT** | `NXDOMAIN` |
| `api.churchmanager.app` | **NONEXISTENT** | `NXDOMAIN` |

**`cms-production-7eb4` is the hostname hard-coded in the repository's own test and audit reports** (`offlineReplayUrl.test.ts:40`, `PRODUCTION_READINESS_REPORT.md:89`). Those documents therefore describe a **stale environment**, and that test asserts against a dead URL.

**`.env.example` ships `APP_URL=https://churchmanager.app` and `VITE_API_URL=https://api.churchmanager.app`** — both non-resolving placeholders. **Production reality is `dafb` / `flame-eta`.** An operator configuring from `.env.example` would deploy a broken system.

---

## 2. RAILWAY GROUND TRUTH (§18)

| Item | Status | Value / Evidence |
|---|---|---|
| Project | **NOT VERIFIED** | no dashboard access |
| Service | **PARTIALLY VERIFIED** | responds on `*.up.railway.app`; `x-hikari-trace`, `x-railway-edge` present |
| Environment | **NOT VERIFIED** | domain contains `cms-production`, strongly implying "production" |
| **Deployed SHA** | **NOT VERIFIED** | 🔴 see §2.1 |
| Deployment timestamp | **NOT VERIFIED** | — |
| Runtime | **VERIFIED (inferred)** | nginx + PHP-FPM; `Server: railway-hikari`; production CSP active |
| **Replica count** | **NOT VERIFIED** | ⚠️ **critical unknown** for concurrent-migration risk |
| Region | **NOT VERIFIED** for backend (frontend is `fra1`) | `x-railway-edge` value differs from Vercel's |
| **Health-check path** | **VERIFIED as configured** | `railway.json` → `/healthcheck.txt` |
| **Health-check response** | **VERIFIED** | `HTTP 200`, body `OK` (3 bytes) |
| Start command | **VERIFIED (from config)** | `supervisord -c /etc/supervisor/supervisord.conf` |
| **Worker process** | **NOT VERIFIED** | Supervisor child, not externally observable |
| **Scheduler process** | **NOT VERIFIED** | Supervisor child |
| Container status | **INFERRED healthy** | serving traffic |
| Restart history | **NOT VERIFIED** | — |
| Deployment history | **PARTIALLY VERIFIED** | a `railway-app[bot]` branch exists from 2026-07-12 |
| **Env var names** | **NOT VERIFIED** | no dashboard access |
| Database attachment | **INFERRED yes** | `/health` reports `connected` |
| Worker/scheduler as separate services? | **VERIFIED (from config)** | **Supervisor children of the same container**, not separate Railway services |

### 2.1 🔴 The deployed commit cannot be identified

| Reference | SHA | Date |
|---|---|---|
| `origin/main` | `bfb2c61` | 2026-09-27 |
| `origin/railway/code-change-U-q8O3` | `647672f` | 2026-07-12 (`railway-app[bot]`) |
| local `HEAD` | `bd37cf5` | 2026-09-28 — *"NOT production ready"* |
| **Deployed** | **UNKNOWN** | — |

**Live corroboration that the deployed build is older than the working tree:** production returns **no `X-Request-Id`** on any request, while the working tree's `AssignRequestId` middleware (untracked) emits one on every request and includes `request_id` in error bodies. Reproduced locally: the working tree returns `"request_id":"01M3SJJMVH6XCCKHQAVV16TYDE"`; production returns no such field.

**`origin/main` contains none of:** the composite-FK migration, `TenantConsistencyService`, `TenantAudit`, `TenantVerifySchema`, `AssignRequestId`, or 12 security tests.

### 2.2 A Railway bot branch exists — deployment is Git-driven

`refs/heads/railway/code-change-U-q8O3`, author `railway-app[bot]`, message *"fix: enable catch_workers_output in php-fpm www.conf"*. This proves Railway's Git integration is configured against this repository. **It does not reveal which branch is currently deployed** — that is dashboard-only.

⚠️ The bot branch is from **2026-07-12**, roughly 2.5 months stale relative to `main` (2026-09-27).

---

## 3. RAILWAY HEALTH CHECK (§19) — 🔴 P1 CONFIRMED IN PRODUCTION

| Endpoint | Status | What it actually verifies |
|---|---|---|
| `GET /health` | **200** `{"status":"healthy","database":"connected"}` | nginx + PHP-FPM + **real PDO connection** |
| `GET /up` | **200** (Laravel default page) | PHP-FPM only |
| `GET /healthcheck.txt` | **200** `OK` | **nginx only — a static string** |

`railway.json` configures `/healthcheck.txt`. The Dockerfile `HEALTHCHECK` also targets it.

### 3.1 The required WHAT IS / IS NOT CHECKED table

| | |
|---|---|
| **✅ CHECKED** | nginx is running and accepting connections; the container is listening on `$PORT` |
| **❌ NOT CHECKED** | PHP-FPM alive · **database reachable** · **queue worker alive** · **scheduler alive** · application bootable · migrations succeeded · disk space · memory |

### 3.2 False-positive / false-negative conditions

| Condition | Health check says | Reality |
|---|---|---|
| **Database completely down** | ✅ **healthy** | 🔴 total outage, undetected |
| **PHP-FPM crashed, nginx up** | ✅ **healthy** | 🔴 total outage, undetected |
| **Queue worker dead** | ✅ **healthy** | 🟠 silent job loss |
| **Scheduler dead** | ✅ **healthy** | 🟠 invites never expire, audit logs never clean |
| **Migrations failed, boot aborted** | n/a | `docker-entrypoint.sh` **fails the boot**, so the container exits — Railway would restart-loop. This is the one failure mode the entrypoint *does* catch. |
| nginx dead | ❌ unhealthy | correct |

**Phase 0 predicted this (R-05). Production probing confirms it exactly.**

**Remediation (NOT applied):** point `railway.json` `healthcheckPath` at `/health`, which already returns **503 on a degraded database** (`routes/web.php:20-21`, fail-closed by design).

---

## 4. APPLICATION HEALTH (§20)

| Item | Value |
|---|---|
| Endpoint | `GET https://cms-production-dafb.up.railway.app/health` |
| **HTTP status** | **200** |
| **Body** | `{"status":"healthy","service":"Church Manager API","version":"1.0.0","database":"connected","timestamp":"2026-09-30T16:24:26.273090Z"}` |
| Database | **`connected`** |
| `request_id` header | **absent** (older build — see §2.1) |
| Timestamp | present, ISO-8601 UTC |
| Sensitive detail exposed | **None** — no DSN, no host, no version, no credentials |

**Assessment: `/health` is well designed.** It probes a real PDO connection, returns 503 when degraded (fail-closed), and leaks nothing. **The only problem is that Railway does not use it.**

### 4.1 Discrepancy documented

| | Probed by platform | Real app health |
|---|---|---|
| Path | `/healthcheck.txt` | `/health` |
| Checks DB? | **No** | **Yes** |
| Degraded → non-2xx? | Never (always `200 OK`) | **Yes (503)** |
| Verdict | **False assurance** | Correct but unused |

---

## 5. VERCEL GROUND TRUTH (§28)

| Item | Status | Value |
|---|---|---|
| Project | **NOT VERIFIED** | no dashboard access |
| Production domain | **VERIFIED** | `cms-flame-eta.vercel.app` |
| Deployed SHA | **NOT VERIFIED** | — |
| Deployment timestamp | **NOT VERIFIED** | — |
| Build command | **VERIFIED (config)** | `npm run build` |
| Output directory | **VERIFIED (config)** | `dist` |
| Framework | **VERIFIED (config)** | `vite` |
| **Live build output** | **VERIFIED** | `index.html` + `assets/index-CfpkSYLS.js` (255 805 B) + modulepreloads `rolldown-runtime`, `i18n`, `vendor`, `ui` |
| `manualChunks` present? | **YES** | vendor/ui/i18n chunks exist — matches `vite.config.ts` |
| Env var names | **NOT VERIFIED** | — |
| **Which deployment path is live** | **VERIFIED** | ✅ **Vercel** — NOT the Docker/nginx frontend path |

### 5.1 Live config matches `vercel.json` exactly

| Header | Live value | `vercel.json` | Match |
|---|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` | same | ✅ |
| `Content-Security-Policy` | `default-src 'self'; script-src 'self'; … frame-ancestors 'self'; upgrade-insecure-requests` | same | ✅ |
| `X-Frame-Options` | `SAMEORIGIN` | same | ✅ |
| `X-Content-Type-Options` | `nosniff` | same | ✅ |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | same | ✅ |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(self)` | same | ✅ |
| `X-Powered-By` | **absent** | (Dockerfile `expose_php=Off`; not a Vercel header) | ✅ |

**The Vercel path is correctly configured, correctly declared, and correctly deployed. No discrepancy.**

### 5.2 SPA fallback behaviour — confirmed working

`GET /assets/Users-Bg-qVCMb.js` (a hashed asset from `FINAL_AUDIT_SUMMARY.md`, from a **different** build) returns **HTTP 200** — but the body is **byte-identical to `index.html`** and `Content-Type: text/html`. That is the `rewrites: /(.*) → /index.html` fallback working as designed for a non-existent asset. Not a defect; noted so it is not misread as "the asset is cached."

### 5.3 PWA assets deployed

| Asset | Status | Size |
|---|---|---|
| `/sw.js` | **200** | 9 561 B |
| `/workbox-dcde9eb3.js` | **200** | 21 434 B |
| `/manifest.webmanifest` | **200** `application/manifest+json` | 1 543 B |
| `/offline.html` | **200** | 1 407 B |

**Service worker is deployed and active.** Deployed `/api` occurrences: **1** — inside a `NavigationRoute` denylist. **Phase 0's P0 finding R-73 is CONFIRMED IN PRODUCTION.**

---

## 6. FRONTEND / BACKEND URL GROUND TRUTH (§29) — 🔴 MISMATCH

| Source | Value |
|---|---|
| **Live bundle** (`index-CfpkSYLS.js`) | **`https://cms-production-dafb.up.railway.app`** ✅ |
| `offlineReplayUrl.test.ts:40` | `https://cms-production-7eb4.up.railway.app` ❌ **DEAD** |
| `PRODUCTION_READINESS_REPORT.md:89` | `https://cms-production-7eb4.up.railway.app` ❌ **DEAD** |
| `.env.example` `VITE_API_URL` | `https://api.churchmanager.app` ❌ **NONEXISTENT** |
| `.env.example` `APP_URL` / `FRONTEND_URL` | `https://churchmanager.app` ❌ **NONEXISTENT** |

**⇒ U-12 ANSWERED: production is Vercel (frontend) + Railway (backend). The Docker/nginx frontend path is not in production. The live frontend is correctly wired to the live backend — but three of the four documented URLs in the repository are wrong or dead.**

---

## 7. CORS VERIFICATION (§30) — ✅ PASS

| Probe | Result |
|---|---|
| Preflight, `Origin: https://cms-flame-eta.vercel.app` | **204** |
| `Access-Control-Allow-Origin` | `https://cms-flame-eta.vercel.app` |
| `Access-Control-Allow-Credentials` | `true` |
| `Access-Control-Allow-Headers` | `authorization,accept,content-type` |
| `Access-Control-Max-Age` | `86400` |
| `Vary` | `Access-Control-Request-Method, Access-Control-Request-Headers` |
| **Preflight, `Origin: https://evil.example.com`** | **204, ACAO = `https://cms-flame-eta.vercel.app`** — **evil origin NOT reflected** ✅ |
| Actual GET response | carries `Access-Control-Allow-Origin: https://cms-flame-eta.vercel.app` |

**The production frontend origin is allowed; untrusted origins are not reflected. CORS is correctly configured in production. U-10 = VERIFIED PASS.**

`allowed_origins_patterns` is `[]` and the list is static — no wildcard. `supports_credentials: true` is currently inert (bearer-token auth; `statefulApi()` not registered) but is not wrong.

---

## 8. SANCTUM VERIFICATION (§31) — BEARER TOKEN, confirmed

| Evidence | Conclusion |
|---|---|
| Protected endpoints return `401 UNAUTHORIZED` with no cookie set | Sanctum guard is enforcing |
| `Access-Control-Allow-Credentials: true` present | Laravel default for this config; **does not prove** SPA cookie auth |
| No `Set-Cookie` observed on API responses | **no cookie session in use** |
| `config/sanctum.php` `expiration => 1440` | token expiry configured (24 h) — **cannot be confirmed without a token** |
| `statefulApi()` **not registered** in `bootstrap/app.php` | cookie/SPA session path is **inactive by design** |
| Live bundle issues `Authorization: Bearer` | client is bearer-token |

**⇒ Production uses Sanctum bearer tokens. `SANCTUM_STATEFUL_DOMAINS` is inert. Confirmed consistent with the code.**

**Not verified:** that a 1440-minute token actually expires in production, and that `sanctum:prune-expired` runs (it is **not scheduled** — Phase 0 R-44).

---

## 9. STORAGE / SUPABASE GROUND TRUTH (§32) — NOT VERIFIED, HIGH-RISK

| Item | Status |
|---|---|
| Actual buckets | **NOT VERIFIED** — no Supabase access, no CLI, no credential |
| **Public/private status** | **NOT VERIFIED in production** |
| Repository config | `config/supabase-storage.php` sets **4 of 5 buckets `public => true`**: `profiles`, `events`, `documents`, **`ids`** — only `attachments` is private |
| Bucket policies | **NOT VERIFIED** |
| Direct public URLs | **NOT VERIFIED** |
| Signed URLs | **NOT VERIFIED** — no evidence the app uses them |
| **National-ID exposure** | **NOT VERIFIED — but plausible** |

⚠️ **STOP-4 status: NOT DETERMINABLE.** The `ids` bucket holds church-application **national ID and church-permission document scans** (per `ChurchApplicationRequest` and `ChurchApplicationObserver`). The repository configures it `public => true`.

**I could not confirm or refute this. It is listed as the highest-priority unverified item in this document because the repository's own configuration says the exposure should exist, and nothing in the codebase suggests object-level authorization compensates for it.**

`SupabaseStorageService` uses the **service-role key** and returns plain URLs. If the bucket is public, those URLs are world-readable. `StorageEndpointAuthorizationTest` covers *deletion* authorization; it does not cover *read* exposure.

---

## 10. REQUEST ID / OBSERVABILITY (§40) — 🔴 NOT DEPLOYED

| Check | Result |
|---|---|
| Request ID generated | **NO** |
| Returned in response headers | **NO** — `X-Request-Id` absent from every response |
| Propagated into logs | **NOT VERIFIABLE** (logs inaccessible) |
| Valid incoming ID preserved | **CANNOT TEST** — no ID is echoed at all |
| Hostile incoming ID rejected | **CANNOT TEST** |
| Correlation across errors | **NOT AVAILABLE** |

**Production has no request correlation.** The `AssignRequestId` middleware is untracked and absent from `origin/main`. This was independently predicted by Phase 0 and confirmed live.

**Consequence:** the only correlation available in production is Railway's `x-railway-request-id` (observed: `bxlX9-7xQQGN_7wtGbGh5g`) and `x-hikari-trace` (`cdg1.8vsn`) — infrastructure-level, **not** application-level. Application logs cannot be correlated to individual requests.

---

## 11. LOGGING GROUND TRUTH (§41) — NOT VERIFIED

| Item | Status |
|---|---|
| Log channel | **NOT VERIFIED** (config says `stack`→`single`) |
| Destination | **NOT VERIFIED** — inside the container, no access |
| Rotation | **NOT VERIFIED** — config says `single`, i.e. unbounded |
| External shipping | **NOT VERIFIED** — no log shipper in config |
| Searchable | **NOT VERIFIED** |
| Alerting | **NOT VERIFIED** |
| **5xx visibility** | ⚠️ **Degraded — see §12** |
| Failed-job visibility | **NOT VERIFIED** |

**One thing is verifiable and it matters:** the rate-limit 500 defect (§12) means production's error signal is **polluted with non-incidents**. Any 5xx-based alerting is already firing on correct throttling behaviour.

---

## 12. 🔴 NEW FINDING — RATE LIMITING RETURNS 500, NOT 429

Full detail in `PHASE_1_PRODUCTION_GROUND_TRUTH.md` §3. Infrastructure-relevant summary:

| Endpoint | Limiter | Before limit | After limit |
|---|---|---|---|
| `POST /api/v1/auth/login` | 5/min | 401 ×5 | **HTTP 500** |
| `GET /api/v1/verses/active` | 60/min | 200 ×59 | **HTTP 500** ×5 |
| `GET /api/v1/qr/validate/{token}` | 10/min | 422 ×10 | **HTTP 500** ×2 |

**The limit IS still enforced** — subsequent requests returned 200 with `X-RateLimit-Remaining: 59…55`, proving the limiter state is intact.

**Root cause narrowed but not confirmed:** the working tree's limiter response callback returns **429** correctly when invoked directly (verified locally). Production returns 500. The deployed build predates the working tree, so an older `bootstrap/app.php` exception path is the likely cause. **Exact mechanism NOT VERIFIED** — requires production log access.

**Severity: P1.** Live, reproducible, affects every limiter, degrades client behaviour and 5xx alerting. **Not a rate-limit bypass.**

---

## 13. MONITORING / ALERTING (§42) — NOT VERIFIED

| Alert | Exists? | Basis |
|---|---|---|
| Application 5xx | **NOT VERIFIED** | no monitoring config in repo; no dashboard access |
| Database unavailable | **NOT VERIFIED / EFFECTIVELY ABSENT** | platform health check is a static nginx 200 |
| Queue backlog | **NOT VERIFIED** | no instrumentation in repo |
| Failed jobs | **NOT VERIFIED** | no `failed_jobs` alerting in repo |
| Worker stopped | **NOT VERIFIED / EFFECTIVELY ABSENT** | Supervisor `autorestart` only; platform check cannot see it |
| Scheduler stopped | **NOT VERIFIED / EFFECTIVELY ABSENT** | same |
| Disk / memory exhaustion | **NOT VERIFIED** | — |
| Deployment failure | **NOT VERIFIED** | — |
| Certificate expiry | **NOT VERIFIED** | certs valid 58/86 days; renewal assumed automated |
| Backup failure | **NOT VERIFIED / NO BACKUP EVIDENCE** | — |
| Suspicious auth events | **NOT VERIFIED** | — |

**No alerting configuration exists anywhere in the repository.** This is consistent with Phase 0's finding that there is no error-reporting integration, no log shipping, and no metrics. **Combined with the static health check, production currently has no mechanism by which an outage is known.**

---

## 14. DOMAIN / SSL (§43) — ✅ VERIFIED HEALTHY

| Item | Value |
|---|---|
| Frontend domain | `cms-flame-eta.vercel.app` |
| Backend domain | `cms-production-dafb.up.railway.app` |
| HTTPS | ✅ both |
| Backend cert issuer | Let's Encrypt `YE2` |
| **Backend cert expiry** | **2026-12-26 — 86 days** |
| Frontend cert expiry | **2026-11-27 — 58 days** |
| **HTTP → HTTPS redirect** | ✅ **301** |
| HSTS | `max-age=31536000; includeSubDomains; preload` on both |
| Mixed content | none observed; `upgrade-insecure-requests` in both CSPs |
| API accessibility | ✅ public endpoints 200; protected 401 |

**TLS is healthy and correctly configured. Both certificates are valid with 2+ months of headroom.**

---

## 15. DNS (§18) — 🔴 THREE DEAD NAMES

| Name | State |
|---|---|
| `cms-production-dafb.up.railway.app` | ✅ 69.46.46.68 — **LIVE** |
| `cms-flame-eta.vercel.app` | ✅ 64.29.17.195, 216.198.79.195 — **LIVE** |
| `cms-production-7eb4.up.railway.app` | ⚠️ 69.46.46.25 resolves, **404 on every path** (`x-railway-fallback`) — **DEAD** |
| `churchmanager.app` | ❌ **NXDOMAIN** |
| `api.churchmanager.app` | ❌ **NXDOMAIN** |

**`.env.example` ships two non-resolving domains as if they were production URLs.** This is a concrete operational trap: a deployment configured from `.env.example` would serve a frontend pointing at a non-existent API and generate CORS/reset links for a non-existent domain.

---

## 16. REPLICA / CONCURRENCY TOPOLOGY (§47) — 🔴 U-4 UNRESOLVED

| Item | Status |
|---|---|
| Application replicas | **NOT VERIFIED** |
| Worker count | **1 Supervisor program** (verified from config) |
| Scheduler count | **1 Supervisor program** |
| DB connection pool | `prefix_indexes => true`, `PGSQL_ATTR_DISABLE_PREPARES => true` (config) |
| **Concurrent deployment** | **NOT VERIFIED** |
| **Multiple containers running migrations** | ⚠️ **UNRESOLVED — see below** |
| **File-based state in multi-replica** | ⚠️ **MATTERS — see below** |

### 16.1 Concurrent migration risk

`docker-entrypoint.sh` runs `php artisan migrate --force` on **every container start** when `RUN_MIGRATIONS` is unset (default `true`). Docker Compose explicitly sets `RUN_MIGRATIONS=false` on `worker` and `scheduler`; **Railway has no equivalent**.

**If Railway runs >1 replica, multiple containers can execute `migrate --force` concurrently.** The replica count is dashboard-only.

### 16.2 `TrackActivity` state is per-container file cache

`TrackActivity` stores last-activity in `Cache` under `user-last-active-{id}`. Phase 0 established `CACHE_STORE` defaults to **`file`** — a container-local store.

**With >1 replica behind Railway's edge router, a user's requests can land on different containers, each with its own activity timestamp. Idle-session revocation becomes unreliable** — a user could stay authenticated indefinitely by bouncing between replicas, or be logged out early.

**Also per-container:** the **log file** (`storage/logs/laravel.log`) — each replica writes its own, and with no shipping there is no aggregate view.

**Both are consequences of the same unknown: the replica count.**

---

## 17. CONFIDENCE SUMMARY

| Claim | Status |
|---|---|
| Production is live and serving real multi-tenant data | **VERIFIED** |
| Frontend is Vercel; backend is Railway; Docker path is not in production | **VERIFIED** |
| Live frontend bundle targets the live Railway backend | **VERIFIED** |
| Three documented URLs are wrong or dead | **VERIFIED** |
| CORS correctly allows production frontend, rejects untrusted origins | **VERIFIED** |
| Security headers match `vercel.json` exactly | **VERIFIED** |
| Service worker deployed and does not cache `/api/` | **VERIFIED** |
| TLS valid, HSTS correct, HTTP→HTTPS enforced | **VERIFIED** |
| Railway health check is a static nginx 200 and cannot see DB/worker/scheduler | **VERIFIED** |
| Auth is bearer-token; `statefulApi()` inactive | **VERIFIED** |
| Production has no request-ID correlation | **VERIFIED** |
| Rate limiting returns 500 instead of 429 | **VERIFIED** (mechanism NOT verified) |
| Deployed build predates the working tree | **VERIFIED** |
| Deployed commit SHA | **NOT VERIFIED** |
| **Replica count** | **NOT VERIFIED** |
| PostgreSQL version / schema / constraints | **NOT VERIFIED** |
| Storage bucket visibility; national-ID exposure | **NOT VERIFIED** |
| Worker / scheduler / queue health | **NOT VERIFIED** |
| Logging, rotation, shipping, alerting | **NOT VERIFIED** |
| Railway/Vercel env var names | **NOT VERIFIED** |
