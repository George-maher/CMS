# Church Management System — Production Hardening Report

**Review date:** 2026-09-24  
**Readiness decision:** **NOT READY**  
**Scope:** Architecture, tenant and stage isolation, authentication, authorization, OWASP risks, email and password workflows, QR invitations, storage, database behavior, queues, frontend security, deployment configuration, CI, and available automated verification.

## Executive Summary

The application uses a recognizable layered architecture: React and TypeScript consume Laravel API routes; middleware and form requests establish request boundaries; policies, scope resolution, controllers, services, repositories, Eloquent models, and PostgreSQL persistence implement the application flow. The prior remediation work corrected the highest-risk application-level authorization defects, including event QR mutation ordering, event sub-resource scope enforcement, attendance-context tenant scoping, registration target ownership, storage bucket matching, invite-token logging, and four-hour QR expiry.

The system is **not yet production-ready** because several assurance gates remain unverified and one frontend security concern is architecturally significant: bearer tokens are persisted in `localStorage` to support session persistence and offline synchronization. This increases the impact of any frontend XSS and should be replaced with an HttpOnly-cookie-based Sanctum SPA session, or an explicitly accepted compensating control, before a high-assurance production deployment. PostgreSQL execution, true concurrent tests, Linux production-image execution, browser smoke tests, and live Railway/Vercel checks are also outstanding.

No claim of complete security or production readiness is made.

## Architecture Review

### Correct architecture

The repository consistently uses service and repository abstractions for major domains, Form Requests for validation, Policies and permission middleware for coarse authorization, `ScopeResolver` for stage/class scope, API Resources for response shaping, observers for storage cleanup, and cache abstractions for invalidation. The latest event middleware centralizes parent-event authorization for sub-resources rather than duplicating unrelated checks in every controller.

### Confirmed architectural corrections

| Area | Correction | Evidence |
|---|---|---|
| Event authorization | Centralized event scope service and `EnsureEventScope` middleware | `backend/app/Services/EventAuthorizationService.php`, `backend/app/Http/Middleware/EnsureEventScope.php` |
| Event QR check-in | Event-scoped token lookup occurs before mutation | `EventRegistrationService`, `EventRegistrationRepository` |
| Storage mutation | Route bucket must match the parsed object bucket | `SupabaseStorageService`, storage regression test |
| Attendance contexts | Authenticated, church-scoped active reads | `AttendanceContextRepository`, `AttendanceContextService`, routes |
| Production startup | Production mode rejects missing key, debug mode, wrong environment, and incomplete SMTP configuration | `backend/docker-entrypoint.sh` |
| Queue safety | Database queue dispatches use after-commit behavior | `backend/config/queue.php` |
| Frontend prefetch | Route and data prefetching is role-aware and intentionally staggered | `frontend/src/App.tsx`, `frontend/src/lib/dataPrefetch.ts` |
| CI | Composer/npm audits, PHP syntax, tests, static checks, Compose validation, and PostgreSQL job are configured | `.github/workflows/ci.yml` |

## Security Review

### Confirmed vulnerabilities remediated

| ID | Severity | Root cause | Fix | Verification |
|---|---:|---|---|---|
| SEC-01 | P0 | QR check-in could mutate a registration before validating the requested event | Event- and church-scoped lookup inside the transaction before mutation | Existing event registration tests plus prior remediation |
| SEC-02 | P0 | Storage deletion/replacement trusted URL-encoded bucket identity | Require authorized bucket and reject mismatch | `StorageEndpointAuthorizationTest` |
| SEC-03 | P0 | Event sub-resources did not consistently reuse parent event scope | Centralized event authorization middleware | Existing event-management coverage; full sub-resource matrix remains open |
| SEC-04 | P0 | Attendance-context reads could use ambient/unscoped tenant context | Authentication and church-scoped repository queries | `AttendanceContextTest` |
| SEC-05 | P1 | Registration target ownership was checked too broadly | Same-church and actor-scope validation in service layer | Existing event registration coverage |
| SEC-06 | P1 | Invite expiry and raw token logging conflicted with security policy | Four-hour default and metadata-only logs | QR tests and source inspection |
| SEC-07 | P1 | Production scheduler and environment handling were incomplete | Scheduler configuration and fail-closed production entrypoint | Configuration review; runtime execution remains open |
| SEC-08 | P1 | QR token rotation was absent | Transactional rotate endpoint invalidates the old token and issues a four-hour token | `QRInviteTest::test_rotating_an_invite_invalidates_the_old_token_and_issues_a_new_four_hour_token` |

