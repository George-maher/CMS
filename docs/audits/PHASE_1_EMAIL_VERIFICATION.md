# PHASE 1 — EMAIL VERIFICATION

**Audit date:** 2026-09-30
**Target:** whether production email actually works.

---

## 1. GATE I — EMAIL

```
GATE I — EMAIL:  UNKNOWN
```

```
Application send path:  VERIFIED (code exists; the primary queued path is dead)
Provider acceptance:     UNKNOWN
Actual delivery:         UNKNOWN
Bounce handling:        UNKNOWN
Complaint handling:     UNKNOWN
```

**No email was sent. No provider was contacted. No configuration was changed.**

---

## 2. THE DECISIVE FINDING: the queued-mail pathway is dead code

This was established in Phase 0 and is **unchanged**. The trace, in full:

| Step | Evidence |
|---|---|
| **1. One send site** | `grep 'Mail::'` across `backend/` → exactly one hit: `app/Jobs/SendEmailJob.php:48` → `Mail::send(new SystemMail(...))` |
| **2. One dispatcher** | `SendEmailJob` is dispatched only by `EmailService` (10 call sites: `:20, :32, :44, :54, :73, :85, :101, :116, :128, :143`) |
| **3. No caller** | `grep -E 'EmailService\|sendNotification\|sendInviteEmail\|sendWelcomeEmail\|sendFeedbackReply\|sendEventNotification\|sendAttendanceNotification\|sendApplicationApproved\|sendApplicationRejected'` across `app/Http/Controllers/` → **no matches** |
| **4. ⇒** | **The entire queued-mail pathway cannot execute.** |
| **5. What is live** | 9 `ShouldQueue` Notification classes via `->notify()`. **4 are never dispatched by any code**: `PasswordChangedNotification`, `PasswordResetRequestSubmittedNotification`, `PasswordResetRequestApprovedNotification`, `PasswordResetRequestRejectedNotification` |
| **6. ⇒** | At most **5** notification classes can ever reach the queue |

**Phase 1 could not verify whether `origin/main` differs.** The live build predates the working tree (no `X-Request-Id`), and `EmailService` call sites were reworked during hardening, so the *working tree* finding is certain while the *production* finding is a strong inference.

**Consequence:** even with a perfectly configured Resend account, **most of what the system appears to do by email, it does not do.**

---

## 3. Configuration state

### 3.1 The four-way conflict (CONFIRMED STILL PRESENT)

| Source | Claim |
|---|---|
| `AGENTS.md` — Common Pitfalls #10 | *"Resend removed: Email sending is NOT implemented — use in-app notifications"* |
| `README.md` — Tech Stack | *"Email — Authenticated SMTP via Laravel Mail; production requires explicit SMTP credentials"* |
| `backend/.env.example:53-62` | `MAIL_MAILER=resend`, `MAIL_HOST=smtp.resend.com`, `MAIL_PORT=587`, `RESEND_API_KEY=` |
| `composer.json:13` | `resend/resend-laravel: ^1.4` — a **production** dependency |
| `config/mail.php:64-66` | `'resend' => ['transport' => 'resend']` mailer defined |
| `config/resend.php` | full config, `api_key` from `RESEND_API_KEY` |
| `config/mail.php:47` | the **smtp** mailer's password defaults to `env('RESEND_API_KEY')` — Resend is load-bearing for SMTP too |
| `app/Services/AuthService.php:214` (comment) | *"Resend removed 2026-08-22"* |

**CONFLICT — code takes precedence. The truth is: the package, config, and mailer all exist; the send path is dead.**

### 3.2 Defaults are unsafe

| Setting | Value | Risk |
|---|---|---|
| `config/mail.php:17` `default` | **`env('MAIL_MAILER','log')`** | 🔴 **If production does not set `MAIL_MAILER`, all mail is written to `storage/logs/laravel.log`.** |
| `config/mail.php:114` `from.address` | `env('MAIL_FROM_ADDRESS','hello@example.com')` | 🔴 placeholder default |
| `MailConfigurationValidator::NON_DELIVERING_DRIVERS` | `['log','array','null','fail']` | the guard exists |
| `EXEMPT_ENVIRONMENTS` | `['local','testing']` | correct |
| `shouldEnforceAtBoot()` | **called from nowhere** | 🔴 **the guard is never enforced** |

**The single live protection** is `EmailVerificationService::dispatch()` (`:228-239`), which refuses to send when the transport cannot deliver:
```php
if (! $this->mailConfiguration->isVerificationDeliveryConfigured()) {
    // Never hand a token to a transport that will write it to a log.
    Log::error('email_verification', ['event' => 'verification_dispatch_refused', …]);
    return false;
}
```
**This covers 1 of 9 notification classes. The other 8 are unguarded.**

**⇒ If `MAIL_MAILER` is unset in production, 8 of 9 notification bodies — including welcome and church-application-rejection messages — are written to the container log, and users receive nothing, with no error surfaced to them.**

**Whether `MAIL_MAILER` is set in production: NOT VERIFIED** (no Railway env access). **This is a high-priority env-var check.**

---

## 4. Provider state — NOT VERIFIED, NO ACCESS

| Item | Status | Basis |
|---|---|---|
| Resend account exists | **NOT VERIFIED** | no account access |
| API key present | **NOT VERIFIED** | `RESEND_API_KEY` absent from local `.env`; production unknown |
| `MAIL_MAILER` value in production | **NOT VERIFIED** | — |
| **Default mailer in production** | **NOT VERIFIED** — config default is `log` | — |
| From address | **NOT VERIFIED** | — |
| Reply-To | **NOT VERIFIED** | `config/resend.php` defines it; no `resend` mailer wiring found to consume it |
| Domain verification | **NOT VERIFIED** | — |
| **SPF** | **NOT VERIFIED** | — |
| **DKIM** | **NOT VERIFIED** | — |
| **DMARC** | **NOT VERIFIED** | — |
| Delivery / bounce / complaint status | **NOT VERIFIED** | — |
| Webhook configuration | **NOT VERIFIED** | none in repository |
| Retry behaviour | **NOT VERIFIED** | — |

