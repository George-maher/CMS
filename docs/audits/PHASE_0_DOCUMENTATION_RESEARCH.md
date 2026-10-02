# PHASE 0 — DOCUMENTATION RESEARCH LOG

**Audit date:** 2026-09-30

This log records only the research questions where a conclusion depended on an **authoritative external source** rather than on reading the code. Code-derived findings are evidenced with `file:line` in the other audit documents and are not repeated here.

**Source hierarchy applied:** actual current code → official framework/runtime documentation → official platform/provider documentation → authoritative architecture/security references → secondary sources → general model knowledge.

**Version determination before any documentation was consulted** (per the version-aware research rule):

| Technology | Version in use | How determined | Docs version used |
|---|---|---|---|
| Laravel Framework | **v12.69.3** | `composer.lock` + `php artisan --version` | 12.x |
| Laravel Sanctum | **v4.3.2** | `composer.lock` | 12.x Sanctum page |
| PHP | **8.2.12 CLI (ZTS)** local; **8.3** in Docker/CI | `php -v`; `Dockerfile` `FROM php:8.3-fpm`; `ci.yml` `php-version: '8.3'` | n/a |
| PHPUnit | **11.5.55** | `composer.lock` | n/a |
| React / React Router | **19.2.7 / 7.18.3** | `package-lock.json` | n/a (conclusion did not depend on version) |
| Vite | **8.1.0** | `package-lock.json` | n/a (conclusion did not depend on version) |
| Vite PWA | **1.3.0** | `package-lock.json` | n/a |
| Workbox | **7.4.1** (transitive) | `package-lock.json` | n/a |
| PostgreSQL | **unknown in production**; 15 compose / 16 CI / "18.4" claimed in a report | three conflicting sources | 18 (current) with 15/16 behaviour assumed unchanged for the features consulted |

---

## R-01 — Does `withoutGlobalScope()` on an unregistered scope do anything?

| | |
|---|---|
| **Question** | `EmailVerificationService.php:88, 116, 182` call `User::withoutGlobalScope(ChurchScope::class)`. But `User` does not use the `BelongsToChurch` trait, so `ChurchScope` is never registered on `User`'s builder. Are these calls a scope bypass (a security finding) or a no-op (a code-clarity finding)? |
| **Technology** | Laravel Eloquent 12 |
| **Source** | **Installed framework source, not a doc page** — `backend/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:213-224` at the exact installed version v12.69.3 |
| **Finding** | `withoutGlobalScope($scope)` normalises a non-string to `get_class()`, then executes `unset($this->scopes[$scope])` **unconditionally**, then appends to `removedScopes`. There is no existence check. |
| **Conclusion** | `unset()` on an absent key is a no-op. The three calls **neither bypass nor protect anything**. They express an intent (`User` was presumably expected to be scoped) that the model does not implement. |
| **Why the installed source, not the docs** | Laravel's documentation does not specify the absent-key behaviour. The authoritative artefact for the version actually deployed is the source that will execute. Using the docs would have produced an inference; using the vendored source produced a fact. |
| **Impact** | Downgraded the finding from "scope bypass in the email-verification path" to a **P3 code-clarity issue**, and recorded that if `User` were ever scoped these three calls would silently become live bypasses. |

---

## R-02 — Is MATCH SIMPLE the right assumption for the composite tenant foreign keys?

