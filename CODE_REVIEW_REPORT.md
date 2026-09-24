# Church Management System — Complete Engineering Review

**Review date:** 2026-09-22  
**Scope:** Current repository implementation, project documentation, prior audit reports, backend/frontend configuration, routes, selected services/controllers/repositories, CI, and tests.  
**Change policy:** The confirmed P0/P1 findings were remediated in application source, deployment configuration, CI, and documentation; unrelated findings were left unchanged.

## Executive Summary

The system has a credible Laravel/React/Docker architecture and several strong security foundations: Sanctum authentication, server-side approval checks, centralized role/scope resolution, transaction-based attendance and password-reset workflows, token-scoped frontend caching, lazy-loaded routes, and explicit cache/header controls.

However, the current implementation cannot be considered production-ready for security-sensitive multi-tenant use. The application-level P0/P1 defects identified in the pre-remediation review are now marked remediated, but PostgreSQL, Linux runtime, browser, concurrency, live deployment, and frontend bearer-token storage gates remain open. See `PRODUCTION_HARDENING_REPORT.md` for the current OWASP and production-readiness assessment.

The repository now documents authenticated SMTP, four-hour QR expiry, role-aware prefetching, after-commit queue behavior, and production fail-closed checks. Performance concerns remain code-level observations rather than benchmarked latency claims.

**Current conclusion:** Not ready. The critical application findings have been remediated and regression-checked, but live deployment behavior, PostgreSQL-specific behavior, Linux Docker quality gates, browser flows, true concurrency, and the frontend bearer-token storage architecture still require verification or correction before release.

## Remediation Status

The current remediation set is implemented. QR check-in now resolves the requested event and performs event-scoped token lookup before mutation; registration targets are checked against the event church and the registering actor's resolved scope; storage API deletion and replacement verify the caller-supplied bucket; attendance-context active reads require authentication and use church-scoped queries; invite expiry is four hours and raw invite tokens are no longer logged; event sub-resources use centralized tenant and Stage/Class authorization middleware; production startup fails closed when required runtime settings are absent or unsafe; the production supervisor runs the scheduler; CI includes dependency audits, PHP syntax validation, frontend checks, Compose validation, and a PostgreSQL job; queue dispatch uses after-commit behavior; QR rotation is available; and frontend route prefetching is role-aware.

Verification completed after these changes: **278 backend tests passed with 896 assertions**, including **44 focused security tests with 100 assertions**; all changed PHP files passed syntax validation; frontend ESLint, TypeScript, and EN/AR key parity checks passed; Composer audit reported no advisories; npm audit reported zero high-severity vulnerabilities; and Compose configuration passed. The Linux production image build was blocked because Docker Desktop's Linux engine was unavailable. PostgreSQL execution, browser smoke tests, true concurrency, and live deployment verification remain open.

## 1. Review Basis and Evidence Rules

The review first examined `README.md`, `SECURITY.md`, `AGENTS.md`, `AUDIT_CHANGES.md`, `PRODUCTION_READINESS_REPORT.md`, `STAGE_ADMIN_AUDIT_REPORT.md`, `FINAL_AUDIT_SUMMARY.md`, dependency manifests, and CI configuration. The current source was then inspected against those documented rules.

A **confirmed finding** means the cited implementation or documentation conflict was directly observed. It does not necessarily mean that a live exploit was executed. A **potential risk** means the code path warrants validation but the failure was not established. Historical test/build results in prior reports are treated as historical claims, not current verification.

## 2. What Is Correct

