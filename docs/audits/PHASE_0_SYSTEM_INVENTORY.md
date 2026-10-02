# PHASE 0 — FULL SYSTEM INVENTORY

**Audit date:** 2026-09-30
**Repository:** `D:\xampp\htdocs\CHproject` · `origin` = `https://github.com/George-maher/CMS.git` · branch `main` · HEAD `bd37cf5` · 144 commits · **0 tags**
**Auditor role:** Principal Architect + Senior Laravel/React/PostgreSQL Engineer + Security Engineer + DevOps Engineer
**Mode:** **DISCOVERY ONLY** — no code, schema, configuration, dependency, permission or infrastructure was changed.

---

## 1. SYSTEM STATUS

```
DISCOVERY COMPLETE
```

This is **not** a statement of production readiness. It is a statement that the repository has been systematically mapped, with every important claim either evidenced (`file:line`), marked `EXTERNAL INFRASTRUCTURE — NOT VERIFIED`, or explicitly marked unknown.

**Headline numbers**

| | |
|---|---|
| Backend | Laravel **v12.69.3** on PHP 8.2/8.3 · 224 routes (218 API) · 37 controllers · 13 policies (8 never invoked) · 40 services · 32 models · 108 migrations |
| Frontend | React **19.2.7** + TypeScript 5.9.3 + Vite 8.1.0 · 62 pages · 23 API modules · 72 passing tests |
| Database | PostgreSQL (production) / SQLite (tests) · 40 tables · 7 composite tenant FKs · **0 CHECK constraints** |
| Tests | **542 passed, 6 skipped, 3202 assertions** (SQLite) · PHPStan level-max **0 errors** · Pint **passed** · Frontend 72 passed / tsc clean / eslint clean / i18n parity exact |
| Findings | **P0: 0 · P1: 9 · P2: 24 · P3: 16 · INFO: 7** |
| Working tree | **296 modified files, 30 untracked paths, nothing committed** |

---

## 2. THE SINGLE MOST IMPORTANT THING IN THIS REPORT

**The strongest engineering in this repository is uncommitted.**

The composite tenant foreign keys, the tenant-consistency audit tooling, the request-id middleware, the schema-verification command, 12 security test files, the frontend offline-queue session isolation, and the PWA API-exclusion guard — **none of it is on `origin/main`, none of it is tagged, and the HEAD commit is literally titled "WIP: security hardening in progress — NOT production ready".**

Therefore: **the 542 green tests prove something about a working tree on one machine, not about any deployable commit.** This is finding **R-01** and it is the reason this audit cannot conclude anything about production.

The second-order consequence: the repository contains a *documented incident* (`.github/workflows/ci.yml:52-69`) in which a composite-FK migration used syntax PostgreSQL rejects and SQLite accepts, so `migrate` aborted in production and **none of the tenant constraints were ever created** — while every SQLite test stayed green. The fix is in source and is guarded at the source level. Whether it is actually applied in production is **U-1**, the highest-value unknown in the register.

---

## 3. ARCHITECTURE

**Verified:**
- Laravel 12.69.3 / PHP 8.2–8.3, `composer.lock` at laravel/framework v12.69.3, sanctum v4.3.2, resend-laravel v1.4.0, phpstan 2.2.5, phpunit 11.5.55.
- React 19.2.7, TypeScript 5.9.3, Vite 8.1.0, Tailwind 4.3.1, axios 1.18.1, vite-plugin-pwa 1.3.0, vitest 5.0.2, ESLint 10.5.0, `idb` 8.0.3, zod 3.25.76.
- Layered backend: `Http/Controllers` → `Services` → `Repositories` → `Models`, with 46 `Contracts/` interfaces bound in `AppServiceProvider::register()`.
- Route surface: 218 API routes, 13 genuinely public, 12 platform-scoped, ~60 church-scoped, ~55 authenticated-self-scoped, **0 stage-scoped at the route layer** (stage isolation lives inside `ScopeResolver` and `EventAuthorizationService`).
- All API writes are permission-gated. 30+ named rate limiters, including `login` 5/min per IP+email, `register` 10/hr/IP, `invite-accept` 10/hr/token.
- Frontend role gating is **100% client-side** render-time branching on a `localStorage` role string — exactly as `AGENTS.md` states it should be.

**Partially verified:**
- The layered architecture is real but **not consistently honoured**. 20 of 37 controllers contain route closures or inline queries. `app/Policies/UserPolicy.php` is an 8-line empty stub. `app/Http/Controllers/Api/UserController.php` is a 3-line "DELETED" comment. `app/Services/UserService.php` and `app/Services/StorageService.php` are 3-line stubs.
- The "Repository pattern" exists for 11 models; the other 21 models are queried directly from services.
- Design-pattern usage: **Policy** (13 registered, 8 dead), **Service Layer** (real and consistently applied), **Repository** (partial), **Observer** (4, all doing external storage deletion), **Strategy/Factory/Adapter** — not present as named patterns. `AuditService::maskPii()` and `ChurchScope` are Strategy-shaped but implemented as switch/if chains.

