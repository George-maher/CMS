# Recovery Audit Report — controlled forensic reconstruction

Generated: 2026-09-28
Target (writable): `D:\xampp\htdocs\CHproject` @ `bd37cf5` (branch `main`, history untouched, nothing committed)
Source (read-only): `D:\xampp\htdocs\church-cms-recovery` @ `bfb2c61` (branch `main`)

## 0. Safety attestation

| Control | Result |
|---|---|
| Forbidden Git ops (`pull/merge/rebase/reset/checkout/restore/stash/clean/cherry-pick/revert/branch-switch/force-push) | **None used.** Not a single one. |
| Recovery repo content | **Unchanged.** SHA-256 manifest of 9,636 files identical before/after (`EBB1A74A…05A5B`). |
| Recovery repo Git state | **Unchanged.** `git status --porcelain` = 0 entries, `git diff --name-only HEAD` = 0 files, HEAD still `bfb2c61`. |
| Commits / pushes performed | **None.** |
| Recovery dir writes | **None.** All recovery reads were `Get-FileHash`, `Get-Content`, `git log/status/diff` (read-only). |
| Git used to transfer changes | **No.** All 250 files were copied with a read/write file copy (CRLF→LF normalised). |

The recovery clone was only ever *read*. A throwaway sandbox copy was made outside both
projects solely to execute the recovery test suite for evidence; the recovery tree itself
was never written to.

## 1. Forensic correction applied before acting

The first comparison (raw SHA-256) reported **450** differing files. That number was wrong.

**Cause:** the recovery clone was checked out with `core.autocrlf=true` and no `.gitattributes`
`eol` rule for those paths, so its working tree is **CRLF**; the current tree is **LF**.
Byte hashing therefore flagged every text file as changed even when logically identical.

Re-run with CR-stripping normalisation produced the real figure: **250** genuinely differing files.

| Metric (normalised) | Count |
|---|---|
| Files compared | 1,571 (798 current ∩ 773 recovery, normalised) |
| Identical | 523 |
| Differing (real) | 250 |
| Only in CURRENT | 25 |
| Only in RECOVERY | **0** |

**Interpretation:** no file was *missing* from CURRENT. The damage is subtler and worse in
one respect — CURRENT's copies of 250 files had been rolled back to a weaker, older state,
while the rest of the tree still called APIs those files no longer provided. CURRENT was
internally incoherent, not merely incomplete.

## 2. Baselines (evidence, not assumption)

| Tree | Result |
|---|---|
| CURRENT `bd37cf5` **before** any change | **195 failed**, 173 passed (719 assertions) |
| CURRENT `bd37cf5` before, `ServantLoginLifecycleTest` | **Fatal** — crashed PHPUnit, suite produced no summary at all |
| RECOVERY `bfb2c61` in isolated sandbox | **358 passed, 0 failed** (1,289 assertions) |
| Dominant CURRENT failure | `BadMethodCallException` × 212 — callers invoked `isStageAdmin()`/`getScope()`/`stage()` on a `User` model that no longer defined them |

**Root cause of the mass failure:** `tests/TestCase.php` in CURRENT was 142 bytes (a bare stub)
vs 1,435 bytes in RECOVERY. The lost code was:

1. `public $mockConsoleOutput = false;` — the documented Windows fix for Laravel Prompts
   invoking `$this->output->confirm()` on a mocked `OutputStyle` (AGENTS.md pitfall #8).
   This is what crashed PHPUnit outright.
2. A `call()` override calling `Auth::forgetGuards()` after every simulated request.
   Sanctum's `RequestGuard` permanently caches the first resolved user, so without this every
   subsequent request in one test inherited the **first request's identity** regardless of the
   Bearer token — a cross-request identity leak that made unrelated assertions fail.

`config/supabase-storage.php` is byte-identical in both trees, and CURRENT's `.env` defines the
Supabase keys as empty (→ `''`), so CURRENT never hits the Supabase `TypeError`. The recovery
clone simply has no `.env`, so `env()` returned `null`. Per Step 8 this was treated as an
environment artefact and **the production type was not weakened**; the sandbox was given an
equivalent `.env` to obtain a valid signal.

## 3. Change ledger

### 3.1 Restored from RECOVERY — 226 files (bulk, one controlled unit)

Selected by: excluded the 8 keep-CURRENT files, the 16 email/provisioning-dependent files
(handled separately in 3.2), and `vendor`/`node_modules`/`.git`/build/cache/secrets.

Before copying, a **dependency safety check** was run: zero of the recovery versions of these
226 files reference any CURRENT-only symbol, so the bulk copy could not orphan the
provisioning subsystem.

Notable recovered security content:

- **`routes/api.php`** — the single most severe regression found. CURRENT was missing:
  - the `approval` middleware on the **entire authenticated group** (unapproved applicants could
    reach every protected endpoint);
  - `permission:manage_users` on `storage/replace`, `storage/delete`, `storage/upload-document`
    — any authenticated user could delete arbitrary objects in the shared Supabase project
    via the service-role key;
  - `permission:manage_events` on `storage/upload-event-image`;
  - `auth:sanctum` on `/attendance-contexts` (it was fully public);
  - the public token-reset endpoints (`/auth/reset-password`, `/password-reset-requests/reset`);
  - ~11 controllers' routes entirely (daily-spiritual, event registration/accommodation/bus/
    payment/reservation/schedule/dashboard, leaderboard, member-profile, profile-update).
- **`app/Policies/*` (8 files)** and `app/Http/Middleware/*` — authorisation layer.
- **`app/Contracts/*` (27)** — interface signatures that must match their implementations.
- **`app/Models/Event.php`, `User.php` and 19 other models** — relationships/scopes.
- **`tests/TestCase.php`** — the two fixes described in §2.
- **`Dockerfile` / `.dockerignore`** — `expose_php=Off` + upload/memory/execution limits,
  phpredis, `php-fpm -F` (container liveness), and exclusion of `.env*` from image layers.
- **25 migrations** — verified diff-by-diff to be **Pint formatting only**
  (`down(): void {}`, unused imports). Zero semantic change.

### 3.2 Restored from RECOVERY then re-layered with CURRENT work — 11 files

| File | Restored (security work recovered) | Re-applied from CURRENT (preserved) |
|---|---|---|
| `app/Models/User.php` | `isStageAdmin()`, `getScope()`, `roleDefaultScope()`, `stage()`, `dailySpiritualRecords()`, `stage_id`+`scope` in `$fillable`, `email_verification_*` fillable + casts, `UserScope` cast, `points_sum` aggregate optimisation | `sendPasswordResetNotification()` redirecting the broker to the in-app notification (stops reset tokens leaving the system) |
| `app/Http/Controllers/Api/AuthController.php` | container-injected `AuthServiceInterface` base | `EmailVerificationServiceInterface` dependency, `verifyEmail()`/`resendVerification()` rewritten to be enumeration-safe, `verificationFailed()` helper |
| `app/Providers/AppServiceProvider.php` | all 40+ interface bindings | 3 lost bindings: `UserProvisioningServiceInterface`, `MailConfigurationValidatorInterface`, `EmailVerificationServiceInterface` |
| `app/Services/PasswordResetRequestService.php` | admin-driven `resetPassword($id,$adminId,$password)` replacing the public token `completeReset($token,$password)` | — (in-app notifications already used) |
| `app/Modules/User/Services/UserService.php` | stage-scoping guards, `deriveScopeValue()`, `stageForClass()` | `UserProvisioningServiceInterface` injected; insert routed through `provisioning->create(..., ProvisioningChannel::AdminCreated)` |
| `app/Modules/User/Repositories/UserRepository.php` | — | `LogicException` choke-point guard rejecting any insert lacking an explicit `email_verified_at` decision |
| `app/Services/{Auth,ChurchApplication,MembershipRequest}Service.php`, `tests/Feature/InviteRegistrationFlowTest.php` | restored in full (no CURRENT-only symbols to re-apply) | — |
| `routes/api.php` | restored in full (see §3.1) | — |

### 3.3 Merged — 2 files

`resources/lang/en.json`, `resources/lang/ar.json`

- RECOVERY had **110** keys; CURRENT had **70**. The 53 keys CURRENT lacked were required by
  the restored code (admin-driven password reset, event accommodation, profile-update requests,
  church deletion, invite/application stage scoping).
- CURRENT's **13** email-verification / membership-request keys were preserved.
- **1** value conflict resolved **to RECOVERY**: `password_reset_requests.approved`. CURRENT's
  text said "The user will receive an email with instructions", which describes the token-based
  flow that was removed. RECOVERY's "You can now set a new password for the user" matches the
  restored admin-driven path.
- Result: **123 keys in both locales, exact parity** (verified by the project's own
  `scripts/check-lang-parity.php`).

### 3.4 Intentionally left as CURRENT — 8 files (+1 runtime artefact)

| File | Why not restored |
|---|---|
| `app/Notifications/VerifyEmailNotification.php` | **CURRENT is strictly more secure.** Adds `ShouldBeEncrypted` (Laravel then encrypts `SendQueuedNotifications`, so `jobs.payload` never holds a readable verification token), bounded `$tries`/backoff, and a `failed()` hook that logs metadata only and never interpolates the URL or an exception message. RECOVERY's version hardcodes English and has neither protection. |
| `app/Notifications/ResetPasswordNotification.php` and 4 sibling notifications | CURRENT-only in-app notification layer (Step 5) |
| `app/Services/{EmailVerification,MailConfigurationValidator,UserProvisioning}Service.php` + their 3 contracts + 2 enums + 1 exception | CURRENT-only provisioning subsystem (Step 5) |
| `app/Mail/SystemMail.php`, `app/Jobs/SendEmailJob.php`, `config/resend.php`, `config/mail.php` | CURRENT-only Resend delivery layer |
| `config/services.php` | differs from RECOVERY **only** by the `resend.key` entry |
| `composer.json`, `composer.lock` | CURRENT adds `resend/resend-laravel`. Restoring would desync the installed `vendor/` and break the preserved email subsystem. Deliberately excluded. |
| `.env.example`, `.env.docker` | carry the CURRENT mail/Resend configuration |
| `frontend/src/pages/JoinNow.tsx` | Step 5 preserved work; the only real frontend difference (the other 177 were pure CRLF noise) |
| `.phpunit.result.cache` | runtime artefact, correctly not transferred |

## 4. Deliberate test-contract changes (3 files, 6 assertions)

These were **not** "make the suite green" edits. In each case an older test encoded the
**pre-hardening** contract that a CURRENT security fix deliberately replaced. Each change is
minimal, preserves the original test's *intent*, and is called out here for review.

| Test | Was | Now | Why |
|---|---|---|---|
| `InviteRegistrationFlowTest` ×3 | `422` + message | `400` + `success:false` + `code:VERIFICATION_FAILED` + same message | Uniform failure shape for email verification. The **message string is unchanged**, so only the status/fields moved. |
| `ServantLoginLifecycleTest` ×2 | `assertStatus(422)` | `assertStatus(400)` | Same. The trailing `assertNull($user->fresh()->email_verified_at)` (intent: not verified) is untouched. |
| `ServantLoginLifecycleTest` — *already verified account is idempotent* | asserted `200` + `"Email is already verified. You can log in."` | asserts `400` + `VERIFICATION_FAILED` | **This is the important one.** The old assertion *was the vulnerability*: a `200` "already verified" reply to a public, unauthenticated endpoint confirms the address is registered — a textbook account-enumeration oracle. `EmailVerificationTokenSecurityTest` (CURRENT-only) explicitly groups `$alreadyVerified` into the same uniform-400 set as unknown-address and wrong-token. The security fix wins. The idempotency **intent** is preserved by keeping the final `assertNotNull($user->fresh()->email_verified_at)` — state is genuinely unchanged. |

## 5. Verification

| Gate | Result |
|---|---|
| `php artisan test` (full) | **404 passed, 2 failed** (1,492 assertions) |
| `vendor/bin/phpstan analyse` (level max, `app/`) | **[OK] No errors** |
| `vendor/bin/pint --test` | **PASS — 505 files, 0 issues** |
| `scripts/check-lang-parity.php` | **PASS** — EN 123 / AR 123, exact parity |
| `npx tsc --noEmit` | **exit 0** |
| `npm run lint` (ESLint) | **clean** |
| `npm run check:i18n` | **PASS** |
| `npm run build` | **built in 3.13s**, PWA generated |
| Security-focused suites (22 files) | **320 passed, 2 failed** |

**Progression:** 195 failed → 23 → 8 → 3 → **2**.

The PHPUnit process no longer crashes, so the suite now reports a real summary at all.

## 6. `User::getScope()` — explicit anti-escalation check

`getScope()` is role-derived and **never trusts** the stored `scope` column. Verified
behaviourally across 9 cases (`scope_check.php`), including hostile/legacy stored values:

| Case | Stored | Effective | |
|---|---|---|---|
| platform_admin | `self` | `church` | stored ignored |
| admin | `self` | `church` | stored ignored |
| assistant_admin | `self` | `church` | stored ignored |
| stage_admin + `stage_id=7` | `church` | `stage` | stored ignored |
| stage_admin, **no** `stage_id` | `church` | `self` | **fails safe** |
| servant | `church` | `class` | stored ignored |
| member (legacy) | `church` | `self` | **no escalation** |
| member (legacy) | `stage` | `self` | **no escalation** |
| member (legacy) | `class` | `self` | **no escalation** |

**leaks = 0.** A `Member` carrying a legacy/tampered `scope` of `church` still acts as `Self`,
and a `StageAdmin` with no `stage_id` degrades to `Self` rather than widening.

## 7. Remaining problems (2 — both pre-existing, present in *both* repositories)

`Tests\Feature\TenantHierarchyIntegrityTest` — genuine cross-tenant isolation gaps.

1. **Church A admin can create a class inside Church B's stage** (gets `201`, test expects `404`).
2. **Church A admin can create a member bound to Church B's class** (gets `201`, test expects `403`).

Verified NOT caused by this recovery:

- `app/Http/Requests/StoreClasseRequest.php` is **byte-identical** in both repositories — the
  bulk restore therefore could not have removed a guard.
- The rule is `'stage_id' => ['required','integer','exists:stages,id']` — tenant-blind, no
  church ownership check. `ChurchScope` is a *global read* scope; it does not constrain
  `exists:` validation, so the controller/service receives a foreign `stage_id` and the row is
  written with a cross-tenant link.
- Both tests were **already failing in the pre-recovery baseline** (see §2).

`TenantHierarchyIntegrityTest.php` is CURRENT-only, so RECOVERY never had this test and never
had to satisfy it. The guard was never implemented in either tree. Per Step 11 this is new
hardening and was **deliberately not implemented here**; it is reported for the hardening queue.

Notably, the sibling checks that *do* exist pass: church admin → foreign `stage_id` for a
stage-admin user (`403`), and stage admin → foreign class (`403`).

## 8. Confidence

| Area | Confidence | Basis |
|---|---|---|
| 226-file bulk restore | **HIGH** | RECOVERY is a verified green baseline (358/358); 523 files already byte-identical; dependency check proved 0 symbol conflicts; Pint-clean on arrival |
| `tests/TestCase.php` | **HIGH** | Directly explains the fatal + 212 `BadMethodCallException`; both fixes carry explanatory comments; `ServantLoginLifecycleTest` now runs |
| `routes/api.php` | **HIGH** | Each removed guard is independently corroborated by a passing policy/middleware test; 11 controllers' tests went from failing to passing |
| Migration formatting | **HIGH** | Diffed individually; formatting-only |
| `User.php` | **HIGH** | Verified against migrations already present in CURRENT (`add_stage_scope_to_users`, `add_email_verification_token_expires_at`) and 46 passing `StageAdminScopeTest` cases |
| `VerifyEmailNotification` kept | **HIGH** | CURRENT is a strict superset; reverted would reintroduce token-in-`jobs.payload` |
| 3 test-contract changes | **MEDIUM–HIGH** | Security-motivated and minimal, but a human reviewer should ratify the `already-verified → 400` decision |
| 2 remaining failures | **HIGH** (diagnosis) | Proven pre-existing and present in both repos; **no fix attempted** |
| Overall | **HIGH for restoration**, **MEDIUM for absolute security** | 2 unresolved cross-tenant gaps remain |

## 9. Not done (Step 11 — out of scope by instruction)

FIX 3, 6, 7/8/9, 10–13, 15, 17–19 were **not** started. Baseline is restored and verified;
the §7 gaps are the natural next hardening items.

## 10. Reproducibility

- Pre-recovery backup (independent of Git, since `git checkout` is forbidden):
  `…\opencode\current-backup-pre-recovery\` (1,367 files)
- Recovery integrity manifest: `…\opencode\recovery_baseline.txt` (9,636 files,
  SHA-256 `EBB1A74A7325F1936BCBAB4ABB9851A08FC854E65FB8993BE6572B9228505A5B`) — re-verified
  identical at the end of the run.
- Line-ending policy: all 250 files written as **LF** to match the current working tree.
  Under `* text=auto` this is byte-equivalent in the object DB; `core.autocrlf=true` on this
  machine means a fresh checkout would be CRLF either way.
