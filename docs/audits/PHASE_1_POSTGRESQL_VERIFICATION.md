# PHASE 1 — POSTGRESQL VERIFICATION

**Audit date:** 2026-09-30
**Target:** production PostgreSQL behind `https://cms-production-dafb.up.railway.app`

---

## 0. GATE B — POSTGRESQL CONNECTIVITY: **UNKNOWN**

```
GATE B — POSTGRESQL CONNECTIVITY:  UNKNOWN
```

**The composite tenant foreign key verification required by §9 and §10 of the Phase 1 brief was NOT PERFORMED.** This is a hard gate and it could not be executed.

---

## 1. WHY THE GATE COULD NOT BE RUN — evidence, not excuse

| Blocker | Evidence |
|---|---|
| No `psql` client | `Get-Command psql` → **NOT PRESENT** |
| No `pg_dump` | `Get-Command pg_dump` → **NOT PRESENT** |
| No production database credential | environment scan for `RAILWAY\|VERCEL\|SUPABASE\|RESEND\|POSTGRES\|PG\|DATABASE` → **zero matches** |
| No `DATABASE_URL` anywhere in the repo | searched all `.env`/`.env.*` → **none set** |
| Local `.env` points at localhost | `backend/.env` → `DB_HOST=127.0.0.1`, `DB_DATABASE=church_management` — a **local dev** target |
| `backend/.env.docker` points at the compose service | `DB_HOST=postgres` — only resolvable inside the Docker network |
| No `supabase` CLI | **NOT PRESENT** |
| No `railway` CLI | **NOT PRESENT** |
| Docker daemon not running | `docker ps` → `failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine` — so a **disposable local PostgreSQL could not be started either** |

**Consequence:** no SQL of any kind was executed against any PostgreSQL instance, production or otherwise.

---

## 2. WHAT *IS* KNOWN about the production database

Two independent facts, both from live production responses:

### 2.1 The application has a working database connection

```
GET https://cms-production-dafb.up.railway.app/health
→ HTTP 200
  {"status":"healthy","service":"Church Manager API","version":"1.0.0",
   "database":"connected","timestamp":"2026-09-30T16:24:26.273090Z"}
```

`/health` executes `DB::connection()->getPdo()` (`routes/web.php:10-29`). A `200` therefore proves:

| Established | Not established |
|---|---|
| PHP-FPM is executing | **The PostgreSQL version** |
| The DB connection is configured | **The database name** |
| **The database is reachable and accepting queries** | **The active schema** |
| | **The migration state** |
| | **Whether the 7 composite FKs exist** |
| | **Whether production data satisfies tenant invariants** |
| | **Whether any constraint was ever created** |

### 2.2 Real data is being served

| Probe | Result |
|---|---|
| `GET /api/v1/verses/active` | **200**, 1 387 bytes — a `daily_verses` row with Arabic text |
| `GET /api/v1/churches/active` | **200**, **2 `churches` rows** (ids 117, 129) with real names, slugs, addresses |

**This proves the schema is at least partially migrated and populated** — `churches` and `daily_verses` exist and are queryable. It proves nothing about the tenant constraints.

---

## 3. THE CRITICAL INFERENCE — migration state is *unknown but constrained*

This is the most important analytical result of this document, and it does **not** require database access.

### 3.1 The application is running a build in which the composite-FK migration does not exist

| Evidence | Source |
|---|---|
| `2026_09_29_000001_add_composite_tenant_foreign_keys.php` is **ABSENT from `origin/main`** | `git cat-file -e origin/main:<path>` → fails |
| It is **untracked** in the local working tree | `git status --short` |
| It is **ABSENT from the Railway bot branch** `647672f` | `git cat-file -e 647672f:<path>` → fails |
| `TenantConsistencyService`, `TenantAudit`, `TenantVerifySchema` are **ABSENT from `origin/main`** and untracked | same |
| `AssignRequestId` middleware is **ABSENT from `origin/main`**, and live production returns **no `X-Request-Id`** | `git show origin/main:bootstrap/app.php` + live probe |

**The deployed build is older than the working tree** (Phase 1 Ground Truth §2.4). The composite-FK migration exists **only** in the uncommitted working tree.

### 3.2 ⇒ The composite tenant foreign keys are, with high confidence, NOT in production

