# Design Document: Tenant Admin Review Fixes

## Overview

This feature applies a series of targeted fixes to the tenant management module, spanning server-side validation, tenant scoping, audit logging, architectural layering, data correctness, pagination standardization, a new endpoint, and shared guard extraction. All changes follow the existing Controller → Service → Repository pattern using PHP 8+ with PDO/MySQL and vanilla JS on the frontend.

No new infrastructure or external dependencies are introduced. The changes are internal refactors and bug fixes within existing files.

## Architecture

The existing 3-layer architecture (Controller → Service → Repository) is maintained. Key structural changes:

- **Shared guard file** (`public/api/routes/_guards.php`) eliminates duplicated tenant-owner closures
- **Service consolidation** moves inline controller logic into `TenantOwnerService::designateFromController()`
- **Repository extraction** moves raw SQL from controllers into dedicated repository methods
- **Validation helpers** (`validatePassword()`) are added as private static methods for reuse

All changes are scoped to the tenant management subsystem and do not affect other modules.

## Components and Interfaces

### 1. Password Validation Utility

**File:** `src/Services/TenantOwnerService.php`

A private static validation method reusable across service methods:

```php
private static function validatePassword(string $password): void
{
    if (strlen($password) < 8) {
        throw new \InvalidArgumentException('Password must be at least 8 characters');
    }
}
```

**Called from:**
- `TenantOwnerService::designateFromController()` (new consolidated method)
- `UserService::createWithMultipleBusinesses()`

### 2. Tenant Scoping Guard on Business Operations

**File:** `src/Controllers/Tenant/TenantBusinessController.php`

Both `viewBusiness()` and `updateBusiness()` gain a tenant membership check at the top:

```php
$tenantId = AuthContext::tenantId();
$business = $this->businessService->getById($businessId);
if (!$business || (int)$business['tenant_id'] !== $tenantId) {
    $this->json(false, null, 'Access denied: business does not belong to your tenant', 403);
    return;
}
```

The `BusinessService::getById()` method (or `BusinessRepository::getBusinessByID()`) must return `tenant_id` in its SELECT.

### 3. Audit Logging for Role Deletion

**File:** `src/Services/TenantOwnerService.php` (within `designateFromController()`)

When an existing user's business-level roles are removed during tenant owner designation, an audit log entry is written within the same transaction:

```php
$this->audit->log(
    'TENANT_OWNER_CHANGE',
    'user_roles',
    $userId,
    'DELETE_BUSINESS_ROLES',
    ['removed_roles' => $removedRoleData],
    null,
    $actingUserId,
    $tenantId,
    null,
    true,
    true
);
```

### 4. Repository Extraction (Move SQL from Controllers)

#### 4a. TenantOwnerRepository::listOwnersForTenant()

**File:** `src/Repository/TenantOwnerRepository.php`

New method replaces the inline SQL in `TenantOwnerController::listOwners()`:

```php
public function listOwnersForTenant(int $tenantId): array
{
    $stmt = $this->db->prepare("
        SELECT u.id AS user_id, u.username,
               CONCAT(u.first_name, ' ', u.last_name) AS full_name,
               u.email, ur.is_active, u.created_at AS designated_at
        FROM user_roles ur
        JOIN users u ON u.id = ur.user_id
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.tenant_id = :tenant_id AND r.type = 'TENANT_OWNER'
        ORDER BY ur.id ASC
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}
```

#### 4b. BusinessTypeRepository::getSystemTypesWithModules()

**File:** `src/Repository/BusinessTypeRepository.php`

New method replaces inline SQL in `TenantBusinessController::listSystemBusinessTypes()`:

```php
public function getSystemTypesWithModules(): array
{
    $stmt = $this->db->prepare("
        SELECT bt.id, bt.code, bt.name, bt.description
        FROM business_types bt WHERE bt.is_system = 1 ORDER BY bt.id ASC
    ");
    $stmt->execute();
    $types = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($types as &$type) {
        $modStmt = $this->db->prepare("
            SELECT m.code, m.name
            FROM business_type_modules btm
            JOIN module_registry m ON m.id = btm.module_id
            WHERE btm.business_type_id = :type_id ORDER BY m.name ASC
        ");
        $modStmt->execute(['type_id' => $type['id']]);
        $type['modules'] = $modStmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    return $types;
}
```

### 5. Consolidated Designation Logic

**File:** `src/Services/TenantOwnerService.php`

New method `designateFromController()` encapsulates all logic currently in `TenantOwnerController::designateOwner()`:

```php
/**
 * Full designation flow called from the controller.
 * Handles: user lookup/creation, duplicate check, max-owner enforcement,
 * business-role removal + audit, role assignment.
 *
 * @param int   $tenantId
 * @param array $data {email, username, first_name, last_name, password}
 * @return int The designated user's ID
 *
 * @throws InvalidArgumentException on validation failure
 * @throws RuntimeException on business rule violation
 */
public function designateFromController(int $tenantId, array $data): int
```

**Logic flow:**
1. Validate email format (filter_var)
2. Validate password length if provided (>= 8 chars)
3. Look up existing user by email, then by username
4. If existing: check not already a tenant owner; remove business-level roles + audit
5. If new: require first_name, last_name, password; create user identity
6. Enforce max 10 owners (via `$this->repo->countByTenantId()`)
7. Insert tenant owner role assignment
8. Audit log the designation
9. Return user ID

The controller becomes:

```php
public function designateOwner(): void
{
    try {
        $tenantId = AuthContext::tenantId();
        $data = $this->input(['email', 'username']);
        $userId = $this->tenantOwnerService->designateFromController($tenantId, $data);
        $this->json(true, ['user_id' => $userId], 'Tenant Owner designated successfully', 201);
    } catch (\InvalidArgumentException $e) {
        $this->json(false, null, $e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        $this->json(false, null, $e->getMessage(), 400);
    } catch (\Throwable $e) {
        $this->serverError('Failed to designate tenant owner');
    }
}
```

### 6. MODULE_TABLE_MAP Correction

**File:** `src/Services/BusinessTypeService.php`

Change the constant:

```php
private const MODULE_TABLE_MAP = [
    // ... unchanged entries ...
    'payments'    => ['payment_slips'],      // was 'payments'
    'collections' => ['collection_slips'],   // was 'collections'
    'salaries'    => ['salaries'],           // unchanged (already correct)
    // ...
];
```

### 7. Pagination Envelope Standardization

**File:** `src/Services/TenantOwnerService.php`

Update `getBusinessSummaries()` return keys to match the standard envelope:

```php
return [
    'data'         => $businesses,        // was 'businesses'
    'total'        => $total,
    'current_page' => $page,              // was 'page'
    'last_page'    => max(1, (int) ceil($total / $perPage)),  // new
    'per_page'     => $perPage,
];
```

**File:** `public/assets/js/pages/Tenant/businesses.js`

Update `fetchBusinesses()`:

```javascript
async function fetchBusinesses() {
    try {
        const res = await apiFetch('/api/tenant/businesses');
        const data = await res.json();
        if (!res.ok || !data.success) {
            showError('Failed to load businesses');
            return;
        }
        renderBusinessTable(data.data?.data || []);
    } catch (err) {
        console.error('Fetch businesses failed:', err);
        showError('Network error loading businesses');
    }
}
```

### 8. Single Tenant Fetch Endpoint

**Route:** `GET /tenant/tenants/{id}`

**File:** `public/api/routes/tenant.php`

```php
$router->get('/tenant/tenants/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantCoreController())->viewTenant((int) $id);
    }
);
```

**File:** `src/Controllers/Tenant/TenantCoreController.php`

New method:

```php
public function viewTenant(int $tenantId): void
{
    try {
        $userId = AuthContext::userId();
        if (!$this->tenantRepository->userOwnsTenant($userId, $tenantId)) {
            $this->json(false, null, 'Access denied: you do not own this tenant', 403);
            return;
        }
        $tenant = $this->tenantRepository->findById($tenantId);
        if (!$tenant) {
            $this->notFound('Tenant not found');
            return;
        }
        $this->json(true, ['tenant' => $tenant]);
    } catch (\Throwable $e) {
        $this->serverError('Failed to fetch tenant');
    }
}
```

**File:** `public/assets/js/pages/Tenant/tenantForm.modal.js`

Update `fetchAndFill()`:

```javascript
async function fetchAndFill(id) {
    try {
        const res = await apiFetch(`/api/tenant/tenants/${id}`);
        const json = await res.json();
        if (!res.ok || !json.success) return;

        const tenant = json.data?.tenant;
        if (!tenant) return;

        const nameInput = modalRoot.querySelector('#inputTenantName');
        const descInput = modalRoot.querySelector('#inputTenantDescription');
        if (nameInput) nameInput.value = tenant.name || '';
        if (descInput) descInput.value = tenant.description || '';
    } catch (err) {
        console.error('Failed to fetch tenant:', err);
    }
}
```

### 9. Server-Side Email Validation

Applied in two locations using `filter_var`:

**In `TenantOwnerService::designateFromController()`:**

