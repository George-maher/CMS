# PHASE 0 — DATABASE MATRIX

**Audit date:** 2026-09-30
**Scope:** `backend/database/migrations/` (108 files), `backend/app/Models/` (32 files), `backend/app/Traits/`, `backend/app/Models/Scopes/`, `backend/database/factories/`, `backend/database/seeders/`.
**Method:** read-only inspection of every migration and every model.

> **The single most important fact in this document:**
> 108 migrations were read. **No PostgreSQL server was reachable from the audit machine** (`docker ps` → Docker Desktop not running; no `psql`). Every statement below about *declared* schema is **VERIFIED from source**. Every statement about *actual* PostgreSQL state is **EXTERNAL INFRASTRUCTURE — NOT VERIFIED**.
> `.github/workflows/ci.yml:52-69` records that this exact question has already caused a production incident once: a composite-FK migration used `church_id IS NOT (SELECT …)`, which SQLite accepts and PostgreSQL rejects — so `migrate` aborted on the production driver, **none of the tenant constraints were ever created**, and every SQLite test stayed green.

---

## 1. TABLE INVENTORY (40 tables)

`id` is an auto-increment `bigint` primary key on every domain table unless noted.

### 1.1 Tenant-owned (carry `church_id` + `ChurchScope` global scope)

| Table | Model | `church_id` | Soft delete | Composite FK out | Notes |
|---|---|---|---|---|---|
| `churches` | `Church` | — (IS the tenant) | **Yes** | — | `booted()` creates **6 AttendanceContext rows on create** (`:94-113`) — a factory side effect |
| `stages` | `Stage` | NOT NULL, cascade | No | `stages_church_id_id_unique` | parent of the composite FKs |
| `classes` | `Classe` | NOT NULL, cascade | No | `classes_church_stage_fk` | `unique(church_id, stage_id, name)`; `classes_church_id_id_unique` |
| `attendance_contexts` | `AttendanceContext` | NOT NULL, cascade | No | — | `unique(church_id, slug)`; **global `church_id = null` rows created by the seeder** |
| `attendances` | `Attendance` | fillable | No | — | 3 partial UNIQUE indexes; `attended_date` derived in `booted()` |
| `points` | `Point` | fillable | No | — | `booted()` throws unless the target is a `Member` (`:52-60`); `unique` partial on `(reference_type, reference_id)` |
| `qr_invites` | `Event`→`QRInvite` | fillable | No | `qr_invites_church_stage_fk`, `qr_invites_church_class_fk` | `token` unique(64); `used_by_users` is a **JSON array of user objects incl. name, phone, member_id, unmasked** |
| `events` | `Event` | fillable | No | `events_church_class_fk` | `status`/`type` are plain `string`, no DB enumeration |
| `event_targets` | `EventTarget` | fillable | No | `event_targets_church_class_fk` | |
| `event_views` | `EventView` | NOT NULL | No | — | **`$timestamps = false`** (`:26`); `unique(event_id, user_id)` |
| `feedback` | `Feedback` | fillable | No | — | table name is `feedback` (singular) |
| `membership_requests` | `MembershipRequest` | fillable | No | — | `unique(church_id, email)` — was **globally** unique until `2026_09_24_000001` relaxed it |
| `daily_verses` | `DailyVerse` | fillable | No | — | |
| `daily_spiritual_records` | `DailySpiritualRecord` | NOT NULL, cascade | No | — | `unique(church_id, user_id, activity_date)` |
| `notifications` | `Notification` | **NOT NULL, cascade** | No | — | |
| `profile_update_requests` | `ProfileUpdateRequest` | nullable, nullOnDelete | No | — | `old_values`/`new_values` are `json NOT NULL` with **no `AuditLogValues` cast and no PII masking** → **R-21 (P2)** |

### 1.2 Has `church_id` but **NO** global scope

| Table | Why it matters |
|---|---|
| `users` | The tenant **root**. `User::byChurch()` is opt-in and a **no-op when `church_id` is falsy** (`User.php:425-433`). Pinned deliberately by `ChurchScopeLoginTest:58` — login must resolve before a tenant exists. |
| `audit_logs` | Every audit-log read sees all tenants. |

