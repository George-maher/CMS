# PHASE 1C — RISK REGISTER

Risks remaining AFTER Phase 1C remediation, ordered by severity. Every entry states why it is
accepted (or deferred) — nothing here is a hidden blocker.

---

## RR-01 — Frontend does not consume `retry_after` on 429

| Field | Value |
|---|---|
| Severity | **P3** (UX / efficiency, not correctness or security) |
| Component | `frontend/src/api/client.ts:314` |
| Status | **DOCUMENTED — deferred** |

**Description.** The P0 fix now returns `retry_after` in the 429 body (plus `Retry-After` header).
The frontend's **live** 429 handler retries with a **fixed 2 s** delay, up to 3 attempts, then
rejects. (The *offline replay* path — where ignoring `Retry-After` actually lost data — was fixed in
R-08: it now honors the header, capped at 60 s. This risk is therefore live-path only, tracked as
R-18.)

**Why not fixed.** The existing behaviour is already safe: bounded retries (max 3), no infinite
loop, no retry storm, 429 treated distinctly from 5xx. Using the server value would improve
efficiency only. Changing client retry policy is an enhancement outside the remediation mandate.

**Residual risk.** If the server asks for a 60 s wait, the client may burn its 3 retries within 6 s
and surface the error early. User sees "too many requests" sooner than optimal — harmless.

---

## RR-02 — Rate-limit counters depend on the configured cache store

| Field | Value |
|---|---|
| Severity | **P3** (operational configuration) |
| Component | `backend/app/Providers/AppServiceProvider.php` limiters; `CACHE_STORE` env |
| Status | **DOCUMENTED** |

**Description.** Throttle counters live in the cache. Production `.env` uses `CACHE_STORE=file`.
If an operator switches to `array` (or a per-instance store) behind more than one PHP worker, each
worker gets its **own** counter and the effective limit multiplies by worker count.

**Why not fixed.** Configuration is correct today (`.env` = `file`), and the tests now prove the
behaviour on BOTH `array` and `file` (R-03). Enforcing a specific store in code would remove
legitimate deployment flexibility.

**Mitigation.** Documented operational rule: production must use a **shared** cache store
(`file` on a single node; `redis`/`memcached` when scaled horizontally). Add to the pre-production
release checklist.

---

## RR-03 — Single-node `file` cache assumption

| Field | Value |
|---|---|
| Severity | **P3** (scaling) |
| Component | cache/session/queue drivers (`backend/.env`) |
| Status | **DOCUMENTED — by design** |

**Description.** `.env` runs `CACHE_STORE=file`, `SESSION_DRIVER` file-based, `QUEUE_CONNECTION=
database`. These are all **single-node** correct. Horizontal scaling of web containers would need
redis (cache/session) + a dedicated queue worker.

**Why not fixed.** Infrastructure/driver changes are explicitly out of scope (§1, no deployment).
Current stack is internally consistent and tested as-is.

---

## RR-04 — Email delivery is configured to `log` in local `.env`

| Field | Value |
|---|---|
| Severity | **P3** (environment-specific, not code) |
| Component | `backend/.env` → `MAIL_MAILER=log`; `.env.example` → `resend`; `.env.docker` → `log` |
| Status | **DOCUMENTED** |

**Description.** The mail pipeline exists and is queued (`SendEmailJob`: `tries=3`,
`backoff=10s`, `retryUntil` 30 min, `failed()` logs permanent failure, jobs table migration
present), but Phase 1C's review confirmed the pathway is currently **dead code** — the
`EmailServiceInterface` binding has zero callers (R-20), consistent with the project rule that
email sending is not implemented. The local `.env` logs mail instead of sending it, and
`.env.example` still advertises the removed `resend` transport (R-13).

**Why not fixed.** Changing mail credentials/driver is a deployment + secret concern (forbidden:
no hardcoded secrets, no production changes), and removing the dead pathway is refactoring outside
the mandate. Pre-production environment must set a real `MAIL_MAILER` + credentials **when email
sending is (re)implemented**, updating `.env.example` in the same change.

**Note.** Email is *not* the primary notification channel — in-app notifications are, per the
project rule that Resend was removed. Password-reset flows still function (token returned via the
documented flow; job recorded in log/queue).

---

## RR-05 — Historical migrations with SQLite-only `down()` (5 files)

| Field | Value |
|---|---|
| Severity | **P3** (test-driver only) |
| Component | 5 historical migrations (see R-04 in remediation log) |
| Status | **NOT-FIXED-BY-DESIGN** |