| | |
|---|---|
| **Question** | `2026_09_29_000001` relies on the claim that a composite FK with any NULL referencing column is not evaluated, which is why `users.class_id = NULL` remains legal. Is that PostgreSQL's actual behaviour, and what else does the design depend on? |
| **Technology** | PostgreSQL |
| **Version** | Documentation for **PostgreSQL 18** consulted; project targets 15/16/18 (three conflicting declarations) |
| **Source** | **Official** — `https://www.postgresql.org/docs/current/ddl-constraints.html` §5.5.5 Foreign Keys |
| **Direct quotes relied on** | *"Normally, a referencing row need not satisfy the foreign key constraint if any of its referencing columns are null. If MATCH FULL is added … a referencing row escapes satisfying the constraint only if all its referencing columns are null."* — confirming **MATCH SIMPLE is the default** and the migration's assumption is correct.<br><br>*"A foreign key must reference columns that either are a primary key or form a unique constraint, or are columns from a non-partial unique index."* — confirming why `stages_church_id_id_unique` and `classes_church_id_id_unique` are **required**, not optional. `TENANT_RULES.md` §6 says the same; the docs confirm it.<br><br>*"ON UPDATE NO ACTION (the default) … will allow the update to proceed and the foreign-key constraint will be checked against the state after the update. ON UPDATE RESTRICT … will prevent the update from running."* — confirming that the deliberate absence of `ON UPDATE CASCADE` (`constraintSql()` `:322-338`) is what makes a stage's `church_id` change **refuse** rather than silently re-home every class. `CompositeForeignKeyTest:235` pins this. |
| **Conclusion** | The design is **correct as documented and as implemented**. MATCH SIMPLE, the mandatory parent UNIQUE keys, and the deliberate NO ACTION on update are all the right choices. |
| **Impact** | The 7 composite FKs were classified as a **sound design with a VERIFIED-in-source, unverified-in-production** status. No CONFLICT found. The migration's own comments (`TENANT_RULES.md` §6, the migration docblock `:23-29, :98-124`) match the official documentation exactly. |

---

## R-03 — Does a composite UNIQUE constraint constrain rows containing NULL?

| | |
|---|---|
| **Question** | `qr_invites` has `unique(created_by, client_request_id)` as an idempotency key. Does that actually prevent duplicates when `client_request_id` is NULL? |
| **Technology** | PostgreSQL |
| **Source** | **Official** — `postgresql.org/docs/current/ddl-constraints.html` §5.5.3 Unique Constraints |
| **Direct quote** | *"By default, two null values are not considered equal in this comparison. That means even in the presence of a unique constraint it is possible to store duplicate rows that contain a null value in at least one of the constrained columns. This behavior can be changed by adding the clause NULLS NOT DISTINCT."* |
| **Conclusion** | The idempotency key is a no-op for rows with a NULL `client_request_id`. This is **correct here** — NULL means "no key supplied", and `QRInviteTest:136-179` covers the keyed case. Recorded as INFO, not a defect. |
| **Impact** | Prevented a false positive finding, and recorded the precise condition under which the guarantee holds. |

---

## R-04 — Is a CHECK constraint the right tool for the missing enum enforcement?

| | |
|---|---|
| **Question** | `users.role`, `events.status`, `event_registrations.status` and 8 other enum-like columns are plain `string` with application-only validation. Is a DB-level CHECK the appropriate PostgreSQL mechanism, so that recommending one is grounded rather than a generic "add constraints" instinct? |
| **Technology** | PostgreSQL |
| **Source** | **Official** — `postgresql.org/docs/current/ddl-constraints.html` §5.5.1 Check Constraints |
| **Direct quotes** | *"The check constraint expression should involve the column thus constrained."* · *"a check constraint is satisfied if the check expression evaluates to true **or the null value**"* — so a CHECK does **not** provide NOT NULL semantics; a separate `NOT NULL` is required. · *"PostgreSQL does not support CHECK constraints that reference table data other than the new or updated row being checked."* — confirms a single-column enum CHECK is entirely appropriate and cheap. |
| **Conclusion** | A single-column `CHECK (col IN (...))` is the correct, cheap, PostgreSQL-native mechanism for these 11 columns. The absence of any CHECK constraint across 108 migrations is a real gap (R-22), not a stylistic preference. |
| **Impact** | Grounded finding rather than a generic recommendation. Also established that a CHECK alone would not have prevented NULLs — relevant to R-31. |

---

## R-05 — Sanctum token expiration: is 1440 minutes actually enforced?