```
TENANT_SCHEMA_GATE = NOT VERIFIABLE — and the evidence points strongly to FAIL
```

**This is an INFERENCE, not a catalog observation, and it is labelled as such.** It cannot be upgraded to VERIFIED without `php artisan tenant:verify-schema` or a catalog query.

**The reasoning chain is sound but has one gap:** it assumes Railway deploys from `main` or the Railway branch. **If Railway is instead deploying from some other branch or a manual upload, the conclusion changes.** That configuration is visible only in the Railway dashboard.

**The required confirmation is a single read-only command:**
```bash
php artisan tenant:verify-schema
```
This exits non-zero on any missing/incorrect constraint and is safe to run. **It is the single highest-value action remaining in this entire audit.**

---

## 4. THE 7 COMPOSITE FOREIGN KEYS — required verification (§10), NOT PERFORMED

The following is the **expected** state per `backend/docs/TENANT_RULES.md` §6 and `PHASE_0_DATABASE_MATRIX.md` §2. **None of it was observed in production.**

| # | Child | Local columns | Parent | Constraint | Prod status |
|---|---|---|---|---|---|
| 1 | `classes` | `(church_id, stage_id)` | `stages (church_id, id)` | `classes_church_stage_fk` | **NOT VERIFIED** |
| 2 | `users` | `(church_id, stage_id)` | `stages (church_id, id)` | `users_church_stage_fk` | **NOT VERIFIED** |
| 3 | `users` | `(church_id, class_id)` | `classes (church_id, id)` | `users_church_class_fk` | **NOT VERIFIED** |
| 4 | `events` | `(church_id, class_year_id)` | `classes (church_id, id)` | `events_church_class_fk` | **NOT VERIFIED** |
| 5 | `event_targets` | `(church_id, class_id)` | `classes (church_id, id)` | `event_targets_church_class_fk` | **NOT VERIFIED** |
| 6 | `qr_invites` | `(church_id, stage_id)` | `stages (church_id, id)` | `qr_invites_church_stage_fk` | **NOT VERIFIED** |
| 7 | `qr_invites` | `(church_id, class_id)` | `classes (church_id, id)` | `qr_invites_church_class_fk` | **NOT VERIFIED** |

### 4.1 Verification queries — prepared, NOT executed

Per §10, each constraint requires: `confdeltype`, `confupdtype`, `confmatchtype`, `condeferrable`, `condeferred`, `convalidated`, `conenforced`. For a **NOT VALID** constraint these differ from a validated one, so a plain `pg_constraint` join without those columns would be misleading.

```sql
-- NOT EXECUTED. Prepared for the operator with read-only DB access.
SELECT
    con.conname,
    src.relname  AS child_table,
    con.conkey,
    tgt.relname  AS parent_table,
    con.confkey,
    con.confdeltype,   -- a=NO ACTION  r=RESTRICT  c=CASCADE  n=SET NULL  d=SET DEFAULT
    con.confupdtype,   -- a=NO ACTION  r=RESTRICT  c=CASCADE  n=SET NULL  d=SET DEFAULT
    con.confmatchtype, -- f=FULL  p=PARTIAL (SIMPLE)  s=SIMPLE
    con.condeferrable,
    con.condeferred,
    con.convalidated,
    con.conenforced
FROM pg_constraint con
JOIN pg_class src ON src.oid = con.conrelid
JOIN pg_class tgt ON tgt.oid = con.confrelid
WHERE con.contype = 'f'
  AND con.conname IN (
    'classes_church_stage_fk','users_church_stage_fk','users_church_class_fk',
    'events_church_class_fk','event_targets_church_class_fk',
    'qr_invites_church_stage_fk','qr_invites_church_class_fk'
  )
ORDER BY con.conname;
```

**Expected if the design is correctly applied** (per `TENANT_RULES.md` §6, confirmed against the official PostgreSQL 18 documentation in Phase 0 Research R-02):

| Column | Required value | Why |
|---|---|---|
| `confupdtype` | **`a` (NO ACTION)** | `ON UPDATE CASCADE` would silently re-home every class when a stage's `church_id` changes — the exact cross-tenant mutation the constraint exists to prevent |
| `confmatchtype` | **`p` (SIMPLE)** | any NULL referencing column ⇒ constraint not evaluated, so `users.class_id = NULL` remains legal |
| `condeferrable` | `false` | immediate enforcement |
| `condeferred` | `false` | |
| **`convalidated`** | **`true`** | ⚠️ **`false` is the critical failure mode.** The migration adds constraints `NOT VALID` when orphans exist (`2026_09_29_000001:131`). `NOT VALID` still enforces *new* writes but does **not** check existing rows. |
| `confdeltype` | `a` or `c` | no requirement either way; deletion behaviour is handled in application code |

