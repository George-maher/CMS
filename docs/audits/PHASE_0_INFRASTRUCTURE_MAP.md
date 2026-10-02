# PHASE 0 — INFRASTRUCTURE MAP

**Audit date:** 2026-09-30
**Repository:** `D:\xampp\htdocs\CHproject` (`origin` = `https://github.com/George-maher/CMS.git`)
**Head commit:** `bd37cf5fd510d2ccdeb6d32bb65b179db2181d77` — *"WIP: security hardening in progress (see conversation) - NOT production ready"* (2026-09-28)
**Method:** read-only inspection of repository configuration. No container was started, no deployment was contacted, no network call was made to a production system.

> **This document describes the topology the repository is CONFIGURED to produce. It is not evidence that any of it is running.**
> Every runtime claim is marked accordingly.

---

## 1. FACT LABELS USED THROUGHOUT

| Label | Meaning |
|---|---|
| **FACT** | Directly read from a file in this repository. |
| **DOCUMENTED BEHAVIOR** | Confirmed against official vendor documentation. |
| **INFERENCE** | Reasoned from evidence; not directly executed. |
| **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** | Cannot be checked from the repository or this machine. |
| **CONFLICT** | Documentation and code disagree. Code takes precedence for current behaviour. |

---

## 2. REPOSITORY TOPOLOGY (FACT)

```
CHproject/
├── .github/workflows/ci.yml        # the ONLY CI workflow
├── backend/                        # Laravel 12 API (PHP 8.2/8.3)
├── frontend/                       # React 19 + TS 5.9 + Vite 8 SPA/PWA
├── docker/
│   ├── nginx/{Dockerfile,default.conf,generate-ssl.sh,ssl/}
│   └── postgres/{Dockerfile,init.sql}
├── docker-compose.yml              # app, nginx, worker, scheduler
├── docker-compose.override.yml     # + postgres, frontend (git-ignored)
├── docs/                           # (this audit is the first file added here)
├── AGENTS.md, README.md, SECURITY.md, CONTRIBUTING.md, LICENSE, COPYRIGHT, NOTICE
└── AUDIT_REPORT.md, FINAL_AUDIT_SUMMARY.md, PRODUCTION_READINESS_REPORT*.md,
    PRODUCTION_HARDENING_REPORT.md, RECOVERY_AUDIT_REPORT.md,
    TENANT_STAGE_ISOLATION_AUDIT.md, STAGE_ADMIN_AUDIT_REPORT.md,
    IP_PROTECTION_REPORT.md, CODE_REVIEW_REPORT.md, AUDIT_CHANGES.md,
    RESponsibleServant_Audit_Report.md, details.txt
```

There is **no** `docs/` directory prior to this audit. `backend/docs/TENANT_RULES.md` is the only pre-existing developer-facing design document.

---

## 3. PRODUCTION TOPOLOGY AS CONFIGURED

### 3.1 Documented / intended (FACT — from `README.md` and `AGENTS.md`)

```
User
 ↓
Vercel                (frontend SPA, static)
 ↓  HTTPS /api/v1
Railway               (Laravel API container: nginx + php-fpm + worker + scheduler)
 ↓
Supabase PostgreSQL   (primary DB + Storage)
 ↓
Laravel database queue → Supervisor-managed worker
 ↓
Resend / SMTP         (MAIL_MAILER=resend; see §7 — NOT VERIFIED and partly DISABLED)
```

### 3.2 Actually encoded in configuration (FACT)

| Component | Config file(s) | What it says |
|---|---|---|
| Frontend hosting | `frontend/vercel.json` | `buildCommand: npm run build`, `outputDirectory: dist`, `framework: vite`. SPA rewrite `/(.*) → /index.html`, with an explicit no-op passthrough `/api/(.*) → /api/$1` first. |
| Backend hosting | `backend/railway.json` | `build.builder: DOCKERFILE`. `deploy.restartPolicyType: ON_FAILURE`, `maxRetries: 5`, `healthcheckPath: /healthcheck.txt`, `healthcheckTimeout: 10`, `healthcheckInterval: 30`. |
| Backend image | `backend/Dockerfile` | Multi-stage. `base` = `php:8.3-fpm` + intl/pdo_pgsql/mbstring/exif/pcntl/bcmath/gd/zip + PECL redis. `production` adds `nginx` + `supervisor`, copies `production/nginx.conf` and `production/supervisord.conf`, `EXPOSE 8080`, `HEALTHCHECK` on `/healthcheck.txt`. |
| Process supervisor | `backend/production/supervisord.conf` | 4 programs: `php-fpm` (root, priority 1), `nginx` (root, priority 2), `queue-worker` (`php artisan queue:work --sleep=3 --tries=3 --timeout=90 --queue=default --max-time=3600 --memory=128`, **user `www-data`**, priority 3), `scheduler` (`php artisan schedule:work`, **user `www-data`**, priority 4). |
| Local / self-hosted | `docker-compose.yml` + `docker-compose.override.yml` | 5 services: `app` (development target), `nginx` (custom image), `worker` (`queue:work database --sleep=3 --tries=3 --backoff=60 --timeout=90 --queue=default`), `scheduler`, and (override only) `postgres` + `frontend` (Vite dev server on 5173). |
| Local DB | `docker/postgres/Dockerfile` | `postgres:15-alpine` + `init.sql` enabling `uuid-ossp` and `pgcrypto`. |
| CI DB (integration) | `.github/workflows/ci.yml` | `postgres:16` service. |
| CI DB (claimed production) | `PRODUCTION_READINESS_REPORT_2026-09-30.md` | claims "real PostgreSQL **18.4** engine". No PostgreSQL is installed on this machine, and no CI job provisions 18 — see §4. |