### 1.3 No `church_id`, no scope — tenant isolation is **transitive and unenforced**

| Table | Tenant path | Composite FK to `events`? |
|---|---|---|
| `event_registrations` | `event_id → events.church_id` | **NO** — `user_id` is `restrictOnDelete`, the only restrict in the schema |
| `event_sessions` | `event_id` | **NO** |
| `event_speakers` | `event_id` | **NO** |
| `event_buses` | `event_id` | **NO** |
| `event_bus_sheets` | `registration_id` / `bus_id` | **NO** |
| `event_payments` | `registration_id` | **NO** |
| `event_rooms` | `event_id` | **NO** — `unique(event_id, room_number)` |
| `event_room_cells` | `room_id` | **NO** — `unique(room_id, cell_number)` |
| `event_accommodations` | `registration_id` / `cell_id` | **NO** — `unique` on both |
| `feedback_replies` | `feedback_id` | n/a |
| `password_reset_requests` | `user_id` only | n/a — policy re-checks `user->church_id` |
| `church_applications` | pre-tenant | n/a |
| `permissions` | global reference data | n/a |
| `role_permission` | global | n/a — **no FK between `role_permission` and `permissions`** |

**This is the largest structural gap in the schema** → **R-19 (P2)**: 10 tables reach a tenant only through a single-column FK chain that the database does not constrain. `event.scope` middleware + `EventAuthorizationService` covers the HTTP path; a direct service call, a job, or a console command does not re-verify.

### 1.4 Infrastructure tables