**Not verified:**
- Whether the running production topology matches any of this. See § Infrastructure.

**CONFLICT — code takes precedence:** `README.md` says "PostgreSQL 15"; CI provisions `postgres:16`; `PRODUCTION_READINESS_REPORT_2026-09-30.md` claims tests ran on "PostgreSQL 18.4". No production pin exists in the repository.

---

## 4. BACKEND INVENTORY — SUMMARY

| Layer | Count | Notable |
|---|---|---|
| Controllers (Api) | 34 | plus 1 in `Modules/User/` (the live `UserController`; the `Api/` one is a deleted stub) |
| Contracts (interfaces) | 46 | bound in `AppServiceProvider` |
| Services | 40 files / 38 real | 2 are empty stubs |
| Repositories | 12 | 11 have a matching interface |
| Models | 32 | 15 use `BelongsToChurch`; 17 do not |
| Policies | 13 registered + 1 empty stub | **8 registered policies are never invoked** |
| Middleware | 10 | **`reauth` is registered but applied to zero routes** |
| Form Requests | 44 | 8 carry conditional `authorize()`; **36 return `true` unconditionally** |
| Jobs | 1 | `SendEmailJob` — **and it has no production caller** |
| Notifications | 9 | **4 are never dispatched by any code** |
| Observers | 4 | all perform external storage deletion, none wrapped in try/catch |
| Enums | 16 | |
| Console commands | 8 | 3 destructive; `analytics:cache` is a no-op stub |

**Architecture strengths, evidenced:**
- `ChurchScope` is a genuine global scope that **fails closed** in both dangerous cases (unauthenticated routed request → `whereRaw('1 = 0')`; authenticated user with no church → `church_id = 0`) and **never** infers a tenant from a client header. The in-code comment at `ChurchScope.php:36-39` records the real cross-tenant disclosure this fixed.
- The platform/church authority split is **deliberate and load-bearing**: `platform_admin` has no key in `defaultRolePermissions()`, so it gets 403 on church endpoints, and a regression test exists specifically to stop anyone "fixing" that into a privilege escalation.
- The **best-instrumented path in the system** is `POST /api/v1/users` (the D-1 remediation): form-request ownership checks for *every* role, controller re-resolution, service re-check, and two composite FKs — with `TenantOwnershipBoundaryTest:594` proving the service **throws** when called directly with middleware bypassed.

**Architecture weaknesses:**
- **8 of 13 policies are dead code.** Each has an inlined equivalent, so there is no hole today — but the policy layer is a second, divergent, untested description of the authorization model. `ClassePolicy::reorder` (role-only, no class ownership) is live and divergent.
- **20 tenant-sensitive inputs are validated with `exists:` and nothing else.** The A/B/C classification in the Endpoint Matrix §5 names the owning layer for each; Categories C (potentially unprotected) are **documented, not fixed**, per the Phase 0 rules.
- 31 global-scope bypass sites, individually assessed. 28 are legitimate and documented; 3 are real issues (R-15, R-16, R-17).

---

## 5. ROUTE INVENTORY — SUMMARY

Full matrix: `PHASE_0_ENDPOINT_MATRIX.md`.

```
224 routes total
├── 218 api/  (214 under api/v1)
│   ├── 13  PUBLIC
│   ├── 12  PLATFORM-SCOPED  (role:platform_admin — the only RoleMiddleware use)
│   ├── ~60 CHURCH-SCOPED
│   ├── ~55 AUTHENTICATED (self-scoped or read-only)
│   └──  6  UNKNOWN
├──  1  sanctum/csrf-cookie
└──  5  web/infra: /, /health, /storage/{path}, /up, /chconfirmation777
```

**One correction worth recording:** `php artisan route:list --json` reports only *route-level* middleware and omits group-inherited middleware. It therefore appears to show `POST /api/v1/auth/logout` as unauthenticated. Re-verified with `route:list -v`, which shows the full `api → Authenticate:sanctum → EnsureApproval → ThrottleRequests:api` stack. **Do not classify routes from the `--json` field.**

---

## 6. TENANT MODEL

The conceptual model in the brief is **confirmed**, with one structural exception:

```
Platform Admin
    ↓
Church
    ↓
Stage
    ↓
Class
    ↓
Servants / Members
```

**Confirmed as designed:** 7 composite tenant foreign keys, all of the form `(child.church_id, child.X) → parent(church_id, id)`, all with **deliberately no `ON UPDATE CASCADE`**, all backed by the mandatory parent UNIQUE keys. This makes "a class belongs to a stage in the same church" **unrepresentable** rather than merely discouraged. Verified against the official PostgreSQL documentation — see the Research Log R-02.