### 3.3 `EXTERNAL INFRASTRUCTURE — NOT VERIFIED`

- Whether any Vercel project exists, its domains, its environment variables, its deployment history.
- Whether any Railway project/service exists, its regions, its attached plugins, its env vars, its logs, its metrics, its rollback history.
- Whether a Supabase project exists, its PostgreSQL version, its storage buckets, its RLS policies, its backups, its PITR.
- Whether the Resend domain is verified, its SPF/DKIM/DMARC records, its bounce/complaint state.
- Whether the queue worker is actually running, and whether the scheduler is actually running.
- GitHub repository settings: branch protection, required reviewers, environments, deployment protection rules, secret inventory, action permissions. `git` exposes none of these.
- Whether any DNS record exists.

---

## 4. POSTGRESQL — THE CENTRAL UNKNOWN

| Question | Answer | Evidence |
|---|---|---|
| Is PostgreSQL the configured production driver? | **YES** | `config/database.php:19` → `env('DB_CONNECTION','pgsql')`; `backend/.env.example:22` `DB_CONNECTION=pgsql`; `docker-entrypoint.sh:70-73` **hard-fails production boot** if `DB_CONNECTION != pgsql`. |
| Is PostgreSQL installed on this audit machine? | **NO** | `docker ps` → `failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine` (Docker Desktop not running). `Get-Command psql` → not found. No PostgreSQL in `D:\xampp`. |
| Could the pgsql test suite be executed during this audit? | **NO** | `phpunit.postgres.xml` requires `127.0.0.1:5432/church_management_test` with `postgres/postgres`. No server available. |
| Which PostgreSQL version does production run? | **UNKNOWN** | `docker/postgres/Dockerfile` pins `15-alpine`; CI pins `16`; the 2026-09-30 report claims `18.4`. Three different answers across three sources. |
| Do the 7 composite tenant FKs exist in the real production database? | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** | The migration is present and correct in source (§ Database Matrix), and `php artisan tenant:verify-schema` exists to prove it against a live catalog. That command was **not run against a live PostgreSQL during this audit.** |
| Does `migrate` succeed on PostgreSQL? | **NOT VERIFIED in this audit** | See §12 for the historical incident this exact question already caused once. |

**CONFLICT — code/config takes precedence:** `README.md` says "PostgreSQL 15"; `.github/workflows/ci.yml` provisions `postgres:16`; `PRODUCTION_READINESS_REPORT_2026-09-30.md` states tests were executed against "PostgreSQL 18.4". The repository does not pin a production version anywhere, and the Railway service uses whatever the Railway PostgreSQL plugin provides.

---

## 5. CI / CD (FACT)

`.github/workflows/ci.yml` is the **only** workflow. Trigger: `push` to `main`, and all `pull_request`. No `workflow_dispatch`, no `schedule`.

### 5.1 Jobs

| Job | Runner | DB | Steps |
|---|---|---|---|
| `backend` | `ubuntu-latest`, PHP 8.3, ext `mbstring sqlite3 pdo_sqlite intl bcmath gd zip` | none | checkout → `composer install` → **`composer audit --locked --no-dev`** → `php artisan test` (SQLite, all 3 suites) → `phpstan analyse --level=max` → `pint --test` → `php scripts/scan-broken-migration-rollbacks.php` |
| `postgres` | `ubuntu-latest`, PHP 8.3 + `pdo_pgsql pgsql` | **`postgres:16` service** | checkout → `composer install` → `composer audit --locked --no-dev` → `php artisan db:show` → **`migrate:fresh --force`** → **`php artisan tenant:audit`** (must be clean) → **`php artisan tenant:verify-schema`** → `migrate:rollback --step=4` + `migrate` + `tenant:verify-schema` → **`php artisan test --testsuite=Security`** → **`php artisan test`** (full suite) |
| `frontend` | `ubuntu-latest`, Node 22, npm cache | none | checkout → `npm ci --legacy-peer-deps` → `npx tsc --noEmit` → `npm run lint` → `npm run check:i18n` → **`npm run build`** (before tests, deliberately — see PWA note) → `npm test` (Vitest) → `npm audit --audit-level=high` |

### 5.2 CD — NOT PRESENT

There is **no deployment job in CI.** `.github/workflows/` contains only `ci.yml`. Deployment to Vercel and Railway is therefore **out-of-band and NOT VERIFIED** — most likely Vercel's own Git integration and Railway's own Git/build integration, neither of which is visible from this repository.

