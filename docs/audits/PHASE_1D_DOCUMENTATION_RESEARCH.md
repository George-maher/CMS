# PHASE 1D — DOCUMENTATION RESEARCH

Research performed **before** any release claim was made. Every behavioural assertion in
the Phase 1D documents traces to one of the sources below. Where a claim could not be
grounded in documentation, it is recorded as an open gate instead of an assumption.

Date of research: 2026-10-01 (Phase 1D session).
Repository: `main` @ `bd37cf5fd510d2ccdeb6d32bb65b179db2181d77` + dirty working tree.

---

## 1. SOURCE PRIORITY APPLIED

| # | Source | Version consulted | Used for |
|---|--------|-------------------|----------|
| 1 | Current repository (code, config, tests) | working tree @ `bd37cf5` | every "what the code does" claim |
| 2 | Phase 0 / 1 / 1B / 1C evidence | `docs/audits/*.md` | continuity, closed gates, prior findings |
| 3 | Official Laravel documentation | 12.x (installed: **Laravel 12.69.3**) | migrations, notifications, queue encryption |
| 4 | Official Composer documentation | current CLI reference (`getcomposer.org/doc/03-cli.md`) | `install --no-dev`, `--optimize-autoloader` |
| 5 | Official PostgreSQL documentation | 16 (verification engine: **16.15-1.pgdg13+2**) | `NOT VALID` / `VALIDATE CONSTRAINT` semantics |
| 6 | Official React / TypeScript / Vite documentation | installed toolchain | PWA build, `navigateFallbackDenylist` |
| 7 | Official framework **source for the exact installed version** | `vendor/laravel/framework` (12.69.3) | exception-render callback order, `ShouldBeEncrypted` |
| 8 | Railway / Vercel / Resend documentation | not consulted in depth | deferred — no production claims are made in Phase 1D |
| 9 | OWASP / authoritative security references | via Phase 0/1B/1C records | carried forward, not re-derived |

Anything under row 8 is deliberately **not** asserted: Phase 1D makes no claim about
production provider behaviour. Those are POST-DEPLOYMENT VERIFICATION GATES.

---

## 2. DOCUMENTATION ITEMS RESOLVED IN PHASE 1D

### D-1D-01 — Composer production build semantics (official Composer CLI docs)

Claim under review: `composer install --no-dev --prefer-dist --optimize-autoloader`
produces the release dependency set.

Documented behaviour:

* `--no-dev` — "Disables the installation of packages listed in `require-dev`."
  Dev tools (PHPUnit, Larastan, Pint, Mockery) are **not** part of the deployed tree.
* `--optimize-autoloader` (alias `-o`) — "Optimizes the autoloader when autoloading
  happening automatically … converts PSR-0/PSR-4 to classmap." It does **not** by
  itself make the classmap authoritative; `--classmap-authoritative` is a separate
  flag, so unknown classes still fall through to PSR-4 resolution.

**Applied in Phase 1D:** the command was executed locally (not deployed), the app was
then booted and route-registered to prove the production autoloader is functional, and
dev dependencies were restored afterwards with a plain `composer install`.
Observed: classmap of **4930 entries / 614 KB**, `php artisan --version` → 12.69.3,
`route:list --json` → 224 routes, `composer.lock` byte-unchanged (mtime preserved).

### D-1D-02 — Laravel migration execution in production (official Laravel 12 docs)

Documented: `php artisan migrate` refuses to run when `APP_ENV=production` unless
`--force` is passed. The Phase 1D runbook therefore states the migration command with
`--force` and requires a backup gate **before** it. No migration is run by Phase 1D.

### D-1D-03 — `ShouldBeEncrypted` (official framework source, exact version)

Claim under review: the Phase 1C R-10 notification fix puts reset tokens out of readable
queue payloads.

Verified in `vendor/laravel/framework` (12.69.3), not inferred:

* `Illuminate/Notifications/SendQueuedNotifications.php`
  `public $shouldBeEncrypted = false;`
  `… $this->shouldBeEncrypted = $notification instanceof ShouldBeEncrypted;`
* `Illuminate/Queue/Queue.php` honours `ShouldBeEncrypted` when serialising the job.

Both `ResetPasswordNotification` and `PasswordResetRequestApprovedNotification` declare
`implements ShouldBeEncrypted, ShouldQueue`, so the marker is actually consumed by the
framework. **R-10 reconfirmed.**

### D-1D-04 — Exception render-callback precedence (official framework source, exact order)

Carried from Phase 1C D-02 and re-verified in this tree:
`Illuminate\Foundation\Exceptions\Handler::renderViaCallbacks()` returns the **first
non-null** response, iterating callbacks in registration order. Consequences that
Phase 1D re-checked in `backend/bootstrap/app.php`:

* typed callbacks (`NotFoundHttpException`, `AccessDeniedHttpException`,
  `ValidationException`, `ThrottleRequestsException`, `HttpResponseException`) are
  registered **before** the generic `HttpException` callback, which is registered
  **before** the catch-all `Throwable`;
* an `HttpResponseException` produced by a throttle `response()` callback therefore
  reaches its own callback instead of being converted into a 500.

