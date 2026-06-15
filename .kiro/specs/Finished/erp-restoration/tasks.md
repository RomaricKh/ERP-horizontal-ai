# ERP Restoration — Tasks

## Completed Tasks

- [x] 1. Fix `AuthMiddleware.php` — Remove PHP 8 union type syntax for PHP 7.4 compatibility
- [x] 2. Fix `ItemRepository::insertStockTransaction` — Use `reference_type`/`reference_id` columns instead of missing `reference` column
- [x] 3. Fix `ItemService::adjustStock` — Pass `reference_type`/`reference_id` with `parseReferenceType()` helper
- [x] 4. Fix `SalesInvoiceService` — Query `collection_allocations` table instead of `collection_slips` for `reference_type`/`reference_id`
- [x] 5. Fix `salesInvoices.js` — Resolve null `rowNode.dataset` by using hidden DataTable column for due_date
- [x] 6. Fix `invoiceForm.modal.js` — Add `colspan="2"` to `lineItemServiceHeader` in mixed mode
- [x] 7. Create missing page files — `Dashboard/dashboard.js`, `Assets/assets.js`, `Settings/expiryThreshold.js`, `Tenant/tenants.js`, `Tenant/reports.js`, `Tenant/tenantForm.modal.js` + HTML templates
- [x] 8. Fix `AuthContext.php` — Add missing `isTenantOwner()` static method
- [x] 9. Fix `appShell.js` — Import and initialize tenant switcher; add `isTenantOwner` import
- [x] 10. Fix `appShell.js` `renderBusinessSelector` — Fallback to API fetch for tenant owners with no business roles in JWT
- [x] 11. Fix `appShell.js` `renderMenu` — Handle `tenantOwner` flag on menu items and groups
- [x] 12. Fix `resolve.js` — Add `isTenantOwner()` route guard for tenant-only routes
- [x] 13. Fix `appShell.js` `renderUserInfo` — Check `isModuleActive()` for user menu items with module property
- [x] 14. Fix test mocks — Add `$force`/`$tenantScope` params to AuditService::log overrides in 15 test files

## Remaining Tasks

- [x] 15. Fix business switcher value reading — Replace `a.value` with `data-business-id` attribute in `appShell.js` (both the render and the action handler)
- [x] 16. Handle `redirectTo` route pattern in `resolve.js` — Check for `route.redirectTo` and return redirect decision before checking `route.load`
- [x] 17. Fix `determineDefaultRoute` for tenant owners — If `isTenantOwner()`, prefer `#/tenant/businesses` as default route
- [x] 18. Verify all API endpoints return data correctly — Test login → dashboard → invoice list → PO list → people → settings flow end-to-end
- [x] 19. Fix remaining test failures — Update `CollectionServiceTest`, `RoleServiceCreateMethodTest`, `RoleServiceUpdateMethodTest`, `RoleServiceTemplateMethodsTest` to match current audit event type naming (`CREATE`/`UPDATE`/`DELETE` vs old `role_created`/`role_updated`/`role_deleted`)
- [x] 20. Verify module gating works — Confirm that disabled modules correctly hide menu items and block API access