**EXTERNAL INFRASTRUCTURE — NOT VERIFIED.** No DNS query for SPF/DKIM/DMARC was made, because those records belong to a domain whose ownership is not established (the documented `churchmanager.app` is `NXDOMAIN`; the live hosts are Railway/Vercel subdomains with provider-managed mail).

---

## 5. LIVE PROBES (§26)

### 5.1 What was probed, and what it showed

| Probe | Result | Interpretation |
|---|---|---|
| `POST /api/v1/auth/login` ×7 with a reserved `.invalid` address | 401 ×5, then **500** (rate limit) | **No email path involved.** Confirms auth + reveals the rate-limit defect. |
| Any endpoint that triggers a notification | **NOT CALLED** | §51 forbids sending email or creating records |

**No probe was performed that could cause an email to be sent.** Doing so would require a real recipient address, which §51 prohibits ("Do not send test emails to arbitrary users"; "If a controlled test recipient is explicitly authorized, document the test").

### 5.2 The in-app notification channel is separate and unaffected

`NotificationService` writes `notifications` rows via `DB::table('notifications')->insert()` (`NotificationService.php:91`) — **in-app only, no mail.** This is the channel the Phase 0 hardening actually relies on, and it is unaffected by the mail configuration.

**⇒ In-app notifications presumably work; email almost certainly does not, or works only for the 5 dispatchable classes with a configured provider.**

---

## 6. MAILER SAFETY (§27)

| Check | Status |
|---|---|
| Does production default to `log`? | **NOT VERIFIED** — config default is `log`; production value unknown |
| Is `shouldEnforceAtBoot()` actually invoked? | **NO — VERIFIED absent from all call sites** |
| Do email features silently degrade into logging? | **POSSIBLY — depends on the production `MAIL_MAILER` value** |
| Can users receive no notification despite a successful write? | **YES, for the 4 never-dispatched classes — unconditionally** |
| Is queueing active? | **Configured yes** (`database` driver, `after_commit=true`); **actual execution NOT VERIFIED** |
| Are notification classes actually dispatched? | **5 of 9 — VERIFIED from code** |

---

## 7. WHAT EMAIL WOULD BE NEEDED TO FUNCTION

Not a remediation plan — a precondition list, so the gap is explicit:

1. `MAIL_MAILER` set to a real transport (not `log`)
2. `MAIL_FROM_ADDRESS` set to a **verified domain**
3. `RESEND_API_KEY` (or `MAIL_USERNAME`/`MAIL_PASSWORD` for SMTP) present and valid
4. The sending domain verified in Resend with **SPF + DKIM + DMARC** published
5. A **live caller** for `EmailService`, or the dead path removed
6. The **4 undispatched notification classes** either wired up or deleted
7. A **non-delivering-transport guard applied to all 9 classes**, not 1
8. `shouldEnforceAtBoot()` actually invoked
9. Queue worker confirmed running
10. Bounce/complaint webhooks configured

**Items 1–4 are configuration. Items 5–8 are code. None is verified as satisfied in production.**

---

## 8. CONFIDENCE

| Claim | Status | Basis |
|---|---|---|
| Exactly one `Mail::send` site exists in the backend | **VERIFIED** | grep |
| It is dispatched only by `EmailService` | **VERIFIED** | grep |
| `EmailService` has no production caller in the working tree | **VERIFIED** | grep |
| 4 of 9 notification classes are never dispatched | **VERIFIED** | grep |
| `config/mail.php` default mailer is `log` | **VERIFIED** | `config/mail.php:17` |
| `shouldEnforceAtBoot()` is called from nowhere | **VERIFIED** | grep |
| Only 1 of 9 notifications is guarded against a non-delivering transport | **VERIFIED** | source |
| Resend package/config/mailer all present | **VERIFIED** | `composer.json`, `config/` |
| **Production `MAIL_MAILER` value** | **NOT VERIFIED** | no Railway access |
| **Whether Resend is configured in production** | **NOT VERIFIED** | no provider access |
| **Whether any email is delivered** | **NOT VERIFIED** | no delivery attempted or observed |
| SPF / DKIM / DMARC | **NOT VERIFIED** | no DNS/provider access |
| Bounce / complaint handling | **NOT VERIFIED** | — |
| 4-way documentation conflict | **VERIFIED** | Phase 0 + this document |

---

## 9. PLAIN STATEMENT

**The application's email subsystem is, in its current form, substantially non-functional by construction — not by configuration.**

The one physical send path has no caller. Four of nine notification classes are never dispatched. The default mailer writes to a log file. The guard that would catch a non-delivering transport covers one class out of nine and is never enforced at boot.

**Whether any of this differs in production is NOT VERIFIED**, because the live build predates the working tree and no provider access exists.

**What can be said with confidence:** *if* production behaves like the code, users are receiving very little email, and the ones who configured the system were told in `README.md` that email works.

**Gate I remains `UNKNOWN`. No email was sent, and no claim about delivery is made.**

**Highest-value single check:** read the production `MAIL_MAILER`, `MAIL_FROM_ADDRESS` and `RESEND_API_KEY` **names and presence** from the Railway dashboard. If `MAIL_MAILER` is unset or `log`, the diagnosis is confirmed in one glance.
