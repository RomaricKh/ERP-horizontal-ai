# Design Document: People Module Review

## Overview

Full code review and refactoring of the People module to improve architecture, security, validation, and performance while preserving all existing API behavior. The refactoring introduces two new service/repository pairs (Insights, PayeeStatement), consolidates junction table ownership, adds comprehensive input validation, and implements consistent error handling patterns across all controllers.

## Architecture

This refactoring restructures the People module into proper 3-layer architecture (Controller → Service → Repository).

### Layer Responsibilities After Refactoring

| Layer | Responsibility |
| ----- | -------------- |
| Controller | HTTP concerns: input extraction, exception-to-HTTP mapping, response formatting |
| Service | Business logic: validation, authorization checks, orchestration, transaction management |
| Repository | Data access: PDO queries, raw data retrieval, no business logic |

## Components and Interfaces

### New Classes

#### `App\Repository\InsightsRepository`

Extracts all SQL currently inline in `InsightsController`. Accepts PDO via constructor and scopes every query by `businessId` parameter.

**Methods:**
- `getSupplierItemInsights(int $payeeId, int $businessId): array` — aggregates PO detail data with last-price via window function
- `getCustomerItemInsights(int $payeeId, int $businessId): array` — aggregates SI detail data with last-price via window function
- `getCustomerInvoiceFallback(int $payeeId, int $businessId): array` — invoice-level summary when no line items exist
- `getGeneralInsights(int $payeeId, int $businessId): array` — expenses, salaries, payments, collections aggregates
- `getPartnerInsights(int $payeeId, int $businessId): array` — shares, equity, distributions, payments, collections
- `getCustomerItems(int $payeeId, int $businessId): array` — items assigned to a customer with stock/batch info

#### `App\Services\InsightsService`

Orchestrates insight data retrieval. Handles payee verification before any query execution.

**Methods:**
- `getSupplierInsights(int $payeeId, int $businessId): array`
- `getCustomerInsights(int $payeeId, int $businessId): array`
- `getGeneralInsights(int $payeeId, int $businessId): array`
- `getPartnerInsights(int $payeeId, int $businessId): array`
- `getCustomerItems(int $payeeId, int $businessId): array`

#### `App\Repository\PayeeStatementRepository`

Contains the complex multi-table transaction queries and opening balance computation currently in `PayeeStatementController`.

**Methods:**
- `getTransactions(int $payeeId, int $businessId, string $startDate, string $endDate): array` — fetches all transaction types (POs, invoices, expenses, salaries, payments, collections, debit/credit notes, profit distributions) sorted by date
- `calculateOpeningBalance(int $payeeId, int $businessId, string $startDate): float` — computes signed opening balance from all pre-period transactions

#### `App\Services\PayeeStatementService`

Orchestrates statement generation: payee verification, date validation, data retrieval, and running-balance computation.

**Methods:**
- `getStatement(int $payeeId, int $businessId, string $startDate, string $endDate): array` — returns `['payee_name', 'opening_balance', 'closing_balance', 'transactions']`
- `verifyPayeeBelongsToBusiness(int $payeeId, int $businessId): array` — shared verification, returns payee record or throws RuntimeException

### Modified Classes

#### `App\Controllers\PeopleController` (modified)

Changes:
- Replace single `catch (\Throwable)` with tiered catch: `InvalidArgumentException` → 422, `RuntimeException` → 422, `Throwable` → 500 (generic message)
- Remove `$e->getMessage()` from generic error responses in `store()` and `update()`
- Add `error_log()` with structured format before returning error responses

#### `App\Controllers\InsightsController` (modified)

Changes:
- Remove all inline PDO/SQL code
- Instantiate `InsightsService` in constructor
- Delegate all data retrieval to service methods
- Implement tiered exception handling with structured logging

#### `App\Controllers\PayeeStatementController` (modified)

Changes:
- Remove `getTransactions()` and `calculateOpeningBalance()` private methods
- Instantiate `PayeeStatementService` in constructor
- Retain only HTTP formatting (JSON response, CSV streaming, PDF generation)
- Implement tiered exception handling with structured logging