`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `personal_access_tokens`, `password_reset_tokens`, `class_years` (**legacy, retained**).

`sessions.user_id` is **not** an FK (`0001_01_01_000000:32`).

---

## 2. COMPOSITE TENANT FOREIGN KEYS — the only 7 in the schema

`2026_09_29_000001_add_composite_tenant_foreign_keys.php` (485 lines), `CONSTRAINTS` at `:47-55`:

| Child | Columns | Parent | Constraint name |
|---|---|---|---|
| `classes` | `(church_id, stage_id)` | `stages (church_id, id)` | `classes_church_stage_fk` |
| `users` | `(church_id, stage_id)` | `stages (church_id, id)` | `users_church_stage_fk` |
| `users` | `(church_id, class_id)` | `classes (church_id, id)` | `users_church_class_fk` |
| `events` | `(class_year_id)` + `church_id` | `classes (church_id, id)` | `events_church_class_fk` |
| `event_targets` | `(church_id, class_id)` | `classes (church_id, id)` | `event_targets_church_class_fk` |
| `qr_invites` | `(church_id, stage_id)` | `stages (church_id, id)` | `qr_invites_church_stage_fk` |
| `qr_invites` | `(church_id, class_id)` | `classes (church_id, id)` | `qr_invites_church_class_fk` |

Supporting parent unique keys: `stages_church_id_id_unique`, `classes_church_id_id_unique` on `(church_id, id)` (`:266-288`).

**Verified against the official PostgreSQL 18 documentation (`postgresql.org/docs/current/ddl-constraints.html`):**
- *"a referencing row need not satisfy the foreign key constraint if any of its referencing columns are null"* — this is **MATCH SIMPLE**, the default. It is why `users.class_id = NULL` remains legal. `TENANT_RULES.md` §6 states this correctly.
- *"A foreign key must reference columns that either are a primary key or form a unique constraint, or are columns from a non-partial unique index"* — hence the two supporting unique keys. Correct, and `CompositeForeignKeyTest:69` pins them.
- *"ON UPDATE NO ACTION (the default) … will allow the update to proceed and the foreign-key constraint will be checked against the state after the update"* — `constraintSql()` (`:322-338`) deliberately emits **no `ON UPDATE CASCADE`**, because a cascade would silently re-home every class when a stage's `church_id` changes. `CompositeForeignKeyTest:235` pins this as a regression guard.

**NOT covered by any composite FK** (verified by enumerating the `CONSTRAINTS` list against the model graph):
`attendances.class_year_id`, `feedback.class_year_id`, `qr_invites.class_year_id`, `qr_invites.attendance_context_id`, `attendances.attendance_context_id`, `users.servant_id`, `users.created_by`, `attendances.user_id`, `points.user_id`, `notifications.user_id`, and **the entire `event_*` subtree**.

**Migration behaviour (VERIFIED from source):**
- `guardAgainstUnreviewedCrossTenantRows()` (`:193-242`) **throws `RuntimeException`** and points at `php artisan tenant:audit [--repair]` if any cross-tenant row exists — it never repairs silently.
- Orphans are reported and logged but **never modified**; in that case the constraints are added `NOT VALID` (`:131`), so pre-existing bad rows survive while future writes are enforced.
- The **SQLite path is a full table drop-and-recreate**: `PRAGMA foreign_keys = OFF` (`:400`) → `DROP TABLE` (`:410`) → re-`CREATE` → `INSERT … SELECT` back → replay index SQL → `PRAGMA foreign_keys = ON`. The migration comment (`:33-35`) states this is a no-op in production (PostgreSQL).
- `down()` is **implemented** for both drivers (`:136-158`).
- `TenantConsistencyTest:177` asserts the source contains no `IS NOT (SELECT …)` (comment-stripped via `token_get_all`) — i.e. the historical PostgreSQL syntax error is guarded at the source level.

**Status: the constraint *definitions* are VERIFIED. Their existence in a live PostgreSQL catalog is EXTERNAL INFRASTRUCTURE — NOT VERIFIED.** The repository ships `php artisan tenant:verify-schema` and CI runs it against `postgres:16`; neither was executed against a live engine in this audit.

---

## 3. TENANT-AWARE UNIQUE CONSTRAINTS (10)

| Constraint | Migration |
|---|---|
| `classes (church_id, stage_id, name)` | `2026_06_16_000003:21` |
| `attendance_contexts (church_id, slug)` | `2025_06_22_000001:13` |
| `membership_requests (church_id, email)` | `2026_09_24_000001:17` |
| `stages (church_id, name)` | `2026_09_12_000001:40` (pg) / `:37` (sqlite raw) |
| `stages (church_id, id)` | `2026_09_29_000001:285` |
| `classes (church_id, id)` | `2026_09_29_000001:285` |
| `daily_spiritual_records (church_id, user_id, activity_date)` | `2026_09_08_000001:21` |
| `qr_invites (created_by, client_request_id)` | `2026_08_08_000001:18` — **idempotency key**; `NULL`s permitted multiple times |
| `role_permission (role_name, permission_key)` | `2025_07_04_000001:26` |
| `class_servant` — composite **PRIMARY KEY** `(class_id, user_id)` | `2026_06_16_000004:16` — no `id` column |

**Non-tenant uniques:** `users.email`, `users.attendance_qr_token`, `users.member_id`, `users.email_verification_token`, `churches.slug`, `qr_invites.token`, `permissions.key`, `personal_access_tokens.token`, `event_views(event_id,user_id)`, `event_registrations(event_id,user_id)`, `event_registrations.qr_token`, `event_rooms(event_id,room_number)`, `event_room_cells(room_id,cell_number)`, `event_accommodations.registration_id`, `event_accommodations.cell_id`, `event_bus_sheets.registration_id`, plus framework tables.

**Documented PostgreSQL caveat (from the official docs):** *"By default, two null values are not considered equal in this comparison. That means even in the presence of a unique constraint it is possible to store duplicate rows that contain a null value in at least one of the constrained columns."* This applies to `qr_invites (created_by, client_request_id)` — **an idempotency key with a NULL `client_request_id` is unconstrained**, which is correct here because NULL means "no key supplied".

---

## 4. PARTIAL UNIQUE INDEXES — the attendance dedup strategy

Raw SQL with driver-specific date expressions (`date(attended_at)` on SQLite, `(attended_at::date)` on PostgreSQL).

**Current set** (recreated by `2026_07_08_000001:32-48`):

| Index | Columns | Predicate |
|---|---|---|
| `attendances_user_context_date_unique` | `(church_id, user_id, attendance_context_id, attended_date)` | `WHERE attendance_context_id IS NOT NULL` |
| `attendances_user_event_date_unique` | `(church_id, user_id, event_id, attended_date)` | `WHERE event_id IS NOT NULL` |
| `attendances_user_date_plain_unique` | `(church_id, user_id, attended_date)` | `WHERE event_id IS NULL AND attendance_context_id IS NULL` |
| `points_reference_unique` | `(reference_type, reference_id)` | `WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL` |

All four lead with `church_id`. This is the actual server-side idempotency that makes the frontend's at-least-once offline replay degrade to at-most-once.

**Deduplication chain:** `AttendanceService::processAttendance` uses `lockForUpdate()` on the member (`:108-111`) inside `DB::transaction` (`:105-164`), checks `hasAttendanceToday`, then relies on the unique index as the final backstop (catching SQLSTATE `23505` at `:166`).

**Concurrency proof status:** `AttendanceConcurrencyTest` opens a **second independent PDO connection** and commits out of `RefreshDatabase`'s transaction to prove a concurrent insert is actually blocked. It is **skipped on SQLite** — verified locally:

```
WARN  Tests\Feature\AttendanceConcurrencyTest
  - a concurrent session is blocked by an uncommitted duplicate → Requires PostgreSQL...
  Tests: 6 skipped (0 assertions)