**The exception, stated plainly:**

| Layer | Status |
|---|---|
| `User` — the tenant **root** — carries **no `ChurchScope`**. | Deliberate, documented, and pinned by `ChurchScopeLoginTest:58` (login must resolve before a tenant exists). But it means `User::byChurch()` is opt-in and a **no-op when `church_id` is falsy** (R-10). |
| **10 tables** (`event_registrations`, `event_sessions`, `event_speakers`, `event_buses`, `event_bus_sheets`, `event_payments`, `event_rooms`, `event_room_cells`, `event_accommodations`, `feedback_replies`) reach a tenant **only** through an unconstrained single-column FK chain. | There is **no `church_id` column and no composite FK to `events`** on any of them. Isolation is transitive and enforced only by `event.scope` middleware + `EventAuthorizationService` — neither of which runs from a command, a job, or a second controller. **R-19.** |
| In **console and queue** contexts, `ChurchScope` applies **no filter at all** (both fail-closed branches require an HTTP route). | R-18. Currently mitigated only because the one live queued path is dead code. |
| Legacy `class_years` is retained and **still written** by `ChurchDeletionService`, but every `class_year_id` FK now points at `classes` — **except `users.class_year_id`**, which is the last one in the old id space. | `TENANT_RULES.md` §12a is **accurate**. `AttendanceClassIdSpaceTest` reproduces the collision and pins the fix. The asymmetry is real and is a known, documented, tested hazard — not a new finding. |

---

## 7. DATABASE

Full matrix: `PHASE_0_DATABASE_MATRIX.md`.

**Verified from source:** 40 tables · 108 migrations classified (66 SAFE / 25 REVIEW / 14 HIGH RISK / 3 UNKNOWN) · 7 composite tenant FKs · 10 tenant-aware unique constraints · 4 partial unique indexes implementing attendance and points dedup · 24 tenant-column indexes · **0 CHECK constraints** · 16 migrations with raw SQL · 58 `dropColumn` · 7 migrations with an empty `down()`.

**Executed during this audit:**
- `php artisan test` → 542 passed, 6 skipped, 3202 assertions (SQLite in-memory, PHP 8.2.12)
- `php artisan test --filter=AttendanceConcurrencyTest` → 6 skipped, all requiring PostgreSQL
- `vendor/bin/phpstan analyse --level=max` → **No errors**
- `vendor/bin/pint --test` → **passed**
- `php scripts/scan-broken-migration-rollbacks.php` → **5 findings** (R-08/R-25)
- `php scripts/check-lang-parity.php` → **PASS** (EN 123 / AR 123)

**Not verified — and this is the critical gap:**
No PostgreSQL server was reachable (`docker ps` → Docker daemon not running; no `psql`; no PostgreSQL in `D:\xampp`). Therefore:

| | |
|---|---|
| Do the 7 composite FKs exist in a live catalog? | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** |
| Does `migrate` complete on PostgreSQL today? | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** |
| Is production data free of tenant violations? | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** (`tenant:audit` not run) |
| Does row-lock concurrency actually work? | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** (6 tests skipped) |
| Is there a backup? Has a restore ever succeeded? | **NO backup configuration exists in the repository; no restore was performed** (R-04) |

**The mitigation is already excellent and simply was not run here:** `php artisan tenant:verify-schema` queries the catalog, checks the constraints are attached to the right columns and are not `ON UPDATE CASCADE`, and exits non-zero on violation. CI runs it against `postgres:16`.

---

## 8. SERVICE LAYER

Full matrix: `PHASE_0_SERVICE_MATRIX.md`.

**Verified:** 38 real services classified by transaction boundary, external side effects, and — the question that actually matters — **whether the service performs its own authorization or assumes the caller did**.

| Authorization posture | Count | Meaning |
|---|---|---|
| **SELF** — re-checks ownership itself | 8 | `UserService`, `ClasseService`, `EventReservationService`, `ProfileUpdateRequestService`, `MemberProfileService`, `AuthService`, `QRInviteService`, `ChurchApplicationService` |
| **PARTIAL** | 4 | |
| **ASSUMES** the caller authorized | 4 | incl. **`AttendanceService`** — the business core (R-33) |
| **NONE** and accepts tenant ids | 17 | incl. `EventPaymentService`, `EventAccommodationService`, `EventScheduleService`, `NotificationService` |

**Transaction boundaries:** 12 services are transactional; 8 are multi-write with **no** transaction, including `EventLifecycleService` (R-35) and `PointService` (R-32 — safe only because both its callers happen to be inside a transaction).

**External side effects:** Supabase Storage HTTP (6 methods, no retry, no compensation), local filesystem, 4 model observers deleting files inside a delete event with no try/catch (R-52), and CSV streaming to `php://output` with no truncation handling.

