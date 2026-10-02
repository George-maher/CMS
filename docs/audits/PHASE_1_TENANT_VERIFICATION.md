# PHASE 1 — TENANT VERIFICATION

**Audit date:** 2026-09-30
**Target:** production multi-tenant isolation, live system

---

## 1. GATE C — TENANT SCHEMA: **UNKNOWN** (evidence points to FAIL)
## 2. GATE D — TENANT DATA: **UNKNOWN**

```
GATE C — TENANT SCHEMA:  UNKNOWN   (strong evidence it is NOT in the deployed build)
GATE D — TENANT DATA:   UNKNOWN   (no audit could be run)
```

**Neither gate could be closed.** No database client, no production credential, and — decisively — **the audit tooling that would answer both questions does not exist in the deployed build.**

---

## 3. What "tenant isolation" rests on, and where each layer actually stands

Phase 0 established a **four-layer** defence. Phase 1 evaluated each against production reality:

| Layer | Mechanism | In deployed build? | In production? | Confidence |
|---|---|---|---|---|
| **L1 — Request filter** | `ChurchScope` global scope on 15 models | ✅ in `origin/main` | ✅ active (401s prove middleware runs) | **VERIFIED** |
| **L2 — Route gate** | `permission:*` / `role:*` middleware | ✅ in `origin/main` | ✅ active (403/401 contract observed) | **VERIFIED** |
| **L3 — Application check** | `ScopeResolver::canAccessUser/Class/Stage`, policies | ✅ in `origin/main` | ⚠️ **cannot be exercised without a session** | **PARTIALLY VERIFIED** |
| **L4 — Database invariant** | 7 composite tenant FKs | ❌ **ABSENT from `origin/main`** | ❌ **inferred absent** | **NOT VERIFIED (inferred FAIL)** |

**The database layer — the only layer that cannot be bypassed by an application bug, a forgotten call site, a job, or a console command — is the one that is missing from the deployed build.**

---

## 4. L1 — `ChurchScope`: confirmed present and working

### 4.1 Runtime evidence that the middleware stack executes

| Probe | Result | Proves |
|---|---|---|
| `GET /api/v1/auth/me` (no token) | **401** `{"code":"UNAUTHORIZED"}` | `auth:sanctum` active |
| `GET /api/v1/stages` (no token) | **401** | same |
| `GET /api/v1/users` (no token) | **401** | same |
| `GET /api/v1/platform/dashboard` (no token) | **401** | same |
| Forced 404 on `/api/v1/does-not-exist-xyz` | **404** `{"code":"NOT_FOUND"}` | the typed JSON error contract from `bootstrap/app.php` is deployed |
| `X-RateLimit-Limit: 60` on API responses | present | `throttle:*` middleware active |
| CSP `default-src 'none'` on API responses | present | production `nginx.conf` deployed |

**The deployed build's middleware and exception stack matches `origin/main`, not the working tree** (no `X-Request-Id`). This is consistent with L1/L2 being fully present.

### 4.2 The fail-closed `ChurchScope` branches cannot be exercised

`ChurchScope` (working tree, `app/Models/Scopes/ChurchScope.php`) has two fail-closed branches:
- unauthenticated **routed** request → `whereRaw('1 = 0')`
- authenticated non-platform user with `church_id = NULL` → `where(church_id, 0)`

**Verifying either requires an authenticated session, which was not created.** Phase 0 covered these in `TenantArchitectureTest` and `PlatformAdminAuthorizationMatrixTest:272` against SQLite. **Their production behaviour is NOT VERIFIED.**

---

## 5. L3 — Application-level authorization: cannot be exercised

**No authenticated request was made to production.** Creating one would require either a credential or registering a real account — and §51 explicitly forbids creating users, sending email, or producing persistent records.

Consequently **all** of the following remain unverified in production:

