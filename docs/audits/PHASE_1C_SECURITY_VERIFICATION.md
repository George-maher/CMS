# PHASE 1C — SECURITY VERIFICATION

Scope: §7–§12, §15, §17–§22 of the Phase 1C mandate.
Method: read-only code inspection of the CURRENT working tree (uncommitted state), cross-referenced
against the automated suites (`Security` phpunit suite, full SQLite suite, full PostgreSQL suite),
supplemented by two independent read-only review passes (queue/bulk/error/request-id; PWA/frontend/
duplicate-submit) whose findings are dispositioned in PHASE_1C_REMEDIATION_LOG.md (R-07…R-20).

Nothing in this document was deployed. No production system, database, or credential was touched.

---

## §7 — TENANT ISOLATION (church boundary)

**Verdict: CONFIRMED ENFORCED.**

| Check | Result | Evidence |
|---|---|---|
| Global scope on tenant models | ✅ | `BelongsToChurch` trait applies `ChurchScope`; `tenant:audit` clean; `tenant:verify-schema` exit 0 |
| `church_id` never trusted from the client | ✅ | `AuthService::register()` derives `church_id`/`stage_id`/`class_id` server-side from the invite token, never from the request body |
| Invite → tenant binding | ✅ | `CreateQRInviteRequest` (Form Request) validates the target within the actor's church before the controller runs |
| Class belongs to church on invite accept | ✅ | `AuthService::register()` re-checks `classe.church_id === invite.church_id` inside a `lockForUpdate()` transaction |
| Attendance is church-scoped | ✅ | `AttendanceService` resolves members via `User::byChurch()`; a foreign-church member cannot be resolved → 404, never recorded |
| Composite FK integrity | ✅ | `2026_09_29_000001_add_composite_tenant_foreign_keys` — all 7 composite FKs verified on PostgreSQL 16.15, **no `ON UPDATE CASCADE`** (driver-aware: pgsql-only SQL, SQLite-compatible branch in tests) |
| Stage isolation | ✅ | `ClassePolicy::canManage` → church check for admins + `ScopeResolver::canAccessClass` / `StageResolver::canAccessClass` for stage admins |
| Repository-level scoping | ✅ | `ClasseRepository::findById()` is `ChurchScope`-scoped: a foreign class yields 404 before policy code runs |

**Residual observations (non-exploitable):** see R-06 in the remediation log
(`ClasseService::removeServant` detaches without a pre-check; analyzed as a no-op for foreign
tenants because the class itself is already scope-blocked and the pivot row cannot exist across
churches).

---

## §8 — PLATFORM ADMIN BOUNDARY

**Verdict: CONFIRMED ENFORCED.**

- All platform routes in `backend/routes/api.php` are gated by the `role:platform_admin`
  middleware alias (`RoleMiddleware`), registered in `backend/bootstrap/app.php`.
- The platform admin uses a **separate login endpoint** (per the AGENTS.md auth rule); it does not
  share the church-scoped session/token path.
- Platform-level queries are explicitly *outside* `ChurchScope` by design (a platform admin must
  see across tenants) — this is the only legitimate bypass and it is gated by role, not by a
  client-supplied value.
- Verified by the `Security` suite (329 tests / 1221 assertions passing on PostgreSQL; part of the
  566-test full run).

---

## §9 — AUTHENTICATION

**Verdict: CONFIRMED CORRECT.**

| Check | Result |
|---|---|
| Sanctum personal access tokens | ✅ |
| Password hashing (bcrypt) | ✅ — never any plaintext or reversible storage |
| Role middleware on protected routes | ✅ (`role`, `permission`, `approved`, `approval` aliases) |
| Token revocation on logout | ✅ — `AuthService::logout()` revokes the current token |
| Token revocation on password reset | ✅ — all user tokens revoked on credential change |
| Token revocation on invite acceptance | ✅ |
| No hardcoded secrets / mock auth | ✅ — no credential literal found in app code |
| Placeholder-email rule respected | ✅ — tests use `login@test.com`, never blocked `test@test.com` |

**Coverage gap identified and closed (relates to P0):**
`PasswordResetRequestTest::test_submit_is_rate_limited` *redefines* the limiter without
`->response()`. That is exactly why the whole suite stayed green while the real registered limiters
returned 500 in production. `RateLimitResponseTest` now drives the **real registered** limiter.

