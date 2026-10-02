# PHASE 1 — DEPLOYMENT VERIFICATION

**Audit date:** 2026-09-30
**Scope:** deployed SHA, CI/CD chain, branch protection, deploy bypass, migration safety in production.

---

## 1. GATE J — DEPLOYMENT IDENTITY

```
GATE J — DEPLOYMENT IDENTITY:  FAIL
```

**The exact commit running in production could not be established.** The live application is real and serving, but its build identity is unknown — and the available evidence indicates it **predates the entire tenant-isolation hardening layer.**

---

## 2. GIT GROUND TRUTH — VERIFIED

### 2.1 Reference matrix

| Reference | SHA | Timestamp | Author | Subject |
|---|---|---|---|---|
| `origin/main` | `bfb2c614da0b3e86c2ea0c611bee41930335364c` | 2026-09-27 10:09:56 +0300 | George | **"last"** |
| `origin/railway/code-change-U-q8O3` | `647672f516501692e7e748b0861f59cd8e8c4e75` | 2026-07-12 07:21:06 +0000 | `railway-app[bot]` | fix: enable catch_workers_output in php-fpm www.conf |
| `refs/pull/1/head` | `647672f…` | 2026-07-12 | — | same as the Railway branch |
| `refs/pull/1/merge` | `c4328e9ed5e9ecb54e46878170505f2711a2ae74` | — | — | — |
| **Local `HEAD`** | `bd37cf5fd510d2ccdeb6d32bb65b179db2181d77` | 2026-09-28 19:39:25 +0300 | — | **"WIP: security hardening in progress (see conversation) - NOT production ready"** |
| **Last tag** | **NONE** | — | — | `git tag` is empty |
| **DEPLOYED** | **UNKNOWN** | — | — | — |

### 2.2 Relationship

```
git merge-base --is-ancestor origin/main HEAD  →  YES
git log --oneline origin/main..HEAD           →  bd37cf5  (exactly 1 commit)
```

**`origin/main` is exactly one commit behind local `HEAD`.** That single commit is the one titled *"NOT production ready"*. On top of it sit **296 modified + 30 untracked** working-tree files.

**The deployed build is `origin/main`-lineage or older.** It cannot be the working tree, and it cannot be local `HEAD` (which is not on the remote at all).

---

## 3. 🔴 WHAT `origin/main` DOES NOT CONTAIN

Verified with `git cat-file -e origin/main:<path>` — **all ABSENT**:

| File | Purpose | In `origin/main`? |
|---|---|---|
| `backend/database/migrations/2026_09_29_000001_add_composite_tenant_foreign_keys.php` | **the 7 composite tenant FKs** | ❌ **ABSENT** |
| `backend/app/Services/TenantConsistencyService.php` | tenant audit engine | ❌ ABSENT |
| `backend/app/Console/Commands/TenantAudit.php` | `php artisan tenant:audit` | ❌ ABSENT |
| `backend/app/Console/Commands/TenantVerifySchema.php` | `php artisan tenant:verify-schema` | ❌ ABSENT |
| `backend/app/Http/Middleware/AssignRequestId.php` | request-ID correlation | ❌ ABSENT |
| `backend/tests/Feature/TenantIsolationMatrixTest.php` | isolation matrix | ❌ ABSENT |
| `frontend/vitest.config.ts` | frontend test runner | ❌ ABSENT |
| `frontend/src/lib/apiUrl.ts` | single-source API URL | ❌ ABSENT |

Also **ABSENT from the Railway bot branch `647672f`**: all five backend files above.

And `git show origin/main:backend/bootstrap/app.php` confirms: **no `AssignRequestId` import, no registration.**

**⇒ If Railway deploys from `main` (the only branch with recent human commits), production runs without the database-level tenant invariant, without the tenant audit tooling, and without request correlation.**

---

## 4. LIVE CORROBORATION — the deployed build is older

Independent of any repository reasoning, obtained by probing production:

| Probe | Production result | Working-tree result |
|---|---|---|
| `X-Request-Id` response header on any API request | **ABSENT** | present |
| `request_id` in error body | **ABSENT** | `"request_id":"01M3SJJMVH6XCCKHQAVV16TYDE"` |
| Client-supplied `X-Request-Id` echoed | **NOT echoed** | preserved when valid |
| `500` body shape | `{"success":false,"message":"Internal server error.","code":"INTERNAL_ERROR"}` | same **+ `request_id`** |

`AssignRequestId` is **untracked**. Its total absence from live responses is direct runtime proof that **the deployed build predates the working tree.**

**Confidence: VERIFIED.**

---

## 5. DEPLOYMENT CHAIN (§45)

```
Developer
   ↓
Git push  ────────────────────────────► origin/main (bfb2c61)
   ↓                                            ↓
   ↓                                    ┌───────┴────────┐
   ↓                                    │                │
   ↓                              Railway Git       Vercel Git
   ↓                              integration       integration
   ↓                                    │                │
   ↓                              BUILD+DOCKER      npm run build
   ↓                                    │                │
   ↓                              container start:        │
   ↓                              migrate --force         │
   ↓                              supervisord             │
   ↓                                    ↓                ↓
   ↓                              cms-production-dafb   cms-flame-eta
   ↓                              .up.railway.app      .vercel.app
```

### 5.1 Where verification can be bypassed

| # | Bypass | Status | Evidence |
|---|---|---|---|
| 1 | **Working tree ≠ any commit** | 🔴 **OPEN, actively exploited** | 296 modified + 30 untracked files, never committed. The 542 green tests prove *the tree*, not *a commit*. |
| 2 | **CI does not gate deploys** | 🔴 **OPEN** | `.github/workflows/` contains only `ci.yml`; **no deploy job**. Deployment is provider-initiated. |
| 3 | **No release tagging** | 🔴 **OPEN** | `git tag` is empty. Nothing identifies what was verified or deployed. |
| 4 | **Deploys run `migrate --force` at container start** | 🟠 **OPEN** | `docker-entrypoint.sh:148-155`. Migrations are **not** a CI-gated step separate from the deploy. |
| 5 | **Multi-replica concurrent migration** | 🟠 **UNRESOLVED** | `RUN_MIGRATIONS` defaults `true`; Railway has no equivalent of Compose's `RUN_MIGRATIONS=false` on worker/scheduler. Replica count unknown. |
| 6 | **Railway bot branch is ~2.5 months stale** | 🟠 **OPEN** | `647672f` from 2026-07-12 vs `main` from 2026-09-27 |
| 7 | **Branch protection** | **NOT VERIFIED** | no `gh` CLI; GitHub settings not visible to `git` |
| 8 | **Required checks / reviewers** | **NOT VERIFIED** | same |
| 9 | **Environment protection / deployment approval** | **NOT VERIFIED** | same |
| 10 | **Direct Railway/Vercel deploy** | **NOT VERIFIED** | dashboard-only |
| 11 | **Production secrets in CI** | **NOT VERIFIED** | no workflow references secrets; the CI workflow needs none |

**⇒ Bypasses 1, 2, 3 and 4 are confirmed open from the repository. Whether GitHub branch protection closes any of them is NOT VERIFIED.**

### 5.2 PR #1

`refs/pull/1/head` = `647672f` (the Railway bot branch), `refs/pull/1/merge` = `c4328e9`. **The PR state (open/merged/closed) is not visible to `git`** and would require GitHub API access.

---

## 6. WHAT CI ACTUALLY GATES

`.github/workflows/ci.yml` — 3 jobs, triggered on `push` to `main` and all `pull_request`:

| Job | Gates | Notes |
|---|---|---|
| `backend` | `composer audit --locked --no-dev`; `php artisan test` (SQLite); `phpstan --level=max`; `pint --test`; migration-rollback lint | ✅ strong |
| `postgres` | `composer audit`; `migrate:fresh`; **`tenant:audit` must be clean**; **`tenant:verify-schema`**; rollback+re-apply; `--testsuite=Security`; full suite on `postgres:16` | ✅ **unusually well designed** |
| `frontend` | `tsc --noEmit`; `eslint`; `check:i18n`; `npm run build`; `npm test`; `npm audit --audit-level=high` | ✅ strong |

**CI is good. The problem is that its output does not reach the deploy path.**

### 6.1 🔴 A CI gate appears to be failing right now

`php scripts/scan-broken-migration-rollbacks.php` is a CI step (`ci.yml:50`). Executed in Phase 0 against the working tree:

```
Scanned 108 migrations.
5 migration(s) with a down() that cannot deterministically undo up() on SQLite:
  2025_01_01_000006_add_attendance_qr_token_to_users.php
  2025_06_01_000001_add_class_year_id_to_events.php
  2025_06_09_000001_add_user_id_to_feedback.php
  2025_06_15_000002_add_context_id_to_attendances.php
  2025_07_09_000001_add_member_id_to_users.php
```