**Consequence:** the `security testsuite` and the `postgres` job gate **merges**, not **deploys**. Whether a commit that passes CI is what actually ships is `EXTERNAL INFRASTRUCTURE — NOT VERIFIED`.

### 5.3 Gaps in CI (FACT)

| Gap | Detail |
|---|---|
| PHPStan and Pint are **not** run on PostgreSQL | Only in the `backend` (SQLite) job. |
| `phpunit.postgres.xml` is **referenced by nothing** | `grep` finds no `--configuration` flag in `ci.yml` and no composer script uses it. The `postgres` job relies on job-level env vars instead. The file is effectively dead configuration. |
| No `migrate:rollback` of the **full** history | Only `--step=4` and only the newest migration are exercised (`MigrationRollbackTest`). |
| `composer audit` runs, but no result is stored | No SARIF, no artifact, no summary step. |
| No secret scanning, no SAST, no SBOM, no dependency review action | NOT PRESENT. |
| No `CODEOWNERS`, no `SECURITY.md`-linked workflow | NOT PRESENT. |

---

## 6. DEPLOYMENT SAFETY CONTROLS (FACT — `backend/docker-entrypoint.sh`)

The production entrypoint is the strongest control in this repository. In production mode it **refuses to boot** unless:

| Guard | Line | Rule |
|---|---|---|
| `APP_ENV` | 47-49 | must be exactly `production` |
| `APP_DEBUG` | 51-57 | must be `false`/`FALSE`/`0` |
| `APP_KEY` | 59-68 | must be a valid 32-byte key (base64 or raw) |
| `DB_CONNECTION` | 70-73 | must be `pgsql` |
| Database credentials | 75-86 | `DATABASE_URL` **or** the full `DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` set |
| `QUEUE_CONNECTION` | 88-91 | must be `database` or `redis` |
| Database reachability | 108-146 | up to 30 probes (`SELECT 1`) at 2 s intervals; **fails the boot** if never reachable |
| Migrations | 148-155 | `migrate --force`; **the container will not start on an unknown schema state** |
| Permission seeder | 157-172 | idempotent check, then `PermissionSeeder --force`; a failure is a **WARN, not a blocker** |
| Config/route/view cache | 196-224 | `config:cache`, `route:cache`, `view:cache`; failure is fatal |
| `PORT` | 176-186 | must be a valid TCP port 1-65535; nginx `listen` is rewritten by `sed` |
| Foreground process | 226-228 | refuses to start with no foreground command |

**Note:** "Values are never printed" (line 83) — verified, no `echo $VAR` of a secret exists in the script.

**Weakness (FACT, P2):** `RUN_MIGRATIONS` defaults to `true`. Every replica/redeploy runs `artisan migrate --force` at boot. In `docker-compose.yml` this is de-conflicted (only `app` migrates; `worker`/`scheduler` set `RUN_MIGRATIONS=false`), but **Railway's replica count is not visible from the repository**, so concurrent-migration safety in production is `NOT VERIFIED`.

---

## 7. QUEUE TOPOLOGY (FACT)

| Layer | Value | Evidence |
|---|---|---|
| Driver in use | **database** | `backend/.env` and `.env.example` → `QUEUE_CONNECTION=database`; `docker-compose.yml` worker command says `queue:work database`; `TENANT_RULES.md` §12 states "The Laravel **database** queue is used. RabbitMQ is not installed." |
| Config default | `sync` | `config/queue.php:16` |
| Tables | `jobs`, `job_batches`, `failed_jobs` | `0001_01_01_000002_create_jobs_table.php` |
| `retry_after` | 300 s | `config/queue.php:43` |
| `after_commit` | `true` | `config/queue.php:44` (correct: jobs never run before the DB transaction commits) |
| Failed-job driver | `database-uuids` | `config/queue.php:124` |
| Named queues | **none** — no job calls `onQueue()` | grep: no matches |
| Redis | available (`phpredis` compiled into the image, `config/queue.php` redis connection, `REDIS_*` env) but **not configured** | `docker-compose.yml` has no redis service; `.env.example` says "requires REDIS_HOST + REDIS_PASSWORD" |
| RabbitMQ / SQS | **NOT PRESENT** | not in `composer.json`, no config, no service |
| Horizon / Telescope / Pulse | **NOT PRESENT** | not in `composer.json` |
| Jobs defined | **1** — `SendEmailJob` (`$tries=3`, `$backoff=10`, `retryUntil=+30min`) | `app/Jobs/SendEmailJob.php` |
| Live jobs dispatched | `SendEmailJob` via `EmailService` — **`EmailService` has no production caller** | grep of `app/Http/Controllers/` for `EmailService` → no matches |
| Live notifications | 9 `ShouldQueue` notification classes; **4 of them are never dispatched by any code** | see Service Matrix §2 |
| `failed_jobs` monitoring | **NOT PRESENT** — no alerting, no dashboard, no pruning schedule | `routes/console.php` schedules only invite-cleanup and audit-log cleanup |
| `sanctum:prune-expired` | **NOT SCHEDULED** | Sanctum docs recommend it when `expiration` is set; `config/sanctum.php:48` sets `expiration=1440`. Without pruning, `personal_access_tokens` grows unbounded. |

