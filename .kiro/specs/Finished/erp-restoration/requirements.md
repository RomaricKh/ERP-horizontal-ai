# ERP Application Restoration Spec

## Introduction

The ERP application was previously in a fully working state. An intermediate agent made extensive modifications that expanded the codebase (tenant owner management, module gating, i18n, horizontal menu layout, etc.) but introduced critical bugs that broke the runtime. This spec documents all identified issues and the tasks needed to restore full functionality.

## Root Cause Summary

1. **Missing `AuthContext::isTenantOwner()` method** — Called by 6+ files but never added to the class. Causes fatal PHP errors on most API routes.
2. **PHP 7.4 incompatibility** — Union type syntax (`string|array|null`) used in AuthMiddleware (PHP 8+ only). Fixed.
3. **Database schema mismatch** — `stock_transactions` table uses `reference_type`/`reference_id` columns but code referenced old `reference` column. Fixed.
4. **`collection_slips` vs `collection_allocations`** — SalesInvoiceService queried `reference_type`/`reference_id` on the wrong table. Fixed.
5. **Frontend tenant/business switcher** — Tenant switcher never initialized; business switcher empty for tenant owners; menu didn't handle `tenantOwner` flag.
6. **Missing page files** — 5 JS pages and 5 HTML templates referenced by router but not present on disk.
7. **Test mock signature drift** — AuditService gained `$force`/`$tenantScope` params but test mocks were never updated.

## Issues Already Fixed (This Session)

| # | Issue | File(s) | Fix Applied |
|---|-------|---------|-------------|
| 1 | PHP 8 union types on PHP 7.4 | `AuthMiddleware.php` | Removed type hints |
| 2 | `reference` column doesn't exist | `ItemRepository.php`, `ItemService.php` | Changed to `reference_type`/`reference_id` with `parseReferenceType()` |
| 3 | Queries on wrong table | `SalesInvoiceService.php` | Query `collection_allocations` via JOIN |
| 4 | `rowNode.dataset` null | `salesInvoices.js`, `salesInvoices.html` | Hidden column approach |
| 5 | Column mismatch in mixed mode | `invoiceForm.modal.js` | `colspan="2"` in mixed mode |
| 6 | Missing page files | 5 JS + 5 HTML + 1 modal | Created from scratch |
| 7 | Missing `AuthContext::isTenantOwner()` | `AuthContext.php` | Added static method |
| 8 | Tenant switcher not initialized | `appShell.js` | Import + `initTenantSwitcher()` call |
| 9 | Business switcher empty for tenant owners | `appShell.js` | Fallback API fetch |
| 10 | Menu doesn't show tenant owner items | `appShell.js` | Added `tenantOwner` check in `renderMenu()` |
| 11 | Tenant routes unprotected | `resolve.js` | Added `isTenantOwner()` guard |
| 12 | Test mocks missing params | 15 test files | Added `$force`/`$tenantScope` to overrides |

## Remaining Issues To Investigate

### R1: Business Switcher Value Not Passed Correctly

The business switcher assigns `a.value = id` where `id` is a number from a Map. The `businessChanged` action reads `el.closest('a')?.value` — but `HTMLAnchorElement.value` is not a standard property. It may not persist correctly across browsers. Should use `data-business-id` attribute instead.

### R2: `hasPermission()` Returns False for Tenant Owners on Business-Scoped Permissions

For a pure tenant owner (only TENANT_OWNER role with `business_id: null` in JWT), `hasPermission('dashboard.view')` returns `false` because:
- `user.roles` contains one entry with `business_id: null`
- The role iteration skips roles where `business_id` doesn't match `localStorage.business_id`
- The TENANT_OWNER role has `business_id: null` so the condition `role.business_id && String(role.business_id) !== String(business_id)` evaluates to `false` (short-circuit on falsy `null`)
- However, the permissions array in the JWT for that role needs to contain ALL permissions (which the backend grants via `loadAllPermissionCodes()`)

If the JWT's TENANT_OWNER role permissions are empty/null, the sidebar will be empty. Need to verify the `fetchAuthContext` query properly populates the TENANT_OWNER role permissions.

### R3: Pages Showing Missing/Incorrect Data

Multiple pages may fail because:
- The `X-Business-Id` header might not be sent (if `localStorage.business_id` is `null`)
- Backend returns empty data when business context is missing
- The `ModuleGateMiddleware` may block requests if `AuthContext::isTenantOwner()` wasn't available (now fixed)

### R4: Route `#/businesses` Uses `redirectTo` Pattern Not Handled by Router

The route config has:
```js
{path: '#/businesses', auth: true, redirectTo: (isTenantOwner) => isTenantOwner ? '#/tenant/businesses' : '#/dashboard'}
```
But `resolveRouteDecision` never calls `route.redirectTo`. This route will fail silently because it has no `load` function.

### R5: `determineDefaultRoute()` May Fail for Tenant Owners

The function finds the first route with `r.auth && r.permission && hasPermission(r.permission)`. For a tenant owner with no business-scoped permissions visible in the JWT, this may fail and the default route won't be set, causing a redirect loop.

### R6: Module Service Cache Not Refreshed on Business Switch

When the user switches businesses via the dropdown, `handleBusinessChange()` calls `invalidateAndReconnect()` which deactivates and re-resolves the route. However, `ensureSession()` (called during `resolveRouteDecision`) refetches `/auth/me` and updates modules via `setActiveModules()`. If this doesn't complete before `renderMenu()` is called again, the sidebar may show stale module data.

## Acceptance Criteria

1. **Login works** — User can log in and see the dashboard with data
2. **Tenant switcher visible** for tenant owners, allows switching between tenants
3. **Business switcher populated** — Shows all businesses the user has access to
4. **All sidebar menu items visible** based on permissions and active modules
5. **All routes navigable** — Clicking any menu item loads the corresponding page without errors
6. **Data displays correctly** — Tables show data from the API without console errors
7. **Business switching works** — Selecting a different business refreshes the context and reloads data
8. **Tenant owner can access tenant management pages** (`#/tenant/*`)
9. **No PHP fatal errors** on any API endpoint
10. **Unit tests pass** (excluding pre-existing test mock signature issues unrelated to runtime)