| Capability | Phase 0 status | Production status |
|---|---|---|
| `admin` sees only own church | covered by `TenantIsolationMatrixTest` on SQLite | **NOT VERIFIED** |
| `stage_admin` cannot reach other stages | covered by `StageAdminScopeTest` (44 tests) on SQLite | **NOT VERIFIED** |
| `platform_admin` blocked from church endpoints | covered by `PlatformAdminAuthorizationMatrixTest` on SQLite | **NOT VERIFIED** |
| `platform_admin` blocked from `POST /users` | covered by `CreateUserStageOwnershipTest:404` on SQLite | **NOT VERIFIED** |
| `member` forced to self on attendance reads | covered on SQLite | **NOT VERIFIED** |
| `role_permission` seeded as expected | `Permission::userHasPermission` falls back to hard-coded defaults if the pivot is empty | **NOT VERIFIED — U-5** |
| A member with `church_id = NULL` sees zero rows | covered on SQLite | **NOT VERIFIED** |

**The single most consequential unverified item is U-5: if production `role_permission` is empty or partially seeded, authorization silently falls back to the hard-coded default map.** The application would still *function*, and would still *look* correct, while behaving by a different rulebook than the database implies. Only an authenticated multi-role test or a direct DB read can resolve this.

---

## 6. L4 — Database invariant: the critical gap

### 6.1 Evidence chain

**Step 1 — the migration is not committed.** `git status` shows it untracked:
```
?? backend/database/migrations/2026_09_29_000001_add_composite_tenant_foreign_keys.php
```

**Step 2 — it is not in `main`.** `git cat-file -e origin/main:<path>` fails. Same for `TenantConsistencyService`, `TenantAudit`, `TenantVerifySchema`, `AssignRequestId`, and 12 security tests.

**Step 3 — it is not in the Railway bot branch** `647672f` (pushed by `railway-app[bot]`, 2026-07-12).

**Step 4 — live corroboration.** Production API responses contain **no `X-Request-Id`**, while the working tree emits one on every request. `AssignRequestId` is untracked. ⇒ **the deployed build predates the working tree.**

**Step 5 — no other mechanism could have created the constraints.** The constraints are defined **only** in that one migration. No alternative migration, no manual `ALTER TABLE` script, and no documentation records a manual application. `2026_06_25_000001` is the only migration making external calls, and it only manages Supabase buckets.

### 6.2 Conclusion

```
INFERENCE (high confidence, not catalog-verified):
  Production does NOT have the 7 composite tenant foreign keys.
```

**This is an inference. It is not a catalog observation and is not labelled VERIFIED.** It would be falsified by `php artisan tenant:verify-schema` exiting 0 — which would mean Railway deployed from a source other than `main` or the Railway branch, or that the constraints were created manually.

### 6.3 Why this is the highest-severity finding in Phase 1

**L1–L3 are all PHP-layer controls. Every one of them is bypassable by:**

| Bypass route | Why L1–L3 do not stop it |
|---|---|
| A missed `ScopeResolver` call in a new controller | No global scope on `User`; `canAccessUser` is opt-in per call site |
| A service called from a job or console command | Middleware does not run; `ChurchScope` applies **no filter at all** in console/queue (Phase 0 R-18) |
| A raw `DB::table()->update()` | Bypasses Eloquent, global scopes, and casts — **22 such sites exist** (Phase 0 DB Matrix §8) |
| A future code change that forgets the policy | **8 of 13 registered policies are already never invoked** — the pattern is established, not hypothetical |
| A `withoutGlobalScope()` call | 31 such sites exist today; 28 are legitimate, and the trait makes it a normal, supported operation |

**Only a database constraint cannot be bypassed this way.** And that is precisely the layer missing from production.

**Critically:** the repository itself documents that this exact failure already happened once.

> *"The composite tenant foreign key migration contained `church_id IS NOT (SELECT …)`, which SQLite accepts and PostgreSQL rejects as a syntax error. `php artisan migrate` therefore aborted on the production driver, so **none of the tenant constraints were ever created** — while every SQLite test stayed green."*
> — `.github/workflows/ci.yml:52-64`