**Worker health:** `docker-compose.yml:99` healthcheck is `pgrep -f 'artisan queue:work'`. In the Railway/production image, the worker is a **Supervisor program inside the same container** (`production/supervisord.conf`), not a separate service — Supervisor restarts it (`autorestart=true`, `startretries=3`) but does **not** report worker death to the platform health check, which only probes `/healthcheck.txt` (a static nginx `return 200 "OK"`).

**INFERENCE:** a wedged or repeatedly-crashing worker could keep the container "healthy" from Railway's point of view. Queue backlog visibility is `NOT VERIFIED` (no metrics, no alerting, no dashboard).

---

## 8. STORAGE (FACT)

| Backend | Selection logic | Evidence |
|---|---|---|
| Supabase Storage (REST) | if `SUPABASE_URL` **and** `SUPABASE_SERVICE_ROLE_KEY` are both non-empty | `AppServiceProvider.php:205-215` |
| Local filesystem | otherwise | `AppStorageService` binding falls back to `LocalStorageService` |

Buckets (`config/supabase-storage.php`): `profiles` (public), `events` (public), `documents` (public), `ids` (public), `attachments` (**private**).

**Note (FACT):** four of five buckets are configured `public => true`. Profile images, event images, church ID documents and membership-request files are therefore world-readable by URL if the bucket setting is applied. `.env.example` documents the service-role key as "backend only, NEVER expose to client" — correct intent; the public-bucket configuration is a separate exposure.

**File serving:**
- Production nginx: `location ^~ /storage/ { alias /var/www/storage/app/public/; }` (`backend/production/nginx.conf:24-35`) with CSP/HSTS re-declared inside the block.
- Fallback: `GET /storage/{path}` in `routes/web.php:46-67` with `realpath()` containment — refuses `..` traversal, absolute paths and symlink escapes. **VERIFIED by reading the code; the refusal is structural (`str_starts_with($fullPath, $base.DIRECTORY_SEPARATOR)`).**
- `docker/nginx/default.conf` also mounts `backend_storage:/var/www/storage:ro`.

**`EXTERNAL INFRASTRUCTURE — NOT VERIFIED`:** whether the Supabase buckets actually exist, whether the `public` flags are actually applied, whether storage RLS is enabled, and whether the `2026_06_25_000001_create_supabase_storage_buckets` migration actually ran in production (it **skips silently** when the env vars are unset — see Database Matrix).

---

## 9. ENVIRONMENT VARIABLES (FACT — no values printed)

`backend/.env.example` is committed. `backend/.env.docker` is **committed to git** and contains a set `DB_PASSWORD` (a local development value, `pos…`, 8 chars). The root `.gitignore` lists `backend/.env.docker` but `backend/.gitignore` explicitly does **not** ignore it ("`docker-entrypoint.sh` needs it at runtime"), and `git ls-files` confirms it is tracked. `backend/.env` and `frontend/.env` are correctly ignored and untracked.

| Category | Variables | Status |
|---|---|---|
| Application | `APP_NAME APP_ENV APP_KEY APP_DEBUG APP_URL FRONTEND_URL APP_LOCALE APP_FALLBACK_LOCALE APP_FAKER_LOCALE APP_MAINTENANCE_DRIVER BCRYPT_ROUNDS` | PRESENT in `.env.example` |
| Database | `DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SSLMODE DATABASE_URL DB_EMULATE_PREPARES` | PRESENT |
| Auth | `SANCTUM_STATEFUL_DOMAINS SANCTUM_TOKEN_PREFIX SANCTUM_TOKEN_EXPIRATION PLATFORM_ADMIN_LOGIN_PATH TRUSTED_PROXIES` | PRESENT |
| CORS | `CORS_ALLOWED_ORIGINS CORS_MAX_AGE` | PRESENT |
| Session | `SESSION_DRIVER SESSION_LIFETIME SESSION_ENCRYPT SESSION_PATH SESSION_DOMAIN SESSION_SECURE_COOKIE SESSION_SAME_SITE SESSION_INACTIVITY_TIMEOUT` | PRESENT |
| Queue | `QUEUE_CONNECTION QUEUE_FAILED_DRIVER` | PRESENT |
| Cache | `CACHE_STORE` | PRESENT |
| Mail | `MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION MAIL_FROM_ADDRESS MAIL_FROM_NAME RESEND_API_KEY` | PRESENT in `.env.example` — **but see §10** |
| Storage | `SUPABASE_URL SUPABASE_ANON_KEY SUPABASE_SERVICE_ROLE_KEY SUPABASE_STORAGE_URL SUPABASE_MAX_IMAGE_SIZE SUPABASE_MAX_DOCUMENT_SIZE` | PRESENT |
| Frontend | `VITE_API_URL VITE_PLATFORM_ADMIN_LOGIN_PATH VITE_API_PROXY` | PRESENT in `frontend/.env.example` |
| Support | `SUPPORT_PHONE SUPPORT_EMAIL` | PRESENT |
| **UNUSED / ORPHANED** | `POSTMARK_API_KEY` (`config/services.php:9`), `SLACK_BOT_USER_OAUTH_TOKEN` / `SLACK_BOT_DEFAULT_CHANNEL` (`config/services.php:19-22`), `MAIL_REPLY_TO_ADDRESS` / `MAIL_REPLY_TO_NAME` (referenced in `config/resend.php` but **no `resend` mailer is wired**, see §10) | REFERENCED in config, **not in `.env.example`**, **not used by any code path** |
| **MISSING from `.env.example` but consumed in code** | `RUN_MIGRATIONS`, `RUN_PERMISSION_SEEDER`, `DB_MAX_ATTEMPTS`, `DB_RETRY_DELAY_SECONDS`, `PORT`, `VITE_APP_NAME`, `POSTMARK/SLACK` (above), `LOG_STACK`, `LOG_LEVEL`, `LOG_DAILY_DAYS`, `CACHE_PREFIX`, `DB_QUEUE`, `DB_CACHE_TABLE`, `REDIS_*`, `AUTH_GUARD`, `AUTH_MODEL`, `MAIL_SCHEME`, `MAIL_URL`, `MAIL_EHLO_DOMAIN` | REFERENCED in config/docker, **not in `.env.example`** — an operator has no documented list of what a production deploy must set |
| **Never documented anywhere** | `DB_FOREIGN_KEYS` (`config/database.php:39`) | REFERENCED, undocumented |