**Queue / email — the decisive trace:**
`SendEmailJob` is the **only** physical mail send site in the entire backend. It is dispatched **only** by `EmailService`. **`EmailService` is injected into no controller and called by no production code** (grep-verified). 4 of 9 notification classes have no dispatcher at all. The one live notification path is guarded against a non-delivering transport — but only that one, and `shouldEnforceAtBoot()` is called from nowhere. See R-41, R-42.

---

## 9. AUTHENTICATION

**Verified:**
- Sanctum **bearer tokens only** — `statefulApi()` is **not** registered, so cookie/SPA session auth is inactive. `SANCTUM_STATEFUL_DOMAINS` and `supports_credentials: true` are therefore inert (R-58).
- Token expiration **is** enforced: `expiration => 1440` (24 h), `personal_access_tokens.expires_at` exists. Verified against the official Sanctum documentation. **`sanctum:prune-expired` is not scheduled** (R-44).
- Separate platform-admin login on a configurable obscure path, rate-limited identically to normal login, with a hard role assertion inside `AuthService::platformLogin` and a reciprocal refusal of platform admins in the normal `login`.
- **Uniform failure responses** for unknown-email and wrong-password (`ServantLoginLifecycleTest`), for password-reset and email-verification and resend across unknown/verified/unverified account states (`EmailVerificationTokenSecurityTest` — asserts the responses are *byte-identical*). This is genuinely well done and closes account-enumeration risk.
- Email verification tokens are **hashed at rest**, the queued payload is **encrypted** (`ShouldBeEncrypted`), a DB dump cannot be replayed, the raw token **never appears in the log**, and `failed()` logs metadata only.
- Password reset is **not** a token flow: there is no public reset endpoint (pinned by a test) and no emailed reset link. An admin sets the new password directly after approving a request.
- Inactivity revocation via `TrackActivity` — but the state lives in `Cache`, which defaults to `file` (per-container), so it is not consistent across replicas (R-45).
- Passwords are `hashed` cast, never double-hashed (3-test `PasswordHashTest`).

**Not verified:** whether any of it behaves this way in production; whether `role_permission` is seeded; whether tokens are actually pruned.

---

## 10. AUTHORIZATION

**Verified — the exact role→permission map** (from `Permission::defaultRolePermissions()`, not from documentation):

| Role | Permissions |
|---|---|
| `platform_admin` | **no key at all** — gets 403 on every `permission:*` endpoint, by design |
| `admin`, `assistant_admin` | the 27 `adminPermissionKeys()` |
| `stage_admin` | the same 27 **minus** `manage_church_settings` |
| `servant` | 16 keys |
| `member` | 6 keys |

Resolution is `role → role_permission pivot → permissions`, with a hard-coded default map as a fallback when the table is empty or a role's mapping resolves empty. **No `Gate::before`, no `Gate::define`, no per-user permission grants in the resolution path.** `UserController::updatePermissions` exists but has **no route**.

**Verified weaknesses:**
- **8 of 13 policies are never invoked** (R-37).
- `ClasseController::store`/`bulkCreate` authorize against the **parent Stage**, so `ClassePolicy::create` is dead.
- `ClassePolicy::reorder` is live, role-only, and diverges from every other class policy (R-16).
- `FeedbackController` inlines its own scoping and admins/assistant-admins **skip the check entirely**.
- `AttendanceController` lets admins read any `userId` in their church, and skips the check entirely on `history`, `stats`, `by-class`, `filtered` (R-13).
- The `UserPolicy` that *would* enforce church+stage+class for user data is registered and never called; the controller inlines equivalent logic instead.

---

## 11. PLATFORM ADMIN

**Verified.** `platform_admin` is checked in **45 places** across routing, middleware, enum, model, policies, services, controllers, scope, resources and seeders. It is enforced by:

- **middleware** — `role:platform_admin` on the whole `/platform/*` group (the only use of `RoleMiddleware` in the codebase)
- **FormRequest `authorize()`** — `DeleteChurchRequest`: role check **+** literal `confirmation: "DELETE CHURCH"` **+** a `Hash::check` closure on the submitted password
- **service-level** — `ScopeResolver::canAccess*` return `true` unconditionally for platform admin
- **policy-level** — `ChurchDeletionPolicy` (all 7 methods), but it is **never invoked** (R-38)

Destructive operations add a password re-check and a confirmation string. `reauth` middleware exists, is registered, and is applied to **zero** routes — the same protection is re-implemented in the FormRequest.

**Authority mixing:** the split is clean. Platform routes require `role:platform_admin`; church routes require `permission:*`, which platform admins hold **no rows for**. A platform admin gets 403 on `POST /api/v1/users` — pinned by a dedicated negative test. `AuditLog` is unscoped, so platform admins can read audit data across tenants through any route that reaches it.

---

## 12. QUEUE