#### `App\Services\PeopleService` (modified)

Changes:
- Remove duplicate `payee_type_ids` validation in `create()` (line 65-67 removed)
- Add comprehensive input validation method `validatePersonInput(array $data): void`
- Add payee type ownership verification `validatePayeeTypeOwnership(array $typeIds, int $tenantId): void`
- Enhance `countTransactionsForPayee` to include `salaries` and `profit_distributions`

#### `App\Repository\PeopleRepository` (modified)

Changes:
- Add region comments grouping methods by concern
- Enhance `countTransactionsForPayee()` to include `salaries` and `profit_distributions` tables
- Add `verifyPayeeBelongsToBusiness(int $payeeId, int $businessId): ?array` method
- Remains the sole owner of junction table writes (`setPayeeTypes`)

#### `App\Repository\PayeeTypeRepository` (modified)

Changes:
- Remove `setTypesForPerson()` method (junction writes now exclusively owned by PeopleRepository)
- Retain read-only junction queries: `getTypesForPerson`, `personHasType`, `findPeopleByType`, `countUsage`

## Interfaces

### Shared Payee Verification

A single verification method lives in `PeopleRepository` and is called by both `InsightsService` and `PayeeStatementService`:

```php
// PeopleRepository
public function verifyPayeeBelongsToBusiness(int $payeeId, int $businessId): ?array
{
    $stmt = $this->db->prepare(
        "SELECT p.id, p.people_id, p.balance, pe.name AS payee_name
         FROM payees p
         JOIN people pe ON pe.id = p.people_id
         WHERE p.id = :id AND p.business_id = :bid
         LIMIT 1"
    );
    $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
```

Services call this and throw if null:

```php
// InsightsService / PayeeStatementService
private function requirePayee(int $payeeId, int $businessId): array
{
    $payee = $this->peopleRepo->verifyPayeeBelongsToBusiness($payeeId, $businessId);
    if (!$payee) {
        throw new \RuntimeException('Payee not found');
    }
    return $payee;
}
```

### Exception Handling Pattern

All People module controllers follow this tiered pattern:

```php
public function store(): void
{
    try {
        // ... main logic
    } catch (\InvalidArgumentException $e) {
        $this->logError(__METHOD__, $e);
        $this->json(false, null, $e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        $this->logError(__METHOD__, $e);
        $this->json(false, null, $e->getMessage(), 422);
    } catch (\Throwable $e) {
        $this->logError(__METHOD__, $e);
        $this->serverError('Failed to create person');
    }
}

private function logError(string $method, \Throwable $e): void
{
    $userId = AuthContext::userId() ?? 'unknown';
    $businessId = AuthContext::businessId() ?? 'unknown';
    $class = get_class($e);
    error_log("[People] {$method}: {$class} - {$e->getMessage()} (user={$userId}, business={$businessId})");
    error_log($e->getTraceAsString());
}
```

### Input Validation Pattern

Validation is centralized in `PeopleService` and runs early (before any DB operation):

```php
private function validatePersonInput(array $data, bool $isCreate = true): void
{
    // Name: required, non-empty after trim, max 255
    $name = trim($data['name'] ?? '');
    if ($name === '' && $isCreate) {
        throw new \InvalidArgumentException('Name is required.');
    }
    if ($name !== '' && mb_strlen($name) > 255) {
        throw new \InvalidArgumentException('Name must not exceed 255 characters.');
    }

    // Email: optional, but must be valid format if provided
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new \InvalidArgumentException('Invalid email format.');
    }

    // Phone: optional, allowed chars only, max 50
    if (!empty($data['phone'])) {
        if (!preg_match('/^[\d\s\-\+\(\)]+$/', $data['phone'])) {
            throw new \InvalidArgumentException('Phone contains invalid characters.');
        }
        if (mb_strlen($data['phone']) > 50) {
            throw new \InvalidArgumentException('Phone must not exceed 50 characters.');
        }
    }

    // Payee type IDs: each must be positive integer
    if (isset($data['payee_type_ids']) && is_array($data['payee_type_ids'])) {
        foreach ($data['payee_type_ids'] as $typeId) {
            if (!is_int($typeId) && !ctype_digit((string)$typeId) || (int)$typeId <= 0) {
                throw new \InvalidArgumentException("Invalid payee type ID: {$typeId}");
            }
        }
    }
}
```