**NOT VERIFIED:** the actual production values of any of these. No `.env` from a deployed environment is in this repository, and Railway/Vercel variable stores are external.

**Secrets hygiene (FACT):** no `.env`, `.pem`, `.key`, `.crt`, `.p12` or credential file is tracked. `git ls-files | grep -E '\.env|secret|key'` returns only `.env.docker`, `backend/.env.docker`, `backend/.env.example`, `frontend/.env.example` — all non-production. **No secret value was printed during this audit.**

---

## 10. EMAIL / RESEND — THE MOST CONFLICTED AREA (FACT)

This is the area where `AGENTS.md`, `README.md`, `.env.example` and the code all disagree.

| Source | Claim |
|---|---|
| `AGENTS.md` §"Common Pitfalls" #10 | *"Resend removed: Email sending is NOT implemented — use in-app notifications"* |
| `README.md` tech stack | *"Email — Authenticated SMTP via Laravel Mail; production requires explicit SMTP credentials"* |
| `backend/.env.example:53-62` | `MAIL_MAILER=resend`, `MAIL_HOST=smtp.resend.com`, `MAIL_PORT=587`, `MAIL_USERNAME=resend`, `MAIL_PASSWORD=`, `RESEND_API_KEY=` |
| `backend/composer.json:13` | `resend/resend-laravel: ^1.4` is a **production** dependency (not `require-dev`) |
| `backend/config/mail.php:64-66` | `'resend' => ['transport' => 'resend']` mailer is defined |
| `backend/config/resend.php` | full config file, `api_key` from `RESEND_API_KEY` |
| `app/Services/AuthService.php:214` (comment) | *"Resend removed 2026-08-22"* |
| `config/mail.php:47` | the **smtp** mailer's password defaults to `env('RESEND_API_KEY')` — Resend is still load-bearing for SMTP |

**CONFLICT — CODE TAKES PRECEDENCE FOR CURRENT BEHAVIOUR.** The actual state:

1. There is exactly **one** physical send site in the entire backend: `app/Jobs/SendEmailJob.php:48` → `Mail::send(new SystemMail(...))`.
2. `SendEmailJob` is dispatched **only** by `EmailService`.
3. `EmailService` is bound in the container (`AppServiceProvider.php:217`) but is **injected into no controller and called by no production code path** (verified by grep across `app/Http/Controllers/`).
   → **The only queued-mail pathway in the application is dead code.**
4. The only *live* mail pathway is 9 `ShouldQueue` Notification classes sent via `->notify()`. Of those, **4 are never dispatched by any code** (`PasswordChangedNotification`, `PasswordResetRequestSubmittedNotification`, `PasswordResetRequestApprovedNotification`, `PasswordResetRequestRejectedNotification`).
5. Even the live path is guarded: `EmailVerificationService::dispatch()` (`:228-239`) **refuses to send** when `MailConfigurationValidator::isVerificationDeliveryConfigured()` is false, i.e. when the mailer is unset or is one of `log/array/null/fail`, or when `mail.from.address` is blank. It logs `email_verification / verification_dispatch_refused / transport_cannot_deliver` and returns `false`. It explicitly does this so *"Never hand a token to a transport that will write it to a log."*
6. `MailConfigurationValidator::shouldEnforceAtBoot()` exists and is tested — but **is never called from `app/`**. There is no live boot-time enforcement.
7. `config/mail.php:17` default mailer is `log`. So an unconfigured production deploy writes every email to `storage/logs/laravel.log` — which is exactly the failure the verification guard exists to prevent, and the guard only covers the *verification* mail, not the other 8 notifications.

