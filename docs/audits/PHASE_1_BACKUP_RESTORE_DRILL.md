# PHASE 1 — BACKUP & RESTORE DRILL

**Audit date:** 2026-09-30
**Target:** determine whether the production database can be recovered.

---

## 1. GATES E & F

```
GATE E — BACKUP:   UNKNOWN  — NOT VERIFIED, NO ACCESS
GATE F — RESTORE:  UNKNOWN  — NOT VERIFIED, NOT PERFORMED
```

```
RPO = UNKNOWN
RTO = UNKNOWN
```

**No backup was found, no backup was inspected, and no restore was performed.** This document records what could and could not be established, and — because a restore drill is a procedural matter as much as a technical one — exactly what must be done to close it.

---

## 2. WHAT PHASE 1 COULD ESTABLISH

### 2.1 The production database exists and holds real data

| Evidence | Source |
|---|---|
| `{"status":"healthy","database":"connected"}` | live `GET /health` |
| 2 `churches` rows (ids 117, 129) with Arabic names, slugs, addresses | live `GET /api/v1/churches/active` |
| 1 `daily_verses` row with Arabic text | live `GET /api/v1/verses/active` |

**⇒ Production holds real, non-trivial, multi-tenant data. It is worth protecting, and its loss would be a business-impacting event.**

### 2.2 The storage backend is not directly observable

| Blocker | Evidence |
|---|---|
| No `psql` / `pg_dump` | `Get-Command` → NOT PRESENT |
| No `supabase` CLI | NOT PRESENT |
| No `railway` CLI | NOT PRESENT |
| No production credential in env | scanned for `RAILWAY\|VERCEL\|SUPABASE\|RESEND\|POSTGRES\|PG\|DATABASE` → **zero matches** |
| `DATABASE_URL` | **not set** in any `.env` in the repository |
| `backend/.env` | `DB_HOST=127.0.0.1` — local dev |
| `backend/.env.docker` | `DB_HOST=postgres` — compose-internal only |
| Docker daemon | not running → **no scratch PostgreSQL could be started either** |

**The database host is never exposed**: `/health` reports only `"connected"`, and no error response leaked a DSN, host, or port.

---

## 3. WHAT THE REPOSITORY SAYS ABOUT BACKUPS

**Nothing.** This is a finding, not an absence of searching.

| Searched | Result |
|---|---|
| `pg_dump`, `pg_basebackup`, `pgbackrest`, `barman`, `wal-g` | **NOT PRESENT** anywhere |
| Backup scripts | **NOT PRESENT** |
| Backup cron / schedule | **NOT PRESENT** — `routes/console.php` schedules only `app:clean-expired-invites` (daily) and `app:clean-audit-logs` (weekly) |
| Restore script | **NOT PRESENT** |
| Backup documentation / runbook | **NOT PRESENT** |
| Recovery point objective | **NOT DOCUMENTED** |
| Recovery time objective | **NOT DOCUMENTED** |
| Supabase PITR config | **NOT VERIFIABLE** — external |

**Phase 0 R-04 (P1) is CONFIRMED: the repository contains no backup mechanism, no restore procedure, and no recovery documentation.**

### 3.1 The one thing that archives data — and is not a backup

`app:clean-audit-logs --days=90 --force` (scheduled weekly) chunks `audit_logs` to JSON on the **local disk inside the container**, then deletes the rows. It is:
- **not** a database backup (one table only)
- **not** off-container (container-local storage, ephemeral on redeploy)
- **not** verified (Phase 0 R-53: `chunk()` returns false on a storage failure, silently truncating the cleanup)

---

## 4. DESTRUCTIVE OPERATIONS THAT ASSUME RECOVERABILITY

Every one of these is live in the codebase. **None was executed.**

