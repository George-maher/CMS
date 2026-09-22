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
* PostgreSQL = Database (SQLite in tests)
* React + TypeScript = Frontend
* Docker = Infrastructure
* Nginx = Reverse Proxy

---

# 📦 BACKEND RULES (Laravel)

## Required Architecture

Backend MUST use:

* Controllers
* Services
* Repositories (where applicable)
* API Resources
* Form Requests
* Middleware
* Policies
* Enums (PHP 8.1+)

## Required Folder Structure

```
app/
├── Http/
│   ├── Controllers/Api/
│   ├── Requests/
│   ├── Middleware/
│   └── Resources/
├── Services/
├── Repositories/
├── Models/
├── Policies/
├── Enums/
├── Traits/
├── Casts/
└── Contracts/
```

---

# 🔐 AUTHENTICATION RULES

Use: Laravel Sanctum (Personal Access Tokens)

Required:

* Login / Register / Logout
* Password hashing (bcrypt)
* Role middleware
* Token validation
* Protected routes
* Platform admin has separate login endpoint

Roles: admin, assistant_admin, servant, member, stage_admin, platform_admin

---

# 👥 USER SYSTEM RULES

Users must support:

* name, email, password
* role (enum)
* church_id (foreign key)
* class_id (foreign key)
* is_active, application_status
* attendance_qr_token

Relations MUST be properly implemented.

---

# 📲 QR SYSTEM RULES

## VERY IMPORTANT

QR codes MUST NEVER contain:

* passwords, raw IDs, sensitive data

QRs must contain ONLY:

* secure token (60-char random string)
* secure URL

## QR Types

* admin_to_servant_invite
* servant_to_member_invite
* attendance_qr
* event_checkin_qr

## Invite Rules

Each invite MUST:

* have expiration time (default 4 hours)
* support revoke/disable/single-use
* Backend MUST validate: token exists, not expired, not used

---

# 🧾 ATTENDANCE RULES

Attendance flow:

1. Servant scans member QR
2. Backend validates QR token
3. Attendance is recorded
4. Duplicate attendance same day is prevented
5. Points are automatically added

Attendance must store: member_id, servant_id, class_id, attendance_context_id, date, status

---

# 🎯 POINTS SYSTEM RULES

After successful attendance:

* Automatically add points
* Prevent duplicate points same day
* Store reason, timestamp, total points

---

# 🏫 ORGANIZATIONAL HIERARCHY

```
Church
├── Church Admin (church-wide)
├── Stage (Secondary, Preparatory, Primary)
│   ├── Stage Admin (stage-scoped)
│   ├── Servants (stage/class-scoped)
│   ├── Classes (belong to stage)
│   └── Members (belong to class)
```

---

# 🔒 SECURITY BOUNDARIES

## Multi-Tenancy (CRITICAL)

Church is the primary tenant boundary.

* Every query MUST be scoped via `BelongsToChurch` trait + `ChurchScope` global scope
* NEVER trust `church_id`, `stage_id`, `class_id`, `user_id` from client
* Frontend permissions are UX only — NEVER security boundary

## Stage Isolation

Stage admins can ONLY access their own stage's resources.

---

# 📊 AUDIT LOGGING (CRITICAL)

The system uses `AuditableTrait` → `AuditService` → `AuditLog` model.

## Key Points

* `old_values`/`new_values` stored as JSON columns
* Uses custom `AuditLogValues` cast for safe JSON encoding
* Handles malformed UTF-8 gracefully (never crashes business transactions)
* PII fields (password, email, phone, address) are masked
* All string values are sanitized for valid UTF-8

## When Modifying Audit Logging

1. Inspect `AuditService::maskPii()` and `AuditLogValues` cast
2. Test with Arabic and English data
3. Test with malformed UTF-8 if applicable
4. Never store: passwords, tokens, secrets, binary data

---

# 🌐 API RULES

Use:

* REST APIs
* JSON responses
* proper HTTP status codes
* API versioning (/api/v1/)

Must include:

* validation (Form Requests)
* error handling (try/catch with localized messages)
* pagination
* authentication middleware

---

# ⚛️ FRONTEND RULES

Frontend MUST use:

* React + TypeScript
* TailwindCSS
* API layer (`src/api/*.ts`)
* role-based routing
* i18n for all user-visible text

Dashboards: Admin, Assistant Admin, Servant, Member, Platform Admin

---

# 🌐 LOCALIZATION / i18n

## EN + AR Support

* Backend: `resources/lang/en.json`, `ar.json`
* Frontend: `src/i18n/en.json`, `ar.json`
* Use translation keys, NEVER `language === 'ar' ? ... : ...`
* RTL/LTR via `dir` attribute + Tailwind logical props
* Date formatting: `frontend/src/lib/dates.ts`

## RTL/LTR Rules

* Use `text-start`/`text-end` instead of `text-left`/`text-right`
* Use `.rtl-flip` class for directional icons
* Verify both Arabic and English UIs

---

# 🗄️ DATABASE RULES

Use PostgreSQL (SQLite in tests).

Must include:

* migrations, relationships, indexes, constraints, foreign keys

