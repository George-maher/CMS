# Tenant and Stage Isolation Audit

## Scope and standard

This audit follows the project security model in `SECURITY.md`, the stage-admin expectations in `STAGE_ADMIN_AUDIT_REPORT.md`, and the repository instructions in `AGENTS.md`. The authoritative boundary is:

> Church → Stage → Class / Service → Resource

Client-supplied `church_id`, `stage_id`, and `class_id` values are not trusted. Authorization must be enforced server-side, and a request must not be able to read or mutate another church or an unauthorized stage.

## Confirmed findings and remediation

| Finding | Evidence and root cause | Severity | Remediation | Verification |
|---|---|---:|---|---|
| Unauthenticated client-header tenant selection | `BelongsToChurch` and `ChurchScope` previously allowed an unauthenticated `X-Church-ID` context to select a tenant. This made the header a trust boundary for model creation and queries. | Critical | Removed unauthenticated header selection from model creation and made web requests fail closed when no trusted authenticated tenant exists. Explicit public token/verse paths remain intentionally scoped through their own public lookup logic. | `TenantScopeHeaderIsolationTest`; focused suite passed. |
| Stage Admin could reorder another stage’s classes | `ClassePolicy::reorder` allowed Stage Admins, while `ClasseRepository::updateOrder` updated submitted IDs by ID only. The service did not verify that every ID belonged to the actor’s authorized stage. | High | `ClasseService::updateOrder` now receives the authenticated actor, resolves every submitted class, verifies `ScopeResolver::canAccessClass`, rejects missing or out-of-scope IDs before repository mutation, and only then performs the update. | New `StageAdminScopeTest::test_stage_admin_cannot_reorder_class_from_other_stage`; focused suite passed. |

## Related controls reviewed

The existing implementation already contained server-side protections for several adjacent paths. Class creation derives `church_id` from the authenticated user and validates the target stage. Bulk class creation resolves the stage through the authenticated church and checks stage access. User assignment derives stage and scope from the target class. Event sub-resources use centralized parent-event authorization. QR, attendance-context, and storage regressions were also exercised in the focused suite.

The review did not weaken database constraints, remove authorization middleware, or rely on frontend filtering. The class-order fix is deliberately in the service layer because it protects the business operation even if a controller or route policy is later bypassed.

## Verification evidence

The focused isolation suite passed:

```text
Tests:    66 passed (159 assertions)
Duration: 7.48s
```

The narrower tenant/stage regression suite also passed:

```text
Tests:    41 passed (98 assertions)
Duration: 5.78s
```

PHP syntax checks passed for all changed scope, service, controller, contract, and regression-test files. `git diff --check` reported no whitespace errors attributable to the current changes.

A complete backend run currently reports **274 passed and 7 failed**. The failures are not in the focused tenant/stage suite. Authentication invite tests fail because their invite token is already used or expired, and profile-update request tests fail to find the expected approval request. Those failures reproduce when the individual `AuthTest` and `ProfileUpdateRequestTest` files are run alone, so they are separate existing test/data-lifecycle issues rather than evidence that the tenant or class-order changes regress isolation. They remain release gates and should be diagnosed before declaring the entire backend suite green.

## Remaining release work

The following items remain outside this narrowly confirmed remediation and must not be marked complete without evidence:

1. Diagnose and repair the two independently reproducible authentication-invite test failures and the three profile-update request failures, then rerun the complete backend suite.
2. Run the PostgreSQL production-dialect PHPUnit configuration in CI or another environment with PostgreSQL available; the local Windows environment does not provide a reliable Docker/PostgreSQL verification path.
3. Run the frontend lint, localization, and TypeScript gates after the complete backend suite is green.
4. Validate the production Docker build and deployment configuration in an environment where Docker is available.
5. Add production observability checks for authorization denials and unexpected missing tenant context without logging tokens or sensitive URLs.

## Conclusion

The two confirmed isolation defects found in this review are remediated and covered by passing regression tests. The project is **not yet fully release-green** because the broader backend suite still contains independently reproducible failures and the production-dialect/deployment gates require an appropriate environment.

The correct next action is to fix the failing authentication and profile-request tests or document their intended lifecycle, then rerun all release gates. No additional speculative authorization changes are recommended until those results and any remaining route-family evidence are available.

## Files changed for this remediation

- `backend/app/Traits/BelongsToChurch.php`
- `backend/app/Models/Scopes/ChurchScope.php`
- `backend/app/Contracts/ClasseServiceInterface.php`
- `backend/app/Services/ClasseService.php`
- `backend/app/Http/Controllers/Api/ClasseController.php`
- `backend/tests/Feature/TenantScopeHeaderIsolationTest.php`
- `backend/tests/Feature/StageAdminScopeTest.php`
- `TENANT_STAGE_ISOLATION_AUDIT.md`

These changes are in the existing working tree alongside earlier audit and production-hardening work; no unrelated files were reverted.

## Documentation consulted

- `AGENTS.md`
- `SECURITY.md`
- `STAGE_ADMIN_AUDIT_REPORT.md`
- `README.md`
- `CODE_REVIEW_REPORT.md`
- `PRODUCTION_HARDENING_REPORT.md`
- `backend/app/Services/ScopeResolver.php`
- `backend/app/Services/EventAuthorizationService.php`
- Existing isolation and feature tests under `backend/tests/Feature/`

Generated: 2026-09-24

