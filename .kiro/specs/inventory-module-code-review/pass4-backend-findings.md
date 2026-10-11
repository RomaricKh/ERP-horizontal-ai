# Pass 4 — Services Backend Findings

## Summary

| Severity | Count |
|----------|-------|
| Critical | 0 |
| High | 2 |
| Medium | 5 |
| Low | 3 |

| Category | Count |
|----------|-------|
| SEC | 2 |
| BUG | 2 |
| PERF | 1 |
| QUAL | 3 |
| VAL | 2 |

---

## Findings

### P4-SEC-001: ServiceRepository::update lacks business_id/tenant_id scoping

**File:** `src/Repository/ServiceRepository.php`
**Line(s):** 91–109
**Severity:** High
**Category:** SEC

**Description:**
The `update()` method builds a dynamic UPDATE query scoped only by `id`. It does not enforce `business_id` or `tenant_id` in the WHERE clause. If an attacker can guess or enumerate service IDs, they could modify services belonging to another business or tenant. The `ServiceService::update()` does call `findById()` first (which is properly scoped), so exploitation requires a TOCTOU race, but the defense-in-depth principle demands the repository itself enforce scoping.

**Current Code:**
```php
public function update(int $id, array $data): void {
    $allowed = ['name', 'description', 'unit', 'rate', 'category_id', 'is_active'];
    $fields  = [];
    $params  = ['id' => $id];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowed, true)) {
            $fields[]    = "`$key` = :$key";
            $params[$key] = $value;
        }
    }

    if (empty($fields)) {
        return;
    }

    $fields[] = "`updated_at` = NOW()";

    $sql = "UPDATE services SET " . implode(', ', $fields) . " WHERE id = :id";
    $this->db->prepare($sql)->execute($params);
}
```

**Recommended Fix:**
```php
public function update(int $id, array $data, int $businessId, int $tenantId): void {
    $allowed = ['name', 'description', 'unit', 'rate', 'category_id', 'is_active'];
    $fields  = [];
    $params  = ['id' => $id, 'business' => $businessId, 'tenant' => $tenantId];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowed, true)) {
            $fields[]    = "`$key` = :$key";
            $params[$key] = $value;
        }
    }

    if (empty($fields)) {
        return;
    }

    $fields[] = "`updated_at` = NOW()";

    $sql = "UPDATE services SET " . implode(', ', $fields)
         . " WHERE id = :id AND business_id = :business AND tenant_id = :tenant";
    $this->db->prepare($sql)->execute($params);
}
```

**Notes:** The caller in `ServiceService::update()` should pass `$ctx->business_id` and `$ctx->tenant_id` to the repository.

---

### P4-SEC-002: ServiceCategoryRepository::findById lacks business_id scoping

**File:** `src/Repository/ServiceCategoryRepository.php`
**Line(s):** 28–35
**Severity:** Medium
**Category:** SEC

**Description:**
`findById()` scopes only by `tenant_id`, not `business_id`. In a multi-business tenant, a user in Business A could potentially look up (and then update/delete, since the service layer uses the result to confirm existence) a category belonging to Business B within the same tenant. The `update()` and `delete()` methods do enforce `business_id`, providing a partial safety net, but the existence check in `ServiceService::updateCategory()` and `deleteCategory()` relies on this method — if it finds a category from another business, the subsequent update/delete will silently fail (rowCount = 0) and throw a generic error rather than a clear "not found".

**Current Code:**
```php
public function findById(int $id, int $tenantId): ?array {
    $stmt = $this->db->prepare("
        SELECT * FROM service_categories
        WHERE id = :id AND tenant_id = :tenant
        LIMIT 1
    ");
    $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
```

**Recommended Fix:**
```php
public function findById(int $id, int $tenantId, int $businessId): ?array {
    $stmt = $this->db->prepare("
        SELECT * FROM service_categories
        WHERE id = :id AND tenant_id = :tenant
          AND (business_id = :business OR (is_system = 1 AND business_id IS NULL))
        LIMIT 1
    ");
    $stmt->execute(['id' => $id, 'tenant' => $tenantId, 'business' => $businessId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
```

**Notes:** The `OR (is_system = 1 AND business_id IS NULL)` clause allows system categories to remain visible. Callers in `ServiceService` need updating to pass `$ctx->business_id`.

---

### P4-BUG-001: ServiceCategoryRepository::updateCategory in ServiceRepository is unused / dead code

