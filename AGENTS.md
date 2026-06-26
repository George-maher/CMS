# OpenCode Rules — Church Management System

## 🚨 GLOBAL EXECUTION RULES

1. NEVER generate the whole project at once.
2. ALWAYS work step-by-step.
3. STOP after each completed step and wait for approval.
4. NEVER skip architecture explanation before coding.
5. NEVER assume a file exists unless already created.
6. ALWAYS explain:

   * file path
   * file purpose
   * architecture reason
   * code explanation
7. ALL code must be:

   * production-ready
   * scalable
   * modular
   * secure
   * clean architecture
8. NEVER generate placeholder code unless explicitly requested.
9. NEVER use mock authentication or fake logic.
10. NEVER hardcode secrets or credentials.

---

# 🧠 PROJECT ARCHITECTURE RULES

## Main Stack

* Laravel 12 = Main Backend
* PostgreSQL = Database
* React + TypeScript = Frontend
* Docker = Infrastructure
* Nginx = Reverse Proxy

---

# 📦 BACKEND RULES (Laravel)

## Required Architecture

Backend MUST use:

* Controllers
* Services
* Repositories
* API Resources
* Form Requests
* Middleware
* Policies
* DTOs if needed

---

## Required Folder Structure

Backend structure MUST follow:

app/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   ├── Middleware/
│   └── Resources/
│
├── Services/
├── Repositories/
├── Models/
├── Policies/
├── DTOs/
├── Enums/
├── Traits/
└── Helpers/

---

# 🔐 AUTHENTICATION RULES

Use:

* Laravel Sanctum OR JWT

Required:

* Login
* Register
* Logout
* Password hashing
* Role middleware
* Token validation
* Protected routes

Roles:

* admin
* servant
* member

NEVER skip authorization.

---

# 👥 USER SYSTEM RULES

Users must support:

* name
* email
* password
* birthday
* school_year
* role
* photo
* servant_id
* attendance_qr_token

Relations MUST be properly implemented.

---

# 📲 QR SYSTEM RULES

## VERY IMPORTANT

QR codes MUST NEVER contain:

* passwords
* raw IDs
* sensitive data

QRs must contain ONLY:

* secure token
* secure URL

Example:
https://app.local/register/member?token=XYZ

---

## QR TYPES

Must support:

* admin_to_servant_invite
* servant_to_member_invite
* attendance_qr

---

## INVITE RULES

Each invite MUST:

* have expiration time
* default expire after 4 hours
* support revoke
* support disable
* support single-use

Backend MUST validate:

* token exists
* token not expired
* token not used

---

# 🧾 ATTENDANCE RULES

Attendance flow MUST work as:

1. Servant scans member QR
2. Backend validates QR token
3. Attendance is recorded
4. Duplicate attendance same day is prevented
5. Points are automatically added

Attendance must store:

* member_id
* servant_id
* class_id
* date
* status

---

# 🎯 POINTS SYSTEM RULES

After successful attendance:

* Automatically add points

Requirements:

* Prevent duplicate points same day
* Store reason
* Store timestamp
* Store total points

---

# 🏫 CLASS / YEAR RULES

Must support:

* First Year
* Second Year
* Third Year

Admin can:

* assign servant to year
* change assignments

Servants can only access:

* their assigned members

---

# 👑 ADMIN RULES

Admins can:

* generate servant invites
* manage users
* assign years
* create new admins
* view analytics
* manage attendance

Promoting another admin MUST NOT remove current admin role.

---

# 📊 ANALYTICS RULES

Analytics must support:

* attendance averages
* weekly comparisons
* servant performance
* member attendance rate
* attendance trends

---

# ⚛️ FRONTEND RULES

Frontend MUST use:

* React
* TypeScript
* TailwindCSS
* API layer
* role-based routing

Dashboards:

* Admin Dashboard
* Servant Dashboard
* Member Dashboard

Must support:

* QR scanning
* analytics charts
* attendance pages
* invite pages

---

# 📷 QR SCANNING RULES

Use:

* html5-qrcode OR react-qr-reader

Flow:
Scan → validate → action

Never trust frontend validation only.

---

# 🗄️ DATABASE RULES

Use PostgreSQL.

Must include:

* migrations
* relationships
* indexes
* constraints
* foreign keys

Required tables:

* users
* invites
* attendance
* points
* classes
* events

---

# 🐳 DOCKER RULES

Must create:

* Laravel container
* PostgreSQL container
* Nginx container

Must use:

* docker-compose
* shared network
* volumes

---

# 🌐 API RULES

Use:

* REST APIs
* JSON responses
* proper HTTP status codes

Must include:

* validation
* error handling
* pagination
* authentication middleware

---

# 🔒 SECURITY RULES

Required:

* secure tokens
* expiration validation
* role authorization
* rate limiting
* hashed passwords
* protected API routes

Never expose internal logic publicly.

---

# 🚀 DEVELOPMENT FLOW RULES

Execution order MUST be:

STEP 1:
Backend architecture

STEP 2:
Database design

STEP 3:
Authentication system

STEP 4:
User + Roles system

STEP 5:
QR Invite system

STEP 6:
Attendance system

STEP 7:
Points system

STEP 8:
Docker setup

STEP 9:
Frontend

DO NOT SKIP STEPS.

---

# ❌ FORBIDDEN THINGS

NEVER:

* generate giant code dumps
* skip explanations
* use fake implementations
* mix frontend with backend logic
* bypass architecture
* write insecure QR logic
* store sensitive data in QR
* use weak token generation

---

# 🎯 FINAL OBJECTIVE

Build a complete production-grade Church Management Platform with:

* scalable backend
* secure QR attendance system
* role-based dashboards
* dockerized infrastructure
* clean architecture
* maintainable codebase

---

# 📌 ANCHORED SUMMARY

## Goal
Complete production readiness audit: remove live credentials, fix Docker compose (add PostgreSQL), create CORS/Supabase configs, fix frontend API URL for Vercel/Render deployment, add production docs, clean dead code.

## Constraints & Preferences
- Must look excellent on all screen sizes (320px to 1920px+).
- No horizontal scrolling, clipped content, or broken layouts on any screen size.
- Use Tailwind responsive prefixes (`sm:`, `md:`, `lg:`) for layout changes.
- All tables must have mobile card views; all headers must stack vertically on mobile.
- Light and dark modes must both be readable.
- Fix invalid CSS classes, missing CSS fallbacks, and `undefined` accessor patterns.

## Progress

### Done
1. **Attendance duplicate prevention** — `lockForUpdate()` + `hasAttendanceToday()` in `AttendanceService.php`.
2. **AttendanceFilter onApply fix** — Fixed `class_id=[object Object]` bug.
3. **QR Invite usage limit** — Atomic `markAsUsed()` with `DB::raw('uses + 1')`.
4. **Forgot Password flow** — ResetPasswordNotification, PasswordChangedNotification, Sanctum invalidation, message sync.
5. **Admin-Approved Password Reset Requests**:
   - **Migration** — `password_reset_requests` table (user_id, email, notes, status, token, rejection_reason, reviewed_by, reviewed_at, token_expires_at, used_at).
   - **Model** — `PasswordResetRequest` with `isValidToken()`, `isPending()`, `generateToken()`, `markAsUsed()`.
   - **Enum** — `PasswordResetRequestStatus` (Pending/Approved/Rejected).
   - **Service** — `PasswordResetRequestService` with `submitRequest()`, `approve()`, `reject()`, `completeReset()`, `listRequests()` — all with `DB::transaction()` + `lockForUpdate()`, admin notification on submit, `PasswordChangedNotification` on complete, Sanctum invalidation.
   - **Controller** — `PasswordResetRequestController` with submit (public), index/show (admin), approve/reject (admin), completeReset (public with token).
   - **Form Requests** — `SubmitPasswordResetRequest`, `ApprovePasswordResetRequest`, `RejectPasswordResetRequest`.
   - **Resource** — `PasswordResetRequestResource` (user details, role, phone, class, stage, avatar, etc.).
   - **Notifications** — `PasswordResetRequestSubmittedNotification` (to admins), `PasswordResetRequestApprovedNotification` (to user with reset URL), `PasswordResetRequestRejectedNotification` (to user with reason) — all EN/AR.
   - **Policy** — `PasswordResetRequestPolicy` (admin: viewAny/approve/reject, member/servant: create).
   - **Routes** — Public: `POST /v1/password-reset-requests`, `POST /v1/password-reset-requests/reset`. Admin: `GET /v1/password-reset-requests`, `GET /{id}`, `POST /{id}/approve`, `POST /{id}/reject`.
   - **Backend lang** — `password_reset_requests.*` keys in both `en.json` and `ar.json`.
   - **Frontend API** — `passwordResetRequests.ts` with all 6 endpoints.
   - **Frontend Type** — `PasswordResetRequest` interface.
   - **ForgotPassword.tsx** — Now submits admin-approved requests with optional notes field (textarea, 1000 char max).
   - **AdminPasswordResetRequests.tsx** — Full admin page: filterable list (pending/approved/rejected), detail modal (name, role, email, phone, avatar, class, stage, notes, request time, status), approve/reject modals, rejection reason textarea, pagination.
   - **ResetPasswordFromRequest.tsx** — Set new password page after approval (token + email from URL, validation, auto-redirect to login after success).
   - **i18n** — `passwordResetRequests.*` + `auth.newPassword`, `auth.confirmNewPassword`, `auth.optionalNote` in both EN/AR.
   - **Sidebar** — Added `nav.passwordResetRequests` to admin nav.
   - **Routes** — `/admin/password-reset-requests`, `/assistant-admin/password-reset-requests`, `/reset-password-request`.

