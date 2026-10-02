# PHASE 1D — POST-DEPLOY CHECKLIST

Production verification procedure for the first production verification cycle.
**Nothing here has been executed.** Every box starts UNCHECKED and is only marked by an
operator with authorised production access after deployment.

Status vocabulary: `PASS` / `FAIL` / `PENDING (post-deployment)`.
All ten gates begin as `PENDING (post-deployment)` — this is not a Phase 1D failure
(mandate §27).

---

## Gate A — Deployment Identity

- [ ] Deployed commit SHA recorded: `________________` (compare to `<RELEASE_SHA>`)
- [ ] `git rev-parse HEAD` on the deployment equals `<RELEASE_SHA>`
- [ ] Expected branch/tag checked out (`main` @ release tag)
- [ ] No unexpected code: `git status` on the deployment is clean; build log shows the
      expected `composer install --no-dev --prefer-dist --optimize-autoloader`
- [ ] Frontend build shows the same SHA/version and `PWA v1.3.0 / precache 138 entries`

```text
Status: PENDING (post-deployment)
```

## Gate B — PostgreSQL

- [ ] PostgreSQL version recorded (target: 16.x, verified pre-deploy on 16.15)
- [ ] Connection healthy (`DB_HOST`/`DB_PORT`/`DB_SSLMODE` as configured)
- [ ] `php artisan migrate:status` → **108 Ran / 0 Pending**
- [ ] `php artisan migrate --force` completed without error (or was already applied)

```text
Status: PENDING (post-deployment)
```

## Gate C — Tenant Schema

```bash
php artisan tenant:verify-schema
```

Expected (as proven on the disposable PostgreSQL 16.15 release tree):

```text
stages.stages_church_id_id_unique              OK
classes.classes_church_id_id_unique            OK
classes_church_stage_fk                        OK
users_church_stage_fk                          OK
users_church_class_fk                          OK
events_church_class_fk                         OK
event_targets_church_class_fk                  OK
qr_invites_church_stage_fk                     OK
qr_invites_church_class_fk                     OK
All tenant isolation invariants are enforced by the database.   → exit 0 / PASS
```

Notes for the operator:

* this command is **safe**: it wraps its behavioural write probes in a transaction and
  always rolls back (`DB::beginTransaction()` … `DB::rollBack()` in `finally`);
* if it reports `NOT VALID` constraints, run the printed
  `ALTER TABLE … VALIDATE CONSTRAINT …` statements and re-run;
* if the behavioural proof reports `SKIPPED`, the database has fewer than two
  churches/stages/classes — record that the proof did not execute.

```text
Status: PENDING (post-deployment)     Expected: PASS
```

## Gate D — Tenant Data

```bash
php artisan tenant:audit        # NEVER with --repair in this gate
```

Expected:

```text
No inconsistencies found. The composite tenant foreign keys can be applied safely.  → exit 0 / PASS
```

**Interpretation rule:** production data is *production evidence*, independent of the
disposable-DB result. A disposable PASS does not certify production rows.
If inconsistencies are reported: **do not run `--repair` automatically** — a repair
re-homes tenants and must be reviewed (the command itself warns that repair
"silently re-homes tenants").

```text
Status: PENDING (post-deployment)     Expected: PASS
```

## Gate E — Backup

- [ ] Latest backup timestamp: `________________`
- [ ] Backup destination recorded: `________________`
- [ ] Retention policy confirmed: `________________`
- [ ] Backup is **newer than the last schema change** and was taken **before** deploy

```text
Status: PENDING (post-deployment)
```

## Gate F — Restore

- [ ] Restore drill performed **only according to the approved production procedure**
      (see `PHASE_1_BACKUP_RESTORE_DRILL.md` for the method proven in Phase 1)
- [ ] Restored instance verified: row counts + `tenant:verify-schema` on the restore

```text
Status: PENDING (post-deployment) — not executed unless the approved drill is authorised
```

## Gate G — Worker

- [ ] Queue worker running (`QUEUE_CONNECTION=database` expected)
- [ ] `jobs` table draining (depth decreasing)
- [ ] `failed_jobs` count: `________` and **not growing**
- [ ] Any failure inspected for `ApiKeyIsMissing` (⇒ mailer/key mismatch, see Gate I)

```text
Status: PENDING (post-deployment)
```

## Gate H — Scheduler

- [ ] Scheduler active (`schedule:run` every minute)
- [ ] Expected scheduled tasks present and last-run timestamps current:
  * `app:clean-expired-invites --days=7` — daily 03:00, `withoutOverlapping`
  * `app:clean-audit-logs --days=90 --force` — `withoutOverlapping`
- [ ] No overlapping/failed scheduler entries

```text
Status: PENDING (post-deployment)
```

## Gate I — Email

- [ ] Production mailer identified: `log` / `resend` / other: `________`
- [ ] If `resend`: `RESEND_API_KEY` (or `services.resend.key`) present — **never print it**
- [ ] Sender/domain authenticated at the provider
- [ ] Safe test delivery performed (single message to an internal address)
- [ ] Queued notifications drain; `failed_jobs` shows no mail exceptions
- [ ] If the mailer is `log`: record explicitly that **delivery is intentionally off**
      and that the 5 notification call sites write to the log instead

```text
Status: PENDING (post-deployment)
```

## Gate J — Application

- [ ] Health endpoint returns 200
- [ ] Login works for: Church Admin / Stage Admin / Servant / Member / Platform Admin
- [ ] Authorization spot-checks (from the Phase 1D matrix):
  * Church Admin **cannot** see another church
  * Stage Admin **cannot** reach another stage
  * Member sees self only
  * Platform Admin is explicitly separate (12 platform routes)
- [ ] Tenant isolation spot-check: a cross-church identifier is rejected
- [ ] QR flows: invite accept + attendance scan work; invite tokens are single-use/expiring
- [ ] Attendance: record → duplicate-prevention → points increment
- [ ] Error handling: a throttled request returns **429** with `Retry-After` (never 500);
      a 404/403 on `api/*` returns JSON
- [ ] `request_id` present in responses and logs
- [ ] Error rate (5xx) at or below pre-deploy baseline

```text
Status: PENDING (post-deployment)
```

---

## SUMMARY

| Gate | Name | Pre-deploy provable? | Status |
|------|------|----------------------|--------|
| A | Deployment identity | No — deployment must exist | PENDING (post-deployment) |
| B | PostgreSQL | Partial (disposable 16.15 verified) | PENDING (post-deployment) |
| C | Tenant schema | Disposable PASS | PENDING (post-deployment) |
| D | Tenant data | Disposable PASS (production is separate evidence) | PENDING (post-deployment) |
| E | Backup | No | PENDING (post-deployment) |
| F | Restore | No | PENDING (post-deployment) |
| G | Worker | No | PENDING (post-deployment) |
| H | Scheduler | No | PENDING (post-deployment) |
| I | Email | No (production config unknown) | PENDING (post-deployment) |
| J | Application | Partial (649 automated tests green) | PENDING (post-deployment) |

**0 of 10 gates are marked PASS, because none may be marked before deployment.**
This is the expected state of a pre-production review, not a defect.
