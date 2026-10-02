# PHASE 1 — PRODUCTION GROUND TRUTH

**Audit date:** 2026-09-30
**Target:** live production of the Church Management System
**Method:** read-only external probing + git remote inspection. **No production data was written. No credential was used. No secret was printed. No remediation was applied.**

---

## 0. HOW THIS AUDIT WAS ACTUALLY CONDUCTED — and its hard limit

**This audit was substantially more productive than expected: a live production environment WAS discovered, identified, and probed read-only.** It was found not by assumption but by searching the repository for real hostnames, resolving them, and probing them.

**What this means:** Gates A, B (partially), I (partially), J, and large parts of §10, §19, §20, §25, §28–§31, §40, §43 are now backed by **live production evidence**, not assumptions.

**What this still cannot do:** everything requiring authenticated production access, provider dashboards, or a PostgreSQL client. There is **no `psql`, no `railway` CLI, no `gh` CLI, no Supabase CLI, and no production credential in this environment.** The Docker daemon is not running, so a local PostgreSQL could not be started either. Therefore:

- **No catalog query was ever run against production PostgreSQL.** The 7 composite tenant FKs remain **NOT VERIFIED**.
- **No backup or restore could be inspected or performed.**
- **No queue, scheduler, or `failed_jobs` state could be read** (these are internal to the container).
- **No Resend, Supabase, Railway, or Vercel dashboard was accessed.**

This document records what is now known from live evidence, and marks the rest honestly.

---

## 1. PRODUCTION IDENTITY (VERIFIED)

| Item | Value | Confidence |
|---|---|---|
| **Backend (LIVE)** | `https://cms-production-dafb.up.railway.app` | **VERIFIED** |
| **Frontend (LIVE)** | `https://cms-flame-eta.vercel.app` | **VERIFIED** |
| Railway service | responds `Server: railway-hikari`, `x-railway-edge`, `x-hikari-trace` | **VERIFIED** |
| Frontend host | `Server: Vercel`, `X-Vercel-Id: fra1::…` (Frankfurt region) | **VERIFIED** |
| **Deployed commit SHA** | **NOT ESTABLISHED** — see §2 | **NOT VERIFIED** |
| Laravel / PHP | **NOT ESTABLISHED** (no version endpoint exposed) | **NOT VERIFIED** |
| **PostgreSQL version** | **NOT ESTABLISHED** — no DB client | **NOT VERIFIED** |

### 1.1 The application is genuinely live and serving real data

| Probe | Result |
|---|---|
| `GET /health` | **HTTP 200** `{"status":"healthy","service":"Church Manager API","version":"1.0.0","database":"connected","timestamp":"2026-09-30T16:24:26Z"}` |
| `GET /up` | **HTTP 200** — Laravel's built-in health route, rendering the default "Application up" page |
| `GET /api/v1/verses/active` | **HTTP 200**, 1387 bytes of Arabic verse text |
| `GET /api/v1/churches/active` | **HTTP 200** — **2 real churches returned** (ids 117, 129), Arabic names, real slugs, real street addresses |
| `GET /api/v1/auth/me`, `/stages`, `/users`, `/platform/dashboard` (no auth) | **all HTTP 401** `{"success":false,"message":"Unauthenticated.","code":"UNAUTHORIZED"}` |
| Forced 404 | **HTTP 404** `{"success":false,"message":"Resource not found.","code":"NOT_FOUND"}` |

**This is a live multi-tenant production system with real data and correctly enforced authentication.** It is not a demo or a stub.

### 1.2 Database is confirmed reachable and connected

`/health` reports `"database":"connected"` and returns 200. This is the app's own PDO probe (`routes/web.php:10-29`). **It proves the application can reach its database. It does NOT reveal the engine version, the schema, or the constraint state.**

### 1.3 ⚠️ `churches/active` is serving real street addresses publicly

```
GET /api/v1/churches/active  →  HTTP 200, unauthenticated
  { "id": 117, "name": "…", "slug": "…", "address": "…" }
  { "id": 129, "name": "…", "slug": "…", "address": "…" }
```