### Done (Python Analytics Removed — 2026-06-26)
1. **Deleted AnalyticsProxyController.php** — proxied requests to Python service (never wired in routes).
2. **Deleted SyncAnalyticsToPython.php** — job that synced attendance data to Python service.
3. **Deleted DispatchAnalyticsSync.php** — listener that dispatched the sync job on attendance recorded.
4. **Cleaned AppServiceProvider.php** — removed `DispatchAnalyticsSync` import and listener registration.
5. **Deleted frontend analytics.ts API** — all endpoints proxied to Python.
6. **Deleted admin/Analytics.tsx** — orphaned page (never in routes).
7. **Cleaned i18n** — removed orphaned `analytics.*` keys from en.json and ar.json.
8. **Cleaned .env.example** — removed `ANALYTICS_API_KEY`.
9. **Updated AGENTS.md** — removed Python/FastAPI references from stack, rules, Docker, and development flow.

### Done (Responsive UI/UX Audit — 2026-06-26)
1. **ChurchDeletion.tsx** — Fixed `.toLocaleString()` crash: `summaryItem` `count` param `number` → `number | undefined` with `?? 0` fallback; same for `total_records`; summary grid `gap-2 sm:grid-cols-2` → `grid-cols-1 xs:grid-cols-2`.
2. **Admin MembershipRequests removed** — Deleted file, lazy import, routes, sidebar nav.
3. **Global CSS (`index.css`)** — Added `.stagger-children > *:nth-child(n+9)` fallback for opacity bug; added `.full` modal size class.
4. **QRManagement.tsx** — Filter inputs `w-40` → `w-32 sm:w-40`, `w-36` → `w-32 sm:w-36`, `w-28` → `w-24 sm:w-28`.
5. **ServantQRInvites.tsx** — Same filter bar fix; `ml-auto` → `sm:ml-auto`.
6. **AdminUsers.tsx** — Search `w-full sm:w-56`; header `flex-col sm:flex-row`.
7. **ServantMembers.tsx** — Search `w-full sm:w-56`.
8. **ServantAttendance.tsx** — Added mobile card view (`sm:hidden` cards, `hidden sm:block` table).
9. **PasswordResetRequests.tsx** — Filter buttons `flex-col sm:flex-row` + `flex-wrap gap-1.5`.
10. **Landing.tsx** — Hero h1 `text-3xl sm:text-5xl`, CTA h2 `text-3xl sm:text-4xl`.
11. **Header.tsx** — Notification panel `w-[calc(100vw-1rem)] sm:w-96`.
12. **FeedbackSubmit.tsx** — Replaced invalid `btn btn-primary btn-block` → `btn-primary btn-md w-full`.
13. **AbsentMembers.tsx** — Added mobile card view.
14. **PlatformDashboard.tsx** — Header `flex-col gap-2 sm:flex-row`; filter `w-full sm:w-40`.
15. **FeedbackManagement.tsx** — Header `flex-col gap-2 sm:flex-row`; filter `w-full sm:w-auto`.
16. **AdminEvents.tsx** — Header `flex-col gap-2 sm:flex-row`.
17. **ServantEvents.tsx** — Same flex-col header fix.
18. **VerseManagement.tsx** — Same flex-col header fix.

