# PHASE 1D — RISK REVIEW

Reassessment of the Phase 1C register (`PHASE_1C_RISK_REGISTER.md`, 14 risks:
RR-01…RR-14 — 0 P0, 0 P1, 0 P2, 9 P3, 5 INFO).

Per mandate §28, no risk was automatically fixed. Each was re-tested against the current
tree. **No severity was raised; no risk became a release blocker.**

Legend: ✔ valid · ✖ invalid · ↳ unchanged

| ID | Sev (1C) | Still valid? | Evidence still valid? | Severity still valid? | Mitigation still valid? | Prod verification required? | Accepted? | Blocker? |
|----|----------|--------------|-----------------------|-----------------------|-------------------------|-----------------------------|-----------|----------|
| RR-01 | P3 | ✔ | ✔ re-verified: offline path honours `Retry-After` via `backoffDelayFor()`; live HTTP path still does not use the header | ↳ P3 | ✔ (deferred enhancement R-18) | No | **Yes** | No |
| RR-02 | P3 | ✔ `RateLimiter` depends on `CACHE_STORE` | ✔ config reviewed in §19 | ↳ P3 | ✔ documented config rule: production cache must be shared/persistent | **Yes** — Gate A/B env confirmation | **Yes** | No |
| RR-03 | P3 | ✔ single-node cache/session/queue drivers | ✔ | ↳ P3 | ✔ by design for this scale | No | **Yes** | No |
| RR-04 | P3 | ✔ local mail driver = `log` | **✔ but evidence sharpened** — see correction below | ↳ P3 | ✔ re-scoped to Gate I | **Yes** — Gate I + Gate G (`failed_jobs`) | **Yes** | No |
| RR-05 | P3 | ✔ 5 historical SQLite-only/broken `down()` | ✔ inventory re-confirmed (`scan-broken-migration-rollbacks.php`); no migration edited in 1C/1D | ↳ P3 | ✔ fix-forward + backup; documented in runbook §4 | **Yes** — backup/restore = Gates E/F | **Yes** (NOT-FIXED-BY-DESIGN) | No |
| RR-06 | INFO | ✔ rollback lint not in CI | ✔ | ↳ INFO | ✔ (Phase 2 CI) | No | **Yes** | No |
| RR-07 | P3 | ✔ `removeServant` cosmetic asymmetry | ✔ | ↳ P3 | ✔ cosmetic only | No | **Yes** | No |
| RR-08 | INFO | ✔ debug detail double-gated (`APP_DEBUG` + non-JSON) | ✔ | ↳ INFO | ✔ must confirm `APP_DEBUG=false` → runbook B3 | **Yes** — env confirmation | **Yes** (NOT-FIXED-BY-DESIGN) | No |
| RR-09 | INFO | ✔ 6 engine-specific skips exist **only** on SQLite | ✔ re-run: SQLite 560 + **6 skipped**; PostgreSQL **566 + 0 skipped** | ↳ INFO | ✔ tests execute on PostgreSQL | No | **Yes** (resolved-as-observed) | No |
| RR-10 | INFO | ✔ error envelope not uniform across every legacy failure | ✔ | ↳ INFO | ✔ documented (R-16) | No | **Yes** | No |
| RR-11 | INFO | ✔ `request_id` absent from **typed** 4xx bodies | ✔ | ↳ INFO | ✔ documented (R-17); `request_id` present in headers/logs | No | **Yes** | No |
| RR-12 | P3 (latent) | ✔ unrouted permission methods; `role_permission` pivot has no `church_id` | ✔ re-verified: `userHasPermission()` fails closed (defaults when un-seeded, defaults when mapping empty) — **an empty pivot cannot open a privilege hole** | ↳ P3 | ✔ fail-closed design + authorization suites | **Yes** — Gate J authorization spot-checks | **Yes** | No |
| RR-13 | P3 | ✔ 3 flows lack button pending states | ✔ | ↳ P3 | ✔ UX-only | No | **Yes** | No |
| RR-14 | P3 | ✔ stale room cell inventories from **pre-fix** resizes | ✔ code fix proven (`EventRoomCapacityResizeTest` 4/4); historical rows untouched | ↳ P3 | ✔ documented data audit | **Yes** — data audit before/after deploy if accommodation data exists | **Yes** | No |

---

## 1. SPOTLIGHT RR-14 (mandate §28)

* **Still valid?** Yes — the *data* remediation was deliberately not performed in
  Phase 1C (only the code path was fixed).
* **Evidence still valid?** Yes — the fix is proven by a fail-first test that was
  re-executed in Phase 1D on both engines.
* **Severity?** P3 — wrong cell inventory on rooms resized **before** the fix.
* **Mitigation?** A bounded `SELECT` of rooms whose `capacity` disagrees with the count
  of their cells, executed as a read-only audit.
* **Production verification required?** Yes, **only if** event accommodation data exists.
* **Accepted?** Yes, with the audit listed as a condition.
* **Blocker?** No — no new incorrect data can be created by the fixed code.

## 2. SPOTLIGHT RR-04 (email, mandate §16/§28)

* **Still valid?** Yes — `MAIL_MAILER` for local/test is `log`/`array`, production unknown.
* **Evidence sharpened:** the Phase 1C line "Resend was removed from the dependency set"
  is **incorrect** — `resend/resend-laravel` v1.4.0 is installed, discovered, and
  registers a `resend` transport in `config/mail.php`. The conclusion
  (`.env.example` value is inert → P3 → DOCUMENTED) is unchanged.
* **Severity?** P3 — no application function depends on mail delivery.
* **Mitigation?** Gate I (mailer + key + test delivery) and Gate G (`failed_jobs`).
* **Production verification required?** Yes.
* **Accepted?** Yes.
* **Blocker?** No.

## 3. SPOTLIGHT RR-13 (queue, mandate §16)

Phase 1C status `VERIFIED-NOT-REQUIRED`. Re-checked: `config/queue.php` uses
`after_commit = true` for the database connection, `failed` table configured, worker +
scheduler provisioning captured in the runbook. **Still valid as verified; not a risk.**

## 4. MANDATE-REFERENCED IDs

| Mandate ref | Meaning in this review |
|-------------|------------------------|
| `RR-04` | mail driver / dead-code pathway → §2 above, Gate I |
| `RR-13` | queue semantics → §3 above, Gate G |
| `RR-20` | **not a risk-register ID** — it is Phase 1C remediation item **R-20**
             (`EmailService`/`SendEmailJob` dead code). Re-verified: 0 external callers.
             Recorded in `PHASE_1D_RELEASE_REVIEW.md` §16 as DOCUMENTED, non-blocker. |
| `RR-14` | §1 above, conditional data audit |

---

## 5. REGISTER TALLY (unchanged)

```text
Total: 14   (RR-01 … RR-14)
P0: 0     P1: 0     P2: 0     P3: 9     INFO: 5
New risks introduced by Phase 1D: 0
Risks promoted to blocker: 0
Risks reopened for code change: 0
```

All fourteen remain **accepted** at their Phase 1C severity, with production
verification attached where the risk can only be observed after deployment.
