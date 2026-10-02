# PHASE 1D — RELEASE BLOCKERS

Blocker definition applied exactly as mandated: P0, P1, confirmed secret exposure,
cross-tenant access, privilege escalation, destructive migration, broken authentication,
unrecoverable deployment risk, **unexpected release code**, failed critical test,
unresolved dependency vulnerability with confirmed relevant exploitability.

Normal P3/INFO observations are **not** blockers.

| ID | Finding | Severity | Evidence | Blocker? | Action |
| -- | ------- | -------- | -------- | -------- | ------ |
| RB-01 | Release candidate exists only as an **uncommitted working tree** (HEAD `bd37cf5` + 305 dirty entries). A deployment of "this exact state" is not addressable until it is committed and pushed — Phase 1 Gate A/J failed for precisely this reason ("nobody can say what code is running"). Phase 1D is forbidden from committing (§31). | Process / non-code prerequisite | `git rev-parse HEAD` = `bd37cf5`; `git status --short` = 305; nothing staged | **CONDITION** (not a code defect) | Commit + tag the reviewed tree on an authorised run; record the resulting SHA as the release SHA (Condition 1 of the decision) |
| RB-02 | No `UNKNOWN` release code | — | diff audit §23: 0 UNKNOWN | No | none |
| RB-03 | No `UNEXPECTED` release code | — | mtime proof: nothing source-level modified after 12:14 (Phase 1C) | No | none |
| RB-04 | Secrets: **0** detected across diff + all untracked text files | INFO | §24 pattern scan, 0 hits in every credential pattern group | No | none |
| RB-05 | Tenant isolation: no regression; 7/7 composite FKs `OK`; behavioural proof 4/4 on PostgreSQL 16.15 | — | `tenant:verify-schema` exit 0; `tenant:audit` clean | No | none |
| RB-06 | Privilege escalation: none found; matrix unchanged; `Permission::userHasPermission` fails closed | — | §13 + authorization test suites | No | none |
| RB-07 | Destructive migration: none in the release; all 108 migrations predate Phase 1C; composite-FK migration adds constraints only, refuses on orphans, has `down()` | — | §10, §11 | No | none |
| RB-08 | Broken authentication: none; 401/403/422/login/logout/verify/reset all green | — | §14 | No | none |
| RB-09 | Failed critical test: **none** — SQLite 560/0, PostgreSQL 566/0, frontend 83/0, PHPStan 0, Pint pass, tsc clean, ESLint clean, builds pass | — | §21 fresh runs | No | none |
| RB-10 | Dependency vulnerability: composer audit clean; npm audit 0 vulnerabilities (all severities); axios 1.20.0 single resolved node | — | §9, §21 | No | none |
| RB-11 | Rate-limit 500 defect (Phase 1C P0) remains fixed: 429 + headers + JSON shape on both engines | — | `RateLimitResponseTest` 8/8 | No | none |
| RB-12 | RR-04 / RR-16: `MAIL_MAILER` in production unknown; if `resend` without `RESEND_API_KEY`, queued notifications throw `ApiKeyIsMissing` and accumulate in `failed_jobs` | **P3** | §16 — failure is contained to the notification pathway; application unaffected | **No** (production-only gate) | Gate I (email) + Gate G (failed_jobs) in the post-deploy checklist |
| RB-13 | RR-14: stale room cell inventories created by pre-Phase-1C resizes (code is fixed; historical rows not repaired) | **P3** | Phase 1C R-11 | **No** | Condition: data audit of `event_rooms`/cells before/after deploy if accommodation data exists |
| RB-14 | RR-01: live HTTP path does not honour `Retry-After` for backoff (offline path fixed by R-08) | **P3** | Phase 1C R-18 deferred enhancement | **No** | accepted |
| RB-15 | RR-05: 5 historical migrations have SQLite-only/broken `down()` | **P3** | `scan-broken-migration-rollbacks.php` inventory | **No** | accepted — rollback limitation documented; forward migration is the release path |
| RB-16 | CI runs PHP 8.3 while local verification ran PHP 8.2.12 | INFO | `php -v` 8.2.12 vs `.github` workflow provisioning 8.3 | **No** | Condition: require a green CI run on the release SHA |
| RB-17 | Post-deploy gates (deployed SHA, production schema/data, backup, restore, worker, scheduler, storage, Resend, env vars, observability) cannot be proven pre-deploy | N/A (not a failure) | mandate §27 | **No** | Gates A–J in `PHASE_1D_POST_DEPLOY_CHECKLIST.md` |

---

## DECISION SUMMARY

```text
Code-level blockers (P0 / P1 / secret / cross-tenant / escalation / destructive
migration / broken auth / unexpected code / failed critical test / exploitable
dependency):                                                       NONE

Release-blocking blockers:                                         NONE

Non-code conditions that must be satisfied before deployment:      4  (see executive summary)
```

No finding in this matrix required reopening Phase 1C (Rule 4: no release-blocking,
independently reproducible defect was discovered).