**Verified:** driver `database`; `retry_after` 300; `after_commit` **true** (correct — jobs never run before the transaction commits); failed-job driver `database-uuids`; **one** job (`SendEmailJob`, `$tries=3`, `$backoff=10`, `retryUntil=+30 min`, **no `$timeout`**, **no idempotency**, `failed()` logs only); **no `onQueue()` anywhere**; Redis compiled into the image and configured but **not deployed**; RabbitMQ/SQS/Horizon/Telescope/Pulse **not present**; `failed_jobs` has **no alerting and no pruning**; `sanctum:prune-expired` and `queue:prune-failed` are **not scheduled**.

**No queue test exists.** `QUEUE_CONNECTION=sync` in both phpunit configs, and there is **no `Queue::fake`, `Mail::fake`, `Notification::fake`, `Http::fake` or `Storage::fake` anywhere in `backend/tests/`**. → **R-54 (test gap), R-41 (dead pathway)**

---

## 13. EMAIL / RESEND — the most conflicted area

**CONFLICT — code takes precedence.** Four sources disagree:

| Source | Claim |
|---|---|
| `AGENTS.md` | *"Resend removed: Email sending is NOT implemented — use in-app notifications"* |
| `README.md` | *"Email — Authenticated SMTP via Laravel Mail"* |
| `.env.example` | `MAIL_MAILER=resend`, `MAIL_HOST=smtp.resend.com`, `RESEND_API_KEY=` |
| `composer.json` | `resend/resend-laravel: ^1.4` is a **production** dependency |

**The actual state, traced end to end:** one physical send site → one dispatcher → **that dispatcher has no production caller** → 4 of 9 notification classes are never dispatched → the one live path is guarded against a non-delivering transport, but only that one, and the default mailer is `log`.

**Verdict: the system does not currently attempt to send most of what its 11 blade templates suggest.** No email was sent or observed in this audit. **This audit makes no claim that email works.**

`EXTERNAL INFRASTRUCTURE — NOT VERIFIED`: Resend account, domain verification, SPF/DKIM/DMARC, delivery, bounces, complaints.

---

## 14. FRONTEND

**Verified:** 62 pages, all `React.lazy`; 5 role-guarded layouts; 23 API modules; 4 contexts; 11 hooks (**5 with no importers**); 16 `lib/` modules; 44 components; i18n EN/AR with exact key parity (1465 lines each) and a `check-i18n` script that also verifies every statically-used `t('…')` key exists in both.

**Verified strengths:**
- **One** base-URL implementation (`src/lib/apiUrl.ts`), imported by both the live client and the replay loop — created specifically to stop a second derivation appearing.
- 401 handling clears localStorage, the in-memory request cache, **and** the IndexedDB queue before redirecting.
- Session restore keeps a cached session on network failure and clears it only on 401 — offline-first, correct.
- No `fetch`, no `XMLHttpRequest`, no `sendBeacon`, no `dangerouslySetInnerHTML` anywhere. The single `innerHTML` clears a QR-scanner mount node.