**If the query returns zero rows → STOP-1 is triggered: the tenant composite FKs are missing in production.**

Supporting parent keys, also NOT VERIFIED: `stages_church_id_id_unique` and `classes_church_id_id_unique` on `(church_id, id)`. PostgreSQL requires a referenced column to be covered by a PK/unique constraint, so **if the composite FKs exist, these must too**.

### 4.2 `ON UPDATE CASCADE` escape check (§11) — NOT EXECUTED

| Constraint | Current update action | Expected | Safe? | Evidence |
|---|---|---|---|---|
| all 7 | **UNKNOWN** | `a` (NO ACTION) | **UNKNOWN** | no catalog access |

The **preferred** check is `php artisan tenant:verify-schema`, which asserts this exact property and exits non-zero on violation.

---

## 5. PRODUCTION TENANT DATA AUDIT (§12) — NOT PERFORMED

`php artisan tenant:audit` **does not exist in the deployed build** (`TenantConsistencyService` is untracked; `git cat-file` confirms absence from `origin/main`). So this gate could not be run **even with database access**.

| Required check | Status |
|---|---|
| Inconsistent record count | **NOT VERIFIED** |
| Tables affected | **NOT VERIFIED** |
| IDs affected | **NOT VERIFIED** |
| Direction of inconsistency | **NOT VERIFIED** |
| Orphan rows | **NOT VERIFIED** |
| Cross-church relationships | **NOT VERIFIED** |
| `church_id` mismatches | **NOT VERIFIED** |
| NULL tenant ownership where forbidden | **NOT VERIFIED** |
| Whether repair would be required | **NOT VERIFIED** |

### 5.1 Read-only SQL to substitute — prepared, NOT executed

Because the command is absent from production, these queries would be needed. **All are read-only `SELECT`s.**

```sql
-- NOT EXECUTED. Read-only. Run inside a transaction you then ROLLBACK, or rely on
-- these being pure SELECTs (they are).

-- 1. users whose class belongs to a different church
SELECT COUNT(*) FROM users u
JOIN classes c ON c.id = u.class_id
WHERE u.class_id IS NOT NULL AND u.church_id IS DISTINCT FROM c.church_id;

-- 2. users whose stage belongs to a different church
SELECT COUNT(*) FROM users u
JOIN stages s ON s.id = u.stage_id
WHERE u.stage_id IS NOT NULL AND u.church_id IS DISTINCT FROM s.church_id;

-- 3. classes whose stage belongs to a different church
SELECT COUNT(*) FROM classes c
JOIN stages s ON s.id = c.stage_id
WHERE c.church_id IS DISTINCT FROM s.church_id;

-- 4. qr_invites whose class/stage belong to a different church
SELECT COUNT(*) FROM qr_invites q JOIN classes c ON c.id = q.class_id
WHERE q.class_id IS NOT NULL AND q.church_id IS DISTINCT FROM c.church_id;
SELECT COUNT(*) FROM qr_invites q JOIN stages s ON s.id = q.stage_id
WHERE q.stage_id IS NOT NULL AND q.church_id IS DISTINCT FROM s.church_id;

-- 5. event_targets / events whose class belongs to a different church
SELECT COUNT(*) FROM event_targets t JOIN classes c ON c.id = t.class_id
WHERE t.church_id IS DISTINCT FROM c.church_id;
SELECT COUNT(*) FROM events e JOIN classes c ON c.id = e.class_year_id
WHERE e.class_year_id IS NOT NULL AND e.church_id IS DISTINCT FROM c.church_id;

-- 6. Orphan detection — child row pointing at a nonexistent parent
SELECT 'users.class_id' AS rel, COUNT(*) FROM users u
  WHERE u.class_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM classes c WHERE c.id=u.class_id)
UNION ALL
SELECT 'users.stage_id', COUNT(*) FROM users u
  WHERE u.stage_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM stages s WHERE s.id=u.stage_id)
UNION ALL
SELECT 'classes.stage_id', COUNT(*) FROM classes c
  WHERE NOT EXISTS (SELECT 1 FROM stages s WHERE s.id=c.stage_id);

-- 7. NULL tenant ownership where it should be forbidden
SELECT 'stages' t, COUNT(*) FROM stages WHERE church_id IS NULL
UNION ALL SELECT 'classes', COUNT(*) FROM classes WHERE church_id IS NULL
UNION ALL SELECT 'users', COUNT(*) FROM users WHERE church_id IS NULL
UNION ALL SELECT 'events', COUNT(*) FROM events WHERE church_id IS NULL
UNION ALL SELECT 'attendances', COUNT(*) FROM attendances WHERE church_id IS NULL
UNION ALL SELECT 'points', COUNT(*) FROM points WHERE church_id IS NULL
UNION ALL SELECT 'qr_invites', COUNT(*) FROM qr_invites WHERE church_id IS NULL
UNION ALL SELECT 'feedback', COUNT(*) FROM feedback WHERE church_id IS NULL
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications WHERE church_id IS NULL;
```

