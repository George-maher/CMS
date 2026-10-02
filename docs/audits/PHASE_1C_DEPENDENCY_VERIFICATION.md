# PHASE 1C — DEPENDENCY VERIFICATION

**Commands actually executed** (results in `PHASE_1C_TEST_RESULTS.md`):

```bash
composer audit --locked --no-dev      # backend/
npm audit --audit-level=high          # frontend/
npm audit                             # frontend/ — all severity levels
npm ls axios                          # frontend/
```

---

## 1. COMPOSER (backend)

| Check | Result |
|---|---|
| `composer audit --locked --no-dev` | ✅ **No security vulnerability advisories found** |

`--locked` inspects `composer.lock` — the file CI and every deploy resolve through
`composer install`. This is the correct check (a bare `composer audit` inspects the local
`vendor/` tree, which had previously drifted ahead of the lock and produced a misleading
"clean" result — see Phase 1 report D-8).

**Remaining HIGH/CRITICAL composer findings: none.**

---

## 2. NPM (frontend)

| Check | Result |
|---|---|
| `npm audit --audit-level=high` | ✅ **found 0 vulnerabilities** |
| `npm audit` (all levels) | ✅ **found 0 vulnerabilities** |
| `npm ls axios` | `church-manager-frontend@1.0.0 └── axios@1.20.0` (no invalid/extraneous) |

**Remaining HIGH/CRITICAL npm findings: none.**

---

## 3. THE AXIOS ADVISORY — full record

| Item | Value |
|---|---|
| **Advisory** | **GHSA-vh66-26gq-q6x8** (CVE-2026-101908) — *"Axios: Prototype pollution gadget in fetch adapter can alter outbound requests"* |
| Source | GitHub Advisory Database, reviewed 2026-09-30 (official, version-aware) |
| Affected package | `axios` (npm) |
| **Affected range** | `>= 1.7.0, < 1.20.0` |
| **Patched version** | **`1.20.0`** |
| **Installed version BEFORE** | **1.18.1** (as reported by Phase 1B) — inside the affected range |
| **Installed version AFTER** | **1.20.0** — outside the affected range |
| Declared in `package.json` | `^1.7.9` (already admits 1.20.0; no edit needed) |
| Lockfile entry | `package-lock.json` → `"node_modules/axios"` → `"version": "1.20.0"` |
| Why 1.20.0 is safe | It is the *designated patched version* of the advisory itself — the boundary is `< 1.20.0`, so 1.20.0 is the first non-affected release. |
| **Audit result after** | `npm audit` → **0 vulnerabilities** at every severity level |

### Additional context from the official advisory

The advisory states the vulnerable behaviour is **specific to the fetch adapter**
(`adapter: 'fetch'`) and *"does not affect Node HTTP adapter requests"*. This application is a
**browser SPA** using axios' default XHR adapter; it never selects the fetch adapter. So the
exploit path is additionally **not reachable** in this application — but the finding is still
recorded as **resolved**, not suppressed, because the installed version is now outside the
affected range regardless.

> The other 6 GHSAs Phase 1B listed alongside it all fell in the same
> `1.0.0 – 1.19.0` window; `npm audit` reporting **0 vulnerabilities at all levels** confirms
> none remain.

### Classification of remaining findings

```text
P0   — none
P1   — none
P2   — none
P3   — none
INFO — none
```

There are **no remaining dependency advisories** to classify for either ecosystem.
Consequently **no suppression, override, or `audit-level` downgrade was used** anywhere in
this phase, and no `npm audit --omit=dev` style filtering was applied to hide results —
`npm audit` was run across **all** severity levels and reports zero.

---

## 4. SCOPE DISCIPLINE

| Action | Taken? |
|---|---|
| Unrelated dependency upgrades | **No** — no other package was touched |
| `package.json` edited | **No** |
| Lockfile edited by 1C | **No** (the lock already carried 1.20.0 before 1C began) |
| Audit suppression / overrides added | **No** |
| Dev-dependency pruning | **No** |

**Conclusion:** both dependency surfaces are clean, and Phase 1C made **zero** dependency
changes because none were required.