```

→ **PostgreSQL concurrency behaviour: EXTERNAL INFRASTRUCTURE — NOT VERIFIED in this audit.** The repository's own 2026-09-30 report claims 6 passing on PostgreSQL 18.4; no PostgreSQL was reachable here.

---

## 5. CHECK CONSTRAINTS

**NONE. NOT PRESENT in any of the 108 migrations.** No `->check()`, no `CHECK (...)` in raw SQL.

Nine "enum-like" columns have **no database-level enumeration** and are validated only in application code: `events.status`, `events.type`, `points.type`, `qr_invites.type`, `users.role`, `users.scope`, `users.application_status`, `feedback.category`, `event_registrations.status` / `payment_status` / `attendance_status`, `event_payments.method`, `attendance_contexts.slug` semantics.

Only two real DB enumerations exist: `event_room_cells.type` (`enum`, `2026_08_23_000004:28`) and `attendances.status` (`enum`, `2026_09_01_000001:12`).

→ **R-22 (P3).** Per the official PostgreSQL documentation, a `CHECK` constraint is the correct tool for this and is cheap; the absence means a bad write from any non-application path (a job, a migration, a manual `psql`) is not stopped.

---

## 6. INDEXES ON TENANT COLUMNS (24 across 6 migrations)

`attendances(church_id)`, `events(church_id)`, `class_years(church_id)`, `points(church_id)`, `qr_invites(church_id)`, `feedback(church_id)`, `daily_verses(church_id)`, `attendance_contexts(church_id)`, `users(church_id)`, `audit_logs(church_id, created_at)`, `attendances(church_id, attended_at)`, `events(church_id, event_date)`, `qr_invites(church_id, expires_at)`, `points(user_id, church_id)`, `points(user_id, church_id, date)`, `users(church_id, role)`, `users(church_id, is_active)`, `daily_verses(is_active, church_id)`, `membership_requests(church_id, status)`, `membership_requests(church_id, created_at)`, `class_years(is_active, church_id)`, `notifications(church_id, user_id)`, `profile_update_requests(church_id, status)`, `stages(church_id, display_order)`, `classes(church_id, stage_id, display_order)`, `event_targets(church_id, …)`, `users(class_id)`, `users(stage_id)`, `qr_invites(stage_id)`.

**Missing (FACT):**
- No index on `users (church_id, class_id)`.
- **No index on `churches.deleted_at`** — `Church` uses `SoftDeletes` and `/platform/churches` calls `Church::withTrashed()->withCount('users')->get()` (an unfiltered full scan with a correlated count).
- No index on `event_views(church_id)` beyond the single-column one created alongside the column.

`TenantArchitectureTest` asserts **13** tenant models use `BelongsToChurch`. The model inventory shows **15**. → **R-23 (P3): the structural guard does not cover `MembershipRequest` or `Notification`**, so removing the trait from either would not fail any test.

---

## 7. MIGRATION CLASSIFICATION — 108 files

| Class | Count |
|---|---|
| **SAFE** | 66 |
| **REVIEW** | 25 |
| **HIGH RISK** | 14 |
| **UNKNOWN** | 3 |

### 7.1 The 14 HIGH RISK migrations

| Migration | Why |
|---|---|
| `2025_07_02_000000_cleanup_duplicate_points` | **`DELETE FROM points WHERE id NOT IN (SELECT MIN(id) …)`** (`:12-22`) — irreversible data deletion, **empty `down()`** |
| `2025_07_02_000001_prevent_duplicate_attendance_points` | 12 raw SQL statements, 6+ index drops/recreations in `down()`, driver branching |
| `2025_07_08_000001_add_church_id_to_event_views` | correlated `UPDATE` then `nullable(false)->change()` — **aborts the migration** if any event has a NULL `church_id` |
| `2025_07_09_000002_add_context_aware_attendance_unique_indexes` | 4 `DROP INDEX IF EXISTS` then 3 partial UNIQUE indexes |
| `2026_06_16_000006_migrate_class_years_to_stages_classes` | data migration; **`down()` deletes every stage named `'Default Stage'` across all churches with no tenant filter** (`:54`) |
| `2026_06_18_000001_fix_feedback_class_year_id_foreign` | correlated `UPDATE` + `dropForeign`/re-`foreign()` |
| `2026_06_18_000002_backfill_class_year_id_tables` | 3 correlated `UPDATE`s + 3 FK swaps; uses a `goto` label |
| `2026_06_22_000001_fix_batch2_failed_migrations` | consolidates 75-83 behind guards; `fixForeignKey()` wraps `dropForeign`+`foreign` in a **bare `catch (Exception)`** that silently no-ops; **empty `down()`** |
| `2026_06_25_000001_create_supabase_storage_buckets` | **not a schema migration** — calls the Supabase REST API; **skips silently** when env vars are unset, so `up()`/`down()` are asymmetric → **R-12-infra** |
| `2026_07_08_000001_add_attended_date_to_attendances` | driver-specific backfill, drops 7 indexes, creates 3 expression-based partial UNIQUE indexes |
| `2026_08_22_000001_remove_email_reset_tokens_from_password_reset_requests` | **drops `token`, `token_expires_at`, `used_at`** — irreversible |
| `2026_09_12_000001_add_stage_scope_to_users` | 3 unscoped mass `UPDATE`s; **`mergeDuplicateStages()` DELETES duplicate stages** (`:90`) |
| `2026_09_24_000001_add_tenant_uniqueness_and_indexes` | `dropUnique(['email'])` + `unique(['church_id','email'])` — **aborts if duplicate `(church_id,email)` pairs already exist** |
| `2026_09_29_000001_add_composite_tenant_foreign_keys` | the 7 composite FKs; SQLite `DROP TABLE` path; throws on unreviewed cross-tenant rows |

### 7.2 The 3 UNKNOWN (cannot be classified without a populated PostgreSQL)

1. `2026_09_29_000001` — the SQLite drop-and-recreate path and the `NOT VALID` branch have not been exercised against real data on PostgreSQL.
2. `2026_06_18_000002` and `2026_06_22_000001` — **the same `class_years → classes` backfill is reachable from two migrations.** Which one runs depends on `hasColumn`/`hasTable` guards that depend on the actual schema state at that point in history. → **R-24 (P2)**
3. `2026_06_17_000005` — uses `DB::table()` with **no `use Illuminate\Support\Facades\DB;` import and no namespace declaration**. It resolves to the global `DB` alias, which Laravel 12 registers by default via `Facade::defaultAliases()`. Factually: the import is absent; the code depends on the global alias. Works, but fragile.

### 7.3 Migrations with an empty / no-op `down()` — 7

`2025_01_01_000012`, `2025_06_12_000001`, `2025_06_13_000002`, `2025_07_02_000000` (**destructive `up`, no rollback**), `2025_07_02_000002`, `2025_07_05_000001`, `2026_06_22_000001`.

### 7.4 Rollback lint — **5 migrations currently fail it**

`php scripts/scan-broken-migration-rollbacks.php` (run locally, exit reported 5 findings):

```
Scanned 108 migrations.
5 migration(s) with a down() that cannot deterministically undo up() on SQLite:
  2025_01_01_000006_add_attendance_qr_token_to_users.php   (1 index not dropped)
  2025_06_01_000001_add_class_year_id_to_events.php         (1 index not dropped)
  2025_06_09_000001_add_user_id_to_feedback.php             (1 index not dropped)
  2025_06_15_000002_add_context_id_to_attendances.php       (1 index not dropped)
  2025_07_09_000001_add_member_id_to_users.php              (2 created, 1 removed)
