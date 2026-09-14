# Production Readiness Report — Church Management System

Date: 2026-09-14
Scope: Full-stack production-readiness audit (Laravel 12 + PostgreSQL + React/TS + Vite/PWA + Docker/Nginx; Vercel frontend, Railway backend/Supabase storage).

---

## 1. Executive Summary

**Verdict: PRODUCTION READY — with two environment variables to set before go-live (see §5).**

This audit verified the stack end-to-end against actual code, fixed the **one confirmed critical vulnerability (P1 storage BOLA/IDOR)** and one **P2 cross-church data-leak**, added regression tests, and hardened production headers. All quality gates pass at the time of writing:

- Backend: **244 tests / 704 assertions** green; PHPStan **level-max 0 errors**; Pint **clean**.
- Frontend: `tsc --noEmit` clean, ESLint **0 warnings/0 errors**, production build succeeds, PWA `sw.js` is **precache-only (no authenticated API caching)**.
- `composer audit --locked --no-dev`: **No security vulnerability advisories found.**
- No hardcoded secrets in tracked files (repo-wide scan of api keys, passwords, `APP_KEY`, URLs).

The only item blocking a clean go-live is operational, not code: the live production CORS allowlist on Railway must include the Vercel origin (see §5).

---

## 2. Scorecard

| Area | Status | Notes |
|---|---|---|
| Authentication / tokens | PASS | Sanctum bearer + role/permission middleware, `approved` gate, rate limiters |
| Authorization (church isolation) | PASS | `ChurchScope` global scope + `byChurch()` + per-role scope resolution; 2 leaks found/fixed this audit |
| Storage security | PASS | P1 BOLA/IDOR fixed: permission-gated routes, server-side bucket allowlist, 422 on invalid bucket |
| QR invite system | PASS | One-time/expiry/revoke; server-side church scoping of class/stage/context now enforced |
| Attendance & points | PASS | Service-layer duplicate prevention + date-based DB unique indexes (driver-aware) |
| Feedback / verses / events / registrations | PASS | Scoped controllers, lifecycle + payment + registration services with lockForUpdate |
| Password reset (admin-approved, no email) | PASS | In-app notification flow; tokens removed; admin sets new password; status Completed |
| API / rate limiting | PASS | 38 named limiters (login 5/min per IP+email, api 300/min, uploads, invites, etc.) |
| CORS / browser security | PASS* | Config correct; **requires env vars on Railway** (§5) |
| Security headers | PASS | HSTS + strict CSP added to frontend nginx/Vercel; HSTS on backend nginx; `camera=(self)` restored for QR scanning |
| PWA / caching correctness | PASS | SW does not cache auth'd API data; token-scoped in-memory cache w/ generation guard (prev. audits) |
| Database schema | PASS | Church-scoped FKs, cascade rules, performance indexes, unique constraints |
| Docker / infra | PASS | Multi-stage hardened Dockerfile, php-fpm localhost binding, supervisor + queue worker, healthchecks |
| CI/CD | PASS | PHPStan max + Pint + tests + frontend lint/tsc/build + `docker compose build` |
| Observability | WARN | Logs to stdout/stderr; no request-ID/structured tracing; dependencies only checked via `composer audit` (no CI step) |
| Dependency governance | WARN | `composer audit`/`npm audit` not wired into CI |

---

## 3. Critical Findings

### P1 — Storage BOLA/IDOR (FIXED)
**Before:** `POST /storage/upload/{bucket}`, `POST /storage/upload-document`, `POST /storage/replace/{bucket}`, `DELETE /storage/delete/{bucket}` were reachable by **any authenticated user**, against the shared Supabase project via the **service-role key**, with:
- arbitrary bucket names (create objects anywhere),
- arbitrary existing object URLs for deletion (no ownership/scoping check).

The official SPA only ever calls `upload-profile-image` and `upload-event-image` (verified in the frontend cache-invalidation map).

**After:**
- `routes/api.php` — generic upload/replace/delete/document endpoints gated by `permission:manage_users`, `upload-event-image` gated by `permission:manage_events` (+ `throttle:storage-upload`).
- `StorageController` — `ALLOWED_BUCKETS = [profiles, events, documents, ids, attachments]`, `normalizeBucket()` (lowercase, strips dots/slashes), `guardBucket()` → 422 `VALIDATION_ERROR`.
- `UploadRequest` — validates `bucket` against allowlist via `Rule::in`; image/document branch derived from the **actual route bucket**, fixing the previous body-field-based branch bug.
- Storage service `\InvalidArgumentException` → 422 (was 500).

### P2 — Cross-church QR invite context leak (FIXED)
**Before:** `CreateQRInviteRequest` accepted any `attendance_context_id` (`exists:attendance_contexts,id` across all churches), and `AttendanceController::lookupByToken()` read the context with `withoutGlobalScope(ChurchScope::class)` — a servant in Church A could attach/reference (and the scan endpoint would return name/slug of) another church's attendance context.

**After:**
- `CreateQRInviteRequest` — `class_id`, `stage_id`, `attendance_context_id` all constrained to the creator's `church_id`.
- `lookupByToken` — context resolved via `AttendanceContext::byChurch()->find(...)`; scope bypass removed; out-of-scope context is dropped (response returns `null`).

