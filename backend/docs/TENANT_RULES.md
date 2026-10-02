# Tenant Rules for Developers

Read this before adding an endpoint, a model, or a tenant-sensitive field.

---

## 1. The one rule

```
Existence  !=  Ownership  !=  Authorization
```

These are three different questions, and answering only the first is the
bug class this project has shipped twice.

| Question | Answered by | Wrong answer looks like |
|---|---|---|
| **Existence** — does row 42 exist? | `exists:classes,id` | `422` when absent |
| **Ownership** — does row 42 belong to *this* actor's church/stage/class? | `ChurchScope`, `ScopeResolver`, a Policy | `404` when foreign |
| **Authorization** — may this actor perform *this operation* on it? | `Gate::authorize()`, `PermissionMiddleware` | `403` when not allowed |

`exists:` proves a row exists **somewhere in the platform**. It says nothing
about which tenant owns it. It is a *type check*, not a *permission check*.

### Why the distinction is load-bearing

A single-column foreign key cannot express ownership either. `users.class_id →
classes.id` only proves class 42 exists. It happily allows church A's admin to
attach a member to church B's class. This is exactly the bug that shipped
twice (on user create, then again on user update).

That is why the composite keys exist:

```
(church_id, class_id) -> classes (church_id, id)
(church_id, stage_id) -> stages  (church_id, id)
```

The parent is proved *in the same church*, so the invalid state becomes
unrepresentable rather than merely discouraged.

---

## 2. The hierarchy

```
Platform Admin
    ↓
Church
    ↓
Stage
    ↓
Class
    ↓
Member / Servant
```

A resource is owned by the **nearest** ancestor that owns it:

- `Stage` belongs to a `Church`.
- `Classe` belongs to a `Stage`, and therefore to that stage's church.
- `User` belongs to a `Class` (and/or a `Stage`), and therefore to a church.

A class is never directly "in a church" independently of its stage. If a
class's `church_id` disagrees with its stage's, one of the two is wrong and
the database now refuses the write.

---

## 3. The correct pattern

```
resolve the resource INSIDE the authorized scope
    → authorize
    → service defence-in-depth
    → persist
    → database invariant
```

Concretely:

