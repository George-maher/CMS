# PHASE 1C — DOCUMENTATION RESEARCH

**Rule applied:** never guess framework/runtime behaviour. Determine the installed version first,
then consult documentation for that exact version (or, for behaviour not written down in prose,
the authoritative source of the exact installed release).

**Versions under study:** PHP **8.2.12**, Laravel **12.69.3**, axios **1.20.0** (lock), npm **11.11.0**.

---

## D-01 — Rate-limiter `->response()` callback: signature and invocation

| | |
|---|---|
| **Issue** | Phase 1B reported that a zero-parameter `fn () => self::rateLimitResponse()` callback caused a 500, because Laravel invoked it with named parameters. |
| **Installed version** | Laravel **12.69.3** |
| **Official documentation consulted** | Laravel 12.x — *Routing → Rate Limiting → Defining Rate Limiters*, <https://laravel.com/docs/12.x/routing#rate-limiting> |
| **Documented behaviour (verbatim)** | *"If the incoming request exceeds the specified rate limit, a response with a 429 HTTP status code will automatically be returned by Laravel. If you would like to define your own response … you may use the `response` method"*:<br>`return Limit::perMinute(1000)->response(function (Request $request, array $headers) { return response('Custom response...', 429, $headers); });` |
| **Relation to the fix** | The documented contract is **two parameters, `($request, $headers)`**, and the documented example **forwards `$headers` into the response**. The application declared zero parameters and discarded `$headers`, so it could not carry the real `Retry-After`/`X-RateLimit-*` values. The fix declares exactly the documented signature and forwards the documented headers. |
| **Conclusion** | **VERIFIED.** The fix matches the official Laravel 12 signature. |

### D-01a — Was the arity mismatch actually the cause of the 500?

| | |
|---|---|
| **Question** | Does invoking a **zero-parameter** arrow function with **two positional** arguments throw in PHP 8.2? |
| **Installed version** | PHP **8.2.12** |
| **Authoritative source consulted** | Installed framework source `vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php` (`buildException()`, line 253) for 12.69.3, plus an isolated PHP probe (see below). |
| **Documented / observed behaviour** | `buildException()` calls `$responseCallback($request, $headers)` **positionally**. An isolated probe on PHP 8.2.12 produced:<br>`POSITIONAL OK: ok-429`<br>`NAMED THROWN: Error: Unknown named parameter $request` |
| **Relation to the fix** | Phase 1B's "isolated proof" used **named** arguments (`$cb(request: …, headers: …)`), which Laravel never does. Extra *positional* arguments to a userland closure are accepted by PHP; only *named* arguments that do not match a parameter are rejected. **The stated root cause is therefore disproved.** The 500 symptom itself was real and was reproduced by Phase 1C. |
| **Conclusion** | **VERIFIED — prior finding DISPROVED.** Real root cause identified separately (D-02). |

---

## D-02 — Exception render-callback precedence (the actual root cause)

| | |
|---|---|
| **Issue** | Why does a throttled request return 500 instead of 429? |
| **Installed version** | Laravel **12.69.3** |
| **Authoritative source consulted** | Installed `vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php` (`render()`, `renderViaCallbacks()`) and `vendor/laravel/framework/src/Illuminate/Http/HttpResponseException.php` → `Illuminate/Http/Exceptions/HttpResponseException.php` for 12.69.3. |
| **Documented / observed behaviour (from the exact installed source)** | `Handler::render()` executes in this order:<br>1. `method_exists($e, 'render')`<br>2. `$e instanceof Responsable`<br>3. `prepareException($e)`<br>4. **`renderViaCallbacks($request, $e)`** ← registered callbacks run here<br>5. `$e instanceof HttpResponseException => $e->getResponse()` ← the framework's own branch<br><br>`HttpResponseException` extends `RuntimeException`, implements **no** `render()` method, and constructs with `parent::__construct('')` — i.e. an **empty message**. |
| **Relation to the fix** | The application registers a catch-all `function (Throwable $e, Request $request)` render callback. Because `HttpResponseException` **is** a `Throwable`, step 4 matches it and returns a 500 body **before** step 5 can return the wrapped 429. This also explains the exact log evidence: `message:""`, `file: ThrottleRequests.php`, `line: 253`. The fix registers a dedicated `HttpResponseException` callback **ahead of** the catch-all, restoring the framework's own precedence. |
| **Prose documentation available?** | **NOT DOCUMENTED** in the Laravel 12 prose docs — the precedence of registered render callbacks versus built-in exception branches is not stated in the guides. Verified directly from the source of the exact installed release, which is authoritative for that release. |
| **Conclusion** | **VERIFIED from installed source.** |

---

## D-03 — `HttpResponseException` must not be swallowed by a catch-all `Throwable` renderer