---

## 4. Other Findings

| Sev | Finding | Disposition |
|---|---|---|
| P3 | `BelongsToChurch::creating` honors a spoofable `X-Church-ID` header, **only** when the creating user has no `church_id` (platform admins / console). Normal users are unaffected (their church wins). | Accept; documented as intended design. Optional hardening: restrict to `PlatformAdmin` role. |
| P3 | Cache default is `file`; production should set `CACHE_STORE=redis` (phpredis + store already configured in Docker image). | Recommendation in `.env.example`. |
| P3 | Backend Observability minimal (stdout logs, no request-ID, no app-level tracing, no `/up` beyond `healthcheck.txt`). | Recommended next step; not blocking. |
| P3 | `composer audit` / `npm audit` not run in CI. | Recommended next step. |
| P3 | Stale comment in `production/supervisord.conf` (queue worker described as "email delivery" only). | Cosmetic; worker is legitimately required for `QUEUE_CONNECTION=database` jobs/send-mail. |
| INFO | `frontend/nginx.conf` `Permissions-Policy` had `camera=()` which would block servant QR scanning in the Docker frontend — corrected to `camera=(self)`. | Fixed. |

---

## 5. Go-Live Checklist (operational)

1. **Railway env vars:** set
   - `CORS_ALLOWED_ORIGINS=https://cms-flame-eta.vercel.app`
   - `FRONTEND_URL=https://cms-flame-eta.vercel.app`
   then restart the backend container. (Preflight OPTIONS currently returns 200 **without** `Access-Control-Allow-Origin` for the Vercel origin → browser blocks POST.)
2. **Vercel env var:** `VITE_API_URL=https://cms-production-7eb4.up.railway.app` (the client appends `/api/v1`), rebuild/deploy.
3. Set production secrets: `APP_KEY`, `SUPABASE_URL`, `SUPABASE_SERVICE_ROLE_KEY`, `SUPABASE_STORAGE_URL`, DB credentials, `QUEUE_CONNECTION=database`, `CACHE_STORE=redis` (+ Redis URL), `SESSION_SECURE_COOKIE=true`, SMTP creds for the remaining transactional mail (`MAIL_MAILER`).
4. Run `php artisan migrate --force`; re-run `php artisan db:seed --class=PermissionSeeder --force` if permissions were changed.
5. `APP_ENV=production`, `APP_DEBUG=false` (already the `.env.example` defaults).

---

## 6. Changes Made This Audit

### Backend (code + tests)
| File | Change |
|---|---|
| `routes/api.php` | Storage endpoints permission-gated; scoped/consistent throttling |
| `app/Http/Controllers/Api/StorageController.php` | Bucket allowlist, normalization, guard → 422, service exceptions → 422 |
| `app/Http/Requests/UploadRequest.php` | Bucket allowlist validation; branch derived from effective route bucket |
| `app/Http/Requests/CreateQRInviteRequest.php` | class_id/stage_id/attendance_context_id scoped to creator's church |
| `app/Http/Controllers/Api/AttendanceController.php` | Removed `withoutGlobalScope(ChurchScope)`; church-scoped context lookup; unused import removed |
| `tests/Feature/StorageEndpointAuthorizationTest.php` | NEW — 12 tests (role matrix, bucket guards, positive paths, 401) |
| `tests/Feature/QRInviteCrossChurchScopingTest.php` | NEW — 3 tests (reject foreign context, accept own context, no context leak on lookup) |

### Deployment / headers
| File | Change |
|---|---|
| `backend/production/nginx.conf` | + `Strict-Transport-Security`, strict CSP (`default-src 'none'`; API JSON only) |
| `frontend/nginx.conf` | + strict CSP (allows Google Fonts, api-origin connects), `camera=(self)` restored |
| `frontend/vercel.json` | + catch-all security headers (HSTS, CSP, frame/ref/policy) |

### Migrations / CI
No migrations or CI changes required. (CI already runs PHPStan max, Pint, tests, lint/tsc/build, docker build.)

---

## 7. Test Results

- **Before this audit:** 229 tests / 676 assertions (baseline).
- **Added:** +15 tests / +28 assertions (storage authorization 12/15, cross-church scoping 3/13).
- **After:** **244 tests / 704 assertions — OK** (30.6s).
- **PHPStan analyse app --level=max:** `[OK] No errors` (301 files).
- **Pint --test:** passed.
- **Frontend:** `tsc --noEmit` clean · ESLint 0 · `vite build` OK (383 modules; largest route chunk `qr` 392.74 kB / 117.36 kB gzip) · PWA precache 138 entries, no API runtime caching.

---

## 8. NOT VERIFIED (requires live environment)

- Live CORS from Vercel → Railway after setting the env vars in §5 (curl/OPTIONS replay recommended).
- Actual header delivery of the new CSP/HSTS from Vercel edge + backend nginx after deploy.
- Real Supabase object egress/ingress paths (Login/storage) beyond unit-level behavior.
- Browser-level CSP validity with the PWA offline + camera flows (no inline scripts in built HTML, so strict CSP is expected to hold).
- npm audit result (no lockfile-driven CI audit yet).