| Operation | Entry point | Destructive scope | Reversible? |
|---|---|---|---|
| **Church hard delete** | `POST /api/v1/platform/churches/{id}/hard-delete` | ~25 table trees, `class_servant`, `class_years` (Phase 0 Service Matrix §3.3) | **Only via backup** |
| `app:reset-data` | `Artisan` (not routed) | all rows in 19 tables + all users; uses `SET session_replication_role = replica` to **disable FK enforcement** | **Only via backup** |
| Migration `2025_07_02_000000` | `migrate` | `DELETE FROM points WHERE id NOT IN (SELECT MIN(id)…)`; **empty `down()`** | **Irreversible by design** |
| Migration `2026_09_12_000001` | `migrate` | deletes duplicate stages after re-pointing `classes` and `users` | Partially |
| Migration `2026_06_16_000006` `down()` | `rollback` | deletes every stage named `'Default Stage'` **across all churches, no tenant filter** | Partially |
| Migration `2026_08_22_000001` | `migrate` | drops `token`, `token_expires_at`, `used_at` from `password_reset_requests` | **Irreversible** |
| Observers | any model delete | deletes files from Supabase storage, **outside the DB transaction** | **Only via storage backup** |
| `app:clean-audit-logs` | scheduled weekly | deletes `audit_logs` > 90 days | Only via the JSON archive |
| `app:clean-expired-invites` | scheduled daily | deletes expired **and all revoked** QR invites | **Only via backup** |

### 4.1 STOP-5 assessment

> *"Production data has no viable recovery mechanism and a destructive operation is imminent."*

| Part | Status |
|---|---|
| No viable recovery mechanism | **NOT ESTABLISHED** — no evidence, but also no proof of absence (provider-side backups are invisible here) |
| A destructive operation is imminent | **NO** — no such operation was observed, scheduled, or in progress |

**⇒ STOP-5 is NOT formally triggered**, but the precondition it guards against is entirely unverified, and the church hard-delete endpoint is **live and platform-admin-gated** in production.

---

## 5. WHY NO RESTORE WAS ATTEMPTED — and why that was correct

A restore drill requires: a backup artifact, a scratch database, and credentials for both. **None of the three exists in this environment.**

**Per §61 the drill must never** overwrite production, change production DNS, modify production credentials, modify production schema, delete production data, or expose production data publicly. With no backup artifact and no scratch instance, **any** restore attempt would have required touching production. **It was not attempted.**

**Per §34, a backup that has never been restored is not verified disaster recovery.** No such claim is made here.

---

## 6. WHAT MUST BE DONE TO CLOSE GATES E AND F

This is the procedure, unexecuted. **It is written so an operator with provider access can execute it without further design work.**

### Step 1 — Establish whether backups exist (read-only, ~15 min)

Check **in this order**, stopping at the first that applies:

1. **Supabase dashboard** → Database → Backups / Point-in-Time Recovery. Record: enabled?, retention window, last successful restore point.
2. **Railway dashboard** → the PostgreSQL plugin / service → whether automated backups or scheduled dumps are configured.
3. **Railway dashboard** → project → any scheduled job performing `pg_dump`.

Record, per §52 evidence standard: finding ID, claim, evidence, source, timestamp, environment, confidence, impact, risk.

### Step 2 — Obtain a backup artifact (read-only, ~15 min)

- Trigger a point-in-time restore, or download the most recent dump.
- **Record its timestamp and size.**
- **Verify it is not empty** and contains the expected tables (`pg_restore -l` on a custom-format dump lists the TOC without restoring).

### Step 3 — Stand up a scratch PostgreSQL (does not touch production)

Docker is the cleanest route:
```bash
docker run -d --name phase1-restore -e POSTGRES_PASSWORD=scratch -p 55432:5432 postgres:16
```
*Requires a working Docker daemon. In this audit environment the daemon was not running, so this was not possible.*

### Step 4 — Restore into scratch and measure (RTO)

```bash
time pg_restore -h 127.0.0.1 -p 55432 -U postgres -d postgres --no-owner --no-privileges <dump>
```
**Record wall-clock duration = RTO for this backup size.** Repeat for a PITR restore; the two numbers differ and both matter.

### Step 5 — Verify integrity

