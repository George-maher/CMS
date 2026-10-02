# PHASE 1 — DOCUMENTATION RESEARCH LOG

**Audit date:** 2026-09-30

Research questions where a Phase 1 conclusion depended on **authoritative external documentation** or on **runtime observation that documentation cannot supply**. Code-derived findings are evidenced with `file:line` or a live probe in the other Phase 1 documents.

**Version determination before consulting documentation** (per the version-aware rule):

| Technology | Version in use | Determined by | Docs consulted |
|---|---|---|---|
| Laravel Framework | **v12.69.3** | `composer.lock`, `php artisan --version` | Laravel 12.x |
| Laravel Sanctum | **v4.3.2** | `composer.lock` | Laravel 12.x Sanctum |
| PHP | **8.2.12** local; **8.3** in Docker/CI | `php -v`, `Dockerfile`, `ci.yml` | — |
| PostgreSQL (production) | **UNKNOWN** | — | 18, with behaviour assumed unchanged for 15/16 |
| Vite | **8.1.0** | `package-lock.json` | — |
| Vite PWA / Workbox | **1.3.0 / 7.4.1** | `package-lock.json` | — |
| React | **19.2.7** | `package-lock.json` | — |

---

## P1-R01 — Does Railway probe only the configured `healthcheckPath`?

| | |
|---|---|
| **Question** | `railway.json` sets `healthcheckPath: /healthcheck.txt`, which nginx serves as a static `return 200 "OK"`. If Railway *also* performed its own process-level liveness check, the Phase 0 R-05 risk would be lower. **Does Railway health-check anything beyond the configured HTTP path?** |
| **Technology** | Railway | 
| **Version** | Production service (version not exposed) |
| **Source** | **Not consulted — deliberately.** See the finding below. |
| **Finding** | The repository + live evidence establish exactly one thing: `/healthcheck.txt` returns a static 3-byte `OK`, and `/health` (which probes the DB) exists but is not the configured path. Whether Railway performs *additional* checks is dashboard-configurable and platform-version-dependent. |
| **Resolution** | The finding was **narrowed to the strongest supportable claim**: *a failure that leaves nginx running — including total database unavailability — is not detected by the configured health check.* That claim is true regardless of what else Railway does, and it is what `PHASE_1_INFRASTRUCTURE_VERIFICATION.md` §3 records. |
| **Impact** | R-05 confirmed as **P1** without asserting unverified platform behaviour. **I declined to consult general documentation here because the question is about *this service's configuration*, which documentation cannot answer.** |

---

## P1-R02 — Is `x-railway-fallback` diagnostic of an unattached domain?

| | |
|---|---|
| **Question** | `cms-production-7eb4.up.railway.app` returns 404 on every path with header `x-railway-fallback`. I wanted to report it as a "dead/decommissioned domain" rather than "an app returning 404s". Is that header Railway-specific and diagnostic? |
| **Technology** | Railway edge |
| **Source** | **Live observation**, cross-checked against a working host |
| **Finding** | The header appears **only** on `…7eb4…` and **not** on the live `…dafb…` host, which returns 200/401/404 from the application. `…7eb4…` also returns 404 for `/api/v1/verses/active`, which is a *live* route on `…dafb…` — so the 404 is generated at the edge, not by Laravel. The empty body (Content-Length 0) and the header's presence together identify an edge-level "no service attached" response. |
| **Conclusion** | `…7eb4…` is an **unattached/stale domain**, not a running application. Reported as such. |
| **Impact** | Established that **three of the four hostnames documented in the repository are wrong or dead** — a concrete operational trap for anyone configuring from `.env.example` or the audit reports. |

---

## P1-R03 — Why does an arrow function calling a private static method work in the working tree?

| | |
|---|---|
| **Question** | Production returns **500** instead of **429** on every rate limiter. The limiter response is `->response(fn () => self::rateLimitResponse())` where `rateLimitResponse()` is a **private static** method. A plausible hypothesis: the closure cannot reach the private method when invoked later by the rate limiter, from outside class scope. **Is that the cause?** |
| **Technology** | PHP 8.2 / Laravel 12 |
| **Source** | **Runtime experiment against the real application**, not documentation |
| **Method** | Booted the actual app and (a) invoked `rateLimitResponse()` via reflection, (b) resolved the real `login` limiter and invoked its `responseCallback` with a synthetic request. |
| **Result** | **(a) HTTP 429. (b) `Illuminate\Http\Cache\RateLimiting\Limit`, callback callable, returns `JsonResponse` status 429.** |
| **Conclusion** | **The hypothesis is FALSE.** PHP arrow functions capture the defining class scope, so `self::` resolves to a private static method correctly, and the closure remains callable after being stored and invoked from outside. The working tree's throttle path is sound. |
| **Why this matters** | It **rules out the most plausible-sounding root cause** and redirects the investigation to the deployed build. Since the deployed build is demonstrably older (`X-Request-Id` absent), the fault is a **divergence between deployed and current code**, not a language-semantics problem. Documented as **mechanism NOT VERIFIED**, requiring production log access to confirm. |
| **Correction to my own process** | An initial scratch probe appeared to "confirm" the hypothesis but was **buggy** (it returned a string where a response was expected, so the assertion failed for the wrong reason). I discarded it and re-tested properly. **A failed experiment that appears to confirm a hypothesis is worse than no experiment.** |