**Description.** 5 `down()` methods drop a column while an index still covers it — fails on SQLite,
succeeds on PostgreSQL (production driver) which drops dependent indexes automatically.

**Verification.** `migrate:fresh` on PG 16.15: 108/108 OK. `MigrationRollbackTest` passes on both
engines. `scan-broken-migration-rollbacks.php` reports them (exit 0, heuristic lint by design —
R-05).

**Mitigation.** Operational rule unchanged: never roll back production past the documented safe
boundary; use backup/restore when a rollback is genuinely required.

---

## RR-06 — Rollback lint cannot fail CI

| Field | Value |
|---|---|
| Severity | **INFO** |
| Component | `backend/scripts/scan-broken-migration-rollbacks.php` |
| Status | **DOCUMENTED** |

A reporting-only gate (exit 0 always). Turning it into a blocking gate is an infrastructure change
outside the mandate, and it would immediately fail on the 5 accepted findings above. Authoritative
check remains `MigrationRollbackTest`. Recommended for the next CI-hardening phase.

---

## RR-07 — `removeServant` has no explicit pre-check (cosmetic asymmetry)

| Field | Value |
|---|---|
| Severity | **P3** (cosmetic) |
| Component | `backend/app/Services/ClasseService.php::removeServant` |
| Status | **DOCUMENTED** (full analysis in R-06 of the remediation log) |

Detaching a non-existent/foreign servant is a **no-op** (class is ChurchScope-blocked; cross-church
pivot row cannot exist). Worst case: a 200 message reporting a detach that changed nothing. Adding a
guard would alter response behaviour without fixing a real defect. Deferred as an API-polish item.

---

## RR-08 — Catch-all 500 renderer leaks detail when `APP_DEBUG=true` AND `app()->isLocal()`

| Field | Value |
|---|---|
| Severity | **INFO** (double-gated by design) |
| Component | `backend/bootstrap/app.php` catch-all renderer |
| Status | **NOT-FIXED-BY-DESIGN** |

Message/file/line/trace are emitted only when `config('app.debug') && app()->isLocal()` — both must
be true. On any non-local environment with debug off (the pre-production/production norm) the body
is the safe `{success:false, message:'Internal server error.', code:'INTERNAL_ERROR', request_id}`.
Standard Laravel development ergonomics; releasing it would harm debugging for no security gain in
real deployments. **Pre-production checklist: `APP_DEBUG=false`, `APP_ENV=production`.**

---

## RR-09 — Test suite skipped-tests parity between engines

| Field | Value |
|---|---|
| Severity | **INFO** |
| Component | backend test suite |
| Status | **RESOLVED-AS-OBSERVED** |

SQLite run skips 6 pgsql-specific tests (by design); PostgreSQL run skips **0** — all 566 execute.
No test is broken on either engine. Recorded so the "6 skipped" figure in the SQLite run is never
misread as a failure.

---

## RR-10 — Error envelope is not uniform across all API failures

| Field | Value |
|---|---|
| Severity | **INFO** (contract consistency; correct status codes everywhere) |
| Component | `PermissionMiddleware` (`{message}` only), `EnsureApproval` (`{message, code}`, no `success`) |
| Status | **DOCUMENTED** (R-16) |

The typed render callbacks and the catch-all emit `{success, message, code}`; the two middleware
above predate that contract. No frontend logic depends on the missing keys (clients switch on HTTP
status + `message`), so this is cosmetic consistency. Normalizing response bodies across endpoints
is a contract change requiring its own pass with tests — deferred deliberately, not overlooked.

---

## RR-11 — `request_id` missing from typed 4xx bodies and unknown-route 404

| Field | Value |
|---|---|
| Severity | **INFO** (observability; server-side correlation unaffected) |
| Component | `backend/bootstrap/app.php` typed render callbacks; `AssignRequestId` |
| Status | **DOCUMENTED** (R-17) |

`request_id` is echoed in headers on every response and in every log line via
`Log::withContext`, so support correlation works today for all requests; only the *body* of typed
4xx responses (and the unknown-route 404) lacks it. Adding the key is an additive contract change
to apply consistently across middleware + callbacks + tests — deferred to a contract-hardening pass.

---

## RR-12 — Unrouted permission-update methods + `role_permission` has no `church_id`