### Open high-priority risk

**SEC-09 — Browser bearer-token persistence.** `frontend/src/contexts/AuthContext.tsx` stores the Sanctum personal-access token in `localStorage`, and `SyncContext.tsx` reads it for offline requests. The current API contract returns a bearer token from login, so this is not safely removable by deleting two storage calls: doing so would break reload persistence and offline synchronization. The correct remediation is an architectural migration to Sanctum SPA cookie authentication with HttpOnly cookies, CSRF bootstrap, server-side session handling, and a redesigned offline queue that never stores bearer credentials. Until that migration or a documented risk acceptance exists, this remains open.

## OWASP Top 10 Status

| Category | Status | Evidence and remaining risk |
|---|---|---|
| A01 Broken Access Control | **Partially verified** | P0/P1 tenant, event, attendance-context, QR, and storage boundaries have fixes and regression tests. Complete endpoint-by-endpoint cross-stage/search/export/bulk matrix is not complete. |
| A02 Cryptographic Failures | **Partially verified** | Password hashing, token generation, token prefixing, HTTPS-oriented config, and token redaction are present. `localStorage` bearer-token persistence remains open. |
| A03 Injection | **Reviewed, targeted verification pending** | Eloquent parameterization is used in reviewed paths; dynamic SQL and raw HTTP call sites require a complete bounded audit and PostgreSQL execution. |
| A04 Insecure Design | **Partially verified** | Approval, password-reset, invitation, event, and attendance workflows use transactions or state checks. Full retry/concurrency matrix remains open. |
| A05 Security Misconfiguration | **Improved, runtime pending** | Debug defaults are now false in tracked Compose/evaluation templates; production entrypoint rejects debug and missing SMTP. Linux runtime and live environment checks remain open. |
| A06 Vulnerable Components | **Partially verified** | Composer audit reported no advisories and npm audit reported zero high vulnerabilities. Docker base-image and transitive update policy still require review. |
| A07 Identification/Auth Failures | **Partially verified** | Sanctum, rate limits, approval checks, email verification, logout, token revocation, and expiry are implemented. Browser-cookie migration and complete authentication smoke coverage remain open. |
| A08 Software/Data Integrity | **Partially verified** | Lockfiles, CI checks, migration job, queue after-commit setting, and Docker validation are configured. Production image build was blocked by unavailable Docker Desktop Linux engine. |
| A09 Logging/Monitoring | **Partially verified** | Raw invite tokens were removed from reviewed logs and audit metadata is retained. Live log review, request correlation, and monitoring alerts remain open. |
| A10 SSRF | **Reviewed, runtime test pending** | No arbitrary user-controlled fetch feature was established in the reviewed paths. Complete HTTP-client destination review remains open. |

## Tenant and Stage Isolation

The implemented and tested boundaries currently demonstrate the following:

- Church A cannot enumerate Church B attendance contexts through the authenticated active-context endpoint.
- A client-supplied church header does not change the authenticated attendance-context scope.
- Foreign attendance contexts cannot be attached to a QR invite through the validated create flow.
- Event registration targets are checked against the event church and authorized actor scope.
- QR check-in is bound to the requested event and church before mutation.
- Storage route buckets are checked against URL buckets before deletion/replacement.
- Stage Admin event sub-resources use the centralized event scope guard.

The following remain open for a complete matrix: global search, autocomplete, pagination manipulation, exports, bulk operations, notifications, profile requests, reports, dashboard aggregates, and every Stage Admin resource outside the already reviewed event and attendance-context paths.

## Authentication and Password Reset

The password reset workflow follows the documented approval-first model: a request remains pending, an authorized administrator approves it, the password update is transactional, the password is hashed, and existing authentication tokens are revoked. Email verification is checked before issuing the normal user token. Login is rate-limited and Sanctum tokens have an expiration configuration.

The remaining authentication concern is the frontend bearer-token storage model described in SEC-09. Browser smoke tests for login, unverified login, logout, session expiry, and token revocation are not yet available.

## Email Architecture