| | |
|---|---|
| **Question** | `config/sanctum.php:48` sets `'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 1440)`. Is a non-null `expiration` actually enforced on bearer tokens, and what is the default if it is omitted? |
| **Technology** | Laravel Sanctum 4.3 (Laravel 12) |
| **Source** | **Official** — `https://laravel.com/docs/12.x/sanctum` § Token Expiration |
| **Direct quotes** | *"By default, Sanctum tokens never expire and may only be invalidated by revoking the token. However, if you would like to configure an expiration time … you can do so via the `expiration` configuration option … This configuration option defines the number of minutes until an issued token will be considered expired."* · *"If you have configured a token expiration time for your application, you may also wish to schedule a task … the `sanctum:prune-expired` Artisan command."* |
| **Conclusion** | **Token expiration IS enforced** at 1440 minutes (24 h) — this is a genuine control, not decoration. The column `personal_access_tokens.expires_at` exists (`0001_01_01_000003:18`). **However `sanctum:prune-expired` is not scheduled**, which the documentation explicitly recommends once `expiration` is set. |
| **Impact** | Corrected an assumption in the opposite direction: the system is **better** than assumed on token expiry, and **worse** than assumed on pruning (R-44). Also confirmed `statefulApi()` is genuinely not required for bearer-token auth, which is why its absence (`bootstrap/app.php`) is inert rather than a break. |

---

## R-06 — Can the service worker cache `/api/` responses?

| | |
|---|---|
| **Question** | `vite.config.ts` uses `generateSW` (the default strategy) with `navigateFallback`, `navigateFallbackDenylist` and a `runtimeCaching` array. A source-config reading cannot prove what workbox actually emitted. Can `/api/` responses reach the Cache API at runtime? |
| **Technology** | Workbox 7.4.1 via `vite-plugin-pwa` 1.3.0 |
| **Source** | **Direct inspection of the generated artefact** — `frontend/dist/sw.js` (9 561 bytes, minified), cross-checked against `vite.config.ts:92-107` |
| **Method** | Counted every occurrence of the substring `/api` in the emitted service worker and printed its full surrounding context. |
| **Result** | **`/api` appears exactly ONCE in the entire 9 561-byte artefact**, and that occurrence is inside `NavigationRoute(createHandlerBoundToURL("/index.html"), {denylist:[/^\/api\//]})` — i.e. it is an instruction **not** to rewrite `/api/` navigations. Two `registerRoute` calls exist in total: that navigation route and one `CacheFirst` for `fonts.googleapis.com`/`fonts.gstatic.com`. `precacheAndRoute` contains only `offline.html`, `index.html`, `icons.svg`, `favicon.svg`, screenshots and icons. No `json` extension appears in `globPatterns`. |
| **Why the artefact rather than the docs** | The question is not "what does `navigateFallbackDenylist` mean in general" — it is "what did *this* build emit". Documentation describes intent; only the generated file describes behaviour. This is the same reasoning the repository's own `pwaIsolation.test.ts` applies, and the same failure mode `ci.yml:186-190` warns about. |
| **Conclusion** | **`/api/` responses can be cached neither by precache nor at runtime. VERIFIED against the actual built artefact.** The `/api` denylist is the only reference and it is a *negative* rule. |
| **Impact** | Recorded as INFO/R-73. The frontend session-isolation design (token-hashed in-memory cache key + IndexedDB wipe on session change) is therefore the **only** cache boundary, and it is a real one. |

---

## R-07 — Can another browser user inherit the offline queue or the IndexedDB cache?

| | |
|---|---|
| **Question** | The offline write queue lives in IndexedDB and carries a bearer token per item. Is IndexedDB partitioned per user, per session, or per device? Could a second user on a shared device replay the first user's queued writes? |
| **Technology** | Web Storage / IndexedDB |
| **Source** | **Official** — MDN, *Storage quotas and eviction criteria*, `https://developer.mozilla.org/en-US/docs/Web/API/Storage_API/Storage_quotas_and_eviction_criteria` |
| **Direct quotes** | *"In most cases, browsers manage stored data per origin."* · *"An origin is defined by a scheme (such as HTTPS), a hostname, and a port."* · *"In some cases, however, browsers can decide to further separate the data stored by an origin in different partitions, for example in cases where an origin is loaded within an `<iframe>` element in multiple different third-party origins."* |
| **Conclusion** | **IndexedDB is partitioned per ORIGIN, not per user, session or browser profile-in-use.** Two different users of the same deployment on the same device and browser share one `church-manager` database. The application must therefore do the isolation itself. |
| **Cross-check against the code** | It does, and comprehensively: `AuthContext.tsx:128` (login), `:142` (platformLogin), `:162` (logout) and `client.ts:306` (401) all call `clearAllData()`, which empties **both** object stores (`db.ts:164-168`). `sync.ts:101-109` additionally re-reads the active token before **every** send and voids the run on change. `sync.ts:120` replays with the **item's own** credential, so a mid-replay session switch cannot sign tenant A's write with tenant B's token. 15 tests in `syncSessionIsolation.test.ts` plus 9 in `authSession.test.tsx`. |
| **Residual gap recorded** | Isolation is by **session-boundary timing**, not by data keying. The `SyncQueueItem` record has **no `church_id` or `user_id` field** (`db.ts:6-25`) — the `token` field is the only discriminator. If `localStorage['auth_token']` were overwritten **without** going through `AuthContext` and with no in-flight replay, the queue is not re-checked against the new tenant until the next `trySyncAll()`, and that replay would still present the **original** tenant's credential — so the server sees the original identity, not a cross-tenant write. That is the safe direction, and it is why the finding is P3/INFO rather than P1. |
| **Impact** | Confirmed that the design is correct and that the mitigation is in the right layer. Converted an assumed risk into a verified control, and precisely bounded the residual. |