**File:** `src/Repository/ServiceRepository.php`
**Line(s):** 138–156
**Severity:** Medium
**Category:** BUG

**Description:**
`ServiceRepository` contains category CRUD methods (`findAllCategories`, `findCategoryById`, `createCategory`, `updateCategory`, `deleteCategory`) that duplicate the functionality in the dedicated `ServiceCategoryRepository`. The `ServiceService` uses `ServiceCategoryRepository` for all category operations, making the ServiceRepository category methods dead code. This creates confusion about which repository is authoritative and risks future callers using the wrong one — notably, `ServiceRepository::updateCategory` and `ServiceRepository::deleteCategory` do NOT enforce `is_system` guards that `ServiceCategoryRepository` does.

**Current Code:**
```php
// In ServiceRepository — dead category methods that lack is_system enforcement:
public function updateCategory(int $id, array $data): void {
    $allowed = ['name'];
    $fields  = [];
    $params  = ['id' => $id];
    // ... no is_system check
    $sql = "UPDATE service_categories SET " . implode(', ', $fields) . " WHERE id = :id";
    $this->db->prepare($sql)->execute($params);
}

public function deleteCategory(int $id, int $businessId, int $tenantId): void {
    // ... no is_system check
    $stmt = $this->db->prepare(
        "DELETE FROM service_categories WHERE id = :id AND business_id = :business AND tenant_id = :tenant"
    );
    // ...
}
```

**Recommended Fix:**
Remove the entire "SERVICE CATEGORIES" section from `ServiceRepository.php` (lines ~116–180). All category operations should go through `ServiceCategoryRepository` exclusively, which properly enforces `is_system` guards.

---

### P4-BUG-002: Service update route catches only RuntimeException, misses InvalidArgumentException

**File:** `public/api/routes/services.php`
**Line(s):** 115–126
**Severity:** Medium
**Category:** BUG

**Description:**
The `PUT /services/{id}` route handler catches only `\RuntimeException` but `ServiceService::update()` can also throw `\InvalidArgumentException` for validation failures (e.g., empty name, invalid rate). When an `InvalidArgumentException` is thrown, it will bubble up uncaught, resulting in a 500 Internal Server Error instead of a proper 422 validation response.

**Current Code:**
```php
$router->put('/services/{id}',
    function ($id) {
        // ...
        try {
            $service->update((int)$id, $input);
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['services.edit']
);
```

**Recommended Fix:**
```php
$router->put('/services/{id}',
    function ($id) {
        $ctx = \App\Support\AuthContext::get();
        if ($ctx->business_id === null) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Active business context required']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new ServiceService(Database::getConnection());
        try {
            $service->update((int)$id, $input);
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['services.edit']
);
```

---

### P4-VAL-001: Service create does not validate category_id exists or belongs to current business

**File:** `src/Services/ServiceService.php`
**Line(s):** 57–95
**Severity:** Medium
**Category:** VAL

**Description:**
The `create()` method accepts `category_id` from user input and passes it directly to the repository without verifying that: (a) it is a valid integer, (b) it references an existing category, or (c) the category belongs to the current business/tenant. A user could assign a service to a category from another business or provide a non-existent ID, resulting in either a foreign key violation (caught as an opaque database error) or a dangling reference if no FK constraint exists.

**Current Code:**
```php
$id = $this->repo->create([
    'tenant_id'   => $ctx->tenant_id,
    'business_id' => $ctx->business_id,
    'category_id' => $data['category_id'] ?? null,
    'name'        => $name,
    // ...
]);
```

**Recommended Fix:**
```php
$categoryId = $data['category_id'] ?? null;
if ($categoryId !== null) {
    $categoryId = (int) $categoryId;
    $category = $this->categoryRepo->findById($categoryId, $ctx->tenant_id, $ctx->business_id);
    if (!$category) {
        throw new \InvalidArgumentException('Invalid service category');
    }
}

$id = $this->repo->create([
    'tenant_id'   => $ctx->tenant_id,
    'business_id' => $ctx->business_id,
    'category_id' => $categoryId,
    'name'        => $name,
    // ...
]);
```

**Notes:** The same validation gap exists in the `update()` method when `category_id` is among the changed fields.

---

### P4-VAL-002: ServiceService::deleteCategory does not enforce is_system guard at service layer

**File:** `src/Services/ServiceService.php`
**Line(s):** 249–271
**Severity:** Medium
**Category:** VAL