```

**This lint is a CI gate** (`.github/workflows/ci.yml:50`) and it **currently reports 5 findings**, which means either the CI backend job is red on `main`, or the script does not fail the build on findings. → **R-25 (P2)** — `MigrationRollbackTest` (which *is* green) only exercises the 4 newest migrations and the 1 newest, not these 5.

### 7.5 Naming collision

`2026_09_01_000001_add_status_to_attendances.php` and `2026_09_01_000001_create_profile_update_requests_table.php` **share the same numeric prefix**. Laravel orders by the full filename string, so the order is deterministic today (`add_…` before `create_…`) — but it is a latent hazard if either is ever renamed.

### 7.6 Raw SQL and destructive operations

- `DB::raw` / raw fragments: 10 sites in `app/` (see §8).
- **`DB::unprepared`: NOT PRESENT** in any migration or service.
- Raw SQL in migrations: 16 files.
- `dropColumn`: 58 occurrences. `dropForeign`: ~35.
- **Row deletion in `up()`:** `DELETE FROM points …` (`2025_07_02_000000:12-22`); `DB::table('stages')->whereIn('id',…)->delete()` (`2026_09_12_000001:90`); `Stage::withoutGlobalScopes()->where('name','Default Stage')->delete()` (`2026_06_16_000006:54`).
- **Unscoped mass `UPDATE` across all tenants:** `events.status` (`2026_08_23_000001:37-38`), `users.scope` (`2026_09_12_000001:26-28`), plus 9 correlated backfill `UPDATE`s.
- **`SET session_replication_role = replica`** in `ResetApplicationData.php:76` (restored `:111`) — a PostgreSQL-only superuser variable that disables **all** FK triggers and cascade/RESTRICT actions for the duration of a data wipe. It will fail on SQLite. → **R-26 (P2)**

---

## 8. RAW SQL & SCOPE-BYPASS INVENTORY (`app/`)

**Raw expressions (10):**

| Location | What |
|---|---|
| `QRInvite.php:186` | `'use_count' => DB::raw('use_count + 1')` — atomic increment paired with an optimistic `where('use_count', $this->use_count)` at `:200` |
| `EventRegistration.php:176` | `'amount_paid' => DB::raw('amount_paid + '.number_format(...))` — the value passes through `number_format`, so the interpolation is numeric, not a user string |
| `ChurchScope.php:20` | `whereRaw('1 = 0')` |
| `Modules/User/Repositories/UserRepository.php:104` | `whereRaw('1 = 0')` — a second independent fail-closed guard |
| `LeaderboardService.php:146-147` | `COALESCE((SELECT SUM(points) FROM points WHERE user_id = users.id),0)` and `COUNT(*) FROM attendances WHERE user_id = users.id` — **the correlated subqueries have no `church_id` predicate**. Safe today only because `user_id` is unique; if a user's points or attendances were ever imported cross-church it would leak. → **R-27 (P3)** |
| `LeaderboardService.php:153-154` | `orderByRaw` on those aliases |
| `TenantConsistencyService.php:254, 302, 322, 343` | cross-tenant mismatch detection |

**`selectRaw` (11):** `AttendanceRepository.php:182-184, 425, 445, 467, 472, 527`; `MemberProfileService.php:69, 83`. All aggregate-only.

**`DB::statement` (7):** all in `TenantVerifySchema.php:312-322` (SAVEPOINT/RELEASE/ROLLBACK TO) and `ResetApplicationData.php:76, 111`.

**`DB::table(...)` direct-builder access — 22 sites** that bypass Eloquent, global scopes, model casts and the `BelongsToChurch::creating` hook:
`HasPermissions.php:51,61` · `VerifyInvitedAccounts.php:71,101` · `TenantVerifySchema.php:198,211,235,243` · `ResetApplicationData.php:64,67,86,88,100,105,150,169` · `CleanAuditLogs.php:27,67,101` · `NotificationService.php:91` (**inserts rows that must carry an explicit `church_id`**) · `FeedbackService.php:185` · `EventRegistration.php:173` · `Permission.php:41,58` · `ChurchDeletionService.php:133,169,360,401,404,412`.

**`withoutGlobalScope(ChurchScope::class)` — 26 sites; `withoutGlobalScopes()` — 4.** See the Endpoint Matrix §6 for the assessment of each. Three of the targeted calls (`EmailVerificationService.php:88, 116, 182`) are **inert** because `User` is not scoped — **verified** against the installed framework source (`Illuminate/Database/Eloquent/Builder.php:213-224`, `unset($this->scopes[$scope])` is unconditional).

**`newQuery()` / `newQueryWithoutScopes()` on a tenant model: NOT PRESENT. `#[ScopedBy]`: NOT PRESENT.** There is exactly **one** `addGlobalScope` call in the codebase (`BelongsToChurch.php:16`).