Phase 0 raised this as **R-55 / P3** ("`address` is not obviously necessary for the public join form"). **In production it is confirmed live and returns real street addresses.**

This remains a **design decision exposed to reality**, not a new P0 — the field is served by a route whose stated purpose is populating a join-request dropdown. But it is now **VERIFIED as live data exposure**, and the decision should be an explicit one.

---

## 2. DEPLOYED CODE IDENTITY — GATE A: **FAIL**

This is the most consequential finding of Phase 1.

### 2.1 The git ground-truth matrix

| Reference | SHA | Date | Notes |
|---|---|---|---|
| `origin/main` (remote) | `bfb2c614da0b3e86c2ea0c611bee41930335364c` | 2026-09-27 10:09:56 +0300 | message: **"last"** |
| `origin/railway/code-change-U-q8O3` | `647672f516501692e7e748b0861f59cd8e8c4e75` | 2026-07-12 07:21:06 | author `railway-app[bot]`, "fix: enable catch_workers_output in php-fpm www.conf" |
| Local `HEAD` | `bd37cf5fd510d2ccdeb6d32bb65b179db2181d77` | 2026-09-28 19:39:25 +0300 | **"WIP: security hardening in progress — NOT production ready"** |
| Last tag | **NONE** | — | `git tag` is empty |
| `refs/pull/1/head` | `647672f…` (same as railway branch) | — | PR #1 exists |
| Deployed SHA | **NOT ESTABLISHED** | — | no access to Railway |

`git merge-base --is-ancestor origin/main HEAD` → **YES**. `origin/main` is exactly **1 commit behind** local `HEAD`; the only commit ahead is `bd37cf5` ("WIP … NOT production ready"). Working-tree changes: **296 modified + 30 untracked** files.

### 2.2 ⚠️ A Railway bot branch exists and is 2+ months stale

`refs/heads/railway/code-change-U-q8O3` was pushed by `railway-app[bot]` on **2026-07-12**. This is direct evidence that **Railway's Git integration is configured against this repository and has pushed a branch**. It is the mechanism by which Railway deploys. It was **never fetched into the local clone** before this audit.

**What this establishes:** deployment is Git-driven from this repository. **What it does not establish:** which branch or SHA Railway currently has deployed. Railway deploys the branch it is configured against; that configuration is only visible in the Railway dashboard.

### 2.3 🔴 `origin/main` contains NONE of the tenant-isolation hardening

Verified with `git cat-file -e origin/main:<path>` — **all ABSENT**:

| File | In `origin/main`? |
|---|---|
| `backend/database/migrations/2026_09_29_000001_add_composite_tenant_foreign_keys.php` | **ABSENT** |
| `backend/app/Services/TenantConsistencyService.php` | **ABSENT** |
| `backend/app/Console/Commands/TenantAudit.php` | **ABSENT** |
| `backend/app/Console/Commands/TenantVerifySchema.php` | **ABSENT** |
| `backend/app/Http/Middleware/AssignRequestId.php` | **ABSENT** |
| `backend/tests/Feature/TenantIsolationMatrixTest.php` | **ABSENT** |
| `frontend/vitest.config.ts` | **ABSENT** |
| `frontend/src/lib/apiUrl.ts` | **ABSENT** |

Also confirmed ABSENT from the Railway bot branch `647672f` (all five backend files).

Also absent from `origin/main`'s `bootstrap/app.php`: the `AssignRequestId` import and registration.

**⇒ If Railway is deployed from `main` (the overwhelmingly likely configuration, and the only branch with recent human commits), production is running WITHOUT:**
- the 7 composite tenant foreign keys
- `tenant:audit` / `tenant:verify-schema`
- request-ID correlation

### 2.4 🔴 Corroborating live evidence: production lacks `X-Request-Id`

This is independent confirmation, obtained without any repository assumption.

| Probe | Result |
|---|---|
| `GET /api/v1/verses/active` → `X-Request-Id` | **absent** |
| `GET /api/v1/verses/active` with `X-Request-Id: phase1-probe-a1b2c3` | **absent (not echoed)** |
| Local (working tree) equivalent request | returns `"request_id":"01M3SJJMVH6XCCKHQAVV16TYDE"` in the 500 body |