| Field | Value |
|---|---|
| Severity | **P3 (latent)** |
| Component | `UserController::updatePermissions` (:518), `bulkUpdatePermissions` (:544); `role_permission` table |
| Status | **DOCUMENTED** (R-14) |

The methods exist but **no route references them** — currently unreachable, therefore not
exploitable. The pivot is role-name keyed by design (roles are application-wide enums), which is
consistent with `Permission::defaultRolePermissions()` rather than a missing tenant column.

**Trigger condition:** if these endpoints are ever routed, church scoping + a policy must land
**with** the route in the same change. Deleting the dead methods is refactoring for a future
cleanup pass.

---

## RR-13 — Missing pending/disabled states on three submit buttons

| Field | Value |
|---|---|
| Severity | **P3** (UX; server-side guards authoritative) |
| Component | three frontend submit flows flagged by review (C-1/C-2/C-3) |
| Status | **DOCUMENTED** (R-15) |

A fast double-click can issue two requests on the flagged flows. Duplicate protection is enforced
server-side (attendance duplicate guard → 422, idempotent business rules), and the one flow with a
confirmed duplicate-write pressure path — the ScanQR confirm button — was fixed in R-09 (state
resets after a queued write, pinned by test). Adding pending states to the remaining pages needs
page-by-page component tests; deferred as UX polish.

---

## RR-14 — Rooms historically resized under the broken code may carry stale cell inventories

| Field | Value |
|---|---|
| Severity | **P3** (pre-existing data condition; code fixed in R-11) |
| Component | `event_room_cells` rows for rooms resized via PUT/PATCH before the R-11 fix |
| Status | **DOCUMENTED** |

Before R-11, capacity changes updated the `rooms` columns without syncing cells — so production
data may contain rooms whose `capacity`/`member_capacity` disagree with their cell rows (typically
under-sized grids after increases). The fixed code does **not** retroactively rewrite historical
rows.

**Mitigation.** The code self-heals on the *next* increase (consecutive renumbering from the
current cell count); decreases only remove unoccupied cells above the new capacity and never touch
occupied assignments. A one-off data audit (`rooms.capacity` vs `COUNT(cells)`) can list affected
rooms pre-production; remediation would be a data fix, which is out of scope for 1C (no DB changes).

---

## Risk Summary Table

| ID | Severity | Area | Status |
|---|---|---|---|
| RR-01 | P3 | Live-path 429 `Retry-After` unused (offline path fixed, R-08) | DOCUMENTED (deferred enhancement, R-18) |
| RR-02 | P3 | Rate-limit counter store dependency | DOCUMENTED (config rule) |
| RR-03 | P3 | Single-node cache/session/queue drivers | DOCUMENTED (by design) |
| RR-04 | P3 | Local mail driver = `log`; email pathway currently dead code | DOCUMENTED (env must set real mailer when email returns; R-13, R-20) |
| RR-05 | P3 | 5 historical SQLite-only rollbacks | NOT-FIXED-BY-DESIGN |
| RR-06 | INFO | Rollback lint non-blocking | DOCUMENTED (CI phase) |
| RR-07 | P3 | `removeServant` cosmetic asymmetry | DOCUMENTED |
| RR-08 | INFO | Debug detail double-gated | NOT-FIXED-BY-DESIGN |
| RR-09 | INFO | 6 engine-specific skips (566 execute on PG) | Resolved-as-observed |
| RR-10 | INFO | Error envelope not uniform | DOCUMENTED (R-16) |
| RR-11 | INFO | `request_id` absent from typed 4xx bodies | DOCUMENTED (R-17) |
| RR-12 | P3 (latent) | Unrouted permission methods; pivot has no `church_id` | DOCUMENTED (R-14) |
| RR-13 | P3 | Missing button pending states (3 flows) | DOCUMENTED (R-15) |
| RR-14 | P3 | Stale room cell inventories from pre-fix resizes | DOCUMENTED (data audit pre-production) |

**No P0 or P1 risks remain open.** All P0/P1 items were fixed with fail-first tests: R-01 (rate
limit 500s), R-07/R-11 (contract + room-resize 4xx/behaviour defects), R-08 (offline write loss),
R-09 (duplicate-submit pressure), R-10 (notification payload encryption, latent), R-03 (test
isolation) — or verified already-resolved (R-02, R-12). Every residual risk is P3 or INFO,
environment-dependent, or explicitly accepted by design — none blocks a pre-production release
**review** (note: release *review*, not production deployment, which remains out of scope for this
phase).