### Payee Type Ownership Verification

```php
private function validatePayeeTypeOwnership(array $typeIds, int $tenantId): void
{
    $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
    $stmt = $this->db->prepare(
        "SELECT id FROM payee_types
         WHERE id IN ({$placeholders})
           AND (is_system = 1 OR tenant_id = ?)");
    $params = array_merge(array_map('intval', $typeIds), [$tenantId]);
    $stmt->execute($params);
    $validIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

    $invalid = array_diff(array_map('intval', $typeIds), $validIds);
    if (!empty($invalid)) {
        throw new \InvalidArgumentException(
            'Invalid payee type IDs: ' . implode(', ', $invalid)
        );
    }
}
```

### Date Validation Pattern

```php
// PayeeStatementService
private function validateDateRange(?string $startDate, ?string $endDate): void
{
    if (!$startDate || !$endDate) {
        throw new \InvalidArgumentException('start_date and end_date are required.');
    }
    if (!$this->isValidDate($startDate)) {
        throw new \InvalidArgumentException('start_date must be a valid date in YYYY-MM-DD format.');
    }
    if (!$this->isValidDate($endDate)) {
        throw new \InvalidArgumentException('end_date must be a valid date in YYYY-MM-DD format.');
    }
    if ($startDate > $endDate) {
        throw new \InvalidArgumentException('start_date must be less than or equal to end_date.');
    }
}

private function isValidDate(string $date): bool
{
    $d = \DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}
```

### N+1 Elimination: Window Function Approach

Replace per-item loop with a single query using `ROW_NUMBER()`:

```sql
-- Supplier insights: aggregate + last price in one query
SELECT i.name AS item_name,
       SUM(pod.quantity) AS total_quantity,
       AVG(pod.unit_price_fc) AS avg_unit_price,
       MAX(po.po_date) AS last_date,
       COUNT(DISTINCT po.id) AS document_count,
       GROUP_CONCAT(DISTINCT po.po_number ORDER BY po.po_date DESC SEPARATOR ', ') AS document_numbers,
       lp.last_price
FROM po_details pod
JOIN purchase_orders po ON po.id = pod.purchase_order_id
JOIN items i ON i.id = pod.item_id
LEFT JOIN (
    SELECT pod2.item_id,
           pod2.unit_price_fc AS last_price,
           ROW_NUMBER() OVER (PARTITION BY pod2.item_id ORDER BY po2.po_date DESC, po2.id DESC) AS rn
    FROM po_details pod2
    JOIN purchase_orders po2 ON po2.id = pod2.purchase_order_id
    WHERE po2.supplier_id = :payee_id
      AND po2.business_id = :business_id
      AND po2.status != 'CANCELLED'
) lp ON lp.item_id = pod.item_id AND lp.rn = 1
WHERE po.supplier_id = :payee_id
  AND po.business_id = :business_id
  AND po.status != 'CANCELLED'
GROUP BY pod.item_id, i.name, lp.last_price
ORDER BY total_quantity DESC
```

### Running Balance Computation

The running balance algorithm (preserved from existing code):

```php
// PayeeStatementService::computeRunningBalances()
public function computeRunningBalances(float $openingBalance, array $transactions): array
{
    $running = $openingBalance;
    foreach ($transactions as &$txn) {
        $debit = (float)($txn['debit_amount'] ?? 0);
        $credit = (float)($txn['credit_amount'] ?? 0);
        $running += $debit + $credit;
        $txn['running_balance'] = $running;
    }
    return $transactions;
}
```

### Junction Table Ownership Consolidation

**Before:**
- `PeopleRepository::setPayeeTypes(int $payeeId, array $typeIds)` — writes by payee_id
- `PayeeTypeRepository::setTypesForPerson(int $peopleId, array $typeIds)` — writes by people_id