| Area | Evidence | Assessment |
|---|---|---|
| Authentication boundary | `backend/routes/api.php:119-124`; `backend/app/Http/Middleware/EnsureApproval.php:19-54` | Authenticated routes use Sanctum, approval, and throttling middleware; pending/rejected users are constrained server-side. |
| Password-reset workflow | `backend/app/Services/PasswordResetRequestService.php:231-285` | Uses transactions and row locking, hashes replacement passwords, and revokes existing Sanctum tokens after completion. |
| QR token generation and usage control | `backend/app/Services/AuthService.php:180-271`; `backend/app/Services/QRInviteService.php:274-382` | Uses random tokens, transactional locking, expiry/usage checks, and avoids embedding sensitive data in QR payloads. |
| Attendance write path | `backend/app/Services/AttendanceService.php:104-147` | Uses a transaction, member-row locking, duplicate detection, and inline points insertion. |
| Cache isolation | `backend/app/Services/CacheService.php:120-225`; `frontend/src/api/client.ts:61-80,210-270` | Backend cache keys are church-versioned; frontend request-cache keys are token-scoped and mutations invalidate cache entries. |
| Frontend route loading | `frontend/src/App.tsx:21-77` | Pages are lazy-loaded instead of loading the entire application route graph up front. |
| Frontend polling behavior | `frontend/src/components/layout/Header.tsx:132-160` | Notification polling is cleaned up and paused while the document is hidden. |
| Static localization parity | Frontend `npm run check:i18n` result from the audit | EN/AR keys matched exactly at 1,381 keys, with statically used keys present. Runtime Arabic/RTL behavior remains unverified. |
| Container separation | `docker-compose.yml:6-34,73-130`; `backend/production/supervisord.conf:41-52` | Development Compose separates app, worker, and scheduler with health/restart configuration; production PHP-FPM is restricted to localhost and the queue worker restarts automatically. |
| Asset caching | `frontend/nginx.conf:50-54`; `frontend/vercel.json:9-27` | Hashed assets receive immutable caching and the service worker is configured not to be cached indefinitely. |

## 3. Confirmed Problems and Security Vulnerabilities

### Critical / P0

#### 3.1 [Remediated] Event QR check-in mutates state before validating the requested event

- **Location:** `backend/app/Services/EventRegistrationService.php:198-215`; `backend/app/Http/Controllers/Api/EventRegistrationController.php:328-350`.
- **Evidence:** The service globally resolves the QR token and calls `checkIn` before the controller compares the registration/event relationship and returns a 422.
- **Expected behavior:** The token must be resolved within the requested event and tenant, and the event relationship must be validated before any state mutation.
- **Actual behavior:** A token for another event can cause a registration check-in mutation, followed by an error response.
- **Impact/attack path:** A caller with the relevant event-management permission who obtains or guesses a valid token can alter registration state outside the requested event context. Cross-tenant impact is possible if the token lookup is not tenant-constrained.
- **Recommended solution:** Pass the requested event into the service and resolve the token with both event and church predicates inside the same transaction. Validate authorization and registration state before calling `checkIn`. Add wrong-event, cross-church, expired, replay, and duplicate tests.
- **Verification:** PostgreSQL-backed feature tests must assert that a wrong-event token leaves the registration unchanged and returns the documented conflict/error response.

#### 3.2 [Remediated] Storage replacement/deletion trusts a bucket encoded in a caller-supplied URL

- **Location:** `backend/app/Http/Controllers/Api/StorageController.php:130-147,170-185`; `backend/app/Services/SupabaseStorageService.php:80-100,350-392`.
- **Evidence:** The controller validates only the route bucket. The storage service extracts the bucket from the supplied URL and operates on that bucket without comparing it with the route bucket.
- **Expected behavior:** The server must authorize the object identity, bucket, tenant, and operation; a raw URL must not be sufficient authorization.
- **Actual behavior:** A caller with the applicable permission can provide an allowed route bucket and a known URL for a different allowed bucket.
- **Impact/attack path:** A known object URL may permit unauthorized replacement or deletion across storage namespaces, including objects belonging to another feature or tenant.
- **Recommended solution:** Compare the parsed URL bucket with the authorized route bucket, reject mismatch, and replace raw URL authorization with server-owned object identifiers/paths tied to a tenant and resource record. Validate object ownership before destructive operations.
- **Verification:** Add tests for route-bucket/URL-bucket mismatch, cross-tenant object URLs, unknown objects, and valid same-resource operations.

#### 3.3 [Remediated] Stage Admin scope is not enforced on event sub-resources