The selected production contract is **Laravel Mail over authenticated SMTP**. Production requires `MAIL_MAILER=smtp`, host, port, username, password, and from address. The production entrypoint rejects missing SMTP configuration. The queue is configured for after-commit dispatching so queued mail cannot observe uncommitted transaction state.

Composer has no direct Resend requirement in the application contract. Stale Resend references were removed from the tracked Docker templates. Laravel framework/vendor references to optional mail transports are dependency metadata and are not an application provider configuration.

Email delivery, retry, permanent-failure handling, and provider connectivity still require a real production environment test.

## QR Security

QR invite tokens use cryptographically random values, are church/stage-bound through server-side relationships, expire after four hours by default, support revocation, enforce usage limits with locking/optimistic checks, and do not log raw tokens. Rotation now generates a new token transactionally, resets expiry, and invalidates the prior token. Wrong-event, wrong-church, expired, revoked, replay, and exact-boundary PostgreSQL coverage remains incomplete.

## Database and Concurrency

SQLite-based automated verification passed locally, but production uses PostgreSQL. A dedicated `backend/phpunit.postgres.xml` and CI PostgreSQL service job are now present. They have not been executed in this Windows environment. True parallel tests are still required for duplicate attendance, registration, invitation acceptance, room allocation, and other race-sensitive workflows; sequential tests must not be presented as concurrency evidence.

## Frontend and PWA

Frontend ESLint, TypeScript, and exact English/Arabic key parity pass. Request-cache invalidation is implemented for major mutation families and no `window.location.reload()` usage was identified in the bounded source review. Theme and language preferences are stored locally, and authentication data is also currently stored locally; only the latter is a security concern. Browser-level RTL, accessibility, QR/camera, PWA, offline queue isolation, logout-on-shared-device, and cross-user cache tests remain open.

## Deployment and CI/CD

Production Nginx configuration includes frame protection, MIME sniffing protection, referrer policy, permissions policy, HSTS, and CSP. CORS uses an explicit configured origin list and credentials support; production values must not use wildcard origins.

Compose configuration validates successfully. The production image build was attempted but failed before application compilation because Docker Desktop's Linux engine was unavailable. Railway/Vercel configuration and live health checks were not executed in this environment. Production must explicitly select the production image target and provide runtime secrets; development templates are not production secret sources.

## Verification Evidence

| Check | Result |
|---|---|
| Full backend suite | **278 passed, 896 assertions** |
| Focused security suites | **44 passed, 100 assertions** |
| PHP syntax checks | Passed for changed files |
| Frontend ESLint | Passed |
| Frontend TypeScript | Passed |
| English/Arabic key parity | Passed: 1,381 keys; used static keys present |
| Composer audit | Passed: no security advisories |
| npm audit | Passed: zero vulnerabilities at high threshold |
| Compose config | Passed |
| PostgreSQL suite | Configured in CI, not run locally |
| Linux production image | Blocked: Docker Desktop Linux engine unavailable |
| Browser smoke tests | Not implemented |
| Live Railway/Vercel verification | Not performed |

## Remaining Release Gates

1. Migrate or formally risk-accept browser bearer-token persistence; preferred solution is HttpOnly Sanctum SPA authentication.
2. Execute the PostgreSQL CI job and add real parallel concurrency tests.
3. Build and run the Linux production image with Nginx, PHP-FPM, queue worker, scheduler, migrations, health checks, and restart tests.
4. Add browser smoke coverage for login, verification rejection, role navigation, event registration, attendance, QR, Stage Admin, logout, and session expiry.
5. Complete endpoint classification and cross-church/cross-stage search, pagination, export, bulk, report, dashboard, notification, and file-access matrix.
6. Review all raw HTTP destinations for SSRF constraints and add tests where user-controlled destinations exist.
7. Verify live CORS, CSP, HSTS, cookies, PWA cache behavior, accessibility, RTL/LTR rendering, and performance traces.
8. Add request correlation and production monitoring for authentication failures, authorization denials, destructive actions, and cross-tenant attempts.

## Production Readiness Decision

**NOT READY.** The application-level P0/P1 remediation is substantially complete and the local regression suite is green, but the remaining frontend token-storage risk and unexecuted PostgreSQL, Linux runtime, browser, concurrency, and live-deployment gates prevent an evidence-based production-ready declaration.