**After:**
- `PeopleRepository::setPayeeTypes(int $payeeId, array $typeIds)` — sole write owner (unchanged)
- `PayeeTypeRepository::setTypesForPerson()` — **removed**
- `PayeeTypeRepository` retains: `getTypesForPerson()`, `personHasType()`, `findPeopleByType()`, `countUsage()`

Note: The existing `PeopleRepository::setPayeeTypes()` already operates on `payee_id`, which maps correctly to the `people_types` junction since the junction references the payee record. The removed `PayeeTypeRepository::setTypesForPerson()` used `people_id` which is a different (and potentially incorrect) key for business-scoped operations.

## Data Models

No schema changes are required. The refactoring operates on existing tables:

- `people` — person records (tenant-scoped)
- `payees` — business-scoped financial identities
- `people_types` — junction: payee_id ↔ payee_type_id
- `payee_types` — classification types with module_code gating

### Transaction Tables (for delete safety check)

```
expenses.payee_id
sales_invoices.customer_id
purchase_orders.supplier_id
payment_slips.payee_id
collection_slips.payee_id
salaries.payee_id            ← NEW in count check
profit_distributions.partner_id  ← NEW in count check
```

## Error Handling

### Exception Hierarchy

| Exception Type | HTTP Code | Response Behavior |
|---------------|-----------|-------------------|
| `InvalidArgumentException` | 422 | Exception message returned to client (validation errors) |
| `RuntimeException` | 422 | Exception message returned to client (business rule violations) |
| `Throwable` (any other) | 500 | Generic message only, never expose internal details |

### Logging Strategy

All exceptions are logged before returning responses:
- Format: `[People] {Controller::method}: {ExceptionClass} - {message} (user={id}, business={id})`
- Stack trace logged on separate line via `error_log($e->getTraceAsString())`
- AuthContext values included when available (graceful fallback to 'unknown')

## Frontend Improvements

### Person Form Modal (`personForm.modal.js`)

Current state: validates only `name` and at-least-one-type. After refactoring:
- Validate email format client-side (HTML5 `type="email"` + JS regex)
- Validate phone characters client-side (digits, spaces, dashes, plus, parens)
- Show validation feedback inline using Bootstrap's `.is-invalid` + `.invalid-feedback`
- Ensure `unmount()` removes all event listeners (search input, document click handler)

### Payee Types Modal (`payeeTypes.modal.js`)

- Block submission when name is empty or whitespace-only
- Show inline validation feedback on the name input
- Ensure `unmount()` cleanup is complete

### General Frontend Patterns

- All `apiFetch()` calls wrapped in try/catch with user-friendly notifications on failure
- Event listeners registered in `mount()` tracked for removal in `unmount()`
- Custom events dispatched after successful mutations (`people.updated`)

## Testing Strategy

### PHP Unit Tests (PHPUnit)

- **Controller exception handling**: Verify tiered catch behavior with mocked services
- **Service validation**: Verify each validation rule with specific invalid inputs
- **Repository method signatures**: Verify new methods exist and return expected types
- **Integration tests**: Verify refactored endpoints return same response shapes as before

### PHP Property Tests (PHPUnit + custom generators)

- Input validation properties (random strings, arrays)
- Date validation properties (random date strings)
- Running balance computation (random transaction sequences)
- Exception message non-leakage (random exception messages)

### JS Property Tests (Vitest + fast-check)

- Frontend form validation (random form states)
- Whitespace-only rejection for type names

### Test Organization

```
tests/
├── Unit/
│   ├── PeopleServiceValidationTest.php
│   ├── PeopleControllerErrorHandlingTest.php
│   └── PayeeStatementServiceTest.php
├── Property/
│   ├── PeopleInputValidationPropertyTest.php
│   ├── StatementBalancePropertyTest.php
│   └── DateValidationPropertyTest.php
└── people-module-review/
    └── frontend-validation.test.js
```

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Error response never leaks internal exception messages