---

## R-08 — Authorization: is "existence ≠ ownership ≠ authorization" the right frame?

| | |
|---|---|
| **Question** | This audit classifies tenant-sensitive inputs as A / B / C depending on whether `exists:` is backed by a named ownership layer. Is that distinction grounded in authoritative guidance, or is it a project-local convention? |
| **Technology** | Application security |
| **Source** | **Primary** — OWASP Cheat Sheet Series, *Access Control*, `https://cheatsheetseries.owasp.org/cheatsheets/Access_Control_Cheat_Sheet.html` |
| **Finding** | The three-way distinction is standard practice. The authoritative backing is OWASP's IDOR guidance, which rests on: *every request must be authorised server-side against the specific object*, and validation that a resource **exists** is not validation that the **actor may access** it. The project's `TENANT_RULES.md` §4 is a faithful, unusually precise articulation of this, including the "name the line of code that answers the ownership question" heuristic. |
| **Conclusion** | The Category A/B/C framework used in `PHASE_0_ENDPOINT_MATRIX.md` §5 is grounded in OWASP guidance, not invented for this audit. `TENANT_RULES.md` is a genuinely good document and is **accurate** against the code as read. |
| **Conflict check performed** | `TENANT_RULES.md` §5 documents that `ChurchScope` used to return `null` for an approved non-platform user with `church_id = NULL`, and that `null` meant *apply no filter at all* — a live cross-tenant disclosure on `GET /api/v1/stages`. Read `ChurchScope.php:43-47`: the fail-closed `where($table.'.church_id', 0)` branch **is present**. **The documentation matches the code. No CONFLICT.** |
| **Impact** | The endpoint matrix's risk ratings for Categories A, B and C are externally grounded. And the tenant-isolation documentation was confirmed trustworthy rather than merely plausible. |

---

## R-09 — Is "an unscoped query is not automatically a vulnerability" a sound audit position?

| | |
|---|---|
| **Question** | Phase 0 was instructed not to label something insecure merely because it is unscoped. Is that a defensible position, or does it risk under-reporting? |
| **Technology** | Architecture / application security |
| **Source** | **Primary** — OWASP Access Control Cheat Sheet (authorization is a property of the *request path*, not of an individual query). **Supporting** — Laravel 12 official documentation on Eloquent global scopes, `https://laravel.com/docs/12.x/eloquent#global-scopes`, which documents `withoutGlobalScope` / `withoutGlobalScopes` as **intentional, first-class** API for exactly the legitimate cases this codebase uses (public token flows, seeders, cron). |
| **Conclusion** | The position is sound, and Laravel's own API surface supports it: a global scope is a **default filter that a competent caller may deliberately remove**, not a security boundary. This is why the audit assessed each of the **31** bypass sites individually and classified 3 of them as problems (R-15, R-16, R-17) and the rest as legitimate — rather than treating the existence of a bypass as a defect. |
| **Counter-consideration recorded** | The offsetting risk is real and is exactly what R-37 captures: because scope bypass is a normal, supported operation, the *only* durable protection is a database constraint. That is the argument for the 7 composite FKs, and it is why their production existence (U-1) is the single highest-value unknown in the register. |
| **Impact** | Prevented both a false-positive flood and a false-negative. R-19 (10 tables reaching a tenant only through an unconstrained FK chain) is the finding that this framing correctly surfaces. |