---

## P1-R04 — Does the deployed service worker still exclude `/api/`?

| | |
|---|---|
| **Question** | Phase 0 verified the **local** `dist/sw.js` contains `/api` exactly once, inside a `NavigationRoute` denylist. **Does the deployed service worker have the same property?** This is the one Phase 0 PWA finding where the local artifact and the shipped artifact could differ. |
| **Technology** | Workbox 7.4.1 / vite-plugin-pwa 1.3.0 |
| **Source** | **The deployed artifact itself** — `https://cms-flame-eta.vercel.app/sw.js` |
| **Method** | Downloaded the live file and counted `/api` occurrences with full surrounding context, as in Phase 0. |
| **Result** | Deployed `sw.js` = **9 561 bytes** (local `dist/sw.js` = 9 559 — 2-byte difference, cause not established and immaterial). **`/api` occurrences: 1**, inside `NavigationRoute(…{denylist:[/^\/api\//]})`. |
| **Conclusion** | **`/api/` remains uncacheable by the service worker in production. CONFIRMED.** |
| **Why the artifact, not the docs** | Same reasoning as Phase 0 R-06: the question is not "what does `navigateFallbackDenylist` mean" but "what did *this* build emit". Documentation describes intent; only the deployed file describes behaviour. |
| **Impact** | Phase 0 R-73 confirmed in production. **The single most safety-critical frontend property holds in the live deployment.** |

---

## P1-R05 — Does Sanctum SPA cookie authentication actually operate?

| | |
|---|---|
| **Question** | Phase 0 established that `statefulApi()` is **never registered** in `bootstrap/app.php`, so cookie/SPA session auth should be inactive. But production returns `Access-Control-Allow-Credentials: true`, which *could* indicate a cookie session. **Is bearer-token the actual production model?** |
| **Technology** | Laravel Sanctum 4.3 |
| **Version** | Laravel 12.x docs |
| **Source** | **Official** — `https://laravel.com/docs/12.x/sanctum` § SPA Authentication |
| **Relevant documented behaviour** | *"Sanctum will only attempt to authenticate using cookies when the incoming request originates from your own SPA frontend. When Sanctum examines an incoming HTTP request, it will first check for an authentication cookie and, if none is present, Sanctum will then examine the `Authorization` header for a valid API token."* |
| **Live observation** | Protected endpoints return **401 with no `Set-Cookie`**. The live bundle issues `Authorization: Bearer`. |
| **Conclusion** | **Bearer-token is the production model. CONFIRMED.** `SANCTUM_STATEFUL_DOMAINS` and `supports_credentials: true` are inert, not wrong. |
| **Impact** | Resolved what Phase 0 could only infer. Confirms `SANCTUM_TOKEN_EXPIRATION=1440` governs the actual session lifetime — **and that `sanctum:prune-expired` being unscheduled is a real unbounded-growth issue**, not a theoretical one. |

---

## P1-R06 — What does a `\d`-level PostgreSQL check require, and can it be done without a client?

| | |
|---|---|
| **Question** | §10 of the brief requires per-constraint `confdeltype`, `confupdtype`, `confmatchtype`, `condeferrable`, `condeferred`, `convalidated`, `conenforced`. Is a simpler query acceptable, and is there any way to obtain this without `psql`? |
| **Technology** | PostgreSQL |
| **Version** | 18 documentation |
| **Source** | **Official** — `https://www.postgresql.org/docs/current/ddl-constraints.html` §5.5.5 |
| **Finding** | `convalidated` is **not optional** to this check. The composite-FK migration adds constraints **`NOT VALID`** when orphans exist (`2026_09_29_000001:131`). `NOT VALID` constraints still enforce new writes but **do not check existing rows**. A query returning only the constraint name and columns would report a constraint that exists while **not** protecting the data that is already there. |
| **Can it be done without a client?** | **No.** `pg_constraint` is a server-side catalog; there is no HTTP path to it, no admin endpoint in the application, and no unauthenticated Laravel route exposing schema state. The application exposes **no** schema-introspection endpoint. |
| **Conclusion** | The full catalog query was prepared (`PHASE_1_POSTGRESQL_VERIFICATION.md` §4.1) and **could not be executed**. `php artisan tenant:verify-schema` remains the single best substitute because it asserts the same properties and exits non-zero. |
| **Impact** | Justifies the `UNKNOWN` verdict on Gate C on methodological grounds rather than access grounds alone: the *correct* check was identified, and it requires access this environment does not have. |