- **Location:** Parent protection: `backend/app/Http/Controllers/Api/EventController.php:196-198,221-223,271-273,320-347`. Unprotected lookups: `EventRegistrationController.php:395-422`, `EventPaymentController.php:24,59,88`, `EventBusController.php:20,35,54,73`, `EventScheduleController.php:28,41,60,79,98,111,130,149`, `EventDashboardController.php:19,47`, `EventReservationController.php:26,57`, `EventAccommodationController.php:275-278`.
- **Evidence:** The parent event controller uses `cannotAccessEvent()`/`ScopeResolver`, while the listed sub-resource controllers directly call `Event::query()->find()` and rely on coarse permission middleware in `backend/routes/api.php:573-628`.
- **Expected behavior:** A Stage Admin may access only events targeting the assigned stage/classes, including every event sub-resource.
- **Actual behavior:** The documented prior audit itself records this as an unresolved gap; sub-resources do not consistently reuse the parent event scope guard.
- **Impact/attack path:** A Stage Admin with a permitted event-sub-resource operation may read or mutate another stage’s event by changing an event ID.
- **Recommended solution:** Extract a shared `assertCanAccessEvent(User, Event)` authorization helper/policy and invoke it immediately after every event lookup. Avoid duplicating divergent scope logic.
- **Verification:** Add read/write tests for every listed sub-resource using in-scope and out-of-scope event IDs, including registration, payment, check-in, scheduling, dashboard, reservation, accommodation, bus, and lifecycle operations.

#### 3.4 [Remediated] Attendance-context management queries bypass church isolation

- **Location:** `backend/app/Http/Controllers/Api/AttendanceContextController.php:19-30`; `backend/app/Repositories/AttendanceContextRepository.php:61-77`.
- **Evidence:** The controller calls the list service without a church filter. The repository removes `ChurchScope` and applies a tenant predicate only when an explicit filter is supplied.
- **Expected behavior:** Every tenant-sensitive query must apply the authenticated user’s church scope or an independently validated tenant scope.
- **Actual behavior:** The management list can return attendance contexts from all churches to an authorized caller.
- **Impact:** Cross-tenant metadata exposure and possible follow-on misuse of context IDs.
- **Recommended solution:** Keep the repository query tenant-scoped by default; derive the church from the authenticated user. Do not require a client-provided `church_id` to activate isolation.
- **Verification:** Add cross-church list tests for platform, church, stage, servant, and unauthenticated callers as appropriate to the documented product behavior.

### High / P1

#### 3.5 [Remediated] Public attendance-context endpoint can enumerate active contexts across tenants

- **Location:** `backend/routes/api.php:293-296`; `backend/app/Services/AttendanceContextService.php:44-52`; `backend/app/Repositories/AttendanceContextRepository.php:83-97`.
- **Evidence:** The route is unauthenticated, selects `getActive()`, removes `ChurchScope`, and applies no church predicate when no authenticated user exists.
- **Expected behavior:** Public metadata must be explicitly tenant-bound or intentionally global.
- **Actual behavior:** Active church-specific context names can be returned across tenants.
- **Unresolved requirement:** The documentation does not establish whether this endpoint is intended to expose global or church-specific metadata.
- **Recommended solution:** Confirm product intent. If public, require an independently validated church/tenant identifier and return only that tenant’s contexts; never rely on unauthenticated `X-Church-ID` as authorization.
- **Verification:** Add tests for the intended public contract and assert no cross-tenant rows are returned.

#### 3.6 [Remediated] Event registration accepts a target user based on global existence only

- **Location:** `backend/app/Http/Requests/StoreEventRegistrationRequest.php:18-25`; `backend/app/Http/Controllers/Api/EventRegistrationController.php:44-66`; `backend/app/Services/EventRegistrationService.php:41-90`.
- **Evidence:** `user_id` is validated for existence globally, passed through by the controller, and not checked against the event’s church in the service.
- **Impact/attack path:** An authorized operator who knows another user’s ID may register that user against a local event, creating cross-tenant data integrity and privacy issues.
- **Recommended solution:** Derive the target through a same-church, authorized query; validate event and user church/stage/class scope in the service, not only the request.
- **Verification:** Add cross-church and out-of-stage registration tests, plus tests for member self-registration versus staff registration rules.

#### 3.7 [Remediated] Public invite details can disclose classes outside the invite church

- **Location:** `backend/app/Services/QRInviteService.php:231-247`; `backend/app/Models/Scopes/ChurchScope.php:41-57`; `backend/app/Traits/BelongsToChurch.php:58-65`; public route `backend/routes/api.php:78-82`.
- **Evidence:** For an invite without a stage, class lookup uses `Classe::byChurch()` without passing the invite church. The unauthenticated scope fallback applies no filter.
- **Impact:** A public no-stage invite-details request can disclose class names/IDs across tenants.
- **Recommended solution:** Query with `where('church_id', $invite->church_id)` and apply any invite stage constraints explicitly. Treat ambient auth/header scope only as supplemental context.
- **Verification:** Create two churches and a no-stage invite in one; assert that details contain only the invite church’s classes.