*For any* exception thrown within a People module controller where the exception is not an `InvalidArgumentException` or `RuntimeException`, the HTTP response body SHALL NOT contain the exception's internal message string.

**Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5**

### Property 2: Exception type determines HTTP status code

*For any* exception caught by a People module controller method, if the exception is an `InvalidArgumentException` or `RuntimeException` the response status SHALL be 422 with the exception message as the response message; for any other `Throwable` the response status SHALL be 500 with a generic message.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4**

### Property 3: All caught exceptions are logged with structured context

*For any* exception caught by a People module controller, the `error_log()` output SHALL contain the exception class name, message, and stack trace, formatted as `[People] {method}: {Class} - {message} (user={id}, business={id})`.

**Validates: Requirements 2.6, 16.1, 16.2, 16.3**

### Property 4: Input validation rejects all invalid person data

*For any* input where the name is empty after trimming or exceeds 255 characters, or the email is non-empty but fails `filter_var(FILTER_VALIDATE_EMAIL)`, or the phone contains characters outside `[0-9 \-\+\(\)]` or exceeds 50 characters, or any `payee_type_id` is not a positive integer, the `PeopleService` SHALL throw an `InvalidArgumentException` identifying the invalid field.

**Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5, 7.6**

### Property 5: Invalid payee_type_ids input is always rejected

*For any* input where `payee_type_ids` is empty, null, or not an array, calling `PeopleService::create()` SHALL throw an `InvalidArgumentException` with the message "At least one payee type must be selected." — and this check SHALL occur exactly once.

**Validates: Requirements 1.2**

### Property 6: Cross-tenant payee type assignment is rejected

*For any* set of `payee_type_ids` provided during create or update, if any ID does not exist in `payee_types` or belongs to a different tenant (where `is_system = 0` and `tenant_id` does not match the authenticated tenant), the `PeopleService` SHALL throw an `InvalidArgumentException` identifying the invalid type IDs.

**Validates: Requirements 8.1, 8.2**

### Property 7: Delete is blocked when any transaction exists

*For any* payee that has at least one linked record in `expenses`, `sales_invoices`, `purchase_orders`, `payment_slips`, `collection_slips`, `salaries`, or `profit_distributions`, calling `PeopleService::delete()` SHALL throw a `RuntimeException` and the payee record SHALL remain unchanged.

**Validates: Requirements 9.1, 9.2, 9.3**

### Property 8: Payee business verification rejects cross-business access

*For any* payee ID that does not belong to the specified business (no row in `payees` with matching `id` and `business_id`), both `InsightsService` and `PayeeStatementService` SHALL throw a `RuntimeException` with message "Payee not found" before executing any data query.

**Validates: Requirements 11.1, 11.2, 11.4**

### Property 9: Date validation rejects malformed and illogical ranges

*For any* string that does not match the format `YYYY-MM-DD` or does not represent a valid calendar date (e.g., 2024-02-30), or for any pair where `start_date > end_date`, the `PayeeStatementService` SHALL throw an `InvalidArgumentException` with a descriptive message.

**Validates: Requirements 12.1, 12.2, 12.3**

### Property 10: Running balance computation is algebraically correct

*For any* opening balance and any sequence of transactions with `debit_amount` and `credit_amount` fields, the running balance at position N SHALL equal `opening_balance + SUM(debit_amount[0..N] + credit_amount[0..N])`, and the closing balance SHALL equal the running balance of the last transaction.

**Validates: Requirements 5.5**

### Property 11: Module-gated type filtering excludes inactive modules

*For any* business configuration with a set of active modules, the payee types returned by listing or person queries SHALL include only types where `module_code IS NULL` or `module_code` is in the business's active module set. Types with a `module_code` linked to an inactive module SHALL never appear.

**Validates: Requirements 14.1, 14.3**

### Property 12: Client-side validation blocks invalid form submissions

*For any* form state where the name field is empty/whitespace-only, or no payee type checkbox is selected, or the payee type name field (in types modal) is empty/whitespace-only, the frontend SHALL prevent API submission and display inline validation feedback.

**Validates: Requirements 15.3, 15.4**