**Emails that exist (templates, `backend/resources/views/emails/`):** `layout`, `welcome`, `invite`, `password-reset`, `verification`(absent — the notification renders `VerifyEmailNotification` view), `event-notification`, `attendance-notification`, `feedback-reply`, `notification`, `registration-submitted`, `application-approved`, `application-rejected`.

**`EXTERNAL INFRASTRUCTURE — NOT VERIFIED`:** whether a Resend account/domain exists; SPF, DKIM and DMARC records; whether `noreply@churchmanager.app` is a verified domain; delivery, bounce and complaint telemetry. **No email delivery was performed or observed during this audit.** This audit makes **no claim** that email works.

---

## 11. DOMAINS, TLS, NETWORK

| Control | Value | Evidence |
|---|---|---|
| Production HSTS | `max-age=31536000; includeSubDomains; preload` | `backend/production/nginx.conf:21`; `frontend/security-headers.conf:5`; `frontend/vercel.json` header block |
| Production CSP (API container) | `default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'; upgrade-insecure-requests` | `backend/production/nginx.conf:22` — a deny-all API CSP, correct for a JSON API |
| Frontend CSP (nginx) | full policy, `script-src 'self'`, `frame-ancestors 'self'` | `frontend/security-headers.conf:7` |
| Frontend CSP (Vercel) | identical policy | `frontend/vercel.json` `/(.*)` header block |
| CORS | `paths: api/*, sanctum/csrf-cookie`; `allowed_origins` from `CORS_ALLOWED_ORIGINS` falling back to `FRONTEND_URL` then `http://localhost:3000`; **`allowed_origins_patterns: []`**; `supports_credentials: true`; `allowed_headers: ['*']` | `config/cors.php:19-36` |
| Sanctum stateful domains | from `SANCTUM_STATEFUL_DOMAINS`; **the shipped default contains no production domain** | `config/sanctum.php:20-24` |
| `statefulApi()` middleware | **NOT registered** in `bootstrap/app.php` | `bootstrap/app.php:35-63` has no `$middleware->statefulApi()`. Cookie/SPA session auth is therefore **not active**; the SPA authenticates with a **bearer token** only. |
| Trusted proxies | `TRUSTED_PROXIES=*` in `.env.example` (trusts all) | `.env.example:104`; `config/trustedproxy.php`; `TrustProxies` appended to web+api in `bootstrap/app.php:57-62` |
| Real production domains | **UNKNOWN** | `APP_URL=https://churchmanager.app`, `FRONTEND_URL=https://churchmanager.app`, `VITE_API_URL=https://api.churchmanager.app` are the `.env.example` *examples*. `EXTERNAL INFRASTRUCTURE — NOT VERIFIED`. |

**Note on CORS (FACT, P3):** because the SPA uses a bearer token and `statefulApi()` is not registered, `supports_credentials: true` and `SANCTUM_STATEFUL_DOMAINS` are both currently inert. They are latent risk if SPA cookie auth is ever enabled.

---

## 12. CI/CD AND MIGRATION — THE ONE INCIDENT THAT PROVES WHY THIS AUDIT EXISTS

`.github/workflows/ci.yml:52-69` documents a real, previously-shipped incident in the repository's own words:

> *"The composite tenant foreign key migration contained `church_id IS NOT (SELECT …)`, which SQLite accepts and PostgreSQL rejects as a syntax error. `php artisan migrate` therefore aborted on the production driver, so none of the tenant constraints were ever created — while every SQLite test stayed green."*
>
> *"SQLite allocates rowids from one database-wide counter, so ids from DIFFERENT tables collide. Two assertions compared a church id against a class id and passed on SQLite purely by coincidence; PostgreSQL's per-table sequences exposed them."*

**CONFLICT — the fix is verified in source but not re-verified in this audit.** The migration `2026_09_29_000001_add_composite_tenant_foreign_keys.php` is now driver-aware and no longer contains the offending construct (`TenantConsistencyTest.php:177` asserts this at the source level, by stripping comments with `token_get_all`). But:
- No PostgreSQL server was reachable from this audit machine.
- Therefore **"the constraints are actually created on the production driver" is `EXTERNAL INFRASTRUCTURE — NOT VERIFIED` as of this audit.**

`scripts/rehearse-production-migration.sh` exists as a 10-step manual rehearsal and **truncates data**; its own header warns to use a disposable database. Whether it has ever been executed against a production-like dump is `NOT VERIFIED`. No rehearsal output, log, or artifact is committed.

---

## 13. MONITORING, ALERTING, BACKUP (FACT)