**Description:**
`deleteCategory()` calls `findById()` to confirm the category exists, then checks the service count, then calls `$this->categoryRepo->delete()`. While the repository's `delete()` method does enforce the `is_system` guard, the service layer does not check it before the service-count validation. If a system category has zero assigned services, the flow proceeds to the repository which correctly blocks the delete — but the error message ("Cannot delete a system-defined service category") comes from the repository layer rather than being caught and surfaced clearly. More importantly, the `countServicesForCategory()` step is wasted work for system categories.

**Current Code:**
```php
public function deleteCategory(int $id): void {
    $ctx     = AuthContext::get();
    $current = $this->categoryRepo->findById($id, $ctx->tenant_id);
    if (!$current) {
        throw new \RuntimeException('Service category not found');
    }

    $count = $this->categoryRepo->countServicesForCategory($id, $ctx->business_id, $ctx->tenant_id);
    if ($count > 0) {
        throw new \RuntimeException('Cannot delete category: it has services assigned');
    }

    // ... calls delete which checks is_system
}
```

**Recommended Fix:**
```php
public function deleteCategory(int $id): void {
    $ctx     = AuthContext::get();
    $current = $this->categoryRepo->findById($id, $ctx->tenant_id);
    if (!$current) {
        throw new \RuntimeException('Service category not found');
    }

    if (!empty($current['is_system'])) {
        throw new \RuntimeException('Cannot delete a system-defined service category');
    }

    $count = $this->categoryRepo->countServicesForCategory($id, $ctx->business_id, $ctx->tenant_id);
    if ($count > 0) {
        throw new \RuntimeException('Cannot delete category: it has services assigned');
    }

    // ...
}
```

**Notes:** Same early-guard should be applied in `updateCategory()`.

---

### P4-PERF-001: findAllForBusiness loads all services without pagination

**File:** `src/Repository/ServiceRepository.php`
**Line(s):** 21–42
**Severity:** Low
**Category:** PERF

**Description:**
`findAllForBusiness()` returns every service for a business with no LIMIT or pagination support. For businesses with many services, this will transfer large result sets. The items module has the same pattern (documented in Pass 1), but services tend to be fewer in number so the practical impact is low. Worth noting for consistency.

**Current Code:**
```php
$sql .= " ORDER BY s.name";

$stmt = $this->db->prepare($sql);
$stmt->execute(['tenant' => $tenantId, 'business' => $businessId]);
return $stmt->fetchAll(PDO::FETCH_ASSOC);
```

**Recommended Fix:**
Add optional `$limit` and `$offset` parameters:
```php
public function findAllForBusiness(int $businessId, int $tenantId, bool $includeInactive = false, ?int $limit = null, ?int $offset = null): array {
    // ... existing query building ...
    $sql .= " ORDER BY s.name";
    if ($limit !== null) {
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset ?? 0;
    }
    // ...
}
```

**Notes:** Low priority — services catalogs are typically small (<100 entries). Monitor growth.

---

### P4-QUAL-001: Services route file uses inline closures instead of Controller pattern

**File:** `public/api/routes/services.php`
**Line(s):** 1–155
**Severity:** Low
**Category:** QUAL

**Description:**
The items and manufacturing route files delegate to Controller classes (`ItemsController`, `ManufacturingController`) using concise arrow functions. The services route file defines full inline closures that handle business context checking, JSON decoding, service instantiation, try/catch, and response encoding directly. This creates code duplication (the business context check is repeated 9 times) and deviates from the established Controller pattern.

**Current Code:**
```php
// services.php — inline closure pattern (repeated for every endpoint)
$router->get('/services',
    function () {
        $ctx = \App\Support\AuthContext::get();
        if ($ctx->business_id === null) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Active business context required']);
            return;
        }
        $service = new ServiceService(Database::getConnection());
        $list = $service->list(true);
        echo json_encode(['success' => true, 'data' => ['services' => $list]]);
    },
    ['services.view']
);
```

**Recommended Fix:**
Create a `ServicesController` extending `BaseController` and refactor routes to:
```php
// services.php — controller pattern (consistent with items/manufacturing)
$router->get('/services',
    fn() => (new ServicesController())->index(),
    ['services.view']
);
```

**Notes:** This is a structural improvement. The current code works correctly but is harder to maintain and inconsistent with the rest of the project.

---

### P4-QUAL-002: Duplicate category CRUD methods across two repositories

**File:** `src/Repository/ServiceRepository.php`
**Line(s):** 116–180
**Severity:** Low
**Category:** QUAL

