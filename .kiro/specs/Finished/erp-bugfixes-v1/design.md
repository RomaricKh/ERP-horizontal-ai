# ERP Bugfixes V1 — Bugfix Design

## Overview

This design addresses eight bugs (A–H) discovered during code review of the multi-tenant PHP ERP system. The bugs span frontend JavaScript (incorrect API response parsing, broken edit modals), backend PHP (number generation collisions, missing authorization, race conditions, validation gaps, audit logging outside transactions), and database permissions. Each fix is minimal and targeted: we change only the code paths that exhibit the defect, preserving all surrounding behavior.

## Glossary

- **Bug_Condition (C)**: The specific input or state that triggers each bug — e.g., opening a PO modal triggers the item-loading path that misparses the API response
- **Property (P)**: The desired correct behavior when the bug condition holds — e.g., `itemsList` contains an array of item objects after `loadItems()` completes
- **Preservation**: Existing behavior that must remain unchanged by each fix — e.g., empty API responses still produce an empty `itemsList`
- **F**: The original (unfixed) function
- **F'**: The fixed function
- **FC / BC**: Foreign Currency / Base Currency — the dual-currency model used throughout the ERP
- **`AuthContext`**: Singleton in `App\Support\AuthContext` that holds the authenticated user's `user_id`, `tenant_id`, `business_id`, and `permissions`
- **`BaseController::json()`**: Response helper that wraps data in `{ success, data, message }` — so `$this->json(true, ['items' => $items])` produces `{ success: true, data: { items: [...] }, message: null }`

## Bug Details

### Bug A — Items list not loading in PO/Invoice form modals

#### Bug Condition

The PO and Invoice form modals call `loadItems()` which fetches `GET /api/items`. The `ItemsController::index()` responds with `$this->json(true, ['items' => $items])`, producing `{ success: true, data: { items: [...] } }`. The JS code assigns `json.data ?? []` to `itemsList`, which gives it the wrapper object `{ items: [...] }` instead of the actual array. Since an object is not iterable by `forEach` in the expected way, the item select dropdowns render empty.

**Formal Specification:**
```
FUNCTION isBugCondition_A(input)
  INPUT: input of type ModalOpenEvent for PO or Invoice form
  OUTPUT: boolean

  RETURN modalType IN ['poForm', 'invoiceForm']
         AND apiResponse.success === true
         AND apiResponse.data is Object with key 'items'
         AND code assigns apiResponse.data directly to itemsList
END FUNCTION
```

#### Examples

- User opens "New Purchase Order" modal → item select dropdown shows only "— Select item —" with no actual items
- User opens "Edit Sales Invoice" modal → existing line items load but adding a new line shows empty item dropdown
- API returns `{ success: true, data: { items: [] } }` → `itemsList` becomes `{ items: [] }` (object) instead of `[]` (array)

### Bug B — PO/Invoice/Slip number generation produces duplicates

#### Bug Condition

The `generatePoNumber()`, `generateInvoiceNumber()`, and `generateSlipNumber()` methods use `COUNT(*)` to determine the next sequence number. After a record is deleted, `COUNT(*)` decreases, causing the next generated number to collide with an existing one.

**Formal Specification:**
```
FUNCTION isBugCondition_B(input)
  INPUT: input of type NumberGenerationRequest (PO, Invoice, or Slip)
  OUTPUT: boolean

  existingCount := COUNT(*) of records for business+year
  deletedCount  := number of records deleted for business+year
  RETURN deletedCount > 0
         AND (existingCount + 1) produces a number that already exists
END FUNCTION
```

#### Examples