**R-01 and R-07 reconfirmed by code reading + tests (not re-opened).**

### D-1D-05 — PostgreSQL `NOT VALID` composite FKs (official PostgreSQL 16 docs)

The composite-FK migration adds constraints as `NOT VALID` only when orphan rows exist,
because PostgreSQL validates existing rows at `ADD CONSTRAINT` time. Documented
semantics applied:

* an unvalidated constraint still applies to **all new/updated rows**;
* `ALTER TABLE … VALIDATE CONSTRAINT` completes it later without taking an exclusive
  lock for the validation scan.

`php artisan tenant:verify-schema` reports `NOT VALID` constraints explicitly and prints
the exact `VALIDATE CONSTRAINT` statement. In the disposable PG 16.15 run all seven
constraints came back `OK` (fully validated — no orphans existed).

### D-1D-06 — Vite PWA `navigateFallbackDenylist` (installed `vite-plugin-pwa` config)

Documented semantics: `navigateFallback` serves `index.html` for SPA navigations,
and `navigateFallbackDenylist` excludes matching paths from that rewrite so that API
requests fall through to the network. The project sets
`navigateFallback: '/index.html'` with `navigateFallbackDenylist: [/^\/api\//]`, and
`runtimeCaching` covers only Google Fonts. Precache `globPatterns` are
`js/css/html/woff2/svg/png/jpg/jpeg/gif/ico` — no API responses.

Verified against the freshly generated `dist/sw.js` (see §20 of the release review).

### D-1D-07 — npm audit threshold semantics

`npm audit --audit-level=high` exits non-zero only for advisories of high/critical
severity. Phase 1D ran it at the mandated threshold and additionally recorded
`found 0 vulnerabilities` (all severities).

---

## 3. ITEMS DELIBERATELY LEFT TO DOCUMENTATION-FREE VERIFICATION

| Item | Why no documentation claim was made |
|------|-------------------------------------|
| Railway deploy mechanics, health checks, worker/scheduler provisioning | production provider — Gate A/G/H are post-deploy only |
| Vercel build output and edge rewrites in production | production provider — Gate A is post-deploy only |
| Resend deliverability, sender/domain authentication | production provider + not configured — Gate I is post-deploy only |
| Behaviour of the production PostgreSQL data | production data — Gate D is post-deploy only |
| Whether the deployed SHA equals `bd37cf5` + tree | deployment has not occurred — Gate A is post-deploy only |

These are **not** Phase 1D failures; they are the reason the executive decision is
conditional rather than unconditional.

---

## 4. CONTINUITY MAP (documentation located, not assumed)

```
Phase 0   docs/audits/PHASE_0_*.md            (9 documents)
   ↓      inventory, endpoint/service/database matrices, test-gap matrix, risks
Phase 1   docs/audits/PHASE_1_*.md            (11 documents)
   ↓      production ground truth, infrastructure, PostgreSQL, tenant, email,
   ↓      queue, backup/restore drill, deployment verification, risks
Phase 1B  docs/audits/PHASE_1B_GATE_CLOSURE.md
   ↓      Gate B/C/D PASS on disposable PostgreSQL, concurrency verified,
   ↓      P1-02 root cause proven, U-8 dependency advisory recorded
Phase 1C  docs/audits/PHASE_1C_*.md            (8 documents)
   ↓      7 FIXED / 3 VERIFIED-NOT-REQUIRED / 9 DOCUMENTED / 1 NOT-FIXED-BY-DESIGN
   ↓      verdict: READY FOR PRE-PRODUCTION RELEASE REVIEW
Phase 1D  docs/audits/PHASE_1D_*.md            (9 documents — this phase)
          pre-production release review → decision
```

Supporting non-audit documentation read for this phase:

* `backend/docs/TENANT_RULES.md` — §4 (`exists:` vs ownership), §5 (`ChurchScope`),
  §7 (Platform Admin semantics), §6 (composite FKs), §11 (offline session isolation),
  §13 (migration discipline).
* `AGENTS.md` — global execution rules, audit-logging rules, test pitfalls,
  documentation-first rule.
* `backend/database/rehearsal/production_like_fixture.sql` — the production-like
  fixture used by `backend/scripts/rehearse-production-migration.sh`.
* `backend/scripts/scan-broken-migration-rollbacks.php` — the source of the historical
  broken-`down()` inventory (RR-05 / R-04).

---

## 5. RESEARCH CONCLUSIONS

1. No documentation consulted in Phase 1D contradicts a Phase 1C conclusion.
2. Two Phase 1C **evidence lines** were sharpened (not their conclusions):
   * R-13 evidence cited `composer show resend/resend`; the installed package is
     `resend/resend-laravel` v1.4.0. See the release review §16 — the conclusion
     (`.env.example` is inert, P3, DOCUMENTED) is unchanged.
   * R-20 ("`EmailService`/`SendEmailJob` are dead code") remains true — 0 external
     callers — but the live notification pathway is **not** dead: five
     `->notify(...)` call sites exist. Both statements are recorded in §16.
3. Nothing found in this research justifies reopening Phase 1C.