#### 3.8 [Remediated] Email verification is documented as mandatory but not enforced at login

- **Location:** `SECURITY.md:42-43`; `backend/app/Services/AuthService.php:62-82,234-247`.
- **Evidence:** Login issues a Sanctum token without checking `email_verified_at`, while registration creates a verification token.
- **Impact:** An unverified account can authenticate despite the documented policy.
- **Recommended solution:** Either enforce verification before issuing tokens, with a localized and documented response, or deliberately revise the security policy and tests. Do not leave the contract ambiguous.
- **Verification:** Add login tests for unverified, verified, approved, inactive, and pending accounts.

#### 3.9 [Remediated] Invite tokens are written to application logs

- **Location:** `backend/app/Services/AuthService.php:260-265`; `backend/app/Services/QRInviteService.php:361-368`; prohibition in `AGENTS.md:205-210`.
- **Impact/attack path:** Anyone with retained log access can use a live invite token until it expires or is revoked.
- **Recommended solution:** Remove raw tokens from logs; log only a one-way fingerprint, invite ID, actor ID, tenant, and outcome. Review retained logs and revoke/rotate affected live invites if necessary.
- **Verification:** Add log-capture tests asserting that raw token values never appear.

#### 3.10 [Remediated] QR expiry policy conflicts with the implementation

- **Location:** `SECURITY.md:51-56` and `AGENTS.md:131-135` specify four hours; `backend/app/Services/QRInviteService.php:19-23,37-42` defaults to 24 hours.
- **Impact:** The security window is six times longer than the documented default.
- **Recommended solution:** Confirm the intended business rule, then align runtime default, environment documentation, UI copy, and tests.
- **Verification:** Test default creation and expiry boundary behavior, including configured overrides.

#### 3.11 [Remediated] Single-container production supervisor does not start the scheduler

- **Location:** Tasks: `backend/routes/console.php:20-30`; Compose scheduler: `docker-compose.yml:104-125`; production supervisor: `backend/production/supervisord.conf:8-52`.
- **Impact:** In a single-container deployment without an external scheduler, expired-invite and audit-log cleanup may never run.
- **Recommended solution:** Add a documented scheduler process or configure an external scheduler/cron. Do not assume the development Compose scheduler exists in the production image.
- **Verification:** Run the actual production image and confirm scheduler health, task execution, restart behavior, and failure logging.

## 4. Documentation and Architecture Review

### 4.1 Architecture strengths

The repository follows the intended broad layering: API routes and middleware, form requests, controllers, services, repositories, models, policies, resources, and frontend API modules. Scope resolution is centralized in `ScopeResolver` for major parent resources, and the project separates coarse permissions from finer stage/class checks.

The main architectural weakness is inconsistent boundary reuse. Event parent routes have a scope guard, but event sub-resources independently fetch models and do not reuse it. This is a security boundary duplication problem, not merely a style issue.

### 4.2 Documentation conflicts requiring resolution

| Conflict | Evidence | Required decision |
|---|---|---|
| Email provider/behavior | `README.md:37-40,47-57` documents Resend; `AGENTS.md:334-345` and `AUDIT_CHANGES.md:249-251` say Resend/email sending was removed; `backend/app/Services/EmailService.php` and `SendEmailJob.php` still contain mail dispatch code | Publish one authoritative runtime contract and remove stale code/docs. |
| QR expiry | Security docs say four hours; service defaults to 24 hours | Confirm business/security rule and align code/docs/tests. |
| Docker quick start | `README.md:93-113` says copy the example override and expects port 3000; `docker-compose.override.yml.example:1-18` does not define the full stack, while tracked override uses frontend port 5173 | Make the documented command reproduce the documented stack, or rewrite the quick start. |
| Production readiness | Prior reports claim readiness while `STAGE_ADMIN_AUDIT_REPORT.md:41-45` explicitly records event sub-resource scope as unresolved | Update readiness status after P0/P1 fixes and current verification. |

### 4.3 Current documentation status