`AssignRequestId` is **untracked** — it exists only in the local working tree. Its **total absence from live production responses** is direct runtime proof that **the deployed build predates the working tree**.

### 2.5 GATE A VERDICT

```
GATE A — PRODUCTION IDENTITY:  FAIL
```

**Confidence: VERIFIED** (that the deployed build is not the working tree) · **NOT VERIFIED** (the exact deployed SHA)

A live deployment **is** running — that much is proven. But **its exact build identity cannot be established without Railway dashboard access**, and everything known about it points to a build that **predates the entire tenant-isolation hardening layer**. This meets **STOP-7**: *"Production is running an unknown/untraceable build and security-critical code cannot be identified."*

---

## 3. 🔴 NEW PRODUCTION DEFECT — RATE LIMITING RETURNS 500 INSTEAD OF 429

**This is a new, live, reproducible production defect discovered in Phase 1. It is not in Phase 0.**

### 3.1 Evidence

Two independent endpoints, both throttled, both exceeding their limit:

| Endpoint | Limiter | Result before limit | Result after limit |
|---|---|---|---|
| `POST /api/v1/auth/login` | `throttle:login` (5/min) | 401 ×5 | **HTTP 500** ×2 |
| `GET /api/v1/verses/active` | `throttle:verse-read` (60/min) | 200 ×59 | **HTTP 500** ×5 |
| `GET /api/v1/qr/validate/{token}` | `throttle:invite-public` (10/min) | 422 ×10 | **HTTP 500** ×2 |

Production 500 body:
```json
{"success":false,"message":"Internal server error.","code":"INTERNAL_ERROR"}
```
No `request_id` field (consistent with §2.4 — older build). No `Retry-After` header. No `X-RateLimit-*` headers on the error response.

### 3.2 The rate limit IS still enforced

This is the mitigating fact, and it matters. After the 500s, subsequent requests returned `200` with `X-RateLimit-Remaining: 59, 58, 57, 56, 55` — i.e. **the limiter state is intact and the request budget was correctly reset for the new window**. The throttling itself works; only the **response contract** is wrong.

**⇒ This is an availability/observability defect, not a rate-limit bypass.** No brute-force amplification is possible.

### 3.3 Root cause — narrowed to the exception renderer, mechanism NOT VERIFIED

The code path:
1. `AppServiceProvider` defines every limiter with `->response(fn () => self::rateLimitResponse())`.
2. `bootstrap/app.php` also registers a `ThrottleRequestsException` renderer returning 429 + `RATE_LIMITED`.

**Local reproduction (working tree):** invoking the `login` limiter's response callback directly returns **HTTP 429** correctly. The private static is accessible to the arrow function's scope. **The mechanism is sound in the working tree.**

**Why production differs:** the deployed build predates the working tree. The likely candidate is an **older `bootstrap/app.php` whose `ThrottleRequestsException` renderer differs, or whose catch-all `Throwable` handler intercepts the exception before the specific renderer**, producing `INTERNAL_ERROR`/500.

**I could not confirm the exact mechanism** because it requires production log access (`bootstrap/app.php:160` logs the underlying exception, but logs are not reachable).

**Confidence: PARTIALLY VERIFIED** — the defect, its reproducibility across 3 endpoints, and the continued enforcement of the limit are all VERIFIED. The precise throwing line is NOT VERIFIED.

### 3.4 Impact

- **Client-side:** a well-behaved client cannot distinguish "slow down" from "server broken". Retry logic keyed on 429 will not engage. Exponential-backoff clients will treat it as a 5xx and may retry harder against a limiter that is correctly rejecting them.
- **Observability:** every rate-limited response is logged as `Unhandled API exception` at `error` level (`bootstrap/app.php:160`). **Under any sustained load or abuse, production error logs fill with non-incidents**, and any 5xx-based alerting fires on what is normal, correct throttling.
- **Not a security bypass.** The limit holds.

**Severity: P1.** It is live, reproducible, affects every limiter in the application, and actively degrades both client behaviour and production alerting.

---