| Capability | Status |
|---|---|
| Application health endpoint | `GET /health` — opens PDO, returns `200 {"status":"healthy"}` or **`503 {"status":"degraded"}`** (`routes/web.php:10-29`). Fail-closed by design. |
| Laravel built-in health | `GET /up` (`bootstrap/app.php:33`) |
| Container health (Docker) | 4 healthchecks: FPM socket (`fsockopen`), nginx `/healthcheck.txt`, `pgrep queue:work`, `pgrep schedule:work` |
| Container health (Railway) | `railway.json` → `/healthcheck.txt`, 30 s interval. This is a **static `return 200 "OK"` from nginx** (`backend/production/nginx.conf:19-22`) — it proves nginx is up, **not** that PHP, the DB, the queue or the scheduler are up. |
| Structured logging | 75 `Log::*` call sites. `AssignRequestId` prepends a ULID `request_id` to every API request and publishes it via `Log::withContext`, echoed as `X-Request-Id`. `bootstrap/app.php:64-191` renders typed JSON errors that all carry `request_id`. |
| Log sink | `LOG_CHANNEL=stack`, `LOG_STACK=single`, `LOG_LEVEL=warning` in `.env.example` → a **single unbounded file** `storage/logs/laravel.log` inside a **container-local** volume. |
| Log rotation | **NOT CONFIGURED** in `.env.example` (`LOG_STACK=single`, not `daily`). |
| Log shipping | **NOT PRESENT** — no Sentry, Bugsnag, Datadog, OpenTelemetry, or any log shipper. |
| Exception reporting | `bootstrap/app.php` renderers only. **No `->report()` override, no `dontReport()` allow-list, no Sentry integration.** |
| Metrics / APM / tracing | **NOT PRESENT** |
| Error alerting | **NOT PRESENT** |
| Queue failure alerting | **NOT PRESENT** |
| **Database backups** | **NOT CONFIGURED IN REPOSITORY.** No `pg_dump` cron, no PITR config, no backup script, no restore script, no backup documentation. |
| **Restore drill** | **NOT PERFORMED.** This audit makes **no claim** that a backup exists or that a restore works. |
| Secret rotation runbook | **NOT PRESENT** (`APP_PREVIOUS_KEYS` is mentioned as a comment in `.env.example:12` but nothing else) |

---

## 14. GIT / RELEASE STATE (FACT)

| Item | Value |
|---|---|
| Branch | `main` (only local branch; `remotes/origin/main` present) |
| Remote | `https://github.com/George-maher/CMS.git` |
| Commit count | 144 |
| Head message | `WIP: security hardening in progress (see conversation) - NOT production ready` |
| Tags | **NONE** — no release tags exist |
| Branch protection / required reviews | **NOT VERIFIED** (GitHub-side setting, not visible to `git`) |
| **Uncommitted work** | **296 modified files, +9 066 / −3 584 lines**, plus **30 untracked paths** |
| Untracked includes | `backend/database/migrations/2026_09_29_000001_add_composite_tenant_foreign_keys.php`, `backend/app/Services/TenantConsistencyService.php`, `backend/app/Console/Commands/TenantAudit.php`, `backend/app/Console/Commands/TenantVerifySchema.php`, `backend/app/Http/Middleware/AssignRequestId.php`, 12 backend security tests, `frontend/src/test/`, `frontend/vitest.config.ts`, `frontend/src/lib/apiUrl.ts`, `PRODUCTION_READINESS_REPORT_2026-09-30.md`, `RECOVERY_AUDIT_REPORT.md` |
| Release strategy | **NOT DEFINED.** No tags, no changelog, no release workflow, no version file. `composer.json` has no `version`; `frontend/package.json` says `1.0.0`; `web.php` health payload hardcodes `"version":"1.0.0"`. |

**This is a material Phase 0 finding (P1):** the entire tenant-isolation hardening layer described in `TENANT_RULES.md` and `PRODUCTION_READINESS_REPORT_2026-09-30.md` — including the composite-FK migration — **exists only as uncommitted working-tree changes on one machine.** It is not on `origin/main`, it is not in any release, and there is no tag or artefact identifying what was verified. Anything the 542 green tests proved is proven about the *working tree*, not about any deployable commit.

Nothing was reset, stashed, rebased, committed, or pushed during this audit.

---

## 15. INFRASTRUCTURE-LEVEL RISK REGISTER EXTRACT