The historical conflicts above are now **Remediated** in the implementation and primary documentation: the runtime mail contract is authenticated SMTP, QR expiry defaults to four hours, the README quick start matches the tracked Compose stack, and the production scheduler is configured. The historical evidence is retained to preserve review traceability.

## 5. Database, Transactions, Cache, and Concurrency

The attendance write path demonstrates good transaction discipline. However, the strongest concurrency claims are not currently proven by the available tests: tests named for concurrency perform sequential requests (`backend/tests/Feature/QRInviteTest.php:176-196`; `AttendanceTest.php:56-92`). Add PostgreSQL-backed parallel transaction/request tests for duplicate attendance, QR usage, bookings, and event registration.

`backend/config/queue.php:38-45` sets `after_commit` to false. A queued job dispatched while a transaction is open may run before the transaction commits. The reviewed attendance event is dispatched inside a transaction (`AttendanceService.php:104-147`), but the current listener was not proven to be queued, so this is a potential runtime risk rather than a confirmed stale-read defect. Review every event/job dispatch from transactional workflows and use after-commit dispatching where consumers read newly committed data.

Backend tests use SQLite (`backend/phpunit.xml:27-29`) while production uses PostgreSQL. This is an explicit documented trade-off, but it leaves PostgreSQL-specific locking, JSON, constraints, indexes, and transaction behavior insufficiently covered. Add a PostgreSQL integration job rather than treating SQLite green status as production-dialect verification.

PHPStan excludes `AttendanceContextController.php` and suppresses broad iterable diagnostics (`backend/phpstan.neon.dist:7-15`). Narrow these exclusions or justify them with targeted tests; a clean report does not currently cover that controller.

## 6. Frontend and Performance Review

### Confirmed code-level performance concerns

1. `frontend/src/App.tsx:89-118` prefetches admin, servant, and member route chunks for any authenticated user. This increases first-session download and parsing work for every role. Select prefetch targets from the authenticated role and likely next destinations.
2. `frontend/src/lib/dataPrefetch.ts:42-59` schedules 17 admin GET tasks, while `RUN_DELAYS` at `:20` defines only 13 entries. Tasks at indexes 13–16 use the fallback 3,000 ms delay in `:94-103`, causing the final tasks to launch together. This is a confirmed scheduling fact, not a measured latency claim. Make preheat page/role-driven and define an intentional delay for every task.
3. The frontend has no test script in `frontend/package.json:6-13`, and no tracked component/spec suite was found. CI therefore cannot automatically verify route access, cache invalidation, PWA behavior, QR/camera flows, or runtime RTL behavior.

### Measurements and limitations

No current document-load, API, database, rendering, FCP, LCP, or bundle measurement was completed. The historical QR chunk size in `PRODUCTION_READINESS_REPORT.md:128` is not a current benchmark. Do not describe the application as slow or fast until browser/network traces and representative API/database timings are collected.

The configuration shows positive design choices: route-level lazy loading, token-scoped in-memory caching, visibility-aware polling, immutable hashed-asset caching, and no authenticated API runtime caching in the service worker configuration. Browser-level PWA/offline/camera and CSP behavior remain unverified.

## 7. Infrastructure, CI, Dependencies, and Secrets

- `composer.lock` and `frontend/package-lock.json` are present.
- The available frontend lockfile audit reported zero high-severity vulnerabilities; Composer audit could not be rerun because Composer was unavailable.
- CI does not run `composer audit` or `npm audit` (`.github/workflows/ci.yml:26-27,66-67,103-104`). Add both gates with documented failure policy.
- Base images and dependencies are broadly mutable: `backend/Dockerfile:1,13,19`, `frontend/Dockerfile:1,29`, and caret ranges in both manifests. This is a reproducibility/governance concern, not proof of a vulnerability. Pin where practical and review update policy.
- `docker-compose.yml` defaults `APP_TARGET` to development (`:6-11,73-78,104-109`). Production deployments must explicitly select the production image stage.
- Conditional fallback to `backend/.env.docker` can leave `APP_ENV=local` and `APP_DEBUG=true` if runtime variables are incomplete (`backend/docker-entrypoint.sh:24-31`; `.env.docker:1-6`). Fail closed for production rather than silently using development defaults.
- Docker, PHP, Composer, and the full frontend quality commands were not runnable in this sandbox. No current clean/failed result is claimed for those tools.