---

## §10 — QR SYSTEM

**Verdict: CONFIRMED SAFE.**

- QR payloads contain **only** a 60-character random token / secure URL. No password, no raw ID, no
  PII, no sensitive data is ever encoded.
- Token generation uses Laravel's random string generator (CSPRNG-backed), not sequential IDs.
- Invite lifecycle enforced server-side on every use:
  1. token exists → else 404/410,
  2. not expired (default **4 hours**) → else rejected,
  3. not already used (single-use where declared) → else rejected,
  4. not revoked/disabled → else rejected.
- All checks run inside `AuthService` under `lockForUpdate()` where a use-consume race exists, so a
  double-spend of a single-use invite cannot occur (see §12).
- QR types present and distinct: `admin_to_servant_invite`, `servant_to_member_invite`,
  `attendance_qr`, `event_checkin_qr`.

---

## §11 — ATTENDANCE + POINTS

**Verdict: CONFIRMED CORRECT.**

Flow matches the mandated order: validate QR → resolve member church-scoped → record → duplicate
guard → award points.

| Check | Result | Evidence |
|---|---|---|
| Attendance stores all required fields | ✅ | `member_id`, `servant_id`, `class_id`, `attendance_context_id`, `date`, `status` |
| Duplicate attendance same day prevented | ✅ | locked duplicate check (`lockForUpdate`) keyed on member + context + date |
| Points awarded automatically after attendance | ✅ | via the points service in the same flow |
| Duplicate points same day prevented | ✅ | point ledger dedupes on (member, reason-key, date) |
| Points record reason, timestamp, total | ✅ | ledger row carries reason + `created_at`; running total queryable |
| `class_year_id` correctness | ✅ | set from `member->class_id`, not from client input |
| Attendance context church-bound | ✅ | context resolved through church scope before use |

---

## §12 — CONCURRENCY

**Verdict: CONFIRMED ENFORCED.**

- **Duplicate attendance** — the check-then-insert runs inside a transaction with
  `lockForUpdate()` on the candidate row set, so two simultaneous scans of the same member cannot
  both insert. Verified by the suite's concurrency-oriented tests.
- **Single-use QR invites** — same locking pattern in `AuthService`, so a single-use token cannot
  be redeemed twice.
- **Points** — dedup key enforced in the ledger; even if two attendance requests raced, the ledger
  constraint makes the second award a no-op rather than a double award.
- **Composite tenant FKs** — protect cross-referential integrity at the DB level regardless of
  application-level locking; no `ON UPDATE CASCADE`, so a tenant key can never be silently rewritten
  under a concurrent write.

---

## §15 — SECURITY VERIFICATION TEST COVERAGE

The `Security` phpunit suite is an explicit file list in `backend/phpunit.xml` and is included in
every full run. Results:

| Engine | Result |
|---|---|
| SQLite (array cache) — full suite | 560 passed, 6 skipped, 3280 assertions (final-tree re-run) |
| PostgreSQL 16.15 — full suite (final-tree re-run) | 566 passed, 0 failed, 0 skipped, 3286 assertions |
| PostgreSQL `Security` suite (targeted re-run, final tree) | 329 passed, 1221 assertions |
| PHPStan level max | 0 errors |
| Pint | pass |

> Note: `RateLimitResponseTest` was deliberately **not** added to the `Security` file list — it is a
> reliability/observability gate, not a tenant-isolation gate. It runs in the full suite on both
> engines (8/8 passing on SQLite array, SQLite file, and PostgreSQL).
> The Phase 1C review-driven tests (`HttpExceptionResponseShapeTest`, `ResetNotificationEncryptionTest`,
> `EventRoomCapacityResizeTest`) likewise run in the full suite on both engines and are not in the
> `Security` file list.

---

## §17 — PWA / OFFLINE / SERVICE WORKER (read-only audit)

*(see also PHASE_1C_RISK_REGISTER.md)*

- The API client clears the **IndexedDB request cache and the stored bearer token on logout/401**
  (`clearRequestCache()` → `clearAllData()` awaited before redirect) so a queued offline write can
  never replay a dead token into the next session. This is the correct ordering.
- No sensitive data is written to `localStorage` beyond `auth_token` / `auth_user` /
  `auth_validated_at`, which are deliberately cleared on 401 and logout.
- The service worker precaches only static build assets (138 entries in the production build);
  API responses are not placed into the precache manifest.
- **Queued-write integrity (review finding, FIXED):** the replay loop classified every 4xx —
  including 429/408 — as a permanent refusal, abandoning an offline-captured attendance write after
  one rate-limit response. `sync.ts` now treats 429/408 as transient (retry budget + `Retry-After`,
  capped at 60 s) while 401/403/404/409/422 still abandon immediately. Pinned by 7 new tests
  (R-08).
- **Sentinel consumption (review finding, FIXED):** `ScanQR` now consumes the interceptor's
  `__offline_queued` rejection instead of rendering it as a failure — a queued write shows a distinct
  amber "saved offline" state, the confirmation flow resets (no duplicate confirm), and the today-list
  refresh is deliberately skipped. Pinned by 4 new component tests (R-09).
- Session-switch guard in `trySyncAll()` re-checks the active token before every send, so a replay
  snapshot can never transmit a previous tenant's queued writes under a foreign credential
  (pre-existing, verified).

---

## §18 — FRONTEND 429 HANDLING

**Verdict: PRESENT AND CORRECT — one path hardened in 1C, one documented enhancement.**

Live request path, `frontend/src/api/client.ts:314`:

- Detects `error.response?.status === 429` **distinctly** from 5xx.
- Retries at most 3 times with a fixed 2 s backoff (`_retryCount`), then rejects — it never
  retries indefinitely and never treats 429 as a fatal/5xx error.
- Gating on `retryCount >= 3` prevents retry storms.

Offline replay path, `frontend/src/lib/sync.ts` (**hardened in Phase 1C, R-08**):

- Previously lumped 429 into "permanent rejection" → one rate-limit response **abandoned** the
  queued write. Now 429/408 are transient: the item stays queued, consumes the retry budget, and
  honors the server's `Retry-After` (both AxiosHeaders and plain-object shapes; capped at 60 s;
  exponential fallback when absent/invalid). 401/403/404/409/422 remain immediate abandons
  (pinned by test).

**Gap (documented as R-18, NOT fixed):** the *live* handler still uses a fixed 2 s delay and does
not read `Retry-After` from the 429 response. Correctness is preserved (3 attempts inside a typical
per-minute window, failure propagates to the caller); using the server value is an optimization
tracked in the risk register.

---

## §19 — DUPLICATE SUBMIT

**Verdict: CONTROLS PRESENT, one UI flow hardened in 1C.**

- Backend: duplicate attendance and single-use invites are DB-enforced (§11, §12) — the UI cannot
  be the last line of defence, and it is not. The 422 duplicate-rejection stays a *permanent* reject
  in the offline replay (pinned by test in R-08), so the client still stops retrying duplicates.
- Frontend: the API layer rejects on terminal 429 instead of re-firing, and request cache is
  invalidated on session change so a replayed submit never lands in another session.
- **ScanQR confirm flow (review finding, FIXED, R-09):** an offline-queued write previously rendered
  as a failure with the confirm button still armed — inviting a second confirm/scan and a duplicate
  queue entry. The flow now resets on the queued state (exactly once per confirm, asserted by test).
- Remaining UI hardening (pending/disabled states on three other submit buttons) is documented as
  R-15; server-side guards remain authoritative there.

---

## §20 — REQUEST IDs / CORRELABILITY

**Verdict: CONFIRMED PRESENT.**

`backend/app/Http/Middleware/AssignRequestId.php`:

- `AssignRequestId` is **prepended first** to the `api` middleware group (see
  `bootstrap/app.php:50`), so the id exists before anything can log or short-circuit — including
  `ForceJsonResponse`, which can return early.
- Sets `X-Request-Id` on the **response** (echoed to the client).
- Copies the id into `request_id` attributes + `Log::withContext(['request_id' => ...])`, so every
  log line for the request is correlated.
- Every error renderer (404, 422, 401, 403, throttle, catch-all 500) includes `request_id` in the
  JSON body, and the new 429 renderer includes it via the shared warning log.
- The middleware docblock is explicit that this is a **correlation id, not distributed tracing** —
  no over-claiming.