| ID | Severity | Finding | Confidence |
|---|---|---|---|
| INF-1 | **P1** | 296 modified + 30 untracked files, including the entire composite-FK tenant-isolation layer, are uncommitted on a branch whose HEAD says "NOT production ready". No release tag identifies what was tested. | **VERIFIED** (`git status`, `git log`, `git tag`) |
| INF-2 | **P1** | No `migrate:fresh` was executed on PostgreSQL during this audit; the composite tenant FKs are unverified against a live production engine. | **VERIFIED** (no PostgreSQL available — `docker ps` failure captured) |
| INF-3 | **P1** | Railway health check probes a static nginx `return 200`, so PHP/DB/queue/scheduler failure is invisible to the platform. | **VERIFIED** (`railway.json`, `backend/production/nginx.conf:19-22`) |
| INF-4 | **P1** | No database backup configuration and no restore procedure exist in the repository. No restore has ever been performed as far as the repository can show. | **VERIFIED** (absence) / recovery capability **NOT VERIFIED** |
| INF-5 | **P2** | `EmailService` → `SendEmailJob` is the only queued-mail pathway and has **no production caller**; 4 of 9 notification classes are never dispatched. Mail delivery is therefore effectively in-app-only, contradicting `README.md` and `.env.example`. | **VERIFIED** (grep + read) |
| INF-6 | **P2** | Default `MAIL_MAILER` is `log`. Only the email-*verification* notification is guarded against a non-delivering transport; the other 8 queued notifications are not. A production deploy with mail unconfigured writes notification bodies to the log file. | **VERIFIED** (`config/mail.php:17`, `EmailVerificationService.php:228-239`) |
| INF-7 | **P2** | `MailConfigurationValidator::shouldEnforceAtBoot()` is implemented and tested but called from nowhere. | **VERIFIED** (grep) |
| INF-8 | **P2** | `LOG_STACK=single` with no rotation and no log shipping; `storage/logs` is container-local. | **VERIFIED** (`.env.example`, `config/logging.php:57-64`) |
| INF-9 | **P2** | `sanctum:prune-expired` is not scheduled even though `config/sanctum.php:48` sets a 1440-minute token expiration. `personal_access_tokens` grows without bound. | **VERIFIED** |
| INF-10 | **P2** | No CD. CI gates merges only; whether a passing commit is what deploys is external. | **VERIFIED** (only `ci.yml` exists) |
| INF-11 | **P2** | PostgreSQL version is stated three different ways: `15-alpine` (compose), `16` (CI), `18.4` (report). No production pin exists in the repository. | **VERIFIED** — **CONFLICT** |
| INF-12 | **P2** | `2026_06_25_000001_create_supabase_storage_buckets` skips silently (log warning) when Supabase env vars are absent, so a production deploy can run migrations forever and never create the buckets. | **VERIFIED** (migration `:16-20`) |
| INF-13 | **P2** | Four of five Supabase storage buckets are configured `public => true`, including the `ids` bucket that holds national-ID / church-permission scans. | **VERIFIED** (`config/supabase-storage.php`) |
| INF-14 | **P2** | `backend/.env.docker` is **tracked in git** and contains a set `DB_PASSWORD` (local development value). Root `.gitignore` lists it; `backend/.gitignore` deliberately does not. | **VERIFIED** (`git ls-files`) |
| INF-15 | **P3** | `.env.example` omits ~20 variables that config and `docker-entrypoint.sh` actually consume (`RUN_MIGRATIONS`, `RUN_PERMISSION_SEEDER`, `PORT`, `DB_MAX_ATTEMPTS`, `DB_RETRY_DELAY_SECONDS`, `LOG_STACK`, `POSTMARK_API_KEY`, `SLACK_*`, …). An operator has no authoritative list. | **VERIFIED** |
| INF-16 | **P3** | `POSTMARK_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_DEFAULT_CHANNEL` are configured but used by nothing. | **VERIFIED** |
| INF-17 | **P3** | `phpunit.postgres.xml` is referenced by no CI step and no composer script. | **VERIFIED** |
| INF-18 | **P3** | PHPStan and Pint run only in the SQLite job, never against the pgsql job. | **VERIFIED** |
| INF-19 | **P3** | `statefulApi()` is never registered; `SANCTUM_STATEFUL_DOMAINS` and `supports_credentials: true` are inert today but are latent misconfiguration if cookie auth is ever enabled. | **VERIFIED** |
| INF-20 | **P3** | `TRUSTED_PROXIES=*` trusts every proxy for the client IP. `TrackActivity` and the rate limiters key on `$request->ip()`. A client that can spoof `X-Forwarded-For` bypasses IP-keyed rate limits. | **VERIFIED** (`.env.example:104`, `AppServiceProvider.php:290-561`, `TrackActivity.php`) |
| INF-21 | **P3** | Docker Compose defines no port-forward restriction, and the local nginx publishes `${NGINX_PORT:-8000}:80` on all interfaces. | **VERIFIED** |
| INF-22 | **INFO** | The production entrypoint is a genuinely strong control: it hard-fails boot on 7 separate misconfigurations and on an unreachable database, refuses to start on an unknown schema state, and never prints a secret. | **VERIFIED** |
| INF-23 | **INFO** | The PostgreSQL CI job is unusually well designed — it provisions the real engine, migrates from scratch, runs a tenant audit that must be clean, verifies the schema catalog, rolls back and re-applies, then runs a named 27-file security suite and the full suite. | **VERIFIED** |

---

## 16. WHAT WOULD BE NEEDED TO VERIFY THE EXTERNAL INFRASTRUCTURE

Recorded here so the next phase does not have to re-derive it. **None of this is performed in Phase 0.**

1. `psql` / `pg_dump` access to the production database (read-only role) — then `php artisan db:show`, `php artisan tenant:verify-schema`, `php artisan tenant:audit`, and `\d+` on the 7 constrained tables.
2. A Railway project token for the service — then logs for `queue-worker` and `scheduler`, service metrics, replica count, env-var name list (values are not needed for this audit).
3. A Vercel project token — deployment list, env-var name list, custom-domain list.
4. A Supabase project token — bucket list with their `public` flags, RLS policy export, backup/PITR configuration.
5. A Resend account — domain verification status, SPF/DKIM/DMARC records, delivery logs for one probe message.
6. `gh` CLI authenticated to the repo — branch protection rules, environments, required reviewers, action permissions, secret names.
7. A database dump plus a scratch PostgreSQL instance — to actually perform a restore drill, which has never been done.