---

## 9. LEGACY `class_years` — VERIFIED CONFLICT SURFACE

`class_years` is **still present and still written** (`ChurchDeletionService.php:404` deletes rows from it), but **every** `class_year_id` foreign key has been repointed to `classes` — **except one**.

| Column | FK target | Meaning |
|---|---|---|
| `users.class_year_id` | **`class_years(id)`** | the last remaining `class_years` id space — and it is `prohibited` on input |
| `attendances.class_year_id` | `classes(id)` | legacy **column name**, `classes` id space |
| `events.class_year_id` | `classes(id)` | same |
| `qr_invites.class_year_id` | `classes(id)` | same |
| `feedback.class_year_id` | `classes(id)` | same |

**The official PostgreSQL documentation confirms the danger is real:** two tables have **independent sequences**, so a `class_years` id and a `classes` id collide routinely. `AttendanceService` once wrote `$member->class_year_id ?? $member->class_id` — a `class_years` id into a `classes` FK. It is now `$member->class_id`, and `AttendanceClassIdSpaceTest:154` reproduces the collision and asserts the attendance lands on the member's **own** class. The test also asserts the FK target of `users.class_year_id`, so the day someone repoints it the test says so rather than the guard going quietly decorative.

`TENANT_RULES.md` §12a is **accurate and matches the code**. Retaining the table is a data decision, not a cleanup, and the documentation says so correctly.