**Verified weaknesses:**
- The bearer token is in **`localStorage` in plaintext**, alongside a user object containing email, phone, address and `attendance_qr_token`. Mitigated by a strict CSP and the total absence of `dangerouslySetInnerHTML`, but not by an `HttpOnly` cookie. **R-66**
- **Zero component, routing, guard or context tests.** All 8 test files target `lib/` and `api/client.ts`. A regression in the role guard is caught by nothing. (Low severity — the guard is explicitly UX-only — but the guard's *correctness as UX* is unverified.)
- `useRoleAccess` implements a numeric hierarchy that **disagrees** with the route guard's flat allowlist. Two models, one codebase, neither a boundary. **R-63**
- `api/structure.ts:17` calls `/structure-management/stages-with-classes` — **a path that does not exist** in `routes/api.php`. Dead call. **R-64**
- `GET /events/{id}/reports/*` is fetched via a plain `<a href>` with **no `Authorization` header**, which cannot work against bearer-token-only auth. Untested. **R-70**
- `src/utils/deviceDetection.ts` and `src/pages/servant/LocalAttend.tsx` are **0 bytes**.

---

## 15. PWA / OFFLINE / SERVICE WORKER

**Verified — and this is the strongest frontend result in the audit.**

The **generated** `frontend/dist/sw.js` (9 561 bytes) contains the substring `/api` **exactly once**, and that occurrence is inside `NavigationRoute(createHandlerBoundToURL("/index.html"), {denylist:[/^\/api\//]})` — a rule that **prevents** `/api/` navigations from being rewritten. There are exactly **two** registered routes: that navigation route and one `CacheFirst` for Google Fonts. `globPatterns` excludes `json` and anything matching `api`. No `strategies: 'injectManifest'`, no hand-written SW, no `srcDir`.

**⇒ `/api/` responses can be cached neither by precache nor at runtime. VERIFIED against the built artefact, not the config.**

And the test guard is unusually well built: `pwaIsolation.test.ts` asserts against the **generated** `dist/sw.js` (4 tests gated on `existsSync`), and **CI runs `npm run build` before `npm test` precisely so those assertions cannot be silently skipped** — with a comment explaining exactly which failure mode that prevents.

**Offline queue — verified correct:**
- IndexedDB `church-manager` v1, 2 object stores: `syncQueue` (attendance writes only) and `cache` (declared, effectively unused at runtime).
- `OFFLINE_WRITABLE_PATTERNS` matches exactly the 2 real attendance endpoints, against the *relative* path the interceptor actually sees.
- MDN confirms IndexedDB is partitioned **per origin, not per user** — so the app must isolate sessions itself, and it does: a full wipe on login / platformLogin / logout / 401, **plus** a token re-check before **every** send that voids the run on change, **plus** per-item credential replay. 40 tests across 3 files.
- Terminal states are correct: 4xx → `abandoned` immediately (a duplicate attendance 422 must not retry 5 times); 5xx → backoff `2000 × 2^n`; at 5 → `abandoned` and never re-selected; a poison item does not block items behind it.
- **Residual (R-07):** isolation is by *session-boundary timing*, not data keying — `SyncQueueItem` has no `church_id` or `user_id` field. The safe direction is preserved (a stale item replays with the *original* tenant's credential, so the server sees the original identity, not a cross-tenant write).

---

## 16. EXTERNAL SERVICES

| Service | Purpose | Status |
|---|---|---|
| **Vercel** | frontend hosting | Configured (`vercel.json`). **EXTERNAL — NOT VERIFIED** |
| **Railway** | backend + worker + scheduler | Configured (`railway.json`, `Dockerfile`). **EXTERNAL — NOT VERIFIED** |
| **Supabase PostgreSQL** | primary DB | Configured. **EXTERNAL — NOT VERIFIED** — version, backups, PITR all unknown |
| **Supabase Storage** | file storage | Configured; 4 of 5 buckets `public => true` (R-39). **EXTERNAL — NOT VERIFIED** |
| **Resend** | email | Configured but largely inert (R-41). **EXTERNAL — NOT VERIFIED** |
| **GitHub Actions** | CI | **VERIFIED** — 1 workflow, 3 jobs |
| **DNS provider** | — | **NOT DETECTED** in the repository |
| **Sentry / monitoring / APM** | — | **NOT PRESENT** |
| **Redis / RabbitMQ / SQS** | — | **NOT PRESENT** (Redis compiled but not deployed) |

---

## 17. INFRASTRUCTURE

Full map: `PHASE_0_INFRASTRUCTURE_MAP.md`.

**Configured production topology:**
```
User → Vercel (SPA) → Railway (nginx + php-fpm + queue worker + scheduler)
                          → Supabase PostgreSQL
                          → database queue → Supervisor worker
                          → Resend (largely inert)
```

**Verified strengths:**
- **`docker-entrypoint.sh` is a genuinely strong control** — hard-fails production boot on 7 misconfigurations, on an unreachable database, and **on a failed migration** ("The container will not start with an unknown schema state"), and never prints a secret.
- The **PostgreSQL CI job is unusually well designed** — provisions the real engine, migrates from scratch, requires `tenant:audit` to be clean, verifies the schema catalog, rolls back and re-applies, then runs a named 27-file security suite and the full suite — with a comment tying each step to the historical incident that motivated it.
- `/storage/{path}` is correctly containment-checked with `realpath()` + prefix matching, refusing `..`, absolute paths and symlink escapes.
- Production CSP is `default-src 'none'` for the API; frontend CSP is `script-src 'self'` with no `unsafe-inline`/`unsafe-eval`.

**Verified weaknesses:**
- **Railway's health check probes a static nginx `return 200`** — a failed PHP, database, queue or scheduler inside the container is invisible (R-05).
- **No CD.** CI gates merges; deployment is out-of-band and unverifiable (R-06).
- `RUN_MIGRATIONS` defaults to `true` on every replica; Docker Compose de-conflicts it, **Railway's replica count is unknown** (U-4).
- `LOG_STACK=single`, no rotation, no log shipping, container-local (R-43).
- **No backup, no restore, no runbook** (R-04).
- `TRUSTED_PROXIES=*` means a client that can set `X-Forwarded-For` bypasses every IP-keyed rate limiter including `login` (R-40).
- `backend/.env.docker` is tracked in git with a set (local) `DB_PASSWORD` (R-47).

---

## 18. ENVIRONMENT VARIABLES

**No secret value was printed.** Every variable below is reported as PRESENT / MISSING / REFERENCED / UNUSED only.

- `backend/.env` and `frontend/.env` are correctly git-ignored and untracked.
- `backend/.env.example` is committed and comprehensive for the categories it covers.
- **~20 variables consumed by config and `docker-entrypoint.sh` are absent from `.env.example`**: `RUN_MIGRATIONS`, `RUN_PERMISSION_SEEDER`, `PORT`, `DB_MAX_ATTEMPTS`, `DB_RETRY_DELAY_SECONDS`, `LOG_STACK`, `LOG_LEVEL`, `DB_QUEUE`, `REDIS_*`, `MAIL_SCHEME`, `MAIL_URL`, `DB_FOREIGN_KEYS`, and others. An operator has **no authoritative list** of what a production deploy must set. (R-15 in the infra map / P3)
- **Orphaned:** `POSTMARK_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_DEFAULT_CHANNEL`, `MAIL_REPLY_TO_*` — configured, referenced by nothing.
- **Not verified:** actual production values. No deployed `.env` exists in the repository, and Railway/Vercel variable stores are external.

---

## 19. SECURITY TOOLING (inventory only — nothing added)

| Tool | Status |
|---|---|
| PHPStan + Larastan, **level max** | **PRESENT** — 0 errors, verified locally. One controller is **excluded** (`AttendanceContextController`). R-61 |
| Laravel Pint | **PRESENT** — passed. No `pint.json`, so the default `laravel` preset |
| ESLint 10 + `typescript-eslint` + `react-hooks` | **PRESENT** — 0 errors, verified locally |
| i18n parity gate (`check:i18n`, `check-lang-parity.php`) | **PRESENT** — both pass |
| Migration rollback lint | **PRESENT and a CI gate** — currently reports **5 findings** (R-08) |
| `composer audit --locked --no-dev` | **PRESENT in CI** — **not executed in this audit** (no network) |
| `npm audit --audit-level=high` | **PRESENT in CI** — **not executed in this audit** |
| Secret scanning / SAST / SBOM / dependency review / DAST | **NOT PRESENT** |
| Branch protection / required reviewers / environments | **NOT VERIFIED** — GitHub-side, not visible to `git` |

---

## 20. DOCUMENTATION AUDIT

| Document | Exists | Current? | Matches implementation? |
|---|---|---|---|
| `README.md` | ✅ | partly (2026-06) | **CONFLICT** — claims PostgreSQL 15 and "Laravel Mail"; both contradicted elsewhere. No Railway/Vercel setup detail. |
| `AGENTS.md` | ✅ | yes | **Accurate.** Every rule in it corresponds to real code, and the "Common Pitfalls" list (test emails, `Church::factory()`, PostgreSQL in tests, UTF-8 audit logs, PII masking, church scope, stage isolation, Windows tests, CORS on Railway, Resend removed) is **correct against the source**. Strongest doc in the repo. |
| `backend/docs/TENANT_RULES.md` | ✅ | yes | **Remarkably accurate.** §5's `ChurchScope` fail-closed branch is present in code. §6's MATCH SIMPLE / no-`ON UPDATE CASCADE` / mandatory parent-UNIQUE analysis **matches the official PostgreSQL documentation exactly**. §12a on legacy `class_years` is correct. §14's verification commands are the ones actually in CI. |
| `SECURITY.md` | ✅ | 2026-09-22 | Not audited for content accuracy |
| `CONTRIBUTING.md` | ✅ | 2026-07-19 | Small |
| `details.txt` | ✅ | 2026-06-26 | **Stale** — predates the entire stage/class/tenant-composite-FK work |
| `AUDIT_CHANGES.md` | ✅ | 2026-09-20 | **Stale** — predates the composite-FK migration (2026-09-29) |
| 8 further audit reports at the repo root | ✅ | mixed | **2 untracked.** Several claim "PRODUCTION READY" for a tree whose HEAD says "NOT production ready". Treated as **historical context only**; no claim in them was accepted without re-verification. |
| Architecture doc | ❌ | — | **Missing** |
| Deployment runbook | ❌ | — | **Missing** |
| Backup / recovery runbook | ❌ | — | **Missing** — and no backup mechanism exists either |
| PWA / offline doc | ❌ | — | **Missing** (explained in code comments and `TENANT_RULES.md` §11) |
| Environment doc | partial | — | `.env.example` only; ~20 vars undocumented |

---

## 21. GIT / RELEASE

**Verified:** branch `main` only; 144 commits; **0 tags**; **296 modified files (+9 066 / −3 584)** and **30 untracked paths**; HEAD message literally `"WIP: security hardening in progress (see conversation) - NOT production ready"`. No changelog, no release workflow, no version file, no CODEOWNERS.

**Nothing was reset, rebased, stashed, committed, or pushed. The working tree is exactly as found.**

---

## 22. AUDIT COVERAGE

### Fully inspected
Repository structure · dependencies & runtime versions · all 218 API routes with resolved middleware stacks · all 37 controllers · 44 FormRequests · 13 policies · 10 middleware · 40 services · 32 models · 108 migrations · config/ (all 15 files) · bootstrap/app.php · the full API middleware and exception stack · queue/jobs/notifications/observers/mail · console commands and schedule · Dockerfiles · docker-compose · supervisord · nginx configs · vercel.json · railway.json · CI workflow · frontend structure, routing, API layer, auth/session, IndexedDB, offline sync, service worker, i18n, tests · environment variable *names* (values never printed) · git state.

### Partially inspected
Policies' *intent* (read, but their callers were not traced for every sub-resource id) · `NotificationService` ownership enforcement (traced to the call, not to the query) · `EventScheduleService` / `EventBusService` sub-resource resolution · `AttendanceService`'s `recordedBy` trust assumption · `Cleanup` command internals.

### NOT INSPECTED
Production database · production logs · Vercel project · Railway project/service replicas · Supabase project (buckets, RLS, backups) · Resend account (domain, SPF/DKIM/DMARC, delivery) · GitHub repository settings (branch protection, environments, secrets) · DNS · any backup or restore · dependency vulnerability scan (no network) · browser behaviour on real devices · mobile Safari / iOS storage partitioning in practice.

---

## 23. WHAT THIS AUDIT DOES **NOT** CLAIM

- ❌ That the system is secure, production-ready, or fit to deploy.
- ❌ That a backup exists. **No restore was performed.**
- ❌ That email works. **No delivery was attempted or observed.**
- ❌ That PostgreSQL compatibility holds. **PostgreSQL was never executed.**
- ❌ That the tenant constraints exist in production. **No live catalog was queried.**
- ❌ That any prior report's conclusions are correct. Every material claim was re-derived from code.
- ❌ That the working tree is deployable. **296 files are uncommitted; HEAD says "NOT production ready".**

---

## 24. TOP RISKS (P0/P1 first)

| ID | Sev | Finding | Confidence |
|---|---|---|---|
| **R-01** | **P1** | The entire tenant-isolation hardening layer is **uncommitted and untagged**; HEAD says "NOT production ready". 296 modified + 30 untracked. The 542 green tests describe a working tree, not a commit. | **VERIFIED** |
| **R-02** | **P1** | Tenant constraints **unverified against a live PostgreSQL** — no engine reachable; 6 concurrency tests skipped. This exact question already caused a shipped incident. | **VERIFIED** (that it is unverified) |
| **R-03** | **P1** | Membership-request approval's **sole** tenant boundary is one service-level comparison with **no test**. `MembershipRequestSubmitTest` has 1 test. | **VERIFIED** |
| **R-04** | **P1** | **No backup configuration, no restore procedure, no restore ever performed.** Every destructive operation assumes recoverability. | **VERIFIED** (absence) |
| **R-05** | **P1** | Railway health check probes a **static nginx `return 200`** — PHP/DB/queue/scheduler failure is invisible. | **VERIFIED** |
| **R-06** | **P1** | **No CD.** CI gates merges; whether a passing commit is what deploys is external. | **VERIFIED** |
| **R-07** | **P1** | Offline attendance replay is at-least-once with **no client idempotency key**; a permanently-abandoned write is never surfaced to the user. | **VERIFIED** |
| **R-08** | **P1** | **5 migrations fail the repository's own rollback lint**, which is a CI gate. | **VERIFIED** — executed |
| **R-09** | **P1** | No tags, no changelog, no release history; the audit trail is untracked. | **VERIFIED** |

**No P0 was found.** See `PHASE_0_RISK_REGISTER.md` for the full register (P2 ×24, P3 ×16, INFO ×7) and for the 12 highest-risk unknowns.

---

## 25. PHASE 0 DELIVERABLES

| File | Contents |
|---|---|
| `PHASE_0_SYSTEM_INVENTORY.md` | this document |
| `PHASE_0_ENDPOINT_MATRIX.md` | all 218 routes, policies, middleware, tenant-input A/B/C classification, scope bypasses |
| `PHASE_0_DATABASE_MATRIX.md` | 40 tables, 108 migrations classified, composite FKs, raw SQL, legacy `class_years` |
| `PHASE_0_SERVICE_MATRIX.md` | 40 services, transaction boundaries, authorization posture, queue/email trace, console commands |
| `PHASE_0_INFRASTRUCTURE_MAP.md` | topology, CI/CD, queue, storage, domains, env vars, observability, backup |
| `PHASE_0_TEST_GAP_MATRIX.md` | what the 542 + 72 tests actually verify, and the ranked gaps |
| `PHASE_0_RISK_REGISTER.md` | P0–P3 + INFO register, confidence, 12 highest-risk unknowns |
| `PHASE_0_EXECUTIVE_SUMMARY.md` | the dashboard and the recommended Phase 1 |
| `PHASE_0_DOCUMENTATION_RESEARCH.md` | 10 research questions, sources, and their effect on findings |

**No application code, schema, configuration, dependency, permission or infrastructure was modified in Phase 0.**