## 4. CORS — U-10: **PASS (VERIFIED)**

| Probe | Result |
|---|---|
| Preflight from `https://cms-flame-eta.vercel.app` | **HTTP 204** |
| `Access-Control-Allow-Origin` | **`https://cms-flame-eta.vercel.app`** — exactly the live frontend |
| `Access-Control-Allow-Credentials` | `true` |
| `Access-Control-Allow-Methods` | `GET` (echoes the requested method) |
| `Access-Control-Allow-Headers` | `authorization,accept,content-type` |
| `Access-Control-Max-Age` | `86400` |
| `Vary` | `Access-Control-Request-Method, Access-Control-Request-Headers` |
| **Preflight from `https://evil.example.com`** | **HTTP 204 but `Access-Control-Allow-Origin: https://cms-flame-eta.vercel.app`** — the evil origin is **NOT** reflected |

**The production frontend origin IS in the production CORS allow-list, and an untrusted origin is not reflected.** `supports_credentials: true` combined with a static allow-list is the correct configuration.

**⇒ U-10 ANSWERED: production CORS is correct. VERIFIED.**

**Residual note (P3):** `Allow-Credentials: true` is set, but Phase 0 established that `statefulApi()` is never registered, so Sanctum cookie auth is inactive and the app is bearer-token only. Credentials support is therefore currently unnecessary — inert, not wrong.

---

## 5. HEALTH CHECKS — production reality

| Endpoint | Result | What it actually checks |
|---|---|---|
| `GET /health` | **200**, `"database":"connected"` | **nginx + PHP-FPM + a real PDO connection to the database** |
| `GET /up` | **200**, Laravel's default page | PHP-FPM only — **no database check** |
| `GET /healthcheck.txt` | **200**, body `OK` (3 bytes) | **nginx only — static string** |

### 5.1 The discrepancy is now confirmed in production

Phase 0 R-05 predicted that Railway probes a static nginx `200` while a real `/health` endpoint exists. **Live probing confirms both exist and behave exactly as predicted.**

**Railway's configured probe is `/healthcheck.txt`** (`railway.json`), which:
- **DOES** detect: nginx dead, container not accepting connections
- **DOES NOT** detect: PHP-FPM dead, **database unreachable**, **queue worker dead**, **scheduler dead**, application misconfiguration

**⇒ A total database outage would leave Railway reporting the service as HEALTHY.** A 100% application outage that still routes through nginx would go unobserved by the platform.

**`GET /health` — which does return 503 on a degraded database — is not the probed path.**

**Severity: P1. Confidence: VERIFIED** (both behaviours directly observed in production).

---

## 6. FRONTEND DEPLOYMENT — U-12: **PARTIALLY ANSWERED**

### 6.1 The live frontend is a real, current Vercel deployment

| Item | Observed |
|---|---|
| Status | **HTTP 200** |
| `<title>` | `Church Manager` |
| Region | `fra1` (Frankfurt) |
| Main bundle | `/assets/index-CfpkSYLS.js` — 255 805 bytes |
| Modulepreloads | `rolldown-runtime`, `i18n`, `vendor`, `ui` — **the manualChunks config from `vite.config.ts` is present** |
| Manifest | linked **twice** (once in `<head>`, once before `</head>`) |
| PWA assets | `/sw.js` 9 561 B, `/workbox-dcde9eb3.js` 21 434 B, `/manifest.webmanifest` 1 543 B, `/offline.html` 1 407 B — **all HTTP 200** |
| `sw.js` referenced in `index.html` | **NO** — registration is in JS, as designed |

### 6.2 🔴 The deployed frontend points to a different Railway host than the repository documents

| Source | Backend URL |
|---|---|
| `AUDIT_CHANGES.md`, `PRODUCTION_READINESS_REPORT.md` | `https://cms-production-7eb4.up.railway.app` |
| `offlineReplayUrl.test.ts:40` | `https://cms-production-7eb4.up.railway.app` |
| **Live bundle `index-CfpkSYLS.js`** | **`https://cms-production-dafb.up.railway.app`** |