```php
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Invalid email format');
}
```

**In `UserService::createWithMultipleBusinesses()`:**

```php
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Invalid email format');
}
```

### 10. Frontend Error Feedback

**File:** `public/assets/js/pages/Tenant/businesses.js`

Add error handling in `fetchBusinesses()` (shown in section 7) and a helper:

```javascript
function showError(message) {
    const container = document.querySelector('#businessesErrorContainer');
    if (container) {
        container.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
}
```

### 11. Input Whitelist Fix

**File:** `src/Controllers/Tenant/TenantCoreController.php`

The `BaseController::input()` method validates that listed fields are present (required check) and returns the full decoded JSON body. Currently `createTenant()` calls `$this->input(['name'])` and reads description separately via `isset($data['description'])`.

The fix: include 'description' in the `input()` call so the endpoint explicitly documents consumed fields. Since `input()` enforces presence of listed fields and description is optional, we handle it by adding description to the call but also accepting null:

```php
$data = $this->input(['name']);
$name = trim($data['name']);
$description = isset($data['description']) ? trim($data['description']) : null;
```

The current code already works correctly. The implementation task will ensure 'description' is properly documented as a consumed input field.

### 12. Active Business Filtering

**File:** `src/Repository/BusinessRepository.php`

Update `getAllForCurrentUser()`:

```php
public function getAllForCurrentUser(int $user_id): array {
    $stmt = $this->db->prepare("
        SELECT b.id, b.name, b.legal_name, b.registration_no,
               b.address, b.phone, b.email, b.currency
        FROM businesses b
        INNER JOIN user_roles ur ON ur.business_id = b.id AND ur.user_id = :user_id
        WHERE b.is_active = 1
        GROUP BY b.id
    ");
    $stmt->execute(['user_id' => $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

### 13. Shared Tenant Guard Extraction

**File:** `public/api/routes/_guards.php`

```php
<?php

use App\Support\AuthContext;

/**
 * Shared Tenant Owner guard closure.
 * Checks AuthContext::isTenantOwner() and returns HTTP 403 JSON on failure.
 * Imported by tenant.php and businesses.php.
 */