---

## 10. FACTORIES AND SEEDERS

**Factories (10):** `User`, `Church`, `Stage`, `Classe`, `AttendanceContext`, `QRInvite`, `Event`, `EventRegistration`, `ChurchApplication`, `Feedback`(?) — actual list is 10 files.

| Factory | Notable behaviour |
|---|---|
| `UserFactory` | **No `church_id`, `stage_id`, `class_id`.** Password `Hash::make('password')` — the reason `PasswordHashTest` exists. State `unverified()`. |
| `ChurchFactory` | Fires `Church::booted()['created']` → **creates 6 AttendanceContext rows** as a side effect |
| `StageFactory` / `ClasseFactory` | `church_id => Church::factory()`; `ClasseFactory` reuses the same church for the stage closure so the composite FK is satisfiable |
| `QRInviteFactory` | **`created_by => User::factory()` and `church_id => Church::factory()` are independent** (`:21-22`) — the two tenants will not match. `token => Str::random(64)`, `expires_at => now()+4h` (the documented default) |
| `EventFactory` | `User::factory()->create()` is called **inside `definition()`** (`:19`) — every event creation also creates a user. **No `church_id`**, so the `BelongsToChurch` `creating` hook supplies it from `auth()` — or leaves it NULL in an unauthenticated test |
| `EventRegistrationFactory` | **No `event_id`, no `user_id`** — both NOT NULL FKs. This factory cannot produce a valid row unaided. → **R-28 (P3)** |

**Missing factories (22):** `Attendance`, `Point`, `Feedback`, `FeedbackReply`, `Notification`, `AuditLog`, `MembershipRequest`, `EventView`, `EventTarget`, `EventSession`, `EventSpeaker`, `EventBus`, `EventBusSheet`, `EventPayment`, `EventRoom`, `EventRoomCell`, `EventAccommodation`, `ProfileUpdateRequest`, `PasswordResetRequest`, `DailyVerse`, `DailySpiritualRecord`, `Permission`.