| | |
|---|---|
| **Issue** | Is it correct application behaviour to return the wrapped response of an `HttpResponseException`? |
| **Installed version** | Laravel **12.69.3** |
| **Source consulted** | Installed `Handler::render()` — the framework itself resolves `HttpResponseException` to `$e->getResponse()`. |
| **Relation to the fix** | The fix does not invent new behaviour; it re-applies the framework's own resolution ahead of an over-broad application callback. Any `abort($response)` / `Limit::response()` in the codebase is affected, so the fix is general rather than throttle-specific. |
| **Conclusion** | **VERIFIED.** Minimal and framework-consistent. |

---

## D-04 — PHP: extra positional arguments to a userland closure

| | |
|---|---|
| **Issue** | Is `fn () => …` invoked as `fn($a, $b)` legal PHP 8.2? |
| **Installed version** | PHP **8.2.12** |
| **Method** | Isolated probe executed on the installed runtime (both positional and named invocation). |
| **Result** | Positional → returns normally. Named unknown parameter → `Error`. |
| **Conclusion** | **VERIFIED by execution**, not assumed. Also verified that an arrow-function parameter may shadow an outer variable of the same name (needed to change all 38 call sites safely): probe returned `SHADOW OK` and `TYPED RESULT: 5`. |

---

## D-05 — axios advisory: affected and patched ranges

| | |
|---|---|
| **Issue** | Phase 1B reported `axios 1.18.1` with a HIGH advisory, fixed in `1.20.0`. |
| **Installed version** | `package-lock.json` → **1.20.0** (declared `^1.7.9`) |
| **Official advisory consulted** | GitHub Advisory Database **GHSA-vh66-26gq-q6x8** (CVE-2026-101908), *axios/axios*, reviewed 2026-09-30. |
| **Documented behaviour** | Affected versions **`>= 1.7.0, < 1.20.0`**; patched version **`1.20.0`**. Severity listed for this advisory: **Moderate** (CVSS 6.9). *"The issue is specific to fetch-adapter behavior and does not affect Node HTTP adapter requests."* |
| **Relation to the finding** | Installed **1.20.0 ≥ patched 1.20.0** ⇒ outside the affected range. Additionally the affected component is the **fetch adapter**; this application is a browser SPA using axios' default XHR adapter and does not select `adapter: 'fetch'`. |
| **Conclusion** | **VERIFIED — advisory resolved.** No dependency change was required during 1C because the lockfile already carried the patched version; `npm audit` reports 0 vulnerabilities at all severity levels. |

---

## D-06 — Cache mechanism used in tests versus production

| | |
|---|---|
| **Issue** | Phase 1C was required to test rate limiting with the storage mechanism relevant to production where possible. |
| **Configuration found** | `config/cache.php` default `env('CACHE_STORE', 'file')`; `.env.example` sets `CACHE_STORE=file`; `phpunit.xml` sets `CACHE_STORE=array`. |
| **Behaviour** | The `array` store is rebuilt per application instance, so rate-limit counters reset between test methods automatically. The `file` store persists across tests **and** runs, so counters accumulate. |
| **Relation to the fix** | The 500 lives in the exception-rendering layer and is independent of cache and database driver. To satisfy the requirement anyway, `RateLimitResponseTest::setUp()` flushes the cache so the same assertions hold on **both** stores. |
| **Verification** | `RateLimitResponseTest` run with `CACHE_STORE=array` → **8 passed**; run with `CACHE_STORE=file` (production mechanism) → **8 passed**; run on PostgreSQL → **8 passed**. |
| **Conclusion** | **VERIFIED on both cache mechanisms.** |

---

## D-07 — Historical broken `down()` migrations (SQLite-only failure mode)

| | |
|---|---|
| **Issue** | 5 historical migrations have a `down()` that cannot undo `up()` **on SQLite**. |
| **Installed versions** | SQLite (tests) vs **PostgreSQL 16.15** (production driver per `.env.example` / CI) |
| **Authoritative source consulted** | PostgreSQL data manipulation documentation for `ALTER TABLE … DROP COLUMN`. |
| **Documented behaviour** | PostgreSQL drops columns that are **no longer used by any other object** and *automatically* drops indexes and table constraints that involve the column. SQLite instead errors when an index still references the dropped column. |
| **Relation to the finding** | The defect is therefore a **SQLite rollback limitation, not a production (PostgreSQL) one**. |
| **Conclusion** | **VERIFIED as non-production-affecting.** Historical migrations left unchanged per §16; documented in the risk register. |

---

## Items explicitly marked NOT VERIFIED

| Item | Why |
|---|---|
| Behaviour of the **production** Railway/Supabase database | 1C forbids touching production; no production shell available |
| Whether deployed SHA == working-tree SHA | Requires Railway; out of scope (§31) |
| Backup / restore, worker, scheduler, email delivery | Require provider dashboards / production access |
| `Retry-After` observed by a real external client | Only observable through the application under test; not reachable without deployment |