**These are the queries that would resolve STOP-2 (cross-church relationships in production data). They were not run.**

### 5.2 Tenant Data Gate

```
GATE D — TENANT DATA:  UNKNOWN
```

⚠️ **The repository documents a prior incident in which cross-tenant writes reached the database and were stopped *only* by the composite FKs (`.github/workflows/ci.yml:52-69`). If those constraints are absent in production — which §3 suggests is likely — then that class of write is no longer stopped at the database layer, and no test or audit currently verifies whether any such row exists in production.**

---

## 6. POSTGRESQL FEATURE SUITE (§14) — NOT RUN AGAINST PRODUCTION

**Correctly not run.** The suite uses `RefreshDatabase`, which wraps every test in a transaction that is rolled back — but it also runs `migrate:fresh` semantics on a real connection and creates 6 `AttendanceContext` rows per `Church::factory()`. Running it against production would **write data**. **It was not run. Correctly so.**

| Question | Answer |
|---|---|
| Can the pgsql suite run? | **Not in this environment** — no PostgreSQL reachable, no client, Docker daemon down |
| `phpunit.postgres.xml` connection | `127.0.0.1:5432/church_management_test`, `postgres/postgres` — a **local CI target**, not production |
| CI PostgreSQL config | `.github/workflows/ci.yml` `postgres:16` service, `church_test` DB — CI only |
| Is `phpunit.postgres.xml` referenced by CI? | **No** (Phase 0 R-59 / P3-59) |
| SQLite-specific assumptions | Yes — `ChurchScope` and the FK migration branch on driver; the composite-FK migration does a full `DROP TABLE` rebuild on SQLite |
| PostgreSQL-specific skips | `AttendanceConcurrencyTest` skips 6 tests on SQLite (verified in Phase 0) |

**⇒ Production feature suite: NOT VERIFIED.** Not run here (no engine) and must not be run against production (it writes).

---

## 7. CONCURRENCY VERIFICATION (§15)

```
Concurrency verification:  NOT VERIFIED
```

| Test | What it proves | Status |
|---|---|---|
| `a concurrent session is blocked by an uncommitted duplicate` | real row-lock blocking via a 2nd PDO connection | **SKIPPED locally (SQLite)** |
| `a second identical attendance row cannot be stored` | SQLSTATE `23505` on a partial unique index | **SKIPPED locally** |
| `plain attendance without context is also deduplicated` | second partial index | **SKIPPED locally** |
| `recording the same member twice creates one attendance and one point award` | dedup + points atomicity | **SKIPPED locally** |
| `points cannot be awarded twice for the same attendance` | points partial unique | **SKIPPED locally** |
| `a second member and a second day are both allowed` | no degenerate one-per-member constraint | **SKIPPED locally** |

Phase 0 executed the filter and confirmed **6 skipped, 0 assertions**, each with the message *"Requires PostgreSQL. SQLite has no row-level write locks, so FOR UPDATE is a no-op and a concurrency assertion would pass vacuously."*

**A disposable PostgreSQL could not be started** (Docker daemon down), so these were not executed against a real engine. **Production race conditions were deliberately NOT induced.**

**This is the single most consequential untested area**, because attendance deduplication is the app's business core and its correctness under concurrency depends on PostgreSQL row locks that SQLite cannot exercise.