```php
public function update(Request $request, UpdateStageRequest $validation, int $id): JsonResponse
{
    // 1. RESOLVE INSIDE THE SCOPE. Never `Stage::find($id)`.
    //    The global ChurchScope adds `church_id = <actor's church>`,
    //    so a foreign id simply does not resolve.
    $stage = Stage::query()->find($id);

    if ($stage === null) {
        return response()->json(['message' => 'Stage not found.'], 404);
    }

    // 2. AUTHORIZE the operation on the resolved object.
    $this->authorize('update', $stage);

    // 3. SERVICE does its own check again (defence in depth), because a
    //    service can be called from a command, a job, or another
    //    controller that forgot the policy.
    $this->stageService->update($stage, $validation->validated());

    // 4. PERSIST. The composite FK is the final backstop.
}
```

**Why resolve-then-authorize rather than authorize-then-resolve?** Because
authorization needs the object. And resolving through the scoped model means
an id belonging to another tenant produces `404`, not `403` — which is
correct: the resource genuinely does not exist *for this actor*, and
distinguishing the two would leak the existence of other tenants' data.

---

## 4. When `exists:` is acceptable, and when it is dangerous

`exists:` is **fine** and should stay when it is a structural type check and
ownership is settled by a different, named layer:

| Situation | Verdict | Why |
|---|---|---|
| A public endpoint where the token itself determines the tenant (invite acceptance, registration) | **Allowed** | There is no actor tenant to check against. `AuthService::register` constrains the class to the *invite's* church. |
| A tenant-scoped field whose controller/service/policy resolves the id through a scoped model | **Allowed** — but the ownership check must be named in `TenantArchitectureTest` | `exists:` is a type check; the scoped resolution is the ownership answer. |
| A bulk array (`target_class_ids.*`) where **every** element is resolved and rejected as a whole | **Allowed** | See `EventController::targetsWithinScope`. |
| A tenant-sensitive field where **nobody** re-resolves the id | **Fix immediately** | This is Category C. It is a live vulnerability. |

### The rule of thumb

> If you cannot name the line of code that answers the *ownership* question,
> `exists:` is the only thing standing between the request and a cross-tenant
> write. Find the owner, or add one.

`TenantArchitectureTest::tenantSensitiveFieldProvider()` is the registry of
"field → the layer that owns the check". Adding a new tenant-sensitive field
means adding a row there. Deleting a row is allowed; deleting the *check* is
not — the test fails if the named method disappears.

### A worked example of the trap
`users.class_year_id` was validated with `exists:class_years,id` and **never**
re-resolved, because the controller only validated ownership for `class_id`.
Meanwhile every *other* `class_year_id` in the schema had been repointed from
the deprecated `class_years` table to `classes`. So the field silently mixed
two id spaces, and `EventPolicy` compared them with `===` — an authorization
decision made by comparing unrelated identifiers. The field is now
`prohibited` on input and the policy compares `class_id` on both sides.

### The same trap, second time, in the data layer

`AttendanceService` recorded:

```php
'class_year_id' => $member->class_year_id ?? $member->class_id,
```

`attendances.class_year_id` is a legacy **column name** holding a `classes.id`
(backfilled and repointed by `2026_06_22_000001`). `users.class_year_id` is the
one remaining `class_year_id` still foreign-keyed to `class_years`. So this
read a `class_years` id and wrote it into a `classes` foreign key.

It was latent rather than live, because `class_year_id` is `prohibited` on
input and every member therefore has `NULL` there, so the `??` arm always
applied. But the column is nullable and still populated by historical
backfills, and a single colliding value is all it takes: either a 500 from the
foreign key, or — far worse — a **successful write attributing the attendance
to a different class**, with no error and corrupted per-class reporting. The
two tables have independent sequences, so collisions are expected, not
exceptional.

**The rule this yields:** a legacy column *name* is not a statement about the
id space it holds. Before reading a `<something>_id` into another table,
confirm the two columns are foreign-keyed at the **same** table. Two
`class_year_id` columns in this schema point at different tables, and that
inconsistency is the whole bug class. `AttendanceClassIdSpaceTest` pins it and
also asserts the FK target, so the day someone finally repoints
`users.class_year_id` at `classes`, the test says so instead of the guard
quietly becoming decorative.

---

## 5. `ChurchScope` — the tenant scope

Models that carry tenant data declare the `BelongsToChurch` trait, which
registers the `ChurchScope` global scope.

```php
class Classe extends Model
{
    use BelongsToChurch;   // ← this is what makes find()/query() safe
}
```

Because it is a **global scope**, this is safe:

```php
Classe::query()->find($id)     // filtered to the actor's church
Classe::find($id)              // same
Classe::withoutGlobalScope(ChurchScope::class)->find($id)   // NOT safe alone
```

### The three outcomes of the scope

| Actor | Result |
|---|---|
| Unauthenticated web request | `WHERE 1 = 0` — **fail closed** |
| Platform Admin | No filter — **explicit, intentional bypass** |
| Authenticated, has `church_id` | `WHERE church_id = <id>` |
| Authenticated, `church_id IS NULL` | `WHERE church_id = 0` — **fail closed** |
| No authenticated user at all (console, queue, seeder) | No filter |

The fourth row was a live vulnerability. `resolveChurchId()` returns `null`
for both "platform admin" and "user with no church", and `null` used to mean
*apply no filter at all*. An approved non-platform user with
`church_id = NULL` therefore read **every church's** stages from
`GET /api/v1/stages`. `StageController::show()` survived only because
`ScopeResolver::canAccessStage()` re-checked afterwards; list endpoints have
no such second check, so the scope itself has to deny the rows.

**If you add a new "unfiltered" case, fail closed.** Absence of tenant
context is never a reason to widen visibility.

### `User` is deliberately not scoped

`User` does **not** use `BelongsToChurch`, and that is intentional. `User`
is needed unscoped by authentication (login by email), by the platform admin
(who belongs to no church), and by invite acceptance (where the invite
determines the church). Adding a global scope would break login.

The cost is that `User::find($id)` is global, so the convention is:

```php
User::byChurch()->find($id)        // tenant-scoped  ← use this
User::find($id)                    // global         ← justify every use
```

`ScopeResolver::canAccessUser($actor, $target)` is the canonical answer to
"may this actor touch this user?" and handles every role, including
platform admin and self-access.

---

## 6. Composite tenant foreign keys

Added by `2026_09_29_000001_add_composite_tenant_foreign_keys.php`.

| Child | Column | Parent | Constraint |
|---|---|---|---|
| `classes` | `stage_id` | `stages` | `classes_church_stage_fk` |
| `users` | `stage_id` | `stages` | `users_church_stage_fk` |
| `users` | `class_id` | `classes` | `users_church_class_fk` |
| `events` | `class_year_id` | `classes` | `events_church_class_fk` |
| `event_targets` | `class_id` | `classes` | `event_targets_church_class_fk` |
| `qr_invites` | `stage_id` | `stages` | `qr_invites_church_stage_fk` |
| `qr_invites` | `class_id` | `classes` | `qr_invites_church_class_fk` |

Properties that matter:

- **MATCH SIMPLE** (the default). If *any* referencing column is `NULL`, the
  constraint is not evaluated. This is why a member with no class is fine.
  Do not "fix" this by making columns `NOT NULL`.
- **No `ON UPDATE CASCADE`, deliberately.** With a cascade, changing a
  stage's `church_id` silently re-homes every class that belongs to it — an
  implicit cross-tenant mutation of exactly the kind the constraint exists to
  prevent. Moving a resource between churches must be explicit and audited,
  so the database refuses instead. `CompositeForeignKeyTest` pins this.
- The parent keys `stages_church_id_id_unique` and
  `classes_church_id_id_unique` exist only because PostgreSQL requires a
  unique key to reference. Do not drop them.

### The migration does not repair silently

`up()` **refuses to run** if cross-tenant rows already exist, because
"fixing" them re-homes real data. It points at:

```bash
php artisan tenant:audit              # read-only report
php artisan tenant:audit --repair     # explicit, confirms first
```

`tenant:audit` reports, per relationship, the offending row count, the ids,
and the proposed direction (`church_id: 901 → 902`). It never modifies data
without `--repair`.

**Orphans** (a child pointing at a parent that does not exist) are reported
but **never** modified. There is no parent to inherit a church from, and the
earlier subquery-based repair wrote `NULL` over `church_id`, permanently
destroying the only tenant information such a row carried.

---

## 7. Platform Admin semantics

`role == platform_admin` does **not** mean "can do anything anywhere".
Platform authority and church authority are **separate domains that do not
overlap**:

- Platform endpoints are gated by `role:platform_admin` middleware. Every
  church role gets `403`.
- Church endpoints are gated by `permission:*` and `ChurchScope`. A platform
  admin has **no** `role_permission` rows, and
  `Permission::defaultRolePermissions()` deliberately has **no**
  `platform_admin` key, so it gets `403` on `manage_users`-class endpoints.

That asymmetry is intentional and load-bearing. Adding a `platform_admin`
entry to the permission defaults would hand every platform admin
church-wide user management — a privilege escalation, not a bug fix.
`PlatformAdminAuthorizationMatrixTest::test_permission_lookup_has_no_platform_admin_default()`
exists to stop exactly that.

Where a platform admin **is** meant to cross tenants, it is its own
endpoints (`/platform/*`), and that is asserted as intended behaviour so it
is not "fixed" away.

Destructive platform operations (`soft-delete`, `restore`, `hard-delete`)
additionally require, via `DeleteChurchRequest`: the platform role, a
password re-check, and the literal string `DELETE CHURCH`.

---

## 8. Adding a new tenant-sensitive resource

1. **Model** — add a `church_id` column and `use BelongsToChurch;`.
2. **Migration** — create it. Do **not** add `ON UPDATE CASCADE`.
3. **Composite FK** — if it references a stage or class, add an entry to
   `TenantConsistencyService::RELATIONSHIPS` and to the `CONSTRAINTS` list in
   the composite-FK migration (or a new migration if that one has shipped).
   Verify on **PostgreSQL**, not only SQLite.
4. **Scope** — resolve through `Model::query()`, never `Model::find()`.
5. **Validate structurally** — `exists:...` for the type, and name the
   ownership check in `TenantArchitectureTest::tenantSensitiveFieldProvider()`.
6. **Authorize** — add a Policy and call `$this->authorize()`.
7. **Service** — re-check ownership; services are callable from commands and
   jobs where middleware does not run.
8. **Test** — add a row to `TenantArchitectureTest::tenantScopedModelProvider()`
   and to the isolation matrix.

### The check must cover every role, not just the one that prompted it

`CreateUserRequest::stage_id` shipped with a `stageWithinScope()` call that sat
**inside** the `if ($role === stage_admin)` branch, so it guarded stage-admin
creation and nothing else. A `member` payload carrying a foreign `stage_id`
reached the database and was stopped by `users_church_stage_fk` — which stopped
it by *throwing*, producing a 500 `INTERNAL_ERROR` and a 500-vs-422 split that
turned the endpoint into a cross-tenant stage-existence oracle. The same field
on `update()` was already correct for every role. That asymmetry was the bug,
and it is invisible unless you ask "which roles does this branch actually
cover?" rather than "is there a check for this field?".

When a check is added or moved, assert it for **every role that can reach the
endpoint**, and add a negative case that asserts a 4xx and an unchanged
database — never just that the field is validated.

---

## 9. Adding a new endpoint safely

```
middleware (auth + role/permission)
  → Form Request: type validation (exists:)
  → controller: resolve INSIDE scope → authorize
  → service: re-check ownership (defence in depth)
  → model/repository: scoped query
  → database: composite FK backstop
```

Checklist:

- [ ] Does the controller resolve through a **scoped** model?
- [ ] Is there an explicit `authorize()` / policy call?
- [ ] Does the service re-check, in case it is called from a command or job?
- [ ] Is every request-controlled id either tenant-validated or derived
      server-side?
- [ ] Are `church_id` / `stage_id` / `class_id` / `role` / approval-status
      fields derived server-side rather than taken from the payload?
- [ ] Does the resource leak anything sensitive in its API Resource?
- [ ] Does the test assert **DB state is unchanged** on rejection, not just
      the status code?

---

## 10. Mass assignment

`$fillable` on `User` includes `church_id`, `role`, `application_status` and
`is_active`. That is a convenience, not a contract — the controller and
service own the decision. Never pass a raw request payload to `create()` or
`update()`; pass `$request->validated()` with the trusted fields set
server-side. A field being in `$fillable` is not permission to accept it.

---

## 11. Offline session isolation (frontend)

The offline write queue lives in **IndexedDB**, so it survives page reloads
and therefore survives session boundaries. The rule:

> The queue belongs to the session that created it.

`AuthContext.login()`, `platformLogin()`, `logout()` and the 401 interceptor
all call `clearAllData()`. A session can be replaced *without* any of those
running — an expired token discarded before a request, cleared localStorage,
or a different user on a shared device — which is why login clears as well as
logout.

The offline queue is **not** a security boundary. Items store the bearer token
that created them and replay it, so a queue that survived a session change
would let the next tenant replay the previous one's writes. It is cleared
rather than trusted.

The in-memory API response cache (`src/lib/requestCache.ts`) is keyed by a
hash of the bearer token, so entries are session-scoped by construction, and
`invalidateCache()` with no argument clears everything on any session change.

The **service worker must never cache `/api/` responses.** It intercepts
before the app's own scoping logic, so a cached API response would be served
across tenants. `src/test/pwaIsolation.test.ts` guards the configuration
**and the generated `dist/sw.js`**, because a source-text check cannot see what
workbox actually emitted. CI builds before it tests, so the artifact
assertions always execute.

### The replay loop must not outlive its session

`AuthContext` clears the IndexedDB queue on login, logout and 401, but it does
so with a fire-and-forget `clearAllData()`, while `trySyncAll()` iterates an
in-memory snapshot taken before the wipe. A session switch landing mid-replay
therefore used to keep the loop running and transmit the **previous tenant's**
queued writes, signed with that tenant's bearer token, after the switch — and
`markSyncCompleted` then no-opped because the row was already gone, so the write
happened and the queue lost the record of it.

`trySyncAll()` re-reads the active token before every send and aborts the run if
it changed. An aborted run is **not** counted as a failure: the items are
untouched and the run is simply void.

A 4xx is also **terminal** in that loop. The backend answers a duplicate
attendance with 422 precisely so the client stops trying; retrying it five
times cannot succeed and only stalls every item queued behind it through the
backoff.

---

## 12. Queue semantics

The Laravel **database** queue is used. RabbitMQ is not installed, and adding
it is out of scope until the architecture changes.

`SendEmailJob` is `$tries = 3`, `$backoff = 10`, with a 30-minute
`retryUntil`. Delivery is therefore **at-least-once**: a worker that dies
after the SMTP call but before the job is marked done will re-send. For
`SystemMail` (a notification to a human) a duplicate is cosmetic and is
accepted rather than engineered around.

If a *transactional* email is ever added, give it a deterministic business
key and a unique constraint. Do not add a general outbox or idempotency
framework for one cosmetic job.

---

## 12a. Legacy `class_years` — current status

`class_years` is **still present and still referenced**. It was not removed,
and removing it is a data decision, not a code cleanup.

| Column | Foreign key points at | Meaning |
|---|---|---|
| `users.class_year_id` | `class_years(id)` | legacy; `prohibited` on input |
| `attendances.class_year_id` | `classes(id)` | legacy column NAME, classes id space |
| `events.class_year_id` | `classes(id)` | same |
| `qr_invites.class_year_id` | `classes(id)` | same |
| `feedback.class_year_id` | `classes(id)` | same |

So exactly **one** `class_year_id` in the schema is still in the `class_years`
id space, and that asymmetry has already produced two shipped bugs (§4). It is
the reason the field is `prohibited` on input, the reason `EventPolicy`
compares `class_id` on both sides, and the reason `AttendanceService` writes
`$member->class_id`.

**Do not drop the column or the table as a cleanup.** The historical
`class_years` rows carry the only record of which class a legacy user belonged
to before the 2026-06 migration, and that mapping is what
`2026_06_22_000001` used to backfill the `classes` references. Deleting the
table would make the backfill unreproducible and unreviewable.

The safe sequence, if the business decides to retire it, is:

1. backfill any remaining `users.class_year_id` from `class_years` into
   `users.class_id`, matching on `(church_id, name)` the way the earlier
   migration did;
2. report every row that does not match — **do not guess** a class for those;
3. only then drop the foreign key, and consider the table itself a separate,
   later, archival decision.

---

## 13. Migration safety

- Migrations are verified on **PostgreSQL**, the production driver. SQLite
  is the test driver and accepts syntax PostgreSQL rejects — `IS NOT
  (subquery)` parses on SQLite and is a syntax error on PostgreSQL, which
  aborted a migration on the production driver while every SQLite test
  passed.
- Never repair data implicitly in a migration. Fail loudly and provide a
  read-only audit command with an explicit `--repair` flag.
- A `down()` must drop every index it depends on **before** dropping the
  column. SQLite refuses otherwise and PostgreSQL silently succeeds, so the
  bug is invisible until a rollback is attempted on SQLite.
  `php scripts/scan-broken-migration-rollbacks.php` lints for this, and
  `tests/Feature/MigrationRollbackTest.php` is the authoritative check.

---

## 14. Verification commands

```bash
# Backend
php artisan test
vendor/bin/phpstan analyse --level=max
vendor/bin/pint --test
php scripts/scan-broken-migration-rollbacks.php

# Frontend
npx tsc --noEmit
npm run lint
npm run check:i18n
npm test
npm run build

# Database — against PostgreSQL, not SQLite
php artisan migrate:fresh
php artisan tenant:audit
```