```sql
-- Row counts vs production (read-only on both)
SELECT 'churches', COUNT(*) FROM churches
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'attendances', COUNT(*) FROM attendances
UNION ALL SELECT 'points', COUNT(*) FROM points
UNION ALL SELECT 'events', COUNT(*) FROM events
UNION ALL SELECT 'qr_invites', COUNT(*) FROM qr_invites
UNION ALL SELECT 'membership_requests', COUNT(*) FROM membership_requests;

-- Run the tenant audit against the RESTORED copy
php artisan tenant:audit

-- Apply the schema gate
php artisan tenant:verify-schema
```

**`tenant:verify-schema` on the restored copy is doubly valuable**: it verifies the backup *and* it may be the first opportunity to answer Gate C.

### Step 6 — Application connectivity test

Point a **local** instance of the app at the scratch database (never production credentials) and confirm:
- `/health` → 200
- login works for a known non-production test account
- one read-only list endpoint returns data

**Do not run migrations against the restored copy before capturing the schema state** — that would destroy the evidence of what was actually backed up.

### Step 7 — Derive RPO

RPO = the interval between the newest data in the restore and now, plus the provider's stated maximum recoverable window.

**Do not invent this number.** If the provider's retention is unknown, `RPO = UNKNOWN`.

### Step 8 — Document and schedule

Record the drill: date, operator, backup timestamp, restore duration, row counts, failures. **Schedule it to recur** — a one-time drill decays into a false assurance within two backup cycles.

---

## 7. RISK IF BACKUP/RESTORE CANNOT BE ESTABLISHED

| Scenario | Consequence | Likelihood |
|---|---|---|
| **No backup exists** | 🔴 **Any data loss is permanent.** A single bad migration, a `app:reset-data` on the wrong host, or the church hard-delete cascade is unrecoverable. | **UNKNOWN — must be established** |
| Backup exists, restore untested | 🟠 Backups exist but have never been proven usable. A format/version mismatch, a missing extension, or a permissions problem would be discovered **during the incident**, not before. This is the most common real-world DR failure. | **UNKNOWN** |
| Backup + restore both fine | 🟢 Recoverable | — |
| Database-level fine, **storage** not backed up | 🟠 National-ID scans, avatars, event images and membership documents are lost even if the database restores. `ChurchApplicationObserver`/`UserObserver` delete files **outside the DB transaction**, so DB and storage can diverge with no reconciliation. | **UNKNOWN** |

**Note the storage dimension explicitly:** the repository has **no evidence of any storage backup**, and `config/supabase-storage.php` places national-ID documents in a bucket. **A database restore does not restore files.**

---

## 8. CONFIDENCE

| Claim | Status |
|---|---|
| Production holds real multi-tenant data worth protecting | **VERIFIED** |
| The repository contains **no** backup mechanism, script, schedule, or runbook | **VERIFIED** — exhaustive search |
| No backup artifact was inspected | **VERIFIED** (it was not accessible) |
| No restore was performed | **VERIFIED** |
| Production backups exist | **NOT VERIFIED** — provider-side, inaccessible |
| Supabase PITR is enabled | **NOT VERIFIED** |
| Backup retention window | **NOT VERIFIED** |
| Last successful backup | **NOT VERIFIED** |
| Backup size / encryption / location | **NOT VERIFIED** |
| Storage (Supabase) backups | **NOT VERIFIED** |
| **RPO** | **UNKNOWN** |
| **RTO** | **UNKNOWN** |
| Disaster recovery readiness | **NOT VERIFIED. No such claim is made.** |

---

## 9. PLAIN STATEMENT

**No evidence of any database backup was obtainable from this audit.** That is not the same as evidence that no backup exists — Supabase and Railway both provide backups that are invisible without dashboard access.

**But it is the same as: nobody has demonstrated, in any artifact available to this repository, that this production database can be recovered.**

**Gates E and F remain open, `RPO` and `RTO` remain `UNKNOWN`, and no disaster-recovery readiness claim can be made.**