## Testing with SQLite

Tests use SQLite in-memory. Some PostgreSQL-specific migrations use driver-aware SQL:

```php
$driver = DB::connection()->getDriverName();
if ($driver === 'pgsql') {
    // PostgreSQL-specific SQL
} else {
    // SQLite-compatible SQL
}
```

---

# 🧪 TESTING RULES

## Required Commands

```bash
# Backend (from backend/)
composer install
php artisan test
vendor/bin/phpstan analyse --level=max
vendor/bin/pint --test

# Frontend (from frontend/)
npm install
npm run dev
npm run build
npm run lint
npx tsc --noEmit
npm run check:i18n
```

## Test Requirements

* All feature tests must pass
* PHPStan level-max must have 0 errors
* Pint must have 0 issues
* Frontend ESLint must have 0 errors
* i18n EN/AR key parity must match exactly

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
* healthchecks

---

# 🔧 COMMON PITFALLS

1. **Test emails**: `test@test.com` blocked by NotPlaceholder rule — use `login@test.com`
2. **Church::factory()**: Use `Church::factory()->create()` in tests, NOT `Church::create([...])`
3. **PostgreSQL in tests**: Tests use SQLite — avoid PostgreSQL-specific SQL in migrations
4. **UTF-8 in audit logs**: Use `AuditLogValues` cast — never store raw strings
5. **PII fields**: Always mask in audit logs (password, email, phone, address)
6. **Church scope**: Always use `BelongsToChurch` trait on tenant-sensitive models
7. **Stage isolation**: Enforce in policies + service queries, not frontend only
8. **Windows tests**: `protected bool $mockConsoleOutput = false;` in TestCase
9. **CORS on Railway**: Set `CORS_ALLOWED_ORIGINS` env var to frontend URL
10. **Resend removed**: Email sending is NOT implemented — use in-app notifications

---

# 📁 KEY FILES

| Purpose | File Path |
|---------|-----------|
| API Routes | `backend/routes/api.php` |
| Platform Controller | `backend/app/Http/Controllers/Api/PlatformController.php` |
| Church Application Service | `backend/app/Services/ChurchApplicationService.php` |
| Audit Service | `backend/app/Services/AuditService.php` |
| Audit Log Values Cast | `backend/app/Casts/AuditLogValues.php` |
| Auditable Trait | `backend/app/Traits/AuditableTrait.php` |
| Church Model | `backend/app/Models/Church.php` |
| Audit Log Model | `backend/app/Models/AuditLog.php` |
| Frontend API Client | `frontend/src/api/client.ts` |
| Frontend i18n | `frontend/src/i18n/en.json`, `ar.json` |

---

# 🚀 DEPLOYMENT

## Railway (Backend)

* Set `CORS_ALLOWED_ORIGINS=https://your-frontend.vercel.app`
* Set `FRONTEND_URL=https://your-frontend.vercel.app`
* `php artisan config:cache` runs at container startup

## Vercel (Frontend)

* `vercel.json` rewrites SPA routes to `index.html`
* `VITE_API_URL=https://your-backend.railway.app`

---

# 📝 CHANGE LOG

See `AUDIT_CHANGES.md` for detailed change history.

---
## Documentation-First Rule

When working on this project, NEVER guess about the behavior, API, syntax, configuration, limitations, or recommended usage of any technology when you are not confident.

If you encounter an error, unexpected behavior, unfamiliar API, unclear implementation, configuration issue, compatibility issue, version-specific issue, or any technical problem you do not fully understand:

1. Identify the technology responsible for the problem.
2. Identify the exact version being used.
3. Consult the official documentation for that technology.
4. Prefer documentation matching the exact version used by the project.
5. Read the relevant documentation and understand the documented behavior.
6. Use that information to determine the root cause.
7. Implement the solution based on the documented behavior.
8. Test the solution.
9. Verify that the original problem is actually fixed.
10. If the solution does not work, return to the official documentation and investigate further instead of randomly changing code.

This applies to every technology used in the project, including frameworks, libraries, packages, APIs, databases, SDKs, runtimes, tools, and platforms such as Laravel, PHP, React, TypeScript, JavaScript, Node.js, Python, FastAPI, PostgreSQL, MySQL, Redis, Docker, Tailwind, Vite, Supabase, Firebase, and any other technology used by the project.

Always prefer sources in this order:

1. Official documentation.
2. Official documentation for the exact version.
3. Official API/reference documentation.
4. Official repository/source code.
5. Official migration guides and release notes.
6. Official issue tracker.
7. Reliable community sources only when official sources are insufficient.

Do NOT blindly use documentation for a different major version.

Do NOT immediately install packages, change architecture, downgrade dependencies, upgrade dependencies, or create workarounds simply because something is not working.

First understand the actual cause.

Before making a technology-specific assumption, ask:

"Do I know this because I verified it from the official documentation/source, or am I assuming it?"

If you are assuming and the issue involves technology behavior, STOP and check the documentation first.

After every documentation-based fix, test and verify the result.

Never claim that a problem is solved without verification.

*This file is compact by design. Move verbose history to `AUDIT_CHANGES.md`.*