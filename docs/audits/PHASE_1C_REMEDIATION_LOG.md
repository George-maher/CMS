# PHASE 1C — REMEDIATION LOG

Format per §28. Statuses: `FIXED` · `VERIFIED-NOT-REQUIRED` · `DOCUMENTED` · `NOT-FIXED-BY-DESIGN`.

---

## R-01

```text
ID:         R-01
Severity:   P0  (reliability / observability / client-retry amplification)
Component:  backend/bootstrap/app.php  +  backend/app/Providers/AppServiceProvider.php
Finding:    Every one of the 38 named rate limiters returned 500 INTERNAL_ERROR
            instead of 429 Too Many Requests once the limit was exceeded, with no
            Retry-After header, and logged the event as an unhandled exception.
Evidence:   Reproduced by Phase 1C BEFORE any fix, via the real HTTP kernel:
              Tests: 5 failed, 3 passed
              6th POST /api/v1/auth/login  ->  500
              11th POST /api/v1/auth/verify-email -> 500
            storage/logs/laravel.log:
              testing.ERROR: Unhandled API exception
                {"message":"","file":".../ThrottleRequests.php","line":253,...}
            The empty message + that exact file/line identify the exception as
            HttpResponseException (constructed with parent::__construct('')).
Root Cause: Laravel's Handler::render() runs registered render callbacks
            (renderViaCallbacks) BEFORE its own
            ` $e instanceof HttpResponseException => $e->getResponse() ` branch.
            The application registers a catch-all `function (Throwable $e, ...)`
            callback. HttpResponseException IS a Throwable, so the catch-all
            matched first, logged "Unhandled API exception" and returned 500 —
            never reaching the framework branch that returns the wrapped 429.

            NOTE — the Phase 1B stated cause (zero-parameter callback invoked
            with NAMED parameters) is DISPROVED. Laravel invokes the callback
            POSITIONALLY; a probe on PHP 8.2.12 showed positional invocation
            succeeds and only NAMED unknown parameters throw.
Fix:        (1) ROOT CAUSE — registered a dedicated render callback for
                HttpResponseException AHEAD of the catch-all Throwable callback
                that returns $e->getResponse(), restoring the framework's own
                precedence. General: also protects any future abort($response).
            (2) CONTRACT — changed rateLimitResponse() to the signature
                documented by Laravel 12: (Request $request, array $headers),
                forwarded the framework-built $headers into the response
                (Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining,
                X-RateLimit-Reset) and aligned the body with the existing
                ThrottleRequestsException renderer so BOTH throttle paths emit
                one identical contract: {success:false, message, retry_after,
                code:'RATE_LIMITED'}.
            (3) OBSERVABILITY — replaced the erroneous ERROR-level record with a
                warning-level "Rate limit exceeded" carrying request_id, so
                routine throttling no longer fires 5xx alerting, without losing
                the signal that previously existed.
Files Changed:
            backend/bootstrap/app.php            (import + dedicated callback)
            backend/app/Providers/AppServiceProvider.php
                                                 (import, 38 call sites,
                                                  rateLimitResponse(),
                                                  headerInt() helper)
Tests Added/Changed:
            backend/tests/Feature/RateLimitResponseTest.php — NEW, 8 tests:
              - request below the limit reaches the handler (401, not 429)
              - requests up to the configured limit still allowed (limit not weakened)
              - next throttled request returns 429 and not 500
              - JSON body matches the application error structure
              - Retry-After + X-RateLimit-* headers preserved
              - no internal exception details leaked
              - a SECOND named limiter also returns 429 (not login-specific)
              - the 429 is not logged as an unhandled exception
            All tests drive the REAL registered limiter. The pre-existing
            PasswordResetRequestTest::test_submit_is_rate_limited REDEFINES the
            limiter without ->response(), which is exactly why the suite stayed
            green while production 500'd — a coverage gap, now closed.
Verification:
            - Before fix:  5 failed, 3 passed (defect proven)
            - After fix:   8 passed (SQLite / array cache)
                          8 passed (SQLite / FILE cache = production mechanism)
                          8 passed (PostgreSQL 16.15)
            - Full suite at the R-01 stage (before R-07…R-11 tests
              existed): 550 passed, 6 skipped (SQLite)
                          556 passed, 0 skipped (PostgreSQL)
            - Final-tree full suite: 560 / 6 skipped (SQLite),
                          566 / 0 skipped (PostgreSQL 16.15) — see
                          PHASE_1C_TEST_RESULTS.md
            - PHPStan level max: 0 errors · Pint: pass
Regression Risk: LOW.
            - Non-API (web) requests: the catch-all returns null outside
              `api/*`, so control already fell through to the framework branch;
              behaviour there is unchanged.
            - Throttle ENFORCEMENT was never broken and is untouched: limit
              values, `by()` keys and hit accounting are identical, proven by
              "requests up to the configured limit are still allowed".
            - ThrottleRequestsException path (limiters without ->response())
              is a different exception class and is unaffected — its existing
              test still passes.
            - The 429 body gained `success` and `code`. It could not have been
              observed by any client before, because the response was never
              reachable (always 500). No API contract was broken in practice.
Status:     FIXED
```

---

## R-02

```text
ID:         R-02
Severity:   P2 (as assessed by Phase 1B; the individual advisories are Moderate)
Component:  frontend/package-lock.json (axios)
Finding:    Phase 1B reported axios 1.18.1 carrying HIGH advisories
            (GHSA-vh66-26gq-q6x8, GHSA-9fr6-4gfg-395g, GHSA-c29m-xwm3-cm6r,
            GHSA-mghh-pgcx-3jjj, GHSA-x97p-jq2g-jp4f, GHSA-3pq3-5fj3-cg6v,
            GHSA-542g-h47m-68v8), fixed in 1.20.0.
Evidence:   npm ls axios            -> axios@1.20.0
            npm audit               -> found 0 vulnerabilities (all levels)
            npm audit --audit-level=high -> found 0 vulnerabilities
            package-lock.json "node_modules/axios" -> "version": "1.20.0"
            Official advisory GHSA-vh66-26gq-q6x8: affected >=1.7.0 <1.20.0,
            patched 1.20.0.
Root Cause: N/A in Phase 1C — the lockfile in the current working tree already
            carries the patched version. The upgrade was performed during
            earlier (uncommitted) hardening work, before Phase 1C began.
Fix:        NONE REQUIRED in Phase 1C. Verified rather than re-applied, per
            Rule 1 ("if a previous finding no longer exists in the current
            code, do not recreate it"). package.json was left at ^1.7.9, which
            already admits 1.20.0; narrowing it would be an unrelated change.
Files Changed: none
Tests Added/Changed: none
Verification: npm audit clean at every severity level; frontend lint / tsc /
            tests / production build all pass (see PHASE_1C_TEST_RESULTS.md).
Regression Risk: NONE (no change made).
Status:     VERIFIED-NOT-REQUIRED
```

---

## R-03

```text
ID:         R-03
Severity:   P2
Component:  backend/tests/Feature/RateLimitResponseTest.php (test isolation)
Finding:    The new rate-limit tests were order-dependent when run on the
            PRODUCTION cache mechanism (CACHE_STORE=file): the 5-attempt
            assertion failed because counters accumulated across test methods
            and across runs.
Evidence:   Run with CACHE_STORE=file before the fix:
              "Requests up to the configured limit are still allowed" FAILED
            phpunit.xml pins CACHE_STORE=array, whose store is rebuilt per
            application instance and therefore hides this entirely.
Root Cause: Rate-limit counters live in the cache. The array store resets per
            test; the file store persists on disk, so state leaks between test
            methods and between runs.
Fix:        Added setUp() { parent::setUp(); Cache::flush(); } with a comment
            explaining why it exists, so every test in the class is valid on
            BOTH the test store and the production store.
Files Changed: backend/tests/Feature/RateLimitResponseTest.php
Tests Added/Changed: same file, setUp()
Verification: 8 passed with CACHE_STORE=array · 8 passed with CACHE_STORE=file
            · 8 passed on PostgreSQL.
Regression Risk: LOW — confined to the new test class; flushing the cache in
            setUp cannot weaken any product assertion.
Status:     FIXED
```

---

## R-04

```text
ID:         R-04
Severity:   P3
Component:  backend/database/migrations (5 historical migrations)
Finding:    5 historical migrations have a down() that cannot deterministically
            undo up() ON SQLITE (drop a column while an index still covers it):
              2025_01_01_000006_add_attendance_qr_token_to_users.php
              2025_06_01_000001_add_class_year_id_to_events.php
              2025_06_09_000001_add_user_id_to_feedback.php
              2025_06_15_000002_add_context_id_to_attendances.php
              2025_07_09_000001_add_member_id_to_users.php
Evidence:   php scripts/scan-broken-migration-rollbacks.php -> 5 findings
            (script exits 0; reporting gate, not a blocking gate).
Root Cause: SQLite errors when an index still references a dropped column.
            PostgreSQL (the production driver) silently drops dependent indexes
            with the column, so the same down() succeeds there.
Fix:        NONE. Per §16, historical migrations were NOT rewritten: changing
            them alters production rollback semantics without necessity, and
            the defect does not reproduce on the production driver.
Files Changed: none
Tests Added/Changed: none
Verification: migrate:fresh on PostgreSQL 16.15 succeeded (all 108
            migrations); MigrationRollbackTest passes as part of the suite.
Regression Risk: NONE.
Status:     NOT-FIXED-BY-DESIGN
            (documented; operational rule preserved — do not roll back
            production past the documented safe migration boundary, use
            backup/restore when necessary)
```

---

## R-05

```text
ID:         R-05
Severity:   INFO
Component:  backend/scripts/scan-broken-migration-rollbacks.php (CI tooling)
Finding:    The rollback lint reports findings but exits 0, so it can never
            fail CI — a gate that cannot fail is not a gate.
Evidence:   script prints "5 migration(s) with a down() that cannot
            deterministically undo up()" and returns exit code 0.
Root Cause: Deliberate design — the file header documents it as "a HEURISTIC
            lint for code review, not a proof of correctness", with the
            authoritative check being MigrationRollbackTest.
Fix:        NONE. Changing CI gates is an infrastructure change outside the
            remediation mandate (§1), and doing so would fail the build today
            for 5 historical findings that are already classified as
            non-production-affecting (R-04).
Files Changed: none
Tests Added/Changed: none
Verification: observed exit code 0.
Regression Risk: NONE.
Status:     DOCUMENTED
```

---

## R-06

```text
ID:         R-06
Severity:   P3 (observation, no confirmed defect)
Component:  backend/app/Services/ClasseService.php::removeServant
Finding:    removeServant() detaches without first verifying that the servant
            exists or belongs to the actor's church, unlike assignServant()
            which resolves via User::byChurch() + assertActorCanManageUser().
Evidence:   ClasseService.php:276  $classe->servants()->detach($servantId);
            ClasseController.php:213-231 validates only `exists:users,id`
            (tenant-blind) before calling the service.
Root Cause: Asymmetry between the assign and remove paths.
Fix:        NONE REQUIRED — analysed as NOT exploitable:
            1. The class is resolved through ClasseRepository::findById(),
               which is ChurchScope-scoped; a foreign class yields 404.
            2. authorize('manageServants', $classe) enforces
               church isolation for admins and StageResolver::canAccessClass()
               for stage admins (ClassePolicy::canManage).
            3. A cross-church servant has no pivot row in the actor's class
               (assignServant resolves the servant via User::byChurch()), so
               detach() is a NO-OP: no cross-tenant row can be removed.
            Residual effect is cosmetic only — a 200 message when nothing was
            detached. Adding a guard would change response behaviour without
            fixing a confirmed security defect.
Files Changed: none
Tests Added/Changed: none
Verification: code-path analysis (documented above); no mutation possible.
Regression Risk: N/A.
Status:     DOCUMENTED
```

---

## R-07

```text
ID:         R-07   (subagent ref C-4)
Severity:   P2 (contract / observability — an expected 403 reported as a 500-class body)
Component:  backend/bootstrap/app.php (exception render callbacks)
            backend/app/Http/Controllers/Api/LeaderboardController.php (sole call site)
Finding:    abort(403) on an API route returned HTTP 403 but a body of
            {success:false, message:"Internal server error.",
             code:"INTERNAL_ERROR", request_id:...} — the scope violation was
            reported to the client as an internal server error, and the
            catch-all logged it through the error path.
Evidence:   NEW test, fail-first, against the REAL route:
              php artisan test --filter=HttpExceptionResponseShapeTest  (BEFORE fix)
              1 failed, 3 passed
              response JSON: {"success":false,"message":"Internal server error.",
                               "code":"INTERNAL_ERROR","request_id":"01M3V..."}
            Reproduction: servant (view_users, class-scoped to Own Class)
            GET /api/v1/leaderboard/class/{OtherClass} -> abort(403, ...) at
            LeaderboardController.php:36.
            Source verification: Application::abort() (Application.php:1434-1441)
            throws a PLAIN Symfony HttpException for every status except 404;
            Handler::renderViaCallbacks() (Handler.php:712-725) walks
            callbacks in REGISTRATION order, is_a() matching, first non-null
            wins. The typed callbacks in bootstrap/app.php cover the
            NotFound / AccessDenied / Throttle SUBCLASSES only, so a plain
            HttpException matched none of them and fell through to the
            catch-all Throwable callback.
Root Cause: Missing render-callback slot for the BASE HttpException class in
            the exception renderer; the catch-all Throwable callback — which
            exists to translate genuine server failures into a 5xx envelope —
            received every abort() outcome instead.
Fix:        Registered a dedicated `function (HttpException $e, Request $request)`
            callback AFTER all typed callbacks and BEFORE the catch-all, so:
              - registration order preserves the specialised subclasses
                (NotFound, AccessDenied, ThrottleRequestsException all extend
                HttpException and are registered earlier);
              - api/* 4xx responses get {success:false, message (exception
                message, else the HTTP status text), code: FORBIDDEN /
                NOT_FOUND / UNAUTHORIZED / METHOD_NOT_ALLOWED /
                CSRF_TOKEN_MISMATCH / RATE_LIMITED / HTTP_<n>};
              - $e->getHeaders() is forwarded (e.g. Retry-After on abort(429));
              - 5xx defers (returns null) to the catch-all, keeping its
                Log::error entry and INTERNAL_ERROR body;
              - non-api requests return null, so web routes keep the
                framework's HTML error pages.
            No frontend consumer switches on these codes (verified: no
            INTERNAL_ERROR/FORBIDDEN/NOT_FOUND references in frontend/src),
            and no backend test asserted a 403 body (verified by grep).
Files Changed:
            backend/bootstrap/app.php                     (imports + callback)
            backend/tests/Feature/HttpExceptionResponseShapeTest.php — NEW
Tests Added/Changed:
            HttpExceptionResponseShapeTest — 4 tests:
              - abort_403_returns_a_forbidden_envelope_not_internal_error
                (the reproduction, drives the real leaderboard route)
              - specialised_subclasses_still_win_over_the_generic_slot
                (registration order preserved: unknown api route -> NOT_FOUND)
              - 5xx_http_exceptions_still_reach_the_catch_all
                (INTERNAL_ERROR body retained for genuine server errors)
              - non_api_requests_keep_the_framework_error_page
                (web 404 stays text/html)
Verification:
            - Before fix: 1 failed, 3 passed (defect proven)
            - After fix:  4 passed (SQLite)
            - Full suite (final-tree re-run): 560 passed, 6 skipped (SQLite)
                          566 passed, 0 skipped (PostgreSQL 16.15)
            - PHPStan level max: 0 errors · Pint: pass
Regression Risk: LOW-MEDIUM.
            - The callback sits centrally in the exception renderer; the
              ordering guarantees above are asserted by dedicated tests.
            - Body-shape change for abort()-originated 4xx: previously
              INTERNAL_ERROR (unreachable-as-correct anyway); no client code
              or test depended on the old shape (grep-verified).
            - 500 paths and web paths are asserted unchanged.
Status:     FIXED
```

---

## R-08

```text
ID:         R-08   (subagent ref A-1)
Severity:   P1 (data loss in the offline-first write path)
Component:  frontend/src/lib/sync.ts  (offline queue replay)
Finding:    isPermanentRejection() classified EVERY 4xx as a permanent
            refusal, so a single HTTP 429 during queue replay abandoned the
            queued attendance write immediately (markSyncAbandoned) — silent
            data loss. Retry-After was ignored entirely, so the fixed
            exponential backoff (2s/4s/8s/16s) could exhaust all five retries
            inside one rate-limit window. Abandoned items are excluded from
            getActionableSyncCount(), so the write disappears without a trace.
Evidence:   Subagent review (read-only) with source refs: sync.ts:43-46
            (`status >= 400 && status < 500`), client.ts sentinel, and the
            backend's replay-route throttles (throttle:attendance-record
            100/min, throttle:api 300/min) making 429 an expected replay
            outcome, not a poisoned item.
Root Cause: "4xx means the server refused the request" conflated semantic
            refusals (401/403/404/409/422 — retrying cannot help) with
            TIME-based refusals (429/408 — the server said "later").
Fix:        (1) EXCLUSIONS — isPermanentRejection() now returns false for
                429 and 408; those items flow into the transient path (retry
                budget + backoff + dead-letter at MAX_RETRIES) instead of
                being abandoned on first contact.
            (2) RETRY-AFTER — new backoffDelayFor(error, retries) honors the
                server's Retry-After header (both AxiosHeaders .get() and
                plain-object shapes), capped at 60s so a malformed header
                cannot park the queue, falling back to the existing
                exponential schedule when absent/invalid.
            (3) The permanent path is otherwise untouched: 401/403/404/409/422
                still abandon immediately (documented decision preserved).
Files Changed:
            frontend/src/lib/sync.ts
            frontend/src/lib/__tests__/sync.test.ts
Tests Added/Changed:
            sync.test.ts — 7 new tests (file now 15):
              - treats 429 as transient: the item stays queued instead of
                being abandoned (then succeeds on the next run)
              - treats 408 as transient likewise
              - still abandons a 422 business-rule rejection immediately
                (the flip side — duplicate attendance still stops the client)
              - backoffDelayFor: honors Retry-After / caps absurd values at
                60s / reads AxiosHeaders-style getters / falls back to
                exponential on missing or invalid headers
Verification:
            npx vitest run src/lib/__tests__/sync.test.ts -> 15 passed
            Full frontend suite: 83 passed (9 files)
            tsc --noEmit clean · eslint clean · npm audit 0 · build OK
Regression Risk: MEDIUM (behavioural, but tightly scoped).
            - A persistently-429 item now consumes the retry budget (5
              attempts) before dead-lettering instead of abandoning at once —
              termination is preserved (MAX_RETRIES + markSyncAbandoned).
            - The 422-still-abandons test pins the most important existing
              behaviour against regression.
            - Real timers retained (a 2s backoff wait in the 408 test);
              suite duration within budget (testTimeout 20s).
Status:     FIXED
```

---

## R-09

```text
ID:         R-09   (subagent ref A-2)
Severity:   P2 (UX integrity / duplicate-write pressure)
Component:  frontend/src/pages/servant/ScanQR.tsx
            frontend/src/i18n/en.json, frontend/src/i18n/ar.json
Finding:    confirmAttendance() did not consume the API layer's
            `{__offline_queued:true}` sentinel (client.ts:207). An
            offline-captured write was reported as a FAILURE: red
            "Failed to record attendance." banner + error toast, with the
            pending-member state left intact — inviting the servant to press
            Confirm or re-scan and queue a SECOND copy of the same write.
Evidence:   Subagent review (read-only): sentinel produced at client.ts:207
            with NO consumer anywhere in frontend/src (grep: only the
            producer and the interceptor contract test reference it);
            ScanQR.tsx catch block treated every rejection identically.
Root Cause: The offline-queue interception was added to the request layer
            without a corresponding branch in the one UI flow that owns
            attendance confirmation.
Fix:        Added an explicit sentinel branch in confirmAttendance():
            - NEW third result state `{success:false, queued:true,
              message:t('attendance.queuedOffline')}` rendered as an amber
              banner (CloudOff icon) — distinct from green success and red
              failure;
            - neutral toast (not toast.error);
            - the flow resets exactly like a success (manual token, pending
              member/token/id, context, QR context name cleared), so the
              confirm button cannot be pressed twice for the same member;
            - the today-list refresh is deliberately skipped (the write is
              not recorded yet, and the read fails offline) — commented.
            New i18n key attendance.queuedOffline in EN + AR (check:i18n
            parity verified).
Files Changed:
            frontend/src/pages/servant/ScanQR.tsx
            frontend/src/i18n/en.json
            frontend/src/i18n/ar.json
            frontend/src/test/scanQROfflineQueue.test.tsx — NEW
Tests Added/Changed:
            scanQROfflineQueue.test.tsx — 4 tests, rendering the REAL
            component (ThemeProvider included — useTheme throws without it)
            and driving the real lookup -> confirm flow; only API modules,
            toast and i18n are mocked:
              - reports a queued write as queued, not as a failure
              - resets the confirmation flow so the same member cannot be
                confirmed twice (recordAttendance called exactly once)
              - skips the today-list refresh for a write not yet recorded
              - still reports a genuine server rejection as a failure
                (regression guard for the red path)
Verification:
            npx vitest run src/test/scanQROfflineQueue.test.tsx -> 4 passed
            Full frontend suite: 83 passed · check:i18n PASS
            tsc --noEmit clean · eslint clean · build OK (138 PWA entries)
Regression Risk: LOW.
            - Only the catch block of confirmAttendance() changed; the
              failure path is pinned by its own test.
            - New result state is additive (queued checked first in the
              render ternary); no other code reads result.success.
Status:     FIXED
```

---

## R-10

```text
ID:         R-10   (subagent ref A-5 — severity downgraded P2-live -> P3-latent
                    after verification)
Severity:   P3 (latent exposure; defense-in-depth hardening)
Component:  backend/app/Notifications/ResetPasswordNotification.php
            backend/app/Notifications/PasswordResetRequestApprovedNotification.php
Finding:    Both queued notifications carry password-reset credentials
            ($token in the reset URL; $resetUrl with token) but neither
            implemented ShouldBeEncrypted — unlike VerifyEmailNotification,
            which documents the project standard — so a dispatched job would
            write the reset credential as READABLE text into jobs.payload.
Evidence:   Subagent review flagged ResetPasswordNotification. Phase 1C
            then verified BOTH classes by source:
              - ResetPasswordNotification is constructed only by
                User::sendPasswordResetNotification() (User.php:467-470, the
                CanResetPassword contract method); its sole caller is Laravel's
                password broker, and grep across app/, routes/, tests/ found
                NO invocation of the broker (Password::broker / sendResetLink /
                ->broker()) — recovery runs through the admin-approval flow
                (AuthService::forgotPassword -> PasswordResetRequestService::
                submitRequest, comment: "No email is involved in password
                recovery"). The dispatch site exists but is not reachable.
              - PasswordResetRequestApprovedNotification is constructed
                NOWHERE (grep: only its own class definition); the approval
                flow has the admin set the password directly
                (POST /password-reset-requests/{id}/reset-password).
            Mechanism verified in framework source: SendQueuedNotifications ->
            Queue::jobShouldBeEncrypted (Queue.php:278-285) -> payload
            encryption at Queue.php:181-183.
            => The Phase 1B "live path" claim is DISPROVED; exposure is
            latent, severity downgraded accordingly.
Root Cause: The ShouldBeEncrypted standard was established when
            VerifyEmailNotification was written but never backfilled to the
            two reset-bearing notifications (both currently undispatched).
Fix:        Added `implements ShouldBeEncrypted, ShouldQueue` to both
            classes, each with a docblock stating (a) the encryption
            mechanism, and (b) the exact reachability facts above so future
            readers do not mistake "latent" for "dead".
Files Changed:
            backend/app/Notifications/ResetPasswordNotification.php
            backend/app/Notifications/PasswordResetRequestApprovedNotification.php
            backend/tests/Feature/ResetNotificationEncryptionTest.php — NEW
Tests Added/Changed:
            ResetNotificationEncryptionTest — 2 tests asserting the queue
            contract the framework keys encryption on (ShouldQueue +
            ShouldBeEncrypted pairing), mirroring
            EmailVerificationTokenSecurityTest::test_notification_is_queued_
            with_an_encrypted_payload.
Verification: 2 passed · full suite 560 passed / 6 skipped (SQLite) ·
            566 passed / 0 skipped (PostgreSQL) · PHPStan 0 · Pint pass.
Regression Risk: VERY LOW.
            - ShouldBeEncrypted only changes payload serialization for
              currently-undispatched notifications; dispatched-in-future jobs
              decrypt transparently via the same marker.
Status:     FIXED  (latent — defense-in-depth)
```

---

## R-11

```text
ID:         R-11   (subagent ref B-4 — upgraded from "dead code" to confirmed
                    functional defect after Phase 1C source verification)
Severity:   P2 (live endpoint, silent data/behaviour corruption)
Component:  backend/app/Services/EventAccommodationService.php::updateRoom
            Route: PUT/PATCH /api/v1/events/{id}/accommodation/rooms/{roomId}
            (routes/api.php:541-543 -> EventAccommodationController::roomsUpdate)
Finding:    Resizing a room NEVER synced its cell inventory, in either
            direction:
              (a) $currentTotal = $room->capacity was read AFTER
                  $room->update(['capacity' => $newCapacity]) — Eloquent's
                  update() writes the new value into the model's attributes,
                  so the comparison compared the capacity to itself: both the
                  increase branch (create cells) and the decrease branch
                  (delete cells) were unreachable;
              (b) the decrease guard counted ALL member cells (which for a
                  consistent inventory equals capacity-1), so ANY reduction
                  was refused regardless of occupancy — while its error
                  message claimed to be counting OCCUPIED cells.
            Net effect: increasing capacity updated the columns
            (capacity/member_capacity) but left the cell grid — the thing
            members actually select from — at the old size, so dashboard
            aggregates (cell-based) and room capacity (column-based) diverged.
Evidence:   Phase 1C source verification: Model::update() = fill()->save()
            (attributes mutated in memory); no existing test exercised
            roomsUpdate (grep: only POST/GET/DELETE rooms appear in
            EventManagementTest), which is why the suite stayed green.
            Fail-first NEW test, BEFORE the fix:
              php artisan test --filter=EventRoomCapacityResizeTest
              4 failed — including the guard reporting
              "occupied cells (5). Minimum allowed: 6." for a room with only
              2 occupied cells, and both sync branches never executing.
Root Cause: Read-after-write staleness ($currentTotal/$currentCells captured
            after the UPDATE) plus a guard that measured the wrong quantity
            (all member cells instead of occupied ones).
Fix:        (1) Capture $currentTotal = (int) $room->capacity and
                $currentCells BEFORE $room->update() with a comment naming
                the read-after-write trap.
            (2) Guard now counts OCCUPIED member cells
                (type=member AND is_available=false — the occupancy marker
                assign() sets) and enforces capacity >= occupied + 1 (the
                reserved servant cell); message wording unchanged, numbers
                now match their own wording.
            (3) Increase/decrease branches otherwise untouched: cells are
                created consecutively after the current inventory, and only
                UNOCCUPIED member cells above the new capacity are deleted
                (occupied assignments are never destroyed by a capacity edit
                — behaviour pinned by test).
Files Changed:
            backend/app/Services/EventAccommodationService.php
            backend/tests/Feature/EventRoomCapacityResizeTest.php — NEW
Tests Added/Changed:
            EventRoomCapacityResizeTest — 4 tests (service-level; the defect
            is in the service, fixture mirrors bulkCreateRooms' layout):
              - increasing capacity creates the new member cells (consecutive
                numbering, member_capacity = capacity-1)
              - decreasing capacity removes unoccupied member cells
              - decreasing capacity keeps occupied member cells
              - reduction below the occupied member cells is refused with
                the truthful message, and the refusal mutates nothing
Verification:
            - Before fix: 4 failed (defect proven)
            - After fix:  4 passed (17 assertions)
            - Full suite (final-tree re-run): 560 passed, 6 skipped (SQLite)
                          566 passed, 0 skipped (PostgreSQL 16.15)
            - PHPStan level max: 0 errors · Pint pass
Regression Risk: MEDIUM.
            - Behaviour change is the POINT (resize now actually resizes
              cells); no existing test or code asserted the broken state.
            - Occupied assignments remain untouchable by reductions (pinned);
              the guard is strictly more permissive than before ONLY for
              reductions that match its documented intent (it previously
              refused ALL reductions).
            - Rooms whose inventory was already stale from past resizes are
              self-healing on the next increase (cell count + consecutive
              numbering), but a decrease on a stale room keeps occupied
              cells — by design.
Status:     FIXED
```

---

## R-12

```text
ID:         R-12   (subagent refs A-3/A-4)
Severity:   P3 (was reported as potential SQLite seeder FK failures)
Component:  backend/database/seeders (PermissionSeeder, AttendanceContextSeeder,
            AdminUserSeeder)
Finding:    Review flagged foreign-key ordering concerns in the seeding path
            on SQLite.
Evidence:   Phase 1C direct probe on the CURRENT tree, scratch database only
            (temp SQLite file, nothing touched in any real DB):
              DB_CONNECTION=sqlite DB_DATABASE=<temp> DB_FOREIGN_KEYS=true
              APP_ENV=testing
              php artisan migrate:fresh --seed
              -> PermissionSeeder DONE · AttendanceContextSeeder DONE ·
                 AdminUserSeeder DONE · SEED_EXIT=0
            FK enforcement was ON (DB_FOREIGN_KEYS=true), so the probe
            exercises the constraint path rather than sidestepping it.
            Default DatabaseSeeder calls exactly these three seeders.
Root Cause: Not reproduced on the current tree (the review was read-only and
            may have reasoned from static ordering rather than execution).
Fix:        NONE. Per Rule 1, a finding that does not exist in the current
            code is not recreated; no code changed.
Files Changed: none
Tests Added/Changed: none
Verification: probe exit 0 (above); the regular suites are unaffected.
Regression Risk: NONE.
Status:     VERIFIED-NOT-REQUIRED
```

---

## R-13

```text
ID:         R-13   (subagent ref A-6)
Severity:   P3 (config-example hygiene; zero runtime impact)
Component:  backend/.env.example (lines 56-64)
Finding:    .env.example ships MAIL_MAILER=resend (+ smtp.resend.com block and
            empty RESEND_API_KEY=) although Resend was removed from the
            project (AGENTS.md: "Resend removed — Email sending is NOT
            implemented"), and `composer show resend/resend` confirms the
            package is not installed.
Evidence:   grep of .env.example; composer show resend/resend -> not found
            (exit 1); AGENTS.md COMMON PITFALLS #10.
Root Cause: The example file was not updated when the mail transport was
            removed from the dependency set.
Fix:        NONE in Phase 1C. .env.example is never loaded by the application
            (runtime config comes from the deployment environment), so the
            value is inert; choosing a replacement transport is a deployment/
            product decision tied to the (still unimplemented) email pathway,
            and guessing one here would be an unrelated change (§1).
Files Changed: none
Tests Added/Changed: none
Verification: analysis; no runtime path reads .env.example.
Regression Risk: NONE.
Status:     DOCUMENTED
            (tracked: when email sending is implemented, update .env.example
            to the chosen transport in the same change)
```

---

## R-14

```text
ID:         R-14   (subagent ref B-3)
Severity:   P3 (latent — no current exposure)
Component:  backend/app/Http/Controllers/Api/UserController.php
            (updatePermissions at :518, bulkUpdatePermissions at :544)
            backend/database/... role_permission table
Finding:    Two permission-update controller methods exist but NO route
            references them (grep routes/api.php: zero matches), and the
            role_permission pivot has no church_id column.
Evidence:   Route grep (no wiring); schema (role_permission is role-name
            keyed by design — Permission::defaultRolePermissions() maps
            UserRole -> keys, and PermissionMiddleware resolves through
            getPermissionsForRole()).
Root Cause: Leftovers from an earlier API surface; the pivot's design is
            global-role, not per-tenant (role names are application-wide
            enums), which is consistent with the permission model rather than
            a missing tenant column.
Fix:        NONE. Unreachable methods are not an exposure; deleting them is
            refactoring outside the remediation mandate (§1), and adding
            church_id to a role-name-keyed pivot would be an architecture
            change (§2 forbidden).
Files Changed: none
Tests Added/Changed: none
Verification: reachability analysis (grep) + permission-model reading.
Regression Risk: NONE.
Status:     DOCUMENTED
            (if these endpoints are ever routed, church scoping and a policy
            must land WITH the route — noted in the risk register)
```

---

## R-15

```text
ID:         R-15   (subagent refs C-1/C-2/C-3)
Severity:   P3 (UX hardening)
Component:  frontend submission buttons (three flows flagged by the review)
Finding:    Certain submit buttons lack a pending/disabled state while their
            request is in flight, so a fast double-click can issue two
            requests.
Evidence:   Subagent review (read-only) — button-level states absent on the
            flagged flows.
Root Cause: `loading` state wired to the async handler but not to every
            submit control.
Fix:        NONE in Phase 1C. The authoritative duplicate protection is
            server-side (attendance duplicate guard returns 422; idempotent
            business rules elsewhere) and those tests pass; adding pending
            states across pages is UX polish that must be verified page by
            page with new component tests — disproportionate to P3 within the
            remediation budget. NOTE: the attendance confirm button (the one
            flow with a confirmed duplicate-write pressure path) WAS fixed as
            part of R-09 (its state resets after a queued write).
Files Changed: none
Tests Added/Changed: none (R-09's tests cover the attendance confirm flow)
Verification: analysis; server-side duplicate guard covered by the existing
            suite (e.g. duplicate attendance 422 tests).
Regression Risk: NONE.
Status:     DOCUMENTED
```

---

## R-16

```text
ID:         R-16   (subagent ref C-5)
Severity:   INFO (contract consistency)
Component:  backend error envelopes across middleware/controllers
Finding:    Not every API error uses the full {success, message, code}
            envelope. Examples verified in source during Phase 1C:
              - PermissionMiddleware (:46-48) returns {message} only (403);
              - EnsureApproval (:51-54) returns {message, code} without
                `success`;
              - the typed render callbacks and catch-all return the full
                {success, message, code(, request_id)} shape.
Evidence:   Direct source reads of the two middleware cited.
Root Cause: Envelope grown incrementally; middleware written before the
            render-callback contract was consolidated.
Fix:        NONE — changing response bodies across endpoints is a contract
            change affecting clients (§1/§2: no unrelated refactors). Status
            codes are correct in every case, and no frontend logic depends on
            the missing keys (the client reads HTTP status + message).
Files Changed: none
Tests Added/Changed: none
Verification: source reads; frontend consumers grep-checked for envelope-key
            dependencies.
Regression Risk: NONE.
Status:     DOCUMENTED
            (candidates for a future contract-hardening pass: add
            success/code to the two middleware bodies with tests)
```

---

## R-17

```text
ID:         R-17   (subagent refs D-5/D-6)
Severity:   INFO (observability)
Component:  backend/bootstrap/app.php (request-id propagation)
Finding:    The correlation `request_id` appears only in 500-class bodies
            (catch-all), not in typed 4xx envelopes, and an unknown-route 404
            carries no id at all.
Evidence:   Source: catch-all adds request_id; NotFoundHttpException,
            ValidationException, AuthenticationException, AccessDeniedHttpException
            callbacks do not; RequestIdTest asserts the CURRENT behaviour
            ("the id is returned on error responses" — 500 path).
Root Cause: request_id was added with the unhandled-exception path; typed
            callbacks predate it.
Fix:        NONE in Phase 1C. Adding the key to every 4xx body is an additive
            contract change that must be applied consistently (middleware +
            callbacks + tests) — a deliberate future pass, not a defect fix.
            The id IS always present in logs for every request (AssignRequestId
            middleware), so correlation works server-side today.
Files Changed: none
Tests Added/Changed: none (existing RequestIdTest continues to pass)
Verification: source reads; full suite green.
Regression Risk: NONE.
Status:     DOCUMENTED
```

---

## R-18

```text
ID:         R-18   (subagent ref A-1 secondary)
Severity:   P3 (efficiency; correctness preserved)
Component:  frontend/src/api/client.ts (live 429 retry, ~:314)
Finding:    The live request interceptor retries 429 responses after a FIXED
            2-second delay (max 3 attempts), ignoring Retry-After.
Evidence:   Subagent review (read-only); source: fixed setTimeout(2000).
Root Cause: Retry-After handling was implemented only in the offline replay
            path (R-08) — and there, only as part of this phase.
Fix:        NONE in Phase 1C. The retry succeeds for typical per-minute
            windows (3 x 2s inside a 60s window covers bursty limits) and
            never loses data (failure propagates to the caller normally).
            Honoring the header here is an optimization; the offline path —
            where ignoring it CAUSED data loss — is fixed in R-08.
Files Changed: none
Tests Added/Changed: none
Verification: analysis; client429 retry behaviour covered indirectly by the
            interceptor tests.
Regression Risk: NONE.
Status:     DOCUMENTED
```

---

## R-19

```text
ID:         R-19   (queue / bulk / error-path review — §10/§11/§18 area)
Severity:   INFO (verification entry, no defect)
Component:  backend queue configuration (config/queue.php, .env*, worker
            contract), bulk operations, error handling
Finding:    Review requested verification that the queue and bulk paths are
            production-sound.
Evidence:   All verified by Phase 1C reading the actual sources:
              - QUEUE_CONNECTION=database in every env file; after_commit=true
                (writes commit before jobs dispatch — no job before the row);
              - failed_jobs migration present; worker contract
                --tries=3 --timeout=90;
              - SendEmailJob declares tries=3, backoff=10 and retryUntil —
                Queue::createPayload honors retryUntil (Queue.php:259-270,
                isset($job->retryUntil)) so jobs get a real deadline;
              - bulk permission updates are atomic (BulkAtomicityTest: a
                mid-batch authorization failure changes nothing) and
                cross-tenant ids are not applied;
              - error handling: typed envelopes + request_id on 500s
                (RequestIdTest) + localized-message catch (R-01/R-07 work).
Root Cause: N/A — no defect found.
Fix:        NONE REQUIRED.
Files Changed: none
Tests Added/Changed: none (existing suites cited above)
Verification: source reads cited; full suites green on both engines.
Regression Risk: NONE.
Status:     VERIFIED-NOT-REQUIRED
```

---

## R-20

```text
ID:         R-20   (queue review secondary)
Severity:   INFO (dead code)
Component:  backend/app/Services/EmailService.php + SendEmailJob
            (bound at AppServiceProvider:218 as EmailServiceInterface)
Finding:    The EmailService/SendEmailJob pathway is dead code: the binding
            exists but grep found zero callers, consistent with AGENTS.md
            ("Email sending is NOT implemented — use in-app notifications").
Evidence:   Binding at AppServiceProvider:218; zero call sites (repo grep).
Root Cause: The transport was removed (Resend dropped) while the abstraction
            shell remained bound.
Fix:        NONE. Removing a service + interface binding is refactoring
            outside the remediation mandate (§1); the binding is inert and
            never resolved at runtime.
Files Changed: none
Tests Added/Changed: none
Verification: reachability analysis.
Regression Risk: NONE.
Status:     DOCUMENTED
            (candidate for cleanup when email is (re)implemented — see also
            R-13)
```

---

## Summary

| ID | Subagent ref | Severity | Status |
|----|--------------|----------|--------|
| R-01 | — | P0 | **FIXED** |
| R-02 | — | P2 | **VERIFIED-NOT-REQUIRED** |
| R-03 | — | P2 | **FIXED** |
| R-04 | — | P3 | **NOT-FIXED-BY-DESIGN** |
| R-05 | — | INFO | **DOCUMENTED** |
| R-06 | — | P3 | **DOCUMENTED** |
| R-07 | C-4 | P2 | **FIXED** |
| R-08 | A-1 | P1 | **FIXED** |
| R-09 | A-2 | P2 | **FIXED** |
| R-10 | A-5 | P3 (latent) | **FIXED** |
| R-11 | B-4 | P2 | **FIXED** |
| R-12 | A-3/A-4 | P3 | **VERIFIED-NOT-REQUIRED** |
| R-13 | A-6 | P3 | **DOCUMENTED** |
| R-14 | B-3 | P3 | **DOCUMENTED** |
| R-15 | C-1/C-2/C-3 | P3 | **DOCUMENTED** |
| R-16 | C-5 | INFO | **DOCUMENTED** |
| R-17 | D-5/D-6 | INFO | **DOCUMENTED** |
| R-18 | A-1 (secondary) | P3 | **DOCUMENTED** |
| R-19 | queue/bulk/error review | INFO | **VERIFIED-NOT-REQUIRED** |
| R-20 | queue review (dead code) | INFO | **DOCUMENTED** |

**Totals: 20 findings — 7 FIXED · 3 VERIFIED-NOT-REQUIRED · 9 DOCUMENTED · 1 NOT-FIXED-BY-DESIGN.**