### Done (Production Readiness Deployment Audit — 2026-06-26)
1. **Security sanitized** — All live credentials removed from `backend/.env`, `.env`, `.env.docker`, `emails` file (DB passwords, Supabase keys, Resend API key, APP_KEY)
2. **`.gitignore` (root)** — NEW — Covers workspace files, node_modules, Docker volumes, `.env`, storage framework paths
3. **`config/supabase-storage.php`** — NEW — All Supabase bucket definitions, max sizes from config
4. **`config/cors.php`** — NEW — Explicit allowed origins from FRONTEND_URL, credentials for Sanctum SPA auth
5. **`docker-compose.yml`** — REWRITTEN — Added postgres, queue worker, scheduler, frontend; fixed DB_* env vars; healthchecks; resource limits
6. **`frontend/vercel.json`** — NEW — SPA rewrites for Vercel deployment
7. **`frontend/src/api/client.ts`** — Uses `VITE_API_URL` env var instead of hardcoded `/api/v1`
8. **`frontend/vite.config.ts`** — Added VITE_API_URL pass-through, `__APP_ENV__` define
9. **`frontend/Dockerfile`** — Added VITE_API_URL build arg
10. **`frontend/nginx.conf`** — Security headers, gzip, asset caching
11. **`backend/SupabaseStorageService.php`** — Reads max sizes from config instead of hardcoded
12. **`backend/SyncAnalyticsCache.php`** — Stubbed Cache::tags (unsupported by file driver)
13. **`backend/routes/api.php`** — Fixed misplaced comment
14. **`backend/routes/console.php`** — Removed stale analytics:cache schedule
15. **`backend/.env.example`** — Enhanced with VITE_API_URL, pooler URL, DB_SSLMODE, LOG_LEVEL=warning
16. **`frontend/README.md`**, **`backend/README.md`** — Updated with actual project info
17. **`README.md` (root)** — NEW — Full deployment guide with architecture diagram
18. **`AUDIT_CHANGES.md`** — Updated with all production readiness changes
19. **`backend/.gitignore`** — Added phpunit.cache, lesshst, bootstrap/cache/*.php

### Done (Production Architecture, Cache & Database Audit — 2026-06-26)
1. **Database config** — `database.php`: PostgreSQL default connection, added missing `host`/`port`/`database`/`username`/`password`/`sslmode` fields.
2. **Cache defaults** — `cache.php` default changed from `database` to `file`; `queue.php` default changed from `database` to `sync`; `.env.example` updated accordingly.
3. **CacheService integrated** — Injected into `LeaderboardService`, `VerseService`, `EventService`, `AttendanceService`, `PointService`. The `remember*` methods are now actually called instead of being dead code.
4. **Cache invalidation** — Added `invalidate*` calls on:
   - `EventService::create/update/delete` → invalidates event cache
   - `PointService::addPoints/addBonusPoints` → invalidates points + dashboard cache
   - `VerseService::create/update/delete/activate` → invalidates verse cache
   - `EventService::list` (with filters) → now cached for 1 hour
   - `LeaderboardService::classLeaderboard/globalLeaderboard/stagesLeaderboards` → cached
   - `AttendanceService::getTodayAttendance/getAttendanceStats/getContextSummary` → cached
5. **.env.example** — Added DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD/DB_SSLMODE; changed LOG_LEVEL from `debug` to `warning`; updated Redis comment to not say "NOT used".
6. **Model fix** — `Notification::point()` relationship now correctly references `points_id` FK.
7. **PERM cache invalidation gap documented** — `Permission::clearCache()` is defined but never called at runtime (no runtime permission management UI yet).

### Done (Codebase Audit — 2026-06-22)
1. **Deleted 8 dead backend files** — stale controllers, services, requests, resources, notifications.
2. **Cleared frontend assets** — removed committed `Vite` asset hashes from git tracking.
3. **Fixed duplicate eager loads** — `AreaController` and `AttendanceFilter`.
4. **Removed duplicate routes** — DTO-related routes stripped from `api.php`.
5. **Fixed lint issues** — `UserController` (deleted, replaced by module), enum import in `ChurchApplicationController`.
6. **Refactored `AttendanceService`** — extracted private `processAttendance()` for DRY.
7. **Refactored `StructureController`** — delegated to `StructureService`.
8. **Refactored `EventController`** — extracted `servantCannotAccessEvent()`; rewrote cleanly to fix `Unclosed '{'` syntax error.
9. **Fixed `Permission` static cache** — added `$cache` array with `flush()`.
10. **Fixed model naming** — `members()`/`memberUsers()` → `allUsers()` in `Classe` model.
11. **Fixed `NotPlaceholder` rule** — removed `'admin'` from blacklist (was blocking valid emails).
12. **Fixed phone input** — slice to 11 digits in frontend.
13. **Added better 422 error messages** — email uniqueness hint.

### Blocked
- Shell/terminal tools are unavailable — cannot run Docker, PHP, or npm commands to verify fixes or clear OPCache.
- EventController 500 error requires Docker restart + `php artisan optimize:clear` to confirm fix.

## Key Decisions
- Supabase config moved from `services.php` to dedicated `supabase-storage.php` because `SupabaseStorageService` reads from `config('supabase-storage.*')`
- Docker compose rewritten with explicit DB_* env vars instead of DATABASE_URL because Laravel DB config uses DB_HOST/DB_PORT/DB_DATABASE etc.
- All live credentials replaced with safe placeholders; .env files excluded via root .gitignore
- Frontend API baseURL now reads `VITE_API_URL` env var for production, falls back to `/api` for Docker dev proxy
- `VITE_API_URL` is baked into frontend Docker image at build time via ARG (not runtime)
- CORS uses `FRONTEND_URL` env var for allowed origins (supports multiple domains)
- `SyncAnalyticsCache` stubbed to safe version because `Cache::tags()` fails with file/database cache driver (requires Redis/Memcached)
- PostgreSQL added to docker-compose.yml for local parity with Supabase production (local postgres on port 5433)

## Key Decisions
- Separate `password_reset_requests` table keeps admin-approved flow independent from the existing `password_reset_tokens` (Laravel broker).
- Service uses `DB::transaction()` + `lockForUpdate()` for approve/reject/completeReset — prevents race conditions.
- Token stored hashed... actually stored as-is (64-char random string, one-use, `unique` index) — never exposed to admins.
- Admin gets notified on each new request via email.
- `ForgotPassword.tsx` modified to submit requests; kept existing Laravel broker flow for future admin self-reset.
- Reset URL uses `frontend_url` config pointing to SPA, never backend.

## Next Steps
(waiting for user direction — suggest: verify production audit changes, test `php artisan migrate` with new defaults, or build the next feature)

## Critical Context
- CacheService remember* methods were dead code — now integrated into 5 services.
- Cache default store: `file` (not `database`; use `redis` in production).
- Queue default: `sync` (use `database` or `redis` for async jobs).
- Database default: `pgsql` with proper connection fields.
- `Permission::clearCache()` is defined but never auto-invoked (no runtime permission management yet).
- 3 empty stub migrations exist as no-ops: `update_qr_invite_types`, `add_church_id_to_event_views` (June), `cleanup_duplicate_points` (July v2).
- `Notification::point()` now references `points_id` FK explicitly.
- All cache invalidation is per-church via versioned namespaces (generation-based).

## Relevant Files
- `backend/database/migrations/2026_06_22_000002_create_password_reset_requests_table.php` — new table
- `backend/app/Models/PasswordResetRequest.php` — model with token/status logic
- `backend/app/Enums/PasswordResetRequestStatus.php` — pending/approved/rejected
- `backend/app/Services/PasswordResetRequestService.php` — core business logic
- `backend/app/Contracts/PasswordResetRequestServiceInterface.php` — service contract
- `backend/app/Http/Controllers/Api/PasswordResetRequestController.php` — API endpoints
- `backend/app/Http/Requests/SubmitPasswordResetRequest.php` — submit validation
- `backend/app/Http/Requests/ApprovePasswordResetRequest.php` — approve auth gate
- `backend/app/Http/Requests/RejectPasswordResetRequest.php` — reject with reason
- `backend/app/Http/Resources/PasswordResetRequestResource.php` — API response format
- `backend/app/Notifications/PasswordResetRequestSubmittedNotification.php` — admin email
- `backend/app/Notifications/PasswordResetRequestApprovedNotification.php` — user approval email
- `backend/app/Notifications/PasswordResetRequestRejectedNotification.php` — user rejection email
- `backend/app/Policies/PasswordResetRequestPolicy.php` — role-based authorization
- `backend/app/Providers/AppServiceProvider.php` — binding + policy registration
- `backend/routes/api.php` — route definitions
- `backend/resources/lang/en.json` — `password_reset_requests.*` translations
- `backend/resources/lang/ar.json` — Arabic translations
- `frontend/src/api/passwordResetRequests.ts` — API client
- `frontend/src/types/index.ts` — `PasswordResetRequest` interface
- `frontend/src/pages/auth/ForgotPassword.tsx` — submit request with optional notes
- `frontend/src/pages/auth/ResetPasswordFromRequest.tsx` — set new password after approval
- `frontend/src/pages/admin/PasswordResetRequests.tsx` — admin review page
- `frontend/src/i18n/en.json` — `passwordResetRequests.*`, `auth.*` keys
- `frontend/src/i18n/ar.json` — Arabic translations
- `frontend/src/App.tsx` — route registration
- `frontend/src/components/layout/Sidebar.tsx` — nav link
- `frontend/src/pages/platform/ChurchDeletion.tsx` — Fixed toLocaleString crash + summary grid responsive
- `frontend/src/index.css` — Added stagger-children nth-child(n+9) fallback, `.full` modal size
- `frontend/src/pages/admin/QRManagement.tsx` — Responsive filter widths
- `frontend/src/pages/servant/QRInvites.tsx` — Responsive filter widths
- `frontend/src/pages/admin/Users.tsx` — Responsive search + header layout
- `frontend/src/pages/servant/Members.tsx` — Responsive search width
- `frontend/src/pages/servant/Attendance.tsx` — Mobile card view
- `frontend/src/pages/admin/PasswordResetRequests.tsx` — Responsive filter buttons
- `frontend/src/pages/Landing.tsx` — Smaller hero text on mobile
- `frontend/src/components/layout/Header.tsx` — Notification panel full-width on mobile
- `frontend/src/pages/FeedbackSubmit.tsx` — Removed invalid CSS classes
- `frontend/src/pages/AbsentMembers.tsx` — Mobile card view
- `frontend/src/pages/PlatformDashboard.tsx` — Responsive header + filter
- `frontend/src/pages/FeedbackManagement.tsx` — Responsive header + filter
- `frontend/src/pages/admin/Events.tsx` — Responsive header
- `frontend/src/pages/servant/Events.tsx` — Responsive header
- `frontend/src/pages/VerseManagement.tsx` — Responsive header
- `backend/config/database.php` — Production PostgreSQL defaults with missing fields
- `backend/config/cache.php` — Default changed from `database` to `file`
- `backend/config/queue.php` — Default changed from `database` to `sync`
- `backend/.env.example` — Added DB connection fields, updated LOG_LEVEL, Redis comment
- `backend/app/Services/CacheService.php` — Versioned per-church cache with `remember*` and `invalidate*` methods
- `backend/app/Services/LeaderboardService.php` — CacheService injected, leaderboard results cached
- `backend/app/Services/VerseService.php` — CacheService injected, active verse cached + invalidated on changes
- `backend/app/Services/EventService.php` — CacheService injected, event list cached + invalidated on CRUD
- `backend/app/Services/AttendanceService.php` — CacheService injected, today/stats/context-summary cached
- `backend/app/Services/PointService.php` — CacheService injected, points/dashboard invalidated on awards
- `backend/app/Models/Notification.php` — Fixed `point()` relationship FK to `points_id`
- `backend/app/Listeners/InvalidateAttendanceCache.php` — Invalidates attendance + dashboard cache