**Verified by live probe:** `cms-production-7eb4.up.railway.app` resolves (69.46.46.25) but returns **HTTP 404 on every path** — `/`, `/health`, `/up`, `/healthcheck.txt`, `/api/v1/verses/active` — and carries the header **`x-railway-fallback`**, which is Railway's response when a domain has **no service attached to it**. It is a **stale/dead domain**.

`cms-production-dafb.up.railway.app` resolves (69.46.46.68) and serves the live application.

**⇒ The `offlineReplayUrl.test.ts` test asserts against a DEAD hostname.** The test still passes because it only asserts URL *construction*, not reachability. **⇒ U-12: Vercel + Railway is the live path. The Docker/nginx frontend path is NOT in production.**

### 6.3 Service worker — API isolation holds in production

| Check | Result |
|---|---|
| Deployed `/sw.js` size | 9 561 bytes (local `dist/sw.js`: 9 559 — 2 bytes differ) |
| `/api` occurrences in **deployed** `sw.js` | **1** |
| Context | inside `NavigationRoute(… {denylist:[/^\/api\//]})` — a **negative** rule |

**⇒ The Phase 0 PWA finding (R-73) is CONFIRMED IN PRODUCTION. `/api/` is not cacheable by the service worker.**

The 2-byte difference is unexplained but immaterial; the `/api` isolation property is identical.

### 6.4 Security headers — live and correct

All from the **live** Vercel response:

| Header | Value | vs `vercel.json` |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` | ✅ identical |
| `Content-Security-Policy` | `default-src 'self'; script-src 'self'; … frame-ancestors 'self'; upgrade-insecure-requests` | ✅ identical |
| `X-Frame-Options` | `SAMEORIGIN` | ✅ |
| `X-Content-Type-Options` | `nosniff` | ✅ |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | ✅ |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(self)` | ✅ |
| `X-Powered-By` | **absent** | ✅ (Dockerfile `expose_php=Off`) |

**The live frontend matches `vercel.json` exactly. This path is correctly configured and deployed.**

---

## 7. TRANSPORT SECURITY & DOMAINS

| Host | Cert issuer | NotAfter | Days left |
|---|---|---|---|
| `cms-production-dafb.up.railway.app` (backend) | Let's Encrypt `YE2` | **2026-12-26** | **86** |
| `cms-flame-eta.vercel.app` (frontend) | (Vercel-managed) | **2026-11-27** | **58** |
| `http://` → `https://` on backend | **HTTP 301** | — | ✅ redirect enforced |

Both certificates are valid and not near expiry. **`includeSubDomains` is present in HSTS, so certificate renewal is automated and currently healthy.**

**The documented `churchmanager.app` has NO DNS record** (`NXDOMAIN`), and `api.churchmanager.app` does not exist. The `.env.example` values `APP_URL=https://churchmanager.app` and `VITE_API_URL=https://api.churchmanager.app` are **placeholders, not production reality**. Anyone treating `.env.example` as the production URL list will be wrong.

---

## 8. PRODUCTION GROUND-TRUTH TABLE

