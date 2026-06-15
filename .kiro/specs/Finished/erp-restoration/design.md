# ERP Restoration — Design

## Architecture Context

The application uses a **3-layer backend** (Controller → Service → Repository) with a **vanilla JS SPA frontend**. Authentication uses JWT tokens stored in localStorage, with a refresh token in an httpOnly cookie. Business/tenant context is sent via HTTP headers (`X-Business-Id`, `X-Tenant-Id`) on every API request.

## Key Subsystems Affected

### 1. AuthContext (Backend Identity)

`AuthContext` is a static singleton set by `AuthMiddleware` on every request. It holds: `user_id`, `tenant_id`, `business_id`, `role_id`, `permissions`, `is_tenant_owner`.

**Fix applied:** Added `isTenantOwner()` static method.

### 2. AuthMiddleware (Request Pipeline)

Handles two paths:
- **Tenant Owner path**: User has TENANT_OWNER role → gets all permissions, bypasses module gating
- **Standard path**: User has business-scoped role → permissions resolved from role, filtered by active modules

**Fix applied:** Removed PHP 8 union types.

### 3. Frontend Auth Flow

```
Login → JWT stored → getUser() decodes JWT → roles array available
             ↓
ensureSession() → /auth/me → sets _isTenantOwner + activeModules cache
             ↓
activateAppShell() → renderMenu() + renderBusinessSelector() + initTenantSwitcher()
```

### 4. Business Switcher Flow

```
renderBusinessSelector(user):
  1. Extract businesses from user.roles (JWT payload)
  2. If empty + isTenantOwner → fetch /api/tenant/businesses
  3. Render dropdown items with data-action="businessChanged"
  
businessChanged action:
  1. Update localStorage.business_id
  2. Broadcast to other tabs
  3. Call invalidateAndReconnect() → deactivates shell → resolveRoute() → re-activates shell
```

### 5. Tenant Switcher Flow

```
initTenantSwitcher():
  1. If !isTenantOwner() → remove switcher, return
  2. Fetch /api/tenant/tenants
  3. Render dropdown in topbar before business dropdown
  4. On switch: update localStorage.activeTenantId → clear business_id → invalidateAndReconnect()
```

### 6. Menu Rendering Logic

```
renderMenu():
  For each MENU_CONFIG entry:
    - If entry.tenantOwner → show only if isTenantOwner()
    - If entry.permission → show only if hasPermission(entry.permission)
    - If entry.module → show only if isModuleActive(entry.module)
    - Groups: filter children by same rules, skip group if no visible children
```

## Remaining Fixes Needed

### Fix R1: Business Switcher Value Attribute

**Problem:** `<a>` elements don't have a standard `.value` property.
**Solution:** Use `data-business-id` attribute and read via `dataset.businessId`.

### Fix R4: `redirectTo` Route Pattern

**Problem:** Route `#/businesses` uses `redirectTo` function but router doesn't handle it.
**Solution:** In `resolveRouteDecision`, before checking `route.load`, check if `route.redirectTo` exists and return a redirect decision.

### Fix R5: Default Route for Tenant Owners  

**Problem:** `determineDefaultRoute()` fails for tenant owners if JWT permissions don't cover business-scoped routes.
**Solution:** If user `isTenantOwner()`, set default route to `#/tenant/businesses` instead of searching permission-based routes.

## Files to Modify

| File | Change |
|------|--------|
| `src/Support/AuthContext.php` | ✅ Done — added `isTenantOwner()` |
| `public/assets/js/Shells/appShell.js` | Fix business switcher value reading |
| `public/assets/js/router/resolve.js` | Handle `redirectTo` routes; fix `determineDefaultRoute` for tenant owners |
