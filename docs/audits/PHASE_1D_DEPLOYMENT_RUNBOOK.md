# PHASE 1D — DEPLOYMENT RUNBOOK

Exact sequence for the **eventual** deployment. Nothing in this document has been
executed against production. Phase 1D performed no deployment activity whatsoever
(Rule 1).

Owners: Release Engineer (RE), Platform/Infra (INF), Application Lead (AL).
All commands run in the deployment environment unless marked *(local/disposable)*.

---

## 0. RELEASE CANDIDATE (must be fixed before anything else)

```text
Reviewed state : HEAD bd37cf5fd510d2ccdeb6d32bb65b179db2181d77  +  305 dirty entries
Branch         : main (uncommitted working tree)
Reproduce with : git checkout <release-sha>   after the authorised commit
```

The reviewed tree is **not yet a commit**. Deployment providers deploy commits, not
working trees. Therefore:

> **Step 0 is a CONDITION, not an instruction Phase 1D may perform.** An authorised
> human commits the tree, tags it (e.g. `release/1d-candidate`), and records the SHA.
> Every step below refers to `<RELEASE_SHA>`.

---

## 1. BEFORE DEPLOY

| # | Check | How | Required state |
|---|-------|-----|----------------|
| B1 | Confirm intended SHA | `git rev-parse HEAD` → equals `<RELEASE_SHA>`; `git status --short` → clean | SHA recorded in the release ticket |
| B2 | Confirm backup | INF confirms a pre-deploy backup of the production PostgreSQL exists (timestamp + destination) | **backup older than the deploy start** |
| B3 | Confirm environment variables | INF lists (values **never** printed): `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `DB_*`, `DB_SSLMODE`, `CACHE_STORE`, `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `QUEUE_CONNECTION`, `MAIL_MAILER`, `RESEND_API_KEY`/`services.resend.key`, `FILESYSTEM_DISK`, `LOG_CHANNEL` | all PRESENT as required; `APP_ENV=production`, `APP_DEBUG=false` |
| B4 | Confirm Railway configuration | `backend/railway.json` healthcheck + start command match the app; root directory = `backend` | verified |
| B5 | Confirm Vercel configuration | `frontend/vercel.json` rewrites (`/(.*)→/index.html`, `/api/(.*)→/api/$1`), `VITE_API_URL` = backend origin | verified |
| B6 | Confirm queue worker | worker process/service defined for the `database` queue | worker present |
| B7 | Confirm scheduler | scheduler runs `schedule:run` every minute; registered tasks: `app:clean-expired-invites --days=7` (daily 03:00), `app:clean-audit-logs --days=90 --force` | scheduler active |
| B8 | Confirm email configuration | decide the production mailer: `log` (no delivery) vs `resend` (requires API key) | decision recorded; if `resend`, key present |
| B9 | Confirm storage | disk driver + credentials present; buckets created (`app:create-storage-buckets` if used) | storage reachable |
| B10 | Confirm CI green on `<RELEASE_SHA>` | CI pipeline (PHP 8.3) passes | green run URL recorded |
| B11 | Re-confirm tenant data is repairable | *(local/disposable or read-only prod query)* `php artisan tenant:audit` **without** `--repair` | "No inconsistencies found" |
| B12 | Confirm rollback plan | previous SHA + backup identified; note the documented rollback limitation (RR-05: 5 historical `down()` methods are SQLite-only) | plan written |

**Go / No-Go:** every row must be ✅ or explicitly waived in writing.

---

## 2. DEPLOY

| # | Step | Notes |
|---|------|-------|
| D1 | Deploy `<RELEASE_SHA>` | backend → Railway; frontend → Vercel. **Do not deploy `main` head if it differs from `<RELEASE_SHA>`.** |
| D2 | Monitor the deployment | watch build logs for `composer install --no-dev --prefer-dist --optimize-autoloader` success and for `php artisan package:discover` errors |
| D3 | **Do not skip migration safety checks** | migrations run only in step D4, after B2/B11 |
| D4 | Run migrations | `php artisan migrate --force` — `--force` is required because `APP_ENV=production` (Laravel 12 docs) |
| D5 | Watch for constraint failures | if the composite-FK migration refuses (orphan rows), **stop** — do not force; run `php artisan tenant:audit` read-only and escalate |
| D6 | Clear/rebuild caches | `php artisan config:cache && php artisan route:cache && php artisan view:cache` (startup may already do this) |
| D7 | Restart workers | queue worker restarted **after** migrations |

---

## 3. IMMEDIATELY AFTER DEPLOY (in this exact order)

```bash
# 1. Deployment identity
php artisan --version && git rev-parse HEAD          # == <RELEASE_SHA>

# 2. Tenant schema gate (SAFE: wraps probes in a transaction and always rolls back)
php artisan tenant:verify-schema
#    expected: all 7 composite FKs OK + 2 unique keys OK → exit 0

# 3. Tenant data gate (READ-ONLY: never pass --repair here)
php artisan tenant:audit
#    expected: "No inconsistencies found." → exit 0
```

Then verify, in order:

| # | Item | Expected |
|---|------|----------|
| A1 | deployed SHA | equals `<RELEASE_SHA>` |
| A2 | migrations | `php artisan migrate:status` → all **Ran**, 0 Pending (108 on this release) |
| A3 | application health | health endpoint 200; no error spike in the first 15 minutes |
| A4 | queue worker | running; `jobs` table draining; `failed_jobs` not growing |
| A5 | scheduler | last run recorded; both scheduled commands executing |
| A6 | failed jobs | count stable/near zero; any `ApiKeyIsMissing` ⇒ mailer/key mismatch (Gate I) |
| A7 | email | if mailer=`resend`, one safe test delivery; if `log`, record that delivery is intentionally off |
| A8 | storage | write + read probe succeeds |
| A9 | logs | `Rate limit exceeded` appears as **warning** (never as an ERROR 500); `request_id` present on requests |
| A10 | request IDs | `AssignRequestId` header/body present on API responses |

**The production commands above are executed only after deployment and authorised
production access exist.** They are listed here so the sequence is deterministic.

---

## 4. ROLLBACK CRITERIA

Roll back `<RELEASE_SHA>` if any of:

* `migrate --force` fails non-transiently, or `tenant:verify-schema` exits non-zero;
* authentication is broken (login 5xx / all requests 401);
* cross-tenant behaviour is observed (`tenant:audit` reports inconsistencies);
* error rate (5xx) materially exceeds the pre-deploy baseline.

Rollback = redeploy previous SHA. **Database rollback is NOT the default path**: five
historical `down()` methods are SQLite-only (RR-05). Prefer *fix-forward*; if a data
migration must be reversed, do it from the backup taken in B2.

---

## 5. WHAT THIS RUNBOOK DELIBERATELY DOES NOT CONTAIN

* production credentials or secret values;
* any command executed by Phase 1D;
* any assumption that production has been verified — that is the purpose of
  `PHASE_1D_POST_DEPLOY_CHECKLIST.md` (Gates A–J).