**The fix for that incident is uncommitted. Production therefore remains in the pre-fix state.**

---

## 7. Data-level tenant integrity — NOT ASSESSED

**No query was run against production data.** `tenant:audit` is absent from the deployed build, so even with database access the intended tool is unavailable.

The read-only SQL that would answer this is prepared in `PHASE_1_POSTGRESQL_VERIFICATION.md` §5.1 and was **not executed**.

**What is known about production data, from the public API only:**

| Fact | Evidence |
|---|---|
| At least 2 churches exist | `/api/v1/churches/active` → ids 117, 129 |
| `daily_verses` is populated | `/api/v1/verses/active` → 1 row |
| Church slugs are suffixed with random tokens (`…-EuAZX2`, `…-O3tBm3`) | the `booted()` slug generator is active |
| Names and addresses are in Arabic | locale handling works end-to-end |

**Nothing about cross-church relationships, orphans, or `church_id` consistency can be inferred from this.**

---

## 8. STOP CONDITIONS

| Stop condition | Triggered? | Basis |
|---|---|---|
| **STOP-1** — production composite FKs missing or incorrect | **PROBABLY TRIGGERED — NOT VERIFIED** | migration absent from `origin/main`; inferred absent. Catalog check impossible without access. |
| **STOP-2** — production data contains cross-tenant relationships | **UNKNOWN** | no data access |
| **STOP-3** — production authorization allows cross-church access | **UNKNOWN** | no authenticated session |
| **STOP-4** — sensitive documents publicly readable | **NOT DETERMINABLE** | no Supabase access. ⚠️ But `config/supabase-storage.php` sets 4 of 5 buckets `public => true`, including `ids` (national-ID scans). **Unverified and plausible.** |
| **STOP-5** — no recovery mechanism + imminent destructive op | **TRIGGERED (partially)** | No backup evidence available. Destructive capability exists (`app:reset-data`, church hard-delete). |
| **STOP-6** — production credential exposed | **NOT TRIGGERED** | `RESEND_API_KEY` and `SUPABASE_*` are empty/absent in the local `.env`; no secret found in tracked files. |
| **STOP-7** — unknown/untraceable build, security-critical code unidentifiable | **TRIGGERED** | deployed SHA unestablished; live evidence shows the build predates the tenant-isolation layer. **This is the one stop condition definitively met.** |
| **STOP-8** — verification action could mutate production | **NOT TRIGGERED** | all probes were GET/OPTIONS; the only POST was to `/auth/login` with a reserved `.invalid` address. |

**Per §60: `STOP → DOCUMENT → ESCALATE → DO NOT PATCH`.** Nothing was patched. The required next phase is remediation of the deployment gap, not further auditing.

---

## 9. Tenant isolation — what can honestly be said

### ✅ VERIFIED
- The deployed build's authentication and route-gate middleware execute correctly (401s, typed 404s, rate-limit headers, production CSP).
- The deployed build is `origin/main`-lineage, not the local working tree.
- The composite tenant FK migration, the tenant audit tooling, and the request-id middleware are **not** in `origin/main` or in the Railway bot branch.

### ❌ NOT VERIFIED
- Whether the 7 composite tenant FKs exist in the production catalog
- Whether any is `NOT VALID` (which would not check existing rows)
- Whether any carries `ON UPDATE CASCADE` (the escape the design forbids)
- Whether production data contains any cross-church row
- Whether any role can reach another role's data in production
- Whether `role_permission` is seeded (U-5)
- Whether `ChurchScope`'s fail-closed branches behave correctly in production
- Whether the `ids` storage bucket holding national-ID scans is publicly readable

### ⚠️ INFERRED (high confidence, not catalog-verified)
- **The database-level tenant invariant is absent in production**, leaving isolation entirely dependent on PHP-layer checks that are individually bypassable.

**The correct status is `NOT VERIFIED`, with the evidence pointing toward `FAIL` — and this is the single most important open question in the entire audit.**