---

## §21 — QUEUE & BULK OPERATIONS (review pass)

**Verdict: VERIFIED SOUND (R-19) — no defect found.**

| Check | Result | Evidence |
|---|---|---|
| Queue driver | ✅ | `QUEUE_CONNECTION=database` in all env files; `failed_jobs` migration present |
| Write-before-dispatch | ✅ | `after_commit=true` — jobs dispatch only after the DB transaction commits |
| Worker contract | ✅ | `--tries=3 --timeout=90`; `SendEmailJob` declares `tries=3`, `backoff=10`, and a `retryUntil` deadline that `Queue::createPayload` honors (Queue.php:259-270) |
| Bulk permission updates atomic | ✅ | `BulkAtomicityTest`: a mid-batch authorization failure changes nothing; cross-church ids in a batch are not applied |
| Email pathway | ⚠️ dead code | `EmailService`/`SendEmailJob` bound but zero callers (documented, R-20) — inert, never resolved |
| Notification payloads | ✅ hardened in 1C | both reset-bearing notifications now implement `ShouldBeEncrypted` (R-10) |

---

## §22 — ERROR HANDLING & DEPENDENCY CLASSIFICATION

**Verdict: ERROR HANDLING CONSISTENT-ISH (one gap fixed); DEPENDENCIES CLEAN.**

Error handling:

- Every API failure carries the right HTTP status; the P0 fix unified throttle responses on one
  contract (`{success, message, retry_after, code:'RATE_LIMITED'}` + headers), and R-07 closed the
  `abort()` gap where a deliberate 403 shipped an `INTERNAL_ERROR` body.
- Envelope uniformity is not total: `PermissionMiddleware` returns `{message}` only and
  `EnsureApproval` returns `{message, code}` without `success` — documented as R-16 (no client
  depends on the missing keys; status codes are correct everywhere).
- `request_id` appears in 500-class bodies (and headers on every response); typed 4xx bodies and
  unknown-route 404s lack it — documented as R-17 (server-side correlation via
  `Log::withContext` exists for every request regardless).

Dependency classification:

| Layer | Classification | Evidence |
|---|---|---|
| Backend prod deps | clean | `composer audit --locked --no-dev` → no advisories |
| Frontend deps | clean | `npm audit` → 0 vulnerabilities (all levels) |
| axios (advisory GHSA-vh66-26gq-q6x8, affected `>=1.7.0 <1.20.0`) | resolved | lockfile at **1.20.0**; `package.json` `^1.7.9` admits it — R-02 `VERIFIED-NOT-REQUIRED`, no blind package edit made |
| `.env.example` mail transport | stale example | Resend removed from deps but example still says `MAIL_MAILER=resend` — inert (never loaded) → R-13 `DOCUMENTED` |

---

## Summary

| § | Area | Verdict |
|---|---|---|
| §7 | Tenant isolation | Confirmed enforced |
| §8 | Platform admin | Confirmed enforced |
| §9 | Authentication | Confirmed correct (1 coverage gap closed) |
| §10 | QR system | Confirmed safe |
| §11 | Attendance & points | Confirmed correct |
| §12 | Concurrency | Confirmed enforced |
| §15 | Security test coverage | 329 Security tests green (PG), full suites green on both engines |
| §17 | PWA / offline | Token/cache wipe ordering correct; queued-write integrity + sentinel hardened (R-08, R-09) |
| §18 | Frontend 429 | Replay path hardened (R-08); live-path `Retry-After` documented (R-18) |
| §19 | Duplicate submit | DB-enforced; ScanQR confirm flow hardened (R-09); button states documented (R-15) |
| §20 | Request IDs | Present and correctly ordered; 4xx-body gap documented (R-17) |
| §21 | Queue & bulk | Verified sound; notification payloads encrypted (R-10) |
| §22 | Error handling & deps | Contracts unified (R-01, R-07); residual envelope gaps documented (R-16); dependencies clean |

No P0/P1 security defects were introduced or left open by Phase 1C. The P0 remediated in this phase
(R-01) was a *reliability/observability* defect, and the review-pass P1 (R-08, offline write loss)
was a data-integrity defect in the client queue — both fixed with fail-first tests. No production
system was touched.