**Seeders (4):**

| Seeder | Behaviour |
|---|---|
| `DatabaseSeeder` | Orchestrator; `WithoutModelEvents` (so **no audit rows during seeding**); calls `PermissionSeeder`, `AttendanceContextSeeder`, `AdminUserSeeder` |
| `PermissionSeeder` | 28 `permissions` via `updateOrCreate`; **`DB::table('role_permission')->truncate()`** (`:24`) — destroys any custom mapping — then a bulk insert, in one transaction, then `Permission::clearCache()` |
| `AttendanceContextSeeder` | 8 global rows with **`church_id => null`** via `withoutGlobalScope(ChurchScope::class)` — required, since the fail-closed scope would filter them out |
| `AdminUserSeeder` | **`run(): void {}` — an empty file**, still called by `DatabaseSeeder` → **R-29 (P3)** |

---

## 11. WHAT IS ENFORCED BY THE DATABASE, LAYER BY LAYER

| Invariant | Form request | Controller | Service | Model scope | **Database** |
|---|---|---|---|---|---|
| a class belongs to a stage **in the same church** | `exists:stages,id` only | `Stage::query()->find()` + `StagePolicy` | `ClasseService` re-check (`TenantOwnershipBoundaryTest:565` asserts it throws) | `ChurchScope` | **`classes_church_stage_fk`** |
| a user's class is in the user's church | `exists:classes,id` | `classWithinScope` | `UserService::create` re-check (`:594` asserts) | — (`User` unscoped) | **`users_church_class_fk`** |
| a user's stage is in the user's church | `exists:stages,id` | `stageWithinScope` (D-1 fix) | — | — | **`users_church_stage_fk`** |
| an event targets a class in its church | `exists:classes,id` | `targetsWithinScope` (whole-array rejection) | — | `ChurchScope` | **`events_church_class_fk`** |
| a QR invite's class/stage is in its church | **`Rule::exists()->where('church_id', …)`** | `allowedClassIds` | token re-bind | `ChurchScope` | **`qr_invites_church_*`** |
| an attendance is unique per (church, user, context, day) | — | — | `lockForUpdate` + `hasAttendanceToday` | `ChurchScope` | **partial UNIQUE index** |
| a point is awarded once per reference | — | — | — | `ChurchScope` | **partial UNIQUE index** |
| a registration belongs to its event | `exists:event_registrations,id` (no event link) | `resolveRegistration` scopes to `event_id` ✔ | — | **none** | **none** |
| an accommodation cell belongs to the event | `exists:event_room_cells,id` | `findEvent` only | unverified | **none** | **none** |
| `role` / `status` / `type` are valid values | `in:` rules | — | — | casts to PHP enums | **none (no CHECK)** |
| `profile_update_requests.old_values` is PII-masked | — | — | `AuditService` for audit logs | — | **none** — unlike `audit_logs`, no `AuditLogValues` cast |

---

## 12. CONFIDENCE

| Claim | Status |
|---|---|
| 108 migrations exist and their declared effects | **VERIFIED** — every file read |
| Every FK / unique / index listed above is declared as stated | **VERIFIED** — source read |
| No CHECK constraint exists anywhere | **VERIFIED** — full grep |
| `class_years` retention and the single remaining FK to it | **VERIFIED** — source + `AttendanceClassIdSpaceTest:108` |
| `withoutGlobalScope` on `User` is a no-op | **VERIFIED** — framework source at the installed v12.69.3 |
| The 5 broken rollbacks | **VERIFIED** — lint executed locally |
| 6 PostgreSQL-only concurrency tests are skipped on SQLite | **VERIFIED** — executed locally |
| **Actual PostgreSQL schema state, including the 7 composite FKs** | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** |
| **Whether `migrate` completes on PostgreSQL today** | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** |
| **Whether production data currently satisfies every constraint** | **EXTERNAL INFRASTRUCTURE — NOT VERIFIED** — `tenant:audit` was not run |
| Backup / restore capability | **NOT VERIFIED** — no backup configuration exists in the repository |