**Description:**
`ServiceRepository` contains a full set of category CRUD methods (`findAllCategories`, `findCategoryById`, `createCategory`, `updateCategory`, `deleteCategory`) that are never called by `ServiceService` (which uses `ServiceCategoryRepository` instead). This violates the single-responsibility principle and creates confusion about which repository is authoritative for category operations. The duplicate methods also lack the `is_system` guard that the dedicated repository enforces.

**Current Code:**
```php
// ServiceRepository.php — unused category section
public function findAllCategories(int $businessId, int $tenantId): array { ... }
public function findCategoryById(int $id, int $businessId, int $tenantId): ?array { ... }
public function createCategory(array $data): int { ... }
public function updateCategory(int $id, array $data): void { ... }
public function deleteCategory(int $id, int $businessId, int $tenantId): void { ... }
```

**Recommended Fix:**
Remove the entire category section from `ServiceRepository.php`. Ensure no other code references these methods (grep for `ServiceRepository.*Category`). All category operations are correctly handled by `ServiceCategoryRepository`.

**Notes:** Related to P4-BUG-001 — same underlying issue viewed from a quality perspective.

---

### P4-QUAL-003: ServiceCategoryRepository::updateCategory scoping allows no-op silent failure

**File:** `src/Repository/ServiceCategoryRepository.php`
**Line(s):** 51–63
**Severity:** Low
**Category:** QUAL

**Description:**
The `update()` method in `ServiceCategoryRepository` correctly checks `isSystemType` and scopes the UPDATE by `business_id` and `tenant_id`. However, it throws a generic "Service category not found or not allowed" if `rowCount() === 0`. This conflates two different failure modes: (1) the category doesn't exist for this business, and (2) the category exists but the name didn't change (MySQL returns rowCount=0 when data is unchanged). The service layer mitigates this by comparing names before calling update, but if that check is bypassed, a legitimate no-change update would throw.

**Current Code:**
```php
$stmt->execute(['id' => $id, 'tenant' => $tenantId, 'business' => $businessId, 'name' => $name]);
if ($stmt->rowCount() === 0) {
    throw new \RuntimeException('Service category not found or not allowed');
}
```

**Recommended Fix:**
Either remove the `rowCount()` check (since the service layer already confirms existence) or split it:
```php
$stmt->execute(['id' => $id, 'tenant' => $tenantId, 'business' => $businessId, 'name' => $name]);
// rowCount check removed — existence is verified at the service layer
// If needed, use a separate SELECT before UPDATE to distinguish "not found" from "no change"
```

---

## Files Inspected

| File | Findings | Notes |
|------|----------|-------|
| `src/Services/ServiceService.php` | P4-VAL-001, P4-VAL-002 | Good validation overall; category_id not validated; is_system check delegated to repo |
| `src/Repository/ServiceRepository.php` | P4-SEC-001, P4-BUG-001, P4-PERF-001, P4-QUAL-002 | Update lacks scoping; dead category code |
| `src/Repository/ServiceCategoryRepository.php` | P4-SEC-002, P4-QUAL-003 | findById scopes only by tenant_id; update rowCount ambiguity |
| `public/api/routes/services.php` | P4-BUG-002, P4-QUAL-001 | Missing catch for InvalidArgumentException; no controller pattern |

## Traceability to Requirements

| Requirement | Criteria | Findings / Status |
|-------------|----------|-------------------|
| 14.1 | Route permission checks | All endpoints have permission arrays — **no issues found** |
| 14.2 | Parameterized statements & scoping | P4-SEC-001 (update lacks scoping), P4-SEC-002 (findById lacks business_id) |
| 14.3 | Authorization on data modifications | Service layer verifies ownership via findById before mutations — **no issues beyond SEC-001** |
| 15.1 | Category assignment & deletion handling | P4-VAL-001 (category_id not validated), category deletion properly guarded |
| 15.2 | is_system enforcement | P4-VAL-002 (service layer doesn't early-check), repository correctly blocks |
| 16.1 | Pagination / over-fetching | P4-PERF-001 (no pagination on list) |
| 16.2 | Required field validation & price range | Service validates name, unit, rate — **adequate** |
| 16.4 | Dead code & pattern deviations | P4-QUAL-001 (no controller), P4-QUAL-002/P4-BUG-001 (duplicate repo methods) |
| 16.5 | Routing consistency | P4-QUAL-001 (inline closures vs controller pattern) |