return function (): bool {
    if (!AuthContext::isTenantOwner()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'data'    => null,
            'message' => 'Access denied: Tenant Owner privileges required'
        ]);
        return false;
    }
    return true;
};
```

**File:** `public/api/routes/tenant.php` (top):

```php
$tenantGuard = require __DIR__ . '/_guards.php';
```

**File:** `public/api/routes/businesses.php` (top):

```php
$businessTenantGuard = require __DIR__ . '/_guards.php';
```

### 14. Client-Side Email Validation

**File:** `public/html/Tenant/ownerForm.html` (or equivalent designation form)

Set input type to `email`:

```html
<input type="email" class="form-control" id="inputOwnerEmail" required>
```

**File:** Corresponding modal controller JS

Add pre-submit validation:

```javascript
const emailInput = modalRoot.querySelector('#inputOwnerEmail');
const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
if (!emailPattern.test(emailInput.value.trim())) {
    markInvalid(emailInput, 'Please enter a valid email address');
    return;
}
```

## Data Models

No new database tables or columns are introduced. All changes operate on existing schema.

### Affected Tables (read/write)
- `user_roles` — role deletion during designation, tenant owner insertion
- `users` — user lookup and creation
- `businesses` — tenant scoping queries, active filtering
- `business_types` / `business_type_modules` / `module_registry` — system types query
- `activity_log` — audit entries

### Pagination Envelope (standardized response shape)

```json
{
  "success": true,
  "data": {
    "data": [...],
    "total": 42,
    "current_page": 1,
    "last_page": 3,
    "per_page": 20
  },
  "message": null
}
```

## Interfaces

### New API Endpoint

| Method | Path | Controller | Description |
|--------|------|------------|-------------|
| GET | `/tenant/tenants/{id}` | `TenantCoreController::viewTenant(int $tenantId)` | Fetch single tenant by ID |

### Modified Service Methods

| Class | Method | Change |
|-------|--------|--------|
| `TenantOwnerService` | `designateFromController(int $tenantId, array $data): int` | **New** — consolidated designation logic |
| `TenantOwnerService` | `getBusinessSummaries()` | Return keys changed to Pagination_Envelope |
| `UserService` | `createWithMultipleBusinesses()` | Add email + password validation at top |
| `BusinessTypeService` | `MODULE_TABLE_MAP` | Fix 'payments' → 'payment_slips', 'collections' → 'collection_slips' |

### Modified Repository Methods

| Class | Method | Change |
|-------|--------|--------|
| `TenantOwnerRepository` | `listOwnersForTenant(int $tenantId): array` | **New** — extracted from controller |
| `BusinessTypeRepository` | `getSystemTypesWithModules(): array` | **New** — extracted from controller |
| `BusinessRepository` | `getAllForCurrentUser()` | Add `WHERE b.is_active = 1` |
| `BusinessRepository` | `getBusinessByID()` | Include `tenant_id` in SELECT |

## Error Handling

| Scenario | HTTP Status | Response |
|----------|-------------|----------|
| Password < 8 chars | 422 | `"Password must be at least 8 characters"` |
| Invalid email format | 422 | `"Invalid email format"` |
| Business not in tenant (view/update) | 403 | `"Access denied: business does not belong to your tenant"` |
| Tenant not owned (GET single) | 403 | `"Access denied: you do not own this tenant"` |
| Non-tenant-owner accessing guarded routes | 403 | `"Access denied: Tenant Owner privileges required"` |
| Tenant not found | 404 | `"Tenant not found"` |

## Testing Strategy

**PHP Property Tests** (PHPUnit + custom generators):
- Password validation (Property 1): Generate random strings of varying lengths, assert rejection/acceptance based on length threshold
- Pagination envelope (Property 3): Generate random page/perPage values, verify envelope shape and computed fields
- Email validation (Property 5): Generate random strings including edge cases, verify alignment with filter_var behavior
- Active business filtering (Property 6): Seed mixed active/inactive data, verify only active returned

**PHP Unit Tests:**
- MODULE_TABLE_MAP correctness (Req 6): Assert specific key → value mappings
- Tenant scoping (Property 2): Mock repository to test guard logic with specific scenarios
- Single tenant fetch (Property 4): Test viewTenant with owned/unowned IDs
- Shared guard (Property 7): Test closure output with mocked AuthContext

**JS Unit Tests** (Vitest + jsdom):
- Pagination envelope consumption: Verify businesses.js reads from `data.data`
- Error feedback: Simulate failed fetch, assert error element is rendered
- Single tenant fetch URL: Verify tenantForm.modal.js calls `/api/tenant/tenants/{id}`
- Client-side email validation: Test pattern match against valid/invalid emails

**Integration Tests:**
- Audit log creation during designation (Req 3): Verify log entry written in same transaction
- End-to-end designation flow via consolidated service method (Req 5)

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Password length validation

*For any* string of length less than 8 characters, calling `validatePassword()` SHALL throw an `InvalidArgumentException`; and *for any* string of length 8 or more characters, calling `validatePassword()` SHALL NOT throw an exception.

**Validates: Requirements 1.1, 1.2, 1.3**

### Property 2: Tenant scoping rejects cross-tenant business access

*For any* business ID and tenant ID pairing where the business's `tenant_id` does not equal the authenticated tenant, both `viewBusiness()` and `updateBusiness()` SHALL return HTTP 403 without exposing or modifying business data.

**Validates: Requirements 2.1, 2.2, 2.3, 2.4**

### Property 3: Pagination envelope structure

*For any* valid page number (≥ 1) and per_page value (1–100), `getBusinessSummaries()` SHALL return an array containing exactly the keys `data`, `total`, `current_page`, `last_page`, and `per_page`, where `last_page` equals `ceil(total / per_page)` and `current_page` equals the requested page.

**Validates: Requirements 7.1**

### Property 4: Single tenant fetch ownership guard

*For any* tenant ID and user ID, `viewTenant()` SHALL return the tenant record if and only if the user owns the tenant; otherwise it SHALL return HTTP 403.

**Validates: Requirements 8.2, 8.3**

### Property 5: Email format validation

*For any* string that does not pass `filter_var($string, FILTER_VALIDATE_EMAIL)`, the designation and user-creation endpoints SHALL reject the request with an `InvalidArgumentException`; and *for any* string that passes `filter_var`, the email validation step SHALL not reject.

**Validates: Requirements 9.1, 9.2, 9.3, 9.4**

### Property 6: Active business filtering

*For any* user with business assignments where some businesses have `is_active = 0`, calling `getAllForCurrentUser()` SHALL return only businesses where `is_active = 1`.

**Validates: Requirements 12.1, 12.2**

### Property 7: Shared tenant guard rejection

*For any* request context where `AuthContext::isTenantOwner()` returns false, the shared tenant guard SHALL output HTTP 403 with the JSON body `{"success": false, "data": null, "message": "Access denied: Tenant Owner privileges required"}` and return false.

**Validates: Requirements 13.4**