---

## P1-R07 — Is `withoutGlobalScope()` on an unscoped model a no-op? (carried forward, re-confirmed)

| | |
|---|---|
| **Question** | Phase 0 R-17 asked whether `User::withoutGlobalScope(ChurchScope::class)` (3 sites in `EmailVerificationService`) is a scope bypass. Phase 1 revisited the same class of question for `DB::table()` bypass sites (22 in the codebase). |
| **Technology** | Laravel Eloquent 12 |
| **Source** | **Installed framework source**, `Illuminate/Database/Eloquent/Builder.php:213-224` (v12.69.3) |
| **Finding** | `unset($this->scopes[$scope])` is **unconditional**; there is no existence check. Absent key ⇒ no-op. |
| **Impact** | Confirmed that Phase 0's downgrading of R-17 from a security finding to a P3 clarity issue was correct, and that **22 `DB::table()` sites bypass Eloquent entirely** — which is precisely why the missing database-level constraint (§3 of the Tenant Verification) matters. The two findings reinforce each other. |

---

## P1-R08 — Does exceeding a rate limit produce 429 by framework contract?

| | |
|---|---|
| **Question** | Every throttled production endpoint returns **500** instead of **429**. Is a 500 ever the framework's documented behaviour for rate limiting? |
| **Technology** | Laravel 12 (`ThrottleRequests`, `Limit::response()`) |
| **Source** | **Live observation** + local runtime experiment (P1-R03) |
| **Documented/expected behaviour** | `Limit::response(callable)` returns the callback's response when the limit is exceeded; the callback in `AppServiceProvider::rateLimitResponse()` returns **429 with `Retry-After: 60`**. `bootstrap/app.php` also registers a `ThrottleRequestsException` renderer returning **429 with `code: RATE_LIMITED`**. |
| **Observed** | Production: **500 `INTERNAL_ERROR`**, no `Retry-After`, no `X-RateLimit-*` on the error. |
| **Conclusion** | **500 is not contract-conformant.** Two independent code paths in the working tree produce 429; the deployed build produces 500 for both. This is a **divergence between deployed and current code**, not framework behaviour. |
| **Impact** | P1 confirmed. The mitigation is that **the limit is still enforced** (`X-RateLimit-Remaining` continues decrementing), so this is an availability/observability defect, **not a rate-limit bypass**. |

---

## Summary of research impact

| # | Question | Direction it moved the finding |
|---|---|---|
| P1-R01 | Railway health-check scope | **Narrowed** R-05 to a supportable claim rather than asserting unverified platform behaviour |
| P1-R02 | `x-railway-fallback` meaning | **Established** that 3 of 4 documented hostnames are wrong/dead — a new operational finding |
| P1-R03 | Arrow-fn private-static access | **Ruled out** the most plausible root cause; redirected the investigation to build divergence |
| P1-R04 | Deployed service worker | **Confirmed** Phase 0 R-73 in production — the property holds |
| P1-R05 | Sanctum auth model | **Resolved** an inference into a verification; confirmed the prune gap is real |
| P1-R06 | Full FK catalog check | **Justified** Gate C = UNKNOWN on methodological, not just access, grounds |
| P1-R07 | Scope-bypass semantics | **Reconfirmed** R-17; linked 22 raw `DB::table()` sites to the missing DB constraint |
| P1-R08 | Rate-limit status contract | **Confirmed** 500 is non-conformant; **bounded** severity (not a bypass) |

**Net: 1 Phase 0 finding confirmed in production, 1 inference resolved into a verification, 1 plausible root cause eliminated, 2 new infrastructure findings, and 1 severity correctly bounded rather than inflated.**

**Methodological note recorded against my own process:** an initial scratch experiment appeared to confirm the P1-R03 hypothesis but failed for an unrelated reason (wrong return type in the probe). It was discarded and re-run correctly. A broken experiment that appears to confirm a hypothesis is more dangerous than no experiment, because it manufactures false confidence.