- Business has PO-2026-001, PO-2026-002, PO-2026-003. Delete PO-2026-002. Next generated: PO-2026-003 (duplicate)
- Business has INV-2025-001. Delete it. Next generated: INV-2025-001 (duplicate of the deleted record's number, which may still exist in audit logs or collection slips)
- Business has CS-2025-001, CS-2025-002. Delete CS-2025-001. Next generated: CS-2025-002 (duplicate)

### Bug C — Sales Invoice PAY: no account-business validation

#### Bug Condition

When `SalesInvoiceService::updateStatus()` transitions an invoice to PAID with an `account_id`, it creates a collection slip and credits the account balance without verifying the account belongs to the same business.

**Formal Specification:**
```
FUNCTION isBugCondition_C(input)
  INPUT: input of type StatusUpdateRequest with account_id
  OUTPUT: boolean

  RETURN input.newStatus === 'PAID'
         AND input.account_id IS NOT NULL
         AND account.business_id !== currentBusiness.id
END FUNCTION
```

#### Examples

- Business A (id=1) marks invoice as PAID with account_id=5 which belongs to Business B (id=2) → collection slip created on wrong business's account, balance credited to wrong account

### Bug D — Expense category deletion race condition

#### Bug Condition

`ExpenseService::deleteCategory()` calls `countExpensesForCategory()` and then `deleteCategory()` without a transaction. A concurrent request can insert an expense between the check and the delete.

**Formal Specification:**
```
FUNCTION isBugCondition_D(input)
  INPUT: input of type ConcurrentRequests (deleteCategory + createExpense)
  OUTPUT: boolean

  RETURN request1.type === 'DELETE_CATEGORY'
         AND request2.type === 'CREATE_EXPENSE' with same category_id
         AND request2.INSERT executes between request1.COUNT and request1.DELETE
END FUNCTION
```

### Bug E — Salary validation gaps

#### Bug Condition

`SalaryService::validateData()` does not check that `period_end >= period_start` or that `tax_fc <= gross_amount_fc`.

**Formal Specification:**
```
FUNCTION isBugCondition_E(input)
  INPUT: input of type SalaryData
  OUTPUT: boolean

  RETURN (input.period_end < input.period_start)
         OR (input.tax_fc > input.gross_amount_fc)
END FUNCTION
```

#### Examples

- Create salary with period_start=2025-06-30, period_end=2025-06-01 → accepted (invalid date range)
- Create salary with gross_amount_fc=1000, tax_fc=1500 → net_amount_fc=-500 (negative net)

### Bug F — PO/Invoice edit modal sends PUT to no-op endpoint

#### Bug Condition

Both `PurchaseOrdersController::update()` and `SalesInvoicesController::update()` return 422 with "Use specific endpoints to modify...". The JS modals send PUT when `currentPoId`/`currentInvoiceId` is set.

**Formal Specification:**
```
FUNCTION isBugCondition_F(input)
  INPUT: input of type ModalSaveAction
  OUTPUT: boolean

  RETURN (input.modalType === 'poForm' AND currentPoId !== null)
         OR (input.modalType === 'invoiceForm' AND currentInvoiceId !== null)
END FUNCTION
```

### Bug G — Audit logging outside transaction

#### Bug Condition

In `PaymentService` and `CollectionService`, the `create()` and `delete()` methods call `audit->log()` after `$this->db->commit()`. If the audit insert fails, the financial operation is persisted without an audit trail.

**Formal Specification:**
```
FUNCTION isBugCondition_G(input)
  INPUT: input of type PaymentOrCollectionOperation
  OUTPUT: boolean

  RETURN operation IN ['payment.create', 'payment.delete', 'collection.create', 'collection.delete']
         AND audit->log() is called AFTER db->commit()
END FUNCTION
```

### Bug H — BUSINESS_OWNER missing permissions

#### Bug Condition

The original migration used `WHERE id > 25` to grant new ERP permissions, but the permissions table may not have had exactly 25 pre-existing rows.

**Formal Specification:**
```
FUNCTION isBugCondition_H(input)
  INPUT: input of type PermissionCheck for BUSINESS_OWNER
  OUTPUT: boolean

  RETURN user.role_id === 1 (BUSINESS_OWNER)
         AND EXISTS permission p IN permissions
         WHERE p.id NOT IN (SELECT permission_id FROM role_permissions WHERE role_id = 1)
END FUNCTION
```

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- All mouse/keyboard interactions in modals unrelated to item loading, save actions, or edit mode continue to work
- Creating new POs and invoices via POST continues to work identically
- All valid salary records (correct date ranges, tax ≤ gross) continue to be accepted and calculated correctly
- Payment and collection operations that succeed (including audit) continue to commit atomically
- All non-BUSINESS_OWNER role permissions remain unchanged
- Empty API responses for items continue to produce empty dropdowns gracefully
- Sequential number generation without deletions continues to produce correct sequential numbers
- Marking invoices as PAID with a valid same-business account continues to work
- Deleting expense categories with no linked expenses continues to succeed

**Scope:**
All inputs that do NOT match any of the eight bug conditions should be completely unaffected by these fixes. This includes:
- Normal CRUD operations on all entities
- All existing API response handling for non-items endpoints
- All existing permission checks for non-BUSINESS_OWNER roles
- All existing financial calculations

## Hypothesized Root Cause

### Bug A — Incorrect property access on API response

The `ItemsController::index()` calls `$this->json(true, ['items' => $items])`. The `BaseController::json()` wraps this as `{ success: true, data: { items: [...] } }`. In both `poForm.modal.js` and `invoiceForm.modal.js`, `loadItems()` does:
```js
itemsList = json.success ? (json.data ?? []) : [];
```
This assigns the wrapper object `{ items: [...] }` to `itemsList` instead of the array `json.data.items`. The `buildItemOptions()` function then calls `itemsList.forEach(...)` which iterates over nothing useful since plain objects don't have a `forEach` method that yields item rows.

**Root cause**: Missing `.items` property access on `json.data`.

### Bug B — COUNT(*) instead of MAX() for sequence generation

All three `generate*Number()` methods use `SELECT COUNT(*) ... WHERE business_id = :business AND YEAR(...) = :year`. After deletions, `COUNT(*)` returns a lower number than the highest existing suffix, causing collisions.

**Root cause**: Using `COUNT(*)` (which decreases on delete) instead of `MAX()` on the numeric suffix (which is monotonically non-decreasing).

### Bug C — Missing business-scope validation on account

`SalesInvoiceService::updateStatus()` uses `$extra['account_id']` directly without calling `AccountRepository::findById($accountId, $ctx->business_id)` to verify ownership.

**Root cause**: Missing authorization check before using the account.

### Bug D — Non-atomic check-then-delete

`ExpenseService::deleteCategory()` calls `countExpensesForCategory()` and `deleteCategory()` as separate operations without a transaction, creating a TOCTOU (time-of-check-time-of-use) window.

**Root cause**: Missing `beginTransaction()`/`commit()` wrapper around the check-and-delete sequence.

### Bug E — Missing business rule validations

`SalaryService::validateData()` checks for required fields but does not enforce `period_end >= period_start` or `tax_fc <= gross_amount_fc`.

**Root cause**: Incomplete validation logic.

### Bug F — Edit modal attempts unsupported PUT

The JS modals have a save handler that sends PUT for existing records, but the PHP controllers return 422 for PUT. The modals should not allow editing existing records since the backend doesn't support it.

**Root cause**: Modal UI does not distinguish between create and view/read-only modes.

### Bug G — Audit log after commit

In `PaymentService` and `CollectionService`, `audit->log()` is called after `$this->db->commit()`, outside the transaction boundary.

**Root cause**: `audit->log()` placed after the `try/catch` block that contains the transaction, instead of inside it before `commit()`.

### Bug H — Hardcoded permission ID threshold

The original migration used `WHERE id > 25` which is fragile. The fix migration `fix_business_owner_permissions.sql` already exists and uses `INSERT IGNORE` with a subquery.

**Root cause**: Original migration assumed exactly 25 pre-existing permissions.

## Correctness Properties

Property 1: Bug Condition A — Item List Parsing

_For any_ PO or Invoice modal open event where the API returns a successful response with items, the fixed `loadItems()` function SHALL assign `json.data?.items ?? json.data ?? []` to `itemsList`, resulting in an array of item objects that `buildItemOptions()` can iterate.

**Validates: Requirements 2.1, 2.2**

Property 2: Preservation A — Empty/Failed Item Responses

_For any_ API response where `success` is false or `data.items` is empty/missing, the fixed `loadItems()` SHALL produce the same empty `itemsList` as the original code, preserving graceful degradation.

**Validates: Requirements 3.1, 3.2**

Property 3: Bug Condition B — Unique Number Generation

_For any_ sequence of create and delete operations on POs, invoices, or collection slips within a business and year, the fixed `generate*Number()` functions SHALL always produce a number whose numeric suffix is strictly greater than any existing suffix, ensuring uniqueness.

**Validates: Requirements 2.3, 2.4, 2.5**

Property 4: Preservation B — First Number Generation

_For any_ business with no existing records for the current year, the fixed `generate*Number()` functions SHALL produce `*-{YYYY}-001` as the first number, identical to the original behavior.

**Validates: Requirements 3.3, 3.4, 3.5, 3.6**

Property 5: Bug Condition C — Account Business Validation

_For any_ sales invoice PAY transition where `account_id` is provided, the fixed `updateStatus()` SHALL verify the account belongs to the current business and throw a `RuntimeException` if it does not.

**Validates: Requirements 2.6**

Property 6: Preservation C — Valid Account PAY Flow

_For any_ sales invoice PAY transition where `account_id` belongs to the current business or is null, the fixed code SHALL produce the same result as the original code (collection slip created, balance updated, or no slip if null).

**Validates: Requirements 3.7, 3.8**

Property 7: Bug Condition D — Atomic Category Deletion

_For any_ expense category deletion request, the fixed `deleteCategory()` SHALL execute the count check and delete within a single database transaction, preventing concurrent inserts from violating the guard.

**Validates: Requirements 2.7**

Property 8: Preservation D — Category Deletion Behavior

_For any_ expense category deletion where no concurrent requests exist, the fixed code SHALL produce the same result as the original code (successful delete if no expenses, rejection if expenses exist).

**Validates: Requirements 3.9, 3.10**

Property 9: Bug Condition E — Salary Date and Tax Validation

_For any_ salary create/update where `period_end < period_start` OR `tax_fc > gross_amount_fc`, the fixed `validateData()` SHALL throw an `InvalidArgumentException` with a descriptive message.

**Validates: Requirements 2.8, 2.9**

Property 10: Preservation E — Valid Salary Calculations

_For any_ salary create/update where `period_end >= period_start` AND `tax_fc <= gross_amount_fc`, the fixed code SHALL produce the same calculated amounts and stored record as the original code.

**Validates: Requirements 3.11, 3.12**

Property 11: Bug Condition F — Read-Only Edit Modals

_For any_ PO or Invoice modal opened with an existing record ID, the fixed modal SHALL disable all form inputs and hide the save button, preventing the user from triggering the unsupported PUT endpoint.

**Validates: Requirements 2.10, 2.11**

Property 12: Preservation F — New Record Modal Behavior

_For any_ PO or Invoice modal opened without an existing record ID (create mode), the fixed code SHALL allow full form editing and POST submission, identical to the original behavior.

**Validates: Requirements 3.13, 3.14**

Property 13: Bug Condition G — Audit Inside Transaction

_For any_ payment or collection create/delete operation, the fixed code SHALL call `audit->log()` inside the database transaction (before `commit()`), ensuring atomicity between the financial operation and its audit trail.

**Validates: Requirements 2.12, 2.13, 2.14, 2.15**

Property 14: Preservation G — Successful Operation Atomicity

_For any_ payment or collection operation where both the financial operation and audit log succeed, the fixed code SHALL commit all changes atomically, identical to the original behavior's intent.

**Validates: Requirements 3.15, 3.16**

Property 15: Bug Condition H — Complete Permission Grant

_For any_ system where the fix migration has been executed, BUSINESS_OWNER (role_id=1) SHALL have every permission in the `permissions` table assigned in `role_permissions`.

**Validates: Requirements 2.16**

Property 16: Preservation H — Other Roles Unchanged

_For any_ role other than BUSINESS_OWNER, the fix migration SHALL not modify their `role_permissions` entries.

**Validates: Requirements 3.17, 3.18**

## Fix Implementation

### Changes Required


### Bug A Fix — `poForm.modal.js` and `invoiceForm.modal.js`

**File**: `public/assets/js/pages/PurchaseOrders/poForm.modal.js`
**Function**: `loadItems()`

**Current code:**
```js
itemsList = json.success ? (json.data ?? []) : [];
```

**Fixed code:**
```js
itemsList = json.success ? (json.data?.items ?? []) : [];
```

**File**: `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js`
**Function**: `loadItems()`

**Current code:**
```js
itemsList = json.success ? (json.data ?? []) : [];
```

**Fixed code:**
```js
itemsList = json.success ? (json.data?.items ?? []) : [];
```

---

### Bug B Fix — `PurchaseOrderRepository.php`, `SalesInvoiceRepository.php`

**File**: `src/Repository/PurchaseOrderRepository.php`
**Function**: `generatePoNumber()`

**Current code:**
```php
public function generatePoNumber(int $businessId): string {
    $year = date('Y');
    $stmt = $this->db->prepare("
        SELECT COUNT(*) AS cnt
        FROM purchase_orders
        WHERE business_id = :business
          AND YEAR(po_date) = :year
    ");
    $stmt->execute(['business' => $businessId, 'year' => $year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = (int) $row['cnt'] + 1;
    return 'PO-' . $year . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}
```

**Fixed code:**
```php
public function generatePoNumber(int $businessId): string {
    $year = date('Y');
    $prefix = 'PO-' . $year . '-';
    $stmt = $this->db->prepare("
        SELECT MAX(CAST(SUBSTRING(po_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq
        FROM purchase_orders
        WHERE business_id = :business
          AND po_number LIKE :prefix
    ");
    $prefixLen = strlen($prefix);
    $stmt->execute([
        'prefixLen' => $prefixLen,
        'business'  => $businessId,
        'prefix'    => $prefix . '%',
    ]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = ($row['max_seq'] !== null) ? ((int) $row['max_seq'] + 1) : 1;
    return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}
```

**File**: `src/Repository/SalesInvoiceRepository.php`
**Function**: `generateInvoiceNumber()`

**Current code:**
```php
public function generateInvoiceNumber(int $businessId): string {
    $year = date('Y');
    $stmt = $this->db->prepare("
        SELECT COUNT(*) AS cnt
        FROM sales_invoices
        WHERE business_id = :business
          AND YEAR(invoice_date) = :year
    ");
    $stmt->execute(['business' => $businessId, 'year' => $year]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = (int) $row['cnt'] + 1;
    return 'INV-' . $year . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}
```

**Fixed code:**
```php
public function generateInvoiceNumber(int $businessId): string {
    $year = date('Y');
    $prefix = 'INV-' . $year . '-';
    $stmt = $this->db->prepare("
        SELECT MAX(CAST(SUBSTRING(invoice_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq
        FROM sales_invoices
        WHERE business_id = :business
          AND invoice_number LIKE :prefix
    ");
    $prefixLen = strlen($prefix);
    $stmt->execute([
        'prefixLen' => $prefixLen,
        'business'  => $businessId,
        'prefix'    => $prefix . '%',
    ]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = ($row['max_seq'] !== null) ? ((int) $row['max_seq'] + 1) : 1;
    return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}
```

**File**: `src/Repository/SalesInvoiceRepository.php`
**Function**: `generateSlipNumber()`

**Current code:**
```php
public function generateSlipNumber(int $businessId): string {
    $year = date('Y');
    $stmt = $this->db->prepare("
        SELECT COUNT(*) AS cnt
        FROM collection_slips
        WHERE business_id = :business
          AND YEAR(slip_date) = :year
    ");
    $stmt->execute(['business' => $businessId, 'year' => $year]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = (int) $row['cnt'] + 1;
    return 'CS-' . $year . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}
```

**Fixed code:**
```php
public function generateSlipNumber(int $businessId): string {
    $year = date('Y');
    $prefix = 'CS-' . $year . '-';
    $stmt = $this->db->prepare("
        SELECT MAX(CAST(SUBSTRING(slip_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq
        FROM collection_slips
        WHERE business_id = :business
          AND slip_number LIKE :prefix
    ");
    $prefixLen = strlen($prefix);
    $stmt->execute([
        'prefixLen' => $prefixLen,
        'business'  => $businessId,
        'prefix'    => $prefix . '%',
    ]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = ($row['max_seq'] !== null) ? ((int) $row['max_seq'] + 1) : 1;
    return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}
```

---

### Bug C Fix — `SalesInvoiceService.php`

**File**: `src/Services/SalesInvoiceService.php`
**Function**: `updateStatus()`

**Change**: Add account-business validation before creating the collection slip. Insert the following check immediately after `$accountId` is determined and before the `createCollectionSlip()` call:

**Current code (inside the `if ($accountId !== null)` block):**
```php
$accountId = isset($extra['account_id']) && $extra['account_id'] !== '' ? (int) $extra['account_id'] : null;
if ($accountId !== null) {
    $slipNumber = $this->repo->generateSlipNumber($ctx->business_id);
```

**Fixed code:**
```php
$accountId = isset($extra['account_id']) && $extra['account_id'] !== '' ? (int) $extra['account_id'] : null;
if ($accountId !== null) {
    // Validate account belongs to the current business
    $account = $this->accountRepo->findById($accountId, $ctx->business_id);
    if (!$account) {
        throw new \RuntimeException('Account not found or does not belong to this business');
    }
    $slipNumber = $this->repo->generateSlipNumber($ctx->business_id);
```

---

### Bug D Fix — `ExpenseService.php`

**File**: `src/Services/ExpenseService.php`
**Function**: `deleteCategory()`

**Current code:**
```php
public function deleteCategory(int $id): void {
    $ctx    = AuthContext::get();
    $before = $this->repo->findCategoryById($id, $ctx->tenant_id);

    if (!$before) {
        throw new \RuntimeException('Category not found');
    }

    if ($this->repo->countExpensesForCategory($id) > 0) {
        throw new \RuntimeException('Cannot delete: category has linked expenses');
    }

    $this->repo->deleteCategory($id, $ctx->tenant_id);

    $this->audit->log(
        'EXPENSE_CATEGORIES',
        'expense_categories',
        $id,
        'DELETE',
        $before,
        null,
        $ctx->user_id,
        $ctx->tenant_id,
        $ctx->business_id
    );
}
```

**Fixed code:**
```php
public function deleteCategory(int $id): void {
    $ctx    = AuthContext::get();
    $before = $this->repo->findCategoryById($id, $ctx->tenant_id);

    if (!$before) {
        throw new \RuntimeException('Category not found');
    }

    $this->db->beginTransaction();
    try {
        if ($this->repo->countExpensesForCategory($id) > 0) {
            throw new \RuntimeException('Cannot delete: category has linked expenses');
        }

        $this->repo->deleteCategory($id, $ctx->tenant_id);

        $this->audit->log(
            'EXPENSE_CATEGORIES',
            'expense_categories',
            $id,
            'DELETE',
            $before,
            null,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );

        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

---

### Bug E Fix — `SalaryService.php`

**File**: `src/Services/SalaryService.php`
**Function**: `validateData()`

**Change**: Add two new validation checks at the end of the existing `validateData()` method, after the existing required-field checks:

**Append after the existing `gross_amount_fc` check:**
```php
// Date range validation
if ($data['period_end'] < $data['period_start']) {
    throw new \InvalidArgumentException('Period end must not be before period start');
}

// Tax cannot exceed gross amount
$grossAmountFc = (float) $data['gross_amount_fc'];
$taxFc = isset($data['tax_fc']) && $data['tax_fc'] !== '' ? (float) $data['tax_fc'] : 0.0;
if ($taxFc > $grossAmountFc) {
    throw new \InvalidArgumentException('Tax cannot exceed gross amount');
}
```

---

### Bug F Fix — `poForm.modal.js` and `invoiceForm.modal.js`

**File**: `public/assets/js/pages/PurchaseOrders/poForm.modal.js`
**Function**: `mount()`

**Change**: After `fetchAndFill()` completes for an existing PO, disable all form fields and hide the save button. Add the following block after the `if (currentPoId) { await fetchAndFill(currentPoId); }` block:

```js
if (currentPoId) {
    await fetchAndFill(currentPoId);
    // Render read-only: disable all inputs and hide save button
    modalRoot.querySelectorAll('input, select, textarea').forEach(el => {
        el.disabled = true;
    });
    modalRoot.querySelectorAll('[data-action="po.save"]').forEach(el => {
        el.style.display = 'none';
    });
    modalRoot.querySelectorAll('[data-action="po.add-line"]').forEach(el => {
        el.style.display = 'none';
    });
    modalRoot.querySelectorAll('[data-action="po.remove-line"]').forEach(el => {
        el.style.display = 'none';
    });
}
```

**File**: `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js`
**Function**: `mount()`

**Change**: Same pattern — after `fetchAndFill()` for an existing invoice, disable all form fields and hide the save button:

```js
if (currentInvoiceId) {
    await fetchAndFill(currentInvoiceId);
    // Render read-only: disable all inputs and hide save button
    modalRoot.querySelectorAll('input, select, textarea').forEach(el => {
        el.disabled = true;
    });
    modalRoot.querySelectorAll('[data-action="invoice.save"]').forEach(el => {
        el.style.display = 'none';
    });
    modalRoot.querySelectorAll('[data-action="invoice.add-line"]').forEach(el => {
        el.style.display = 'none';
    });
    modalRoot.querySelectorAll('[data-action="invoice.remove-line"]').forEach(el => {
        el.style.display = 'none';
    });
}
```

---

### Bug G Fix — `PaymentService.php` and `CollectionService.php`

**File**: `src/Services/PaymentService.php`
**Function**: `create()`

**Change**: Move the `audit->log()` call inside the transaction, before `commit()`:

**Fixed code (transaction block):**
```php
$this->db->beginTransaction();
try {
    $id = $this->repo->create([...]);

    // Outgoing payment: deduct from account balance (negative delta)
    $this->accountRepo->updateBalance($accountId, -$amountBc, $this->db);

    $this->audit->log(
        'PAYMENTS',
        'payment_slips',
        $id,
        'CREATE',
        null,
        [
            'account_id'     => $accountId,
            'payment_number' => trim($data['payment_number']),
            'payment_date'   => $data['payment_date'],
            'currency'       => trim($data['currency']),
            'exchange_rate'  => $exchangeRate,
            'amount_fc'      => $amountFc,
            'amount_bc'      => $amountBc,
            'method'         => trim($data['method']),
        ],
        $ctx->user_id,
        $ctx->tenant_id,
        $ctx->business_id
    );

    $this->db->commit();
} catch (\Throwable $e) {
    $this->db->rollBack();
    throw $e;
}

return $id;
```

**File**: `src/Services/PaymentService.php`
**Function**: `delete()`

**Change**: Move the `audit->log()` call inside the transaction, before `commit()`:

**Fixed code (transaction block):**
```php
$this->db->beginTransaction();
try {
    $this->repo->delete($id, $ctx->business_id);

    // Reverse the deduction: add back the amount (positive delta)
    $this->accountRepo->updateBalance($accountId, +$amountBc, $this->db);

    $this->audit->log(
        'PAYMENTS',
        'payment_slips',
        $id,
        'DELETE',
        $payment,
        null,
        $ctx->user_id,
        $ctx->tenant_id,
        $ctx->business_id
    );

    $this->db->commit();
} catch (\Throwable $e) {
    $this->db->rollBack();
    throw $e;
}
```

**File**: `src/Services/CollectionService.php`
**Function**: `create()`

**Change**: Move the `audit->log()` call inside the transaction, before `commit()`:

**Fixed code (transaction block):**
```php
$this->db->beginTransaction();
try {
    $id = $this->repo->create([...]);

    // Incoming collection: add to account balance (positive delta)
    $this->accountRepo->updateBalance($accountId, +$amountBc, $this->db);

    $this->audit->log(
        'COLLECTIONS',
        'collection_slips',
        $id,
        'CREATE',
        null,
        [
            'account_id'    => $accountId,
            'slip_number'   => trim($data['slip_number']),
            'slip_date'     => $data['slip_date'],
            'currency'      => trim($data['currency']),
            'exchange_rate'  => $exchangeRate,
            'amount_fc'     => $amountFc,
            'amount_bc'     => $amountBc,
            'method'        => trim($data['method']),
        ],
        $ctx->user_id,
        $ctx->tenant_id,
        $ctx->business_id
    );

    $this->db->commit();
} catch (\Throwable $e) {
    $this->db->rollBack();
    throw $e;
}

return $id;
```

**File**: `src/Services/CollectionService.php`
**Function**: `delete()`

**Change**: Move the `audit->log()` call inside the transaction, before `commit()`:

**Fixed code (transaction block):**
```php
$this->db->beginTransaction();
try {
    $this->repo->delete($id, $ctx->business_id);

    // Reverse the addition: deduct the amount (negative delta)
    $this->accountRepo->updateBalance($accountId, -$amountBc, $this->db);

    $this->audit->log(
        'COLLECTIONS',
        'collection_slips',
        $id,
        'DELETE',
        $collection,
        null,
        $ctx->user_id,
        $ctx->tenant_id,
        $ctx->business_id
    );

    $this->db->commit();
} catch (\Throwable $e) {
    $this->db->rollBack();
    throw $e;
}
```

---

### Bug H Fix — `fix_business_owner_permissions.sql`

**File**: `database/migrations/fix_business_owner_permissions.sql`

**Status**: Already implemented correctly. The migration uses `INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 1, p.id FROM permissions p WHERE p.id NOT IN (SELECT rp.permission_id FROM role_permissions rp WHERE rp.role_id = 1)`. This is idempotent and grants all permissions regardless of ID ranges. No code change needed — just ensure this migration is executed.

## Testing Strategy

### Validation Approach

The testing strategy follows a two-phase approach: first, surface counterexamples that demonstrate each bug on unfixed code, then verify the fix works correctly and preserves existing behavior.

### Exploratory Bug Condition Checking

**Goal**: Surface counterexamples that demonstrate each bug BEFORE implementing the fix. Confirm or refute the root cause analysis.

**Test Cases**:

1. **Bug A — Item Loading**: Mock `apiFetch('/api/items')` to return `{ success: true, data: { items: [{id:1,name:'Widget'}] } }`. Assert `itemsList` after `loadItems()` — on unfixed code, `itemsList` will be `{ items: [...] }` (object), not an array. (will fail on unfixed code)

2. **Bug B — PO Number Collision**: Insert PO-2026-001, PO-2026-002, PO-2026-003 for a business. Delete PO-2026-002. Call `generatePoNumber()`. On unfixed code, result is PO-2026-003 (duplicate). (will fail on unfixed code)

3. **Bug C — Cross-Business Account**: Create two businesses with separate accounts. Mark an invoice in business 1 as PAID with an account from business 2. On unfixed code, no error is thrown. (will fail on unfixed code)

4. **Bug D — Race Condition**: Verify that `deleteCategory()` does not wrap its check+delete in a transaction on unfixed code. (structural test — will fail on unfixed code)

5. **Bug E — Invalid Salary Data**: Call `create()` with `period_end < period_start`. On unfixed code, no exception is thrown. Call `create()` with `tax_fc > gross_amount_fc`. On unfixed code, negative net is stored. (will fail on unfixed code)

6. **Bug F — Edit Modal PUT**: Open PO modal with existing PO ID, click save. On unfixed code, a PUT request is sent and returns 422. (will fail on unfixed code)

7. **Bug G — Audit Outside Transaction**: Inspect `PaymentService::create()` — verify `audit->log()` is called after `commit()` on unfixed code. (structural test — will fail on unfixed code)

8. **Bug H — Missing Permissions**: Query `role_permissions` for role_id=1 and compare against all permissions. On unfixed system, some permissions may be missing. (will fail on unfixed system)

**Expected Counterexamples**:
- Bug A: `itemsList.forEach` fails or iterates zero items because `itemsList` is an object
- Bug B: `generatePoNumber()` returns a number that already exists in the table
- Bug C: Collection slip created with cross-business account, no error
- Bug E: Salary record created with negative net_amount_fc

### Fix Checking

**Goal**: Verify that for all inputs where each bug condition holds, the fixed function produces the expected behavior.

**Pseudocode:**
```
FOR ALL input WHERE isBugCondition_A(input) DO
  result := loadItems_fixed(input)
  ASSERT Array.isArray(result.itemsList)
  ASSERT result.itemsList.length === apiResponse.data.items.length
END FOR

FOR ALL input WHERE isBugCondition_B(input) DO
  result := generateNumber_fixed(input)
  ASSERT result NOT IN existingNumbers
END FOR

FOR ALL input WHERE isBugCondition_C(input) DO
  ASSERT updateStatus_fixed(input) THROWS RuntimeException
END FOR

FOR ALL input WHERE isBugCondition_D(input) DO
  ASSERT deleteCategory_fixed uses beginTransaction/commit
END FOR

FOR ALL input WHERE isBugCondition_E(input) DO
  ASSERT create_fixed(input) THROWS InvalidArgumentException
END FOR

FOR ALL input WHERE isBugCondition_F(input) DO
  ASSERT modal inputs are disabled
  ASSERT save button is hidden
END FOR

FOR ALL input WHERE isBugCondition_G(input) DO
  ASSERT audit->log() is called before commit()
END FOR
```

### Preservation Checking

**Goal**: Verify that for all inputs where the bug condition does NOT hold, the fixed function produces the same result as the original function.

**Pseudocode:**
```
FOR ALL input WHERE NOT isBugCondition(input) DO
  ASSERT originalFunction(input) = fixedFunction(input)
END FOR
```

**Testing Approach**: Property-based testing is recommended for preservation checking because:
- It generates many test cases automatically across the input domain
- It catches edge cases that manual unit tests might miss
- It provides strong guarantees that behavior is unchanged for all non-buggy inputs

**Test Cases**:

1. **Item Loading Preservation**: Verify that when API returns `{ success: false }` or `{ success: true, data: { items: [] } }`, `itemsList` is still `[]` after fix
2. **Number Generation Preservation**: Verify that for businesses with no deletions, sequential number generation still produces correct sequential numbers (001, 002, 003...)
3. **Valid Account PAY Preservation**: Verify that marking an invoice as PAID with a valid same-business account still creates the collection slip and updates the balance
4. **Null Account PAY Preservation**: Verify that marking an invoice as PAID without an account_id still works (no collection slip created)
5. **Category Deletion Preservation**: Verify that deleting a category with no linked expenses still succeeds; deleting one with linked expenses still fails
6. **Valid Salary Preservation**: Verify that creating a salary with valid dates and tax ≤ gross still produces correct calculations
7. **New Modal Preservation**: Verify that opening PO/Invoice modals in create mode (no existing ID) still allows full editing and POST submission
8. **Successful Audit Preservation**: Verify that payment/collection operations where audit succeeds still commit atomically

### Unit Tests

- Test `loadItems()` with mocked API responses: success with items, success with empty items, failure
- Test `generatePoNumber()`, `generateInvoiceNumber()`, `generateSlipNumber()` with various existing records and deletions
- Test `SalesInvoiceService::updateStatus()` with cross-business account_id (should throw)
- Test `SalaryService::validateData()` with invalid date ranges and excessive tax
- Test `ExpenseService::deleteCategory()` wraps operations in a transaction
- Test `PaymentService::create()` and `delete()` include audit inside transaction
- Test `CollectionService::create()` and `delete()` include audit inside transaction

### Property-Based Tests

- Generate random sequences of PO creates and deletes, verify all generated PO numbers are unique
- Generate random salary data with arbitrary date ranges and tax/gross ratios, verify validation correctly accepts valid data and rejects invalid data
- Generate random account_id values (some belonging to the business, some not), verify the PAY transition correctly validates ownership
- Generate random item API responses, verify `loadItems()` always produces a valid array

### Integration Tests

- End-to-end test: create POs, delete some, create more — verify no duplicate PO numbers
- End-to-end test: create invoice, mark as PAID with valid account — verify collection slip and balance update
- End-to-end test: create invoice, attempt PAID with cross-business account — verify rejection
- End-to-end test: create salary with invalid dates — verify 422 response
- End-to-end test: create payment, verify audit log entry exists in same transaction
- End-to-end test: run fix migration, verify BUSINESS_OWNER has all permissions