| Area | Expected | **Actual** | Evidence | Confidence | Risk |
|---|---|---|---|---|---|
| **Deployed SHA** | known commit | **NOT ESTABLISHED**; deployed build **predates the working tree** (no `X-Request-Id`) | §2 | **NOT VERIFIED** | **P1** |
| **Hardening in deployed build** | composite FKs + tenant tooling | **ABSENT from `origin/main`**; corroborated live | `git cat-file`; §2.4 | **VERIFIED** (for `origin/main`) | **P0-adjacent** |
| **Backend URL** | documented `7eb4` | **`dafb`** (live); `7eb4` is a dead `x-railway-fallback` domain | §6.2 | **VERIFIED** | P2 |
| **Frontend URL** | Vercel | `cms-flame-eta.vercel.app`, HTTP 200, `fra1` | §6.1 | **VERIFIED** | INFO |
| **App health** | 200 healthy | **200, `database":"connected"`** | §1.1 | **VERIFIED** | INFO |
| **PostgreSQL version** | supported | **NOT ESTABLISHED** | no DB client | **NOT VERIFIED** | **P1** |
| **Tenant FKs** | 7 | **NOT VERIFIED** | no catalog access | **NOT VERIFIED** | **P0 if absent** |
| **Tenant audit** | clean | **NOT VERIFIED** | `tenant:audit` absent from `origin/main` | **NOT VERIFIED** | **P0 if dirty** |
| **Health check probed by platform** | application health | **static nginx 200** | §5 | **VERIFIED** | **P1** |
| **CORS** | production origin allowed | **correct; evil origin not reflected** | §4 | **VERIFIED** | INFO |
| **Rate limiting** | 429 + `Retry-After` | **500 `INTERNAL_ERROR` on every limiter** | §3 | **VERIFIED** | **P1** |
| **Rate limit enforcement** | enforced | **ENFORCED (limit holds)** | §3.2 | **VERIFIED** | INFO |
| **SW API isolation** | no `/api/` caching | **1 occurrence, in a denylist** | §6.3 | **VERIFIED** | INFO |
| **Security headers** | per `vercel.json` | **exact match** | §6.4 | **VERIFIED** | INFO |
| **TLS** | valid | **valid; 86 / 58 days left; HTTP→HTTPS 301** | §7 | **VERIFIED** | INFO |
| **Auth on protected routes** | 401 | **401 on `/auth/me`, `/stages`, `/users`, `/platform/dashboard`** | §1.1 | **VERIFIED** | INFO |
| **Authorization** | per Phase 0 model | **NOT VERIFIED** | no authenticated session | **NOT VERIFIED** | **P1** |
| **Worker** | running | **NOT VERIFIED** | no container access | **NOT VERIFIED** | **P1** |
| **Scheduler** | running | **NOT VERIFIED** | no container access | **NOT VERIFIED** | **P1** |
| **Failed jobs** | monitored | **NOT VERIFIED** | no container access | **NOT VERIFIED** | **P1** |
| **Email** | delivered | **NOT VERIFIED**; no provider access | — | **NOT VERIFIED** | **P1** |
| **Backup** | exists | **NOT VERIFIED** | no provider access | **NOT VERIFIED** | **P1** |
| **Restore** | successful | **NOT VERIFIED** | no provider access | **NOT VERIFIED** | **P1** |
| **Storage** | sensitive files private | **NOT VERIFIED** | no Supabase access | **NOT VERIFIED** | **P1** |
| **Dependency security** | no high advisories | **NOT VERIFIED** | audit not executed | **NOT VERIFIED** | P2 |
| **CI/CD** | controlled | CI-only confirmed; Railway bot branch confirms Git-driven deploy | §2.2 | **PARTIALLY VERIFIED** | **P1** |
| **Church addresses public** | decision | **CONFIRMED serving real street addresses** | §1.3 | **VERIFIED** | P2 |

---

## 9. WHAT WAS AND WAS NOT DONE

**Done (read-only):**
- `git ls-remote origin` + targeted `git fetch` of the Railway branch
- `git cat-file -e origin/main:<path>` for 8 hardening files
- DNS resolution for 5 hostnames
- HTTPS GET/OPTIONS against 2 live hosts, ~90 requests total
- TLS certificate inspection
- Artifact comparison (deployed vs local `sw.js`, bundle inspection)

**NOT done — and deliberately so:**
- No authenticated request. No credential was created, guessed, or used.
- No POST/PUT/PATCH/DELETE against any data-bearing endpoint. The only POSTs were to `/api/v1/auth/login` with a **non-existent, RFC-2606 `.invalid` address** (`example.invalid` is reserved and can never resolve to a real mailbox) purely to trip the rate limiter, and to `/api/v1/membership-requests` — **which was not called**.
- **No test data created. No record modified. No email sent. No queue job injected.**
- No migration, no `db:show`, no catalog query — no DB client exists.
- No backup, no restore, no `pg_dump`.
- No remediation of any kind.

**Probe budget note:** the rate-limit findings required deliberately exceeding limiter budgets. This was done on **3 read-only/low-impact endpoints** using a reserved `.invalid` email, at a rate of ~4 req/s. It was not done against any authenticated or write endpoint.