---

## 8. MIGRATION STATE IN PRODUCTION (§46) — NOT VERIFIED

| Question | Answer |
|---|---|
| Current migration in production | **NOT VERIFIED** — `php artisan migrate:status` requires DB access |
| Are all 108 migrations applied? | **NOT VERIFIED** |
| Migration execution mechanism | `docker-entrypoint.sh:148-155` runs `php artisan migrate --force` on **every container start**, and **fails the boot** if it errors |
| Do migrations run automatically? | **YES** — `RUN_MIGRATIONS` defaults to `true` |
| Replica count | **NOT VERIFIED** — the single most important unknown for this question |
| Concurrent migration risk | **UNRESOLVED.** If >1 replica, two containers can run `migrate --force` simultaneously. Docker Compose explicitly de-conflicts this (`worker`/`scheduler` set `RUN_MIGRATIONS=false`); **Railway does not** |
| Rollback operationally possible? | **5 migrations fail the repository's own rollback lint** (Phase 0 R-08, executed). Against PostgreSQL, `down()` methods that drop a column with a dependent index "silently succeed" per `TENANT_RULES.md` §13 — so a partial rollback is possible without error |

**Positive evidence that migrations run:** real `churches` and `daily_verses` rows are being served, so the schema is at least partially migrated and the boot-time `migrate --force` is not erroring. **This does not indicate WHICH migrations ran.**

---

## 9. DATA INTEGRITY OBSERVABLE WITHOUT DATABASE ACCESS

Despite having no DB client, the live API leaks a small amount of schema information. Recording it because it is real evidence:

| Endpoint | Leaked |
|---|---|
| `/api/v1/verses/active` | A `daily_verses` row: `id`, `verse_text`. Confirms the table exists and is populated. |
| `/api/v1/churches/active` | 2 `churches` rows: `id`, `name`, `slug`, `address`. Confirms the table exists, is populated, and holds real tenant data. |
| Response headers on every API call | `X-RateLimit-Limit: 60`, `X-RateLimit-Remaining: 59` — the `throttle:verse-read` budget is live and decrementing |

**No further schema detail is obtainable without authentication or DB access. No authenticated request was made.**

---

## 10. CONFIDENCE SUMMARY

| Claim | Status | Basis |
|---|---|---|
| Production has a working DB connection | **VERIFIED** | live `/health` → 200 `"database":"connected"` |
| Production serves real tenant data | **VERIFIED** | 2 `churches` rows returned |
| Production PostgreSQL **version** | **NOT VERIFIED** | no client |
| Production **migration state** | **NOT VERIFIED** | no client |
| **7 composite tenant FKs present** | **NOT VERIFIED** | no catalog access |
| **`ON UPDATE CASCADE` escape** | **NOT VERIFIED** | no catalog access |
| **`convalidated = true`** (not `NOT VALID`) | **NOT VERIFIED** | no catalog access |
| **Production tenant data is clean** | **NOT VERIFIED** | `tenant:audit` absent from the deployed build |
| **Cross-church rows exist in production** | **NOT VERIFIED** | no access |
| **PostgreSQL concurrency behaviour** | **NOT VERIFIED** | 6 tests skipped; no engine available |
| Composite FK migration **not in `origin/main`** | **VERIFIED** | `git cat-file -e` |
| Deployed build **predates the working tree** | **VERIFIED** | live responses lack `X-Request-Id` |

---

## 11. THE SINGLE REQUIRED ACTION

Everything in this document resolves with **one read-only command** run by someone with Railway (or Railway Postgres plugin) shell access:

```bash
php artisan tenant:verify-schema
php artisan tenant:audit
php artisan migrate:status
php artisan db:show
```

**All four are read-only and safe.** None modifies data. `tenant:audit` without `--repair` only reports.

**Interpretation:**
- `tenant:verify-schema` exits **0** → Gate C **PASS**
- `tenant:verify-schema` exits **non-zero** → **STOP-1: P0.** The database-level tenant invariant does not exist in production.
- `tenant:audit` reports rows → **STOP-2: P0/P1** depending on exploitability
- `migrate:status` shows a pending composite-FK migration → confirms §3's inference and gives the exact remediation path

**Until these run, the answer to "is production tenant-isolated at the database layer?" is `NOT VERIFIED`, and the available evidence points toward `NO`.**