---

## R-10 — Railway health-check semantics

| | |
|---|---|
| **Question** | `railway.json` sets `healthcheckPath: /healthcheck.txt`, which `backend/production/nginx.conf:19-22` serves as a static `return 200 "OK"`. Is Railway's health check an HTTP probe of that path, or does Railway additionally inspect the process tree / run its own checks? |
| **Technology** | Railway |
| **Source** | **NOT RESEARCHED TO A DEFINITIVE CONCLUSION** — see below |
| **Status** | The repository evidence is unambiguous on the *configuration* side: the configured path returns a static 200 from nginx, and the queue worker and scheduler are Supervisor child processes of the same container with `autorestart=true`, not separately-probed services. That much is **VERIFIED from source**. |
| **What was NOT concluded** | Whether Railway performs any additional liveness signal beyond the configured HTTP path. Rather than assert a platform behaviour I had not read the current official documentation for, the finding (R-05) is stated **only** in terms of what the repository configures: *a failure inside the container that leaves nginx running will not be detected by this health check.* That claim is true regardless of what else Railway does. |
| **Lesson recorded** | Where a platform behaviour could not be confirmed from an authoritative source, the finding was narrowed to the strongest claim the repository evidence supports, rather than being broadened to a plausible-sounding platform claim. |

---

## RESEARCH QUESTIONS DELIBERATELY **NOT** PURSUED

| Question | Why not |
|---|---|
| Resend SPF / DKIM / DMARC / retry / rate-limit behaviour | No Resend account is accessible. DNS records and delivery telemetry are external. The audit therefore reports only what the **code** does, and states `EXTERNAL INFRASTRUCTURE — NOT VERIFIED` for everything else. Consulting generic Resend docs would not have produced evidence about *this* deployment. |
| Vercel rewrite/header/environment-variable semantics | The configuration was read directly and is unambiguous. No behavioural uncertainty existed to resolve. |
| React 19 / Vite 8 concurrency and caching behaviour | No finding depended on it. The material React-adjacent question (IndexedDB partitioning) was resolved from MDN, which is the authority for browser storage. |
| Laravel queue `retry_after` vs job `$timeout` interaction | The finding (R-41/R-44) concerns what is and is not configured, not framework semantics. `retry_after=300` and no `$timeout` are both simply absent/unset in source. |
| OWASP input validation / file upload specifics | `StorageController`'s bucket allowlist and MIME-derived extension are already enforced and tested (`StorageEndpointAuthorizationTest`). No unresolved question remained to justify further research. |

---

## NET EFFECT OF THE RESEARCH ON THE AUDIT

| Research item | Direction it moved the finding |
|---|---|
| R-01 framework source over docs | **Downgraded** R-17 from a scope-bypass security finding to a P3 clarity issue |
| R-02 PostgreSQL FK semantics | **Confirmed** the 7 composite FK design is correct; raised its production verification to the #1 unknown |
| R-03 NULL in composite UNIQUE | **Prevented** a false-positive defect report on the QR idempotency key |
| R-04 CHECK constraints | **Grounded** R-22 as a real gap rather than a style preference |
| R-05 Sanctum expiration | **Improved** the token-expiry assessment, **worsened** the pruning assessment (R-44) |
| R-06 generated SW artefact | **Upgraded** the PWA isolation finding from config-intent to **VERIFIED behaviour** |
| R-07 MDN origin partitioning | **Converted** an assumed shared-device risk into a **verified control** with a precisely bounded residual |
| R-08 OWASP | Grounded the Category A/B/C framework; confirmed `TENANT_RULES.md` matches the code |
| R-09 OWASP + Laravel scopes | Framed all **31** bypass sites individually; surfaced R-19 |
| R-10 Railway | **Narrowed** R-05 to the strongest supportable claim rather than asserting unverified platform behaviour |

**Net: 1 finding downgraded, 1 raised to top-priority unknown, 1 false positive prevented, 1 assumed risk converted to a verified control.**