**If this script exits non-zero, the `backend` CI job is red on `main`** — which would mean `origin/main` (the likely deploy source) **is not passing CI**. That would make bypass #2 far more serious than "CI doesn't gate deploys": it would mean *an already-failing commit is the deploy candidate*.

**NOT VERIFIED** — the script's exit code was not observed, and CI status requires GitHub API access. **This is a one-command check** and is listed in §8.

---

## 7. MIGRATION SAFETY IN PRODUCTION (§46)

| Question | Answer | Evidence |
|---|---|---|
| Current migration in production | **NOT VERIFIED** | requires DB access |
| Migrations run automatically? | **YES** | `docker-entrypoint.sh:148-155` |
| On failure? | **Container refuses to start** | *"Laravel migrations failed. The container will not start with an unknown schema state."* ✅ **strong control** |
| Can migrations run before compatible code? | **YES — this is the current state** | the composite-FK migration is uncommitted, so production **cannot** have it applied while the code that assumes it is also uncommitted |
| Rollback operationally possible? | **PARTIAL** | 5 migrations fail the repository's own rollback lint; `down()` methods that drop an indexed column "silently succeed" on PostgreSQL |
| Replica concurrency | **UNRESOLVED** | replica count unknown |

**The fail-closed migration behaviour is genuinely good and should be preserved.** The risk is not in how migrations run — it is in **which migrations are being run.**

---

## 8. REQUIRED ACTIONS TO CLOSE GATE J

All are read-only. Ordered by value.

### Step 1 — Establish the deployed SHA (5 min)
Railway dashboard → the service → **Deployments** tab → latest deployment → commit SHA. Compare against the §2.1 matrix.

### Step 2 — Check whether `main` is passing CI (5 min)
GitHub Actions → is the `backend` job green on `bfb2c61`? If red because of the rollback lint, **the deploy candidate is a failing commit.**

### Step 3 — Check branch protection (5 min)
GitHub → Settings → Branches. Is `main` protected? Are the 3 CI jobs required? Can it be bypassed?

### Step 4 — Run the tenant schema gate (5 min, from a Railway shell)
```bash
php artisan tenant:verify-schema
php artisan tenant:audit
php artisan migrate:status
```
**Read-only and safe.** Resolves Gates C and D and confirms or refutes §3.

### Step 5 — Check the replica count (1 min)
Railway → service → Settings → **replicas**. Resolves U-4 and the concurrent-migration risk.

---

## 9. CONFIDENCE

| Claim | Status | Basis |
|---|---|---|
| `origin/main` = `bfb2c61` (2026-09-27) | **VERIFIED** | `git ls-remote` |
| A `railway-app[bot]` branch exists from 2026-07-12 | **VERIFIED** | `git ls-remote` + fetch |
| **Git-driven deployment from this repo is in use** | **VERIFIED** | Railway bot branch |
| Local HEAD is 1 commit ahead of `origin/main` | **VERIFIED** | `merge-base --is-ancestor` |
| **The composite-FK migration is absent from `origin/main`** | **VERIFIED** | `git cat-file -e` |
| **The deployed build predates the working tree** | **VERIFIED** | live absence of `X-Request-Id` |
| **The exact deployed SHA** | **NOT VERIFIED** | no Railway access |
| Which branch Railway deploys | **NOT VERIFIED** | dashboard-only |
| Branch protection / required checks | **NOT VERIFIED** | no GitHub API access |
| Whether `main` currently passes CI | **NOT VERIFIED** | no GitHub API access |
| Replica count | **NOT VERIFIED** | dashboard-only |
| PR #1 state | **NOT VERIFIED** | no GitHub API access |
| Production migration state | **NOT VERIFIED** | no DB access |

---

## 10. PLAIN STATEMENT

**A real production system is running. Its build cannot be identified.**

**The deployed build is demonstrably older than the local working tree, and `origin/main` — the only plausible deploy source — contains none of the tenant-isolation hardening, including the composite foreign keys.**

**The deployment chain has four confirmed open bypasses: an uncommitted working tree, no CD linkage between CI and deploy, no release tags, and migrations running automatically at container start with unknown replica concurrency.**

**Gate J: FAIL. STOP-7 is met — production is running an untraceable build and security-critical code cannot be identified.**