## 8. Testing Gaps

Highest-risk missing tests are:

1. Wrong-event and cross-tenant QR check-in must not mutate state.
2. Storage URL bucket mismatch and cross-tenant object replacement/deletion.
3. Stage Admin access to every event sub-resource, for both reads and mutations.
4. Attendance-context management and public endpoint cross-tenant isolation.
5. Cross-church event registration and client-controlled target IDs.
6. No-stage invite class scoping.
7. Unverified-login behavior and invite-token log redaction.
8. Four-hour/24-hour QR expiry boundary.
9. PostgreSQL locking, unique constraints, JSON behavior, and true concurrent operations.
10. Frontend role routing/prefetch, Stage Admin screens, QR/camera flows, PWA/offline behavior, and Arabic/RTL runtime rendering.
11. Scheduler execution in the production image and queue after-commit behavior.

## 9. Historical Pre-Remediation Fix Plan

The checklist in this section records the plan that led to the completed application-level remediation. It is retained for traceability; current open work is listed in Section 10.

### P0 — Block release

- Fix event QR check-in ordering and tenant/event predicates.
- Enforce storage bucket and object ownership; do not authorize destructive operations by raw URL.
- Apply the Stage Admin event-access guard to all seven event sub-resource controller families.
- Correct attendance-context management tenant scoping and decide/secure the public endpoint contract.

**Likely files:** event registration service/controller, storage controller/service, event sub-resource controllers, attendance-context controller/service/repository, policies/scope helpers.  
**Required tests:** cross-tenant and cross-stage authorization matrix, wrong-event token mutation, storage mismatch, public/management context isolation.  
**Change risk:** High; changes touch authorization and mutation boundaries. Use focused regression tests and review all callers.

### P1 — Required before production

- Enforce or deliberately revise email verification policy.
- Remove raw invite tokens from logs and review retained logs.
- Align QR expiry implementation and documentation.
- Validate same-church ownership for event registration targets.
- Ensure the scheduler runs in every production deployment mode.
- Publish one authoritative email/runtime configuration.

**Change risk:** Medium to high; may affect login UX, operational workflows, and existing invite validity.

### P2 — Reliability and performance

- Make README and Compose quick start consistent.
- Add dependency audit gates and Compose validation to CI.
- Fail closed when production environment variables are incomplete.
- Reduce role-agnostic route prefetching and make preheat scheduling intentional.
- Add frontend component/browser smoke coverage.

### P3 — Assurance and maintainability

- Add PostgreSQL CI alongside SQLite.
- Replace sequential “concurrency” tests with real concurrent tests.
- Narrow PHPStan exclusions/suppressions.
- Pin infrastructure inputs and establish dependency update governance.
- Add request IDs/structured tracing and verify deployment headers, PWA, CSP, and CORS in the live environment.

## 10. Verification Limitations and Open Issues

- **Open:** PostgreSQL-backed integration and true concurrent transaction tests have not yet been run.
- **Open:** Linux production-image, Docker Compose, PHP-FPM, Nginx, queue-worker, scheduler, and fail-closed runtime verification remain to be run in the target Linux environment.
- **Open:** Browser smoke tests for login, role navigation, event flows, QR/camera flows, session expiration, and Arabic/RTL rendering are not yet present.
- **Open:** The queue after-commit audit remains incomplete for every job dispatched from transactional workflows.
- **Open:** PHPStan exclusion narrowing, dependency pinning, request tracing, and live security-header/CSP/CORS verification remain assurance work.
- **Verified locally:** 278 backend tests passed with 896 assertions, including 44 focused security tests with 100 assertions; changed PHP syntax checks passed; frontend ESLint, Arabic/English key parity, and TypeScript checks passed; Docker Compose configuration passed validation. The production image build was attempted but blocked because Docker Desktop’s Linux engine was unavailable.

## Final Answer to the Review Question

> Does the current implementation correctly follow the documented architecture, design patterns, system design, security model, business rules, database model, performance expectations, and clean-code standards?

**The critical application-level findings are remediated, but the project is not yet fully production-verified.** The remaining release gates are PostgreSQL/concurrency verification, Linux/Docker runtime verification, browser smoke coverage, queue after-commit review, and live deployment security checks. The current implementation should not be labeled fully production-ready until those gates pass.
