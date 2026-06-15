# ERP Polish & Fixes — Design

## Overview

This document describes the technical design for all fixes and features in the ERP Polish & Fixes spec. Each section maps to one or more requirements and specifies the exact files, SQL, and code changes needed.

## Architecture

The system follows a strict 3-layer architecture: **Controller → Service → Repository**. All fixes and new features MUST follow this pattern.

- **Database changes** are delivered as plain SQL migration files in `database/migrations/`
- **Backend changes** touch only the relevant Repository, Service, and Controller — no cross-layer shortcuts
- **Frontend changes** follow the existing modal pattern: HTML template in `public/html/Module/` + JS controller in `public/assets/js/pages/Module/`
- **New routes** are registered in the corresponding `public/api/routes/*.php` file with explicit permission codes
- **No new dependencies** are introduced without justification; DOMPDF and PHPMailer are the only new Composer packages required

All monetary values continue to be stored in dual-currency format (`_fc` for foreign currency, `_bc` for base currency). All new tables follow the existing naming conventions and include `created_at` and `updated_at` timestamps.

## Components and Interfaces

### New PHP Classes

| Class | Layer | Purpose |
|-------|-------|---------|
| `src/Services/PdfService.php` | Service | Wraps DOMPDF; accepts HTML string, outputs PDF response |
| `src/Templates/InvoicePdf.php` | Template | HTML template for invoice PDF rendering |
| `src/Templates/PurchaseOrderPdf.php` | Template | HTML template for PO PDF rendering |
| `src/Services/EmailService.php` | Service | Wraps PHPMailer; sends transactional emails |
| `src/Repository/CreditNoteRepository.php` | Repository | CRUD for credit_notes and credit_note_items |
| `src/Services/CreditNoteService.php` | Service | Business logic for issuing credit notes (stock reversal, balance adjustment) |
| `src/Controllers/CreditNoteController.php` | Controller | HTTP endpoints for credit note CRUD |
| `src/Repository/DebitNoteRepository.php` | Repository | CRUD for debit_notes and debit_note_items |
| `src/Services/DebitNoteService.php` | Service | Business logic for issuing debit notes |
| `src/Controllers/DebitNoteController.php` | Controller | HTTP endpoints for debit note CRUD |
| `src/Repository/PaymentAllocationRepository.php` | Repository | CRUD for payment_allocations; sumForDocument() |
| `src/Repository/CollectionAllocationRepository.php` | Repository | CRUD for collection_allocations; sumForDocument() |
| `src/Repository/BusinessModuleOverrideRepository.php` | Repository | ~~Deprecated — replaced by tenant-owned business types~~ |
| `src/Repository/BusinessTypeRepository.php` | Repository | CRUD + module management for tenant-owned business types |
| `src/Repository/NotificationPreferenceRepository.php` | Repository | Read/write notification_preferences table |

### Modified PHP Classes

| Class | Change |
|-------|--------|
| `src/Repository/DashboardRepository.php` | Fix `order_date` → `po_date`; fix status filter |
| `src/Repository/AuditRepository.php` | Fix `u.name` → `CONCAT(u.first_name, ' ', u.last_name)` |
| `src/Services/SettlementEngine.php` | Rewrite to document-level settlement |
| `src/Services/CollectionService.php` | Use new settlement engine; set PAID status |
| `src/Services/PaymentService.php` | Use new settlement engine for POs |
| `src/Services/ModuleActivationService.php` | Simplify to single-table lookup via business type; remove override fallback |
| `src/Services/StockValidator.php` | Add `assertSufficientStock()` method |
| `src/Services/SalesInvoiceService.php` | Call stock validation before delivery |
| `src/Services/ManufacturingService.php` | Call stock validation before production |
| `src/Services/AuthService.php` | Add password reset methods |
| `src/Services/ScheduleGeneratorService.php` | Add `generateForAllBusinesses()` |
| `src/Services/RecurrenceService.php` | Add `markOverdueInstances()` |
| `src/Middleware/AuthMiddleware.php` | Check `users.is_active` and `businesses.is_active` |
| `src/Repository/SalesInvoiceRepository.php` | Add tax, discount, due_date fields; add aging query |
| `src/Repository/PurchaseOrderRepository.php` | Add tax and discount fields |
| `src/Repository/ServiceRepository.php` | Add `updated_at` to INSERT/UPDATE |
| `src/Repository/UserRepository.php` | Add `setActive()` and `isTenantOwner()` methods |
| `src/Repository/BusinessRepository.php` | Add `setActive()` method |
| `src/Repository/NotificationRepository.php` | Remove user_notifications references |
| `src/Services/ReportService.php` | Add P&L, Cash Flow, Balance Sheet methods |
| `src/Controllers/StatementController.php` | Add report endpoints |
| `src/Services/UserService.php` | Add `toggleActive()` and tenant-owner guard in `assignRole()` |
| `src/Config/config.php` | Load from .env file |

### New API Endpoints

| Method | Path | Controller | Permission |
|--------|------|-----------|-----------|
| GET | `/sales-invoices/{id}/pdf` | SalesInvoicesController | `invoices.view` |
| GET | `/purchase-orders/{id}/pdf` | PurchaseOrdersController | `purchase_orders.view` |
| GET | `/sales-invoices/aging` | SalesInvoicesController | `invoices.view` |
| GET | `/reports/profit-loss` | StatementController | `reports.view` |
| GET | `/reports/cash-flow` | StatementController | `reports.view` |
| GET | `/reports/balance-sheet` | StatementController | `reports.view` |
| PATCH | `/users/{id}/active` | UsersController | `users.manage` |
| PATCH | `/businesses/{id}/active` | BusinessesController | `businesses.manage` |
| POST | `/auth/forgot-password` | AuthController | public |
| POST | `/auth/reset-password` | AuthController | public |
| GET | `/cron/generate-recurrences` | (standalone script) | CRON_SECRET token |
| POST | `/credit-notes` | CreditNoteController | `invoices.manage` |
| GET | `/credit-notes/{id}` | CreditNoteController | `invoices.view` |
| POST | `/debit-notes` | DebitNoteController | `purchase_orders.manage` |
| GET | `/debit-notes/{id}` | DebitNoteController | `purchase_orders.view` |
| GET | `/tenant/business-types` | BusinessTypesController | `tenant.manage` |
| POST | `/tenant/business-types` | BusinessTypesController | `tenant.manage` |
| PUT | `/tenant/business-types/{id}/modules` | BusinessTypesController | `tenant.manage` |
| DELETE | `/tenant/business-types/{id}` | BusinessTypesController | `tenant.manage` |
| GET | `/tenant/business-types/system` | BusinessTypesController | `tenant.manage` |

## Data Models

### New Tables

**password_reset_tokens**
```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT NOT NULL,
token_hash CHAR(64) NOT NULL,
expires_at DATETIME NOT NULL,
used_at DATETIME NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

**settings_smtp**
```sql
business_id INT PRIMARY KEY,
host VARCHAR(255) NOT NULL,
port SMALLINT NOT NULL DEFAULT 587,
username VARCHAR(255) NOT NULL,
password_enc TEXT NOT NULL,
from_address VARCHAR(255) NOT NULL,
from_name VARCHAR(255) NOT NULL,
is_active TINYINT(1) NOT NULL DEFAULT 0
```

**credit_notes**
```sql
id INT AUTO_INCREMENT PRIMARY KEY,
business_id INT NOT NULL,
sales_invoice_id INT NOT NULL,
credit_note_number VARCHAR(100) NOT NULL,
credit_note_date DATE NOT NULL,
status ENUM('DRAFT','ISSUED') NOT NULL DEFAULT 'DRAFT',
currency VARCHAR(10) NOT NULL,
exchange_rate DECIMAL(12,6) NOT NULL DEFAULT 1.0,
total_amount_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00,
total_amount_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00,
notes TEXT NULL,
created_at DATETIME NOT NULL,
created_by INT NULL
```

**credit_note_items**
```sql
id INT AUTO_INCREMENT PRIMARY KEY,
credit_note_id INT NOT NULL,
item_id INT NULL,
service_id INT NULL,
quantity DECIMAL(12,3) NOT NULL,
unit_price_fc DECIMAL(12,2) NOT NULL,
unit_price_bc DECIMAL(12,2) NOT NULL,
total_price_fc DECIMAL(12,2) NOT NULL,
total_price_bc DECIMAL(12,2) NOT NULL
```

**debit_notes** and **debit_note_items** — same structure as credit notes but referencing `purchase_order_id` and items only (no services).

### Schema Alterations

| Table | Column Added | Type | Default |
|-------|-------------|------|---------|
| sales_invoices | status ENUM | add 'PAID' value | — |
| sales_invoices | due_date | DATE NULL | NULL |
| sales_invoices | tax_total_fc | DECIMAL(12,2) | 0.00 |
| sales_invoices | tax_total_bc | DECIMAL(12,2) | 0.00 |
| sales_invoices | discount_percent | DECIMAL(5,2) | 0.00 |
| si_details | tax_rate | DECIMAL(5,2) | 0.00 |
| si_details | tax_amount_fc | DECIMAL(12,2) | 0.00 |
| si_details | tax_amount_bc | DECIMAL(12,2) | 0.00 |
| si_details | discount_percent | DECIMAL(5,2) | 0.00 |
| purchase_orders | tax_total_fc | DECIMAL(12,2) | 0.00 |
| purchase_orders | tax_total_bc | DECIMAL(12,2) | 0.00 |
| purchase_orders | discount_percent | DECIMAL(5,2) | 0.00 |
| po_details | tax_rate | DECIMAL(5,2) | 0.00 |
| po_details | tax_amount_fc | DECIMAL(12,2) | 0.00 |
| po_details | tax_amount_bc | DECIMAL(12,2) | 0.00 |
| po_details | discount_percent | DECIMAL(5,2) | 0.00 |
| users | is_active | TINYINT(1) | 1 |
| businesses | is_active | TINYINT(1) | 1 |
| services | updated_at | DATETIME NULL | NULL |
| recipes | yield_quantity | DECIMAL(12,4) | 1.0000 |
| payment_slips | reference_type ENUM | remove 'PO' | — |
| payment_slips | reference_type, reference_id | DROP (moved to payment_allocations) | — |
| collection_slips | reference_type, reference_id | DROP (moved to collection_allocations) | — |
| expenses | reference_number | VARCHAR(100) NULL | NULL |
| expenses | reference_date | DATE NULL | NULL |
| expenses | attachment_path | VARCHAR(500) NULL | NULL |
| roles | name UNIQUE | change to (name, business_id) | — |
| business_types | code UNIQUE | change to (code, tenant_id) | — |

## Correctness Properties

### Property 1: Invoice status forward-only transitions
*For any* sales invoice in DELIVERED status, the system SHALL only allow transitioning to PAID (via full collection) or CANCELLED. No transition back to DRAFT SHALL be permitted.
**Validates: Requirements 1.1**

### Property 2: Partner share total cap
*For any* business, the sum of all partner_shares.share_percent values SHALL NOT exceed 100 at any point in time.
**Validates: Requirements 4.21**

### Property 3: Non-negative stock
*For any* OUT stock transaction (invoice delivery, production order, manual adjustment), if the requested quantity exceeds the current stock quantity, the operation SHALL be rejected and stock SHALL remain unchanged.
**Validates: Requirements 1.6**

### Property 4: Credit note amount cap
*For any* credit note issued against a sales invoice, the credit note total_amount_bc SHALL NOT exceed the original invoice's total_amount_bc.
**Validates: Requirements 3.24**

### Property 5: Debit note amount cap
*For any* debit note issued against a purchase order, the debit note total_amount_bc SHALL NOT exceed the original PO's total_amount_bc.
**Validates: Requirements 3.25**

### Property 6: Password reset token single-use expiry
*For any* password reset token, the token SHALL be rejected if its expires_at is in the past OR if used_at is non-null. A successful reset SHALL set used_at to the current timestamp, making the token invalid for any subsequent use.
**Validates: Requirements 3.17**

### Property 7: Payee balance consistency
*For any* payee, the stored balance SHALL equal the algebraic sum of all transactions that affect it: positive for amounts owed to the payee (invoices delivered, POs received, expenses created) and negative for amounts paid (payment slips, collection slips).
**Validates: Requirements 4.21**

### Property 8: Tax calculation precision
*For any* invoice or PO line with a non-zero tax_rate, the stored tax_amount_bc SHALL equal `round(total_price_bc × (tax_rate / 100), 2)`.
**Validates: Requirements 3.14**

### Property 9: Discount applied before tax
*For any* invoice or PO line with both a discount_percent and a tax_rate, the line total SHALL be computed as `unit_price × qty × (1 - discount_percent/100)` and tax SHALL be applied to that discounted total, not to the pre-discount amount.
**Validates: Requirements 3.14, 3.19**

### Property 10: Tenant owner cannot hold business roles in own tenant
*For any* user who holds a TENANT_OWNER role in tenant X, the system SHALL reject any attempt to assign that user a business-scoped role (business_id IS NOT NULL) within tenant X.
**Validates: REQ-026**

### Property 11: Businesses only assigned to tenant-owned types
*For any* business, its `business_type_id` SHALL reference a `business_types` row where `is_system = 0` and `tenant_id = business.tenant_id`. Direct assignment to system types (`is_system = 1`) SHALL be rejected.
**Validates: REQ-011**

### Property 12: Payment allocation sum equals slip amount
*For any* payment slip, the sum of `payment_allocations.amount_bc` WHERE `payment_slip_id = slip.id` SHALL equal `slip.amount_bc`. Same for collection slips and `collection_allocations`.
**Validates: REQ-021**

### Property 13: No over-allocation on documents
*For any* document (invoice, PO, expense, salary), the sum of all allocations referencing it SHALL NOT exceed the document's total amount. Partial allocation is allowed; over-allocation is rejected.
**Validates: REQ-021**

## Error Handling

- All migration scripts MUST be wrapped in transactions where possible so they can be rolled back on failure
- Stock validation failures MUST return HTTP 422 with a message identifying the specific item and available quantity
- PDF generation failures MUST return HTTP 500 with a generic error (do not expose DOMPDF internals)
- Email send failures MUST be logged but MUST NOT block the password reset token creation — the user can request again
- Settlement engine MUST handle the case where `total_amount_bc = 0` (free invoice) by treating it as immediately PAID
- Foreign key constraint violations MUST return HTTP 409 with a human-readable message (not a raw SQL error)
- Cron endpoint MUST return HTTP 403 for missing or invalid CRON_SECRET; MUST return HTTP 200 with a JSON summary on success

## Testing Strategy

- **Phase 1 bugs**: Each fix has a specific test case described in the task. Run `vendor/bin/phpunit` after each fix.
- **Migrations**: Test each migration on a copy of the database before running on production. Verify row counts before and after.
- **Settlement engine rewrite**: Write integration tests covering: full payment, partial payment, overpayment, multi-business isolation.
- **Stock validation**: Write unit tests for `assertSufficientStock()` covering: exact quantity, insufficient quantity, zero stock, fractional quantities.
- **PDF generation**: Manual visual inspection of generated PDFs for layout correctness.
- **Email reset**: Test with a real SMTP server or Mailtrap for email delivery verification.
- **Foreign keys**: Test each constraint by attempting to violate it and verifying the correct error is returned.

---

## Phase 1 — Critical Bug Fixes

### Design: REQ-001 — Fix sales_invoices PAID status

**Migration file:** `database/migrations/fix_sales_invoice_paid_status.sql`

```sql
-- Step 1: Add PAID to the ENUM
ALTER TABLE sales_invoices
  MODIFY COLUMN status ENUM('DRAFT','DELIVERED','PAID','CANCELLED') NOT NULL DEFAULT 'DRAFT';

-- Step 2: Mark fully-paid DELIVERED invoices as PAID
-- An invoice is fully paid when sum of collection slips >= total_amount_bc
UPDATE sales_invoices si
SET si.status = 'PAID'
WHERE si.status = 'DELIVERED'
  AND (
    SELECT COALESCE(SUM(cs.amount_bc), 0)
    FROM collection_slips cs
    WHERE cs.reference_id = si.id
      AND cs.business_id  = si.business_id
  ) >= si.total_amount_bc
  AND si.total_amount_bc > 0;
```

**Backend changes:**

- `src/Services/CollectionService.php` — after creating a collection slip, call `SettlementEngine::evaluateCustomerSettlement()`. If the invoice is fully paid, update its status to `'PAID'` via `SalesInvoiceRepository::updateStatus()`
- `src/Repository/DashboardRepository::getMonthlyRevenue()` — change `status = 'PAID'` (already correct once ENUM is fixed)
- `src/Services/BusinessProfitService.php` — revenue calculation already uses `status = 'PAID'`, will work once ENUM is fixed

**No frontend changes needed** — the status badge display already handles unknown statuses gracefully.

---

### Design: REQ-002 — Fix DashboardRepository payables query

**File:** `src/Repository/DashboardRepository.php`

Change in `getMonthlyPayables()`:
```php
// BEFORE (broken):
AND YEAR(order_date)  = :year
AND MONTH(order_date) = :month

// AFTER (correct):
AND YEAR(po_date)  = :year
AND MONTH(po_date) = :month
```

Also fix the status filter — `purchase_orders` has no `'PAID'` status. Change:
```php
// BEFORE:
AND status NOT IN ('PAID', 'CANCELLED')

// AFTER:
AND status NOT IN ('CLOSED', 'CANCELLED')
```

---

### Design: REQ-003 — Fix AuditRepository user name JOIN

**File:** `src/Repository/AuditRepository.php`

Change in `findForBusiness()` SELECT clause:
```php
// BEFORE:
u.name  AS user_name,
u.email AS user_email

// AFTER:
CONCAT(u.first_name, ' ', u.last_name) AS user_name,
u.email AS user_email
```

---

### Design: REQ-004 — Fix payment_slips reference_type ENUM

**Migration file:** `database/migrations/fix_payment_slips_reference_type.sql`

```sql
-- Step 1: Update existing rows
UPDATE payment_slips SET reference_type = 'PURCHASE_ORDER' WHERE reference_type = 'PO';

-- Step 2: Remove 'PO' from ENUM
ALTER TABLE payment_slips
  MODIFY COLUMN reference_type ENUM('EXPENSE','SALARY','PARTNER_PROFIT','PURCHASE_ORDER') NOT NULL;
```

**Backend search:** grep all PHP files for `'PO'` as a reference_type string and replace with `'PURCHASE_ORDER'`. Key files to check:
- `src/Services/PaymentService.php`
- `src/Services/SettlementEngine.php`
- `src/Repository/PaymentRepository.php`

---

### Design: REQ-005 — Fix roles.name UNIQUE constraint

**Migration file:** `database/migrations/fix_roles_name_unique.sql`

```sql
-- Drop the global unique index
ALTER TABLE roles DROP INDEX name;

-- Add composite unique: name must be unique per business (NULL business_id = system role, allowed to be unique globally)
ALTER TABLE roles ADD UNIQUE KEY uq_roles_name_business (name, business_id);
```

Note: MySQL treats NULL as distinct in unique indexes, so two system roles (business_id = NULL) with the same name would still conflict. The application must ensure system role names remain unique.

---

### Design: REQ-006 — Stock negative prevention

**New/modified file:** `src/Services/StockValidator.php` (already exists — extend it)

Add method:
```php
public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {
    $stock = $this->itemRepo->getStockEntry($itemId, $businessId);
    $current = $stock ? (float)$stock['quantity'] : 0.0;
    if ($current < $quantity) {
        $item = $this->itemRepo->getById($itemId, AuthContext::tenantId());
        $name = $item['name'] ?? "Item #{$itemId}";
        throw new \RuntimeException(
            "Insufficient stock for '{$name}': available {$current}, requested {$quantity}"
        );
    }
}
```

Call this in:
- `src/Services/SalesInvoiceService.php` — before delivering an invoice, validate each line item
- `src/Services/ManufacturingService.php` — before creating a production order, validate each component
- `src/Services/ItemService.php` — before processing a manual OUT adjustment

---

## Phase 2 — Data Integrity & Schema Fixes

### Design: REQ-007 — Add is_active to users

**Migration:** `database/migrations/add_users_is_active.sql`
```sql
ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email;
UPDATE users SET is_active = 1; -- ensure all existing users are active
```

**Backend:**
- `src/Middleware/AuthMiddleware.php` — after loading user from JWT, check `is_active = 1`. Return 401 with message "Account is disabled" if not.
- `src/Repository/UserRepository.php` — add `setActive(int $userId, bool $active): void`
- `src/Services/UserService.php` — add `toggleActive(int $userId): void` that also revokes refresh tokens when deactivating
- `src/Controllers/UsersController.php` — add `PATCH /users/{id}/active` endpoint

**Frontend:**
- `public/assets/js/pages/Users/users.js` — add active/inactive toggle button per row
- `public/html/Users/users.html` — add status column and toggle button

---

### Design: REQ-008 — Fix recipes.yield_quantity type

**Migration:** `database/migrations/fix_recipes_yield_quantity.sql`
```sql
ALTER TABLE recipes MODIFY COLUMN yield_quantity DECIMAL(12,4) NOT NULL DEFAULT 1.0000;
```

**Backend:** No logic changes needed — PHP already handles float arithmetic correctly.

**Frontend:** `public/html/Items/recipeForm.html` — ensure the yield quantity input allows decimal values (`step="0.0001"`).

---

### Design: REQ-009 — Add updated_at to services

**Migration:** `database/migrations/add_services_updated_at.sql`
```sql
ALTER TABLE services ADD COLUMN updated_at DATETIME NULL AFTER created_at;
UPDATE services SET updated_at = created_at;
```

**Backend:** `src/Repository/ServiceRepository.php` — add `updated_at = NOW()` to INSERT and UPDATE statements.

---

### Design: REQ-010 — Add is_active to businesses

**Migration:** `database/migrations/add_businesses_is_active.sql`
```sql
ALTER TABLE businesses ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email;
UPDATE businesses SET is_active = 1;
```

**Backend:**
- `src/Middleware/AuthMiddleware.php` — when resolving business context, check `is_active = 1`. Return 403 if business is suspended.
- `src/Repository/BusinessRepository.php` — add `setActive(int $businessId, int $tenantId, bool $active): void`
- `src/Controllers/BusinessesController.php` — add `PATCH /businesses/{id}/active` endpoint

**Frontend:** `public/assets/js/pages/Tenant/businesses.js` — add active/inactive toggle per business row.

---

### Design: REQ-011 — Tenant-owned business types (replace module overrides)

**Goal:** Eliminate `business_module_overrides` entirely. Module state is determined solely by the business's assigned `business_type_id` → `business_type_modules`. Tenant owners manage module sets by editing their own business types.

**Schema changes:**

```sql
-- 1. Scope the code unique constraint to (code, tenant_id) so tenant types can
--    reuse system codes as a base name without collision.
--    NULL tenant_id = system type (globally unique code).
ALTER TABLE business_types DROP INDEX uq_business_types_code;
ALTER TABLE business_types ADD UNIQUE KEY uq_bt_code_tenant (code, tenant_id);

-- 2. For each tenant that has businesses pointing to a system type,
--    auto-create a tenant-owned copy and re-point the businesses.
--    (Handled in migration: database/migrations/migrate_to_tenant_business_types.sql)
```

**Migration logic (`database/migrations/migrate_to_tenant_business_types.sql`):**
1. For each distinct `(tenant_id, business_type_id)` pair in `businesses` where the type is a system type:
   - Insert a new `business_types` row: `tenant_id = <tenant>`, `is_system = 0`, `code = <system_code>`, `name = <system_name>` (copy)
   - Copy all `business_type_modules` rows from the system type to the new tenant type
   - `UPDATE businesses SET business_type_id = <new_id> WHERE tenant_id = <tenant> AND business_type_id = <system_id>`
2. Clean up orphaned `business_module_overrides` rows (drop table or truncate — it is no longer used)

**Backend:**

- `src/Repository/BusinessTypeRepository.php` — add:
  - `copyFromSystem(int $systemTypeId, int $tenantId, string $name): int` — creates tenant-owned copy with all modules
  - `setModules(int $businessTypeId, array $moduleIds): void` — replaces module set for a tenant type
  - `findByTenant(int $tenantId): array` — returns all tenant-owned types with their module lists
  - `findSystemTypes(): array` — returns all system types (for use as copy templates)
  - Guard: all write methods MUST reject calls where `is_system = 1`

- `src/Services/ModuleActivationService.php` — simplify `isModuleActive()`:
  ```php
  // Single lookup — no overrides table needed
  public function isModuleActive(int $businessId, string $moduleCode): bool {
      $typeId = $this->businessRepo->getBusinessTypeId($businessId);
      return $this->businessTypeRepo->hasModule($typeId, $moduleCode);
  }
  ```

- `src/Controllers/BusinessTypesController.php` — add endpoints:
  - `GET /tenant/business-types` — list tenant-owned types
  - `POST /tenant/business-types` — create by copying a system type (`{ source_system_type_id, name }`)
  - `PUT /tenant/business-types/{id}/modules` — replace module set (`{ module_ids: [...] }`)
  - `DELETE /tenant/business-types/{id}` — delete if no businesses are assigned

- Remove `src/Repository/BusinessModuleOverrideRepository.php` from the new classes list — it is no longer needed.

**Frontend:**

- `public/html/Tenant/businessTypes.html` + `public/assets/js/pages/Tenant/businessTypes.js` — new page showing tenant types with module toggles per type
- `public/html/Tenant/businessTypeForm.html` + `public/assets/js/pages/Tenant/businessTypeForm.modal.js` — modal to create a type by selecting a system template and giving it a name
- The business create/edit form MUST populate the business type dropdown from `GET /tenant/business-types` (tenant-owned only)
- The Settings → Modules page can be simplified or removed — module management now lives on the business type, not per-business

**New API endpoints:**

| Method | Path | Controller | Permission |
|--------|------|-----------|-----------|
| GET | `/tenant/business-types` | BusinessTypesController | `tenant.manage` |
| POST | `/tenant/business-types` | BusinessTypesController | `tenant.manage` |
| PUT | `/tenant/business-types/{id}/modules` | BusinessTypesController | `tenant.manage` |
| DELETE | `/tenant/business-types/{id}` | BusinessTypesController | `tenant.manage` |
| GET | `/tenant/business-types/system` | BusinessTypesController | `tenant.manage` |

---

### Design: REQ-026 — Prevent tenant owners from being assigned business roles

**Problem:** A user with `TENANT_OWNER` role in tenant X should not also hold a business-scoped role (Staff, Manager, etc.) in any business belonging to tenant X. The `user_roles` table allows this today.

**Migration (`database/migrations/fix_tenant_owner_business_roles.sql`):**
```sql
-- Identify and remove conflicting rows:
-- Any user_roles row where the user is a tenant owner of the same tenant
-- AND the row has a non-null business_id (i.e., it's a business-scoped role)
DELETE ur
FROM user_roles ur
WHERE ur.business_id IS NOT NULL
  AND EXISTS (
    SELECT 1 FROM user_roles ur2
    JOIN roles r ON r.id = ur2.role_id
    WHERE ur2.user_id  = ur.user_id
      AND ur2.tenant_id = ur.tenant_id
      AND r.type = 'TENANT_OWNER'
  );
```

**Backend:**

- `src/Services/UserService.php` — in `assignRole(int $userId, int $roleId, int $businessId, int $tenantId)`:
  ```php
  // Guard: reject if user is already a tenant owner of this tenant
  $isTenantOwner = $this->userRepo->isTenantOwner($userId, $tenantId);
  if ($isTenantOwner && $businessId !== null) {
      throw new \InvalidArgumentException(
          'Tenant owners cannot be assigned business-level roles within their own tenant.'
      );
  }
  ```

- `src/Repository/UserRepository.php` — add `isTenantOwner(int $userId, int $tenantId): bool`:
  ```php
  SELECT COUNT(*) FROM user_roles ur
  JOIN roles r ON r.id = ur.role_id
  WHERE ur.user_id = :userId AND ur.tenant_id = :tenantId AND r.type = 'TENANT_OWNER'
  ```

**Frontend:**

- `public/assets/js/pages/Tenant/users.js` (or wherever user-role assignment is managed) — when the selected user is a tenant owner of the current tenant, hide the business role assignment form and show an informational message: "This user is a tenant owner and manages all businesses directly."

---

### Design: REQ-012 — Fix notification table redundancy

**Migration:** `database/migrations/cleanup_notification_tables.sql`
```sql
-- Seed notification_types with known types
INSERT INTO notification_types (id, code, name, description) VALUES
  (1, 'low_stock',        'Low Stock Alert',        'Triggered when item stock falls below min_stock'),
  (2, 'overdue_payment',  'Overdue Payment',         'Triggered when a recurrence instance becomes overdue'),
  (3, 'expiry_alert',     'Item Expiry Alert',       'Triggered when a batch is near expiry'),
  (4, 'invoice_overdue',  'Invoice Overdue',         'Triggered when a sales invoice passes its due date'),
  (5, 'system',           'System Notification',     'General system messages');
```

**Backend:**
- `src/Repository/NotificationRepository.php` — remove all references to `user_notifications` table
- `src/Services/NotificationService.php` — use `notifications` table directly for all read/unread tracking
- `notification_preferences` — add `NotificationPreferenceRepository::setPreference()` and `getPreferences()` methods
- Wire `notification_preferences` into `NotificationService::send()` to check opt-in before creating a notification

---

## Phase 3 — Core Missing Features

### Design: REQ-013 — Invoice and PO PDF generation

**New dependency:** Add `dompdf/dompdf` to `composer.json`

**New files:**
- `src/Services/PdfService.php` — wraps DOMPDF, accepts an HTML template string and returns PDF bytes
- `src/Templates/InvoicePdf.php` — PHP template that renders invoice HTML (business header, customer info, line items table, totals, notes)
- `src/Templates/PurchaseOrderPdf.php` — same for POs
- `src/Controllers/SalesInvoicesController.php` — add `GET /sales-invoices/{id}/pdf` endpoint
- `src/Controllers/PurchaseOrdersController.php` — add `GET /purchase-orders/{id}/pdf` endpoint

**Frontend:**
- `public/assets/js/pages/SalesInvoices/salesInvoices.js` — add "Download PDF" button per invoice row
- `public/assets/js/pages/PurchaseOrders/purchaseOrders.js` — add "Download PDF" button per PO row

**PDF template structure:**
```
[Business Logo] [Business Name]
[Business Address / Phone / Email]
─────────────────────────────────
INVOICE #INV-2026-001          Date: 2026-05-29
                               Due:  2026-06-28
Bill To:
[Customer Name]
[Customer Address]
─────────────────────────────────
# | Item/Service | Qty | Unit Price | Tax% | Total
─────────────────────────────────
                        Subtotal: XXX
                        Tax:      XXX
                        Total:    XXX
Notes: ...
```

---

### Design: REQ-014 — Tax/VAT fields

**Migration:** `database/migrations/add_tax_fields.sql`
```sql
ALTER TABLE si_details
  ADD COLUMN tax_rate     DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER unit_price_bc,
  ADD COLUMN tax_amount_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_rate,
  ADD COLUMN tax_amount_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_amount_fc;

ALTER TABLE po_details
  ADD COLUMN tax_rate     DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER unit_price_bc,
  ADD COLUMN tax_amount_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_rate,
  ADD COLUMN tax_amount_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_amount_fc;

ALTER TABLE sales_invoices
  ADD COLUMN tax_total_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount_bc,
  ADD COLUMN tax_total_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_total_fc;

ALTER TABLE purchase_orders
  ADD COLUMN tax_total_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount_bc,
  ADD COLUMN tax_total_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_total_fc;
```

**Backend:**
- `src/Repository/SalesInvoiceRepository.php` — `addLine()` MUST accept and store tax fields; `recalculateTotals()` MUST sum tax amounts
- `src/Repository/PurchaseOrderRepository.php` — same changes
- Line total formula: `total_price = (unit_price × qty) + tax_amount`

**Frontend:**
- `public/html/SalesInvoices/invoiceForm.html` — add Tax Rate column to line items table
- `public/html/PurchaseOrders/poForm.html` — same
- Invoice/PO totals section MUST show: Subtotal, Tax, Grand Total

---

### Design: REQ-015 — Invoice due date and AR aging

**Migration:** `database/migrations/add_invoice_due_date.sql`
```sql
ALTER TABLE sales_invoices ADD COLUMN due_date DATE NULL AFTER invoice_date;
```

**New endpoint:** `GET /sales-invoices/aging` — returns AR aging buckets

**Backend:**
- `src/Repository/SalesInvoiceRepository.php` — include `due_date` in all SELECT and INSERT queries
- `src/Repository/SalesInvoiceRepository::getAgingReport(int $businessId): array` — returns buckets: current, 1-30, 31-60, 61-90, 90+
- `src/Controllers/SalesInvoicesController.php` — add `GET /sales-invoices/aging` route

**Frontend:**
- `public/html/SalesInvoices/salesInvoices.html` — add Due Date column; highlight overdue rows in red
- New page: `public/html/Finance/arAging.html` + `public/assets/js/pages/Finance/arAging.js`

---

### Design: REQ-016 — Financial reports

**New files:**
- `src/Repository/ReportRepository.php` — (already exists as `StatementRepository.php`, extend it)
- `src/Services/ReportService.php` — (already exists, add P&L, Balance Sheet, Cash Flow methods)
- `src/Controllers/StatementController.php` — add endpoints for each report type

**New endpoints:**
- `GET /reports/profit-loss?start=&end=` — P&L statement
- `GET /reports/cash-flow?start=&end=` — Cash flow statement
- `GET /reports/balance-sheet?as_of=` — Balance sheet

**Frontend:**
- New pages under `public/html/Finance/` and `public/assets/js/pages/Finance/` for each report
- Each report page MUST have date range pickers and a "Download PDF" button

---

### Design: REQ-017 — Email-based password reset

**New table migration:** `database/migrations/add_password_reset_tokens.sql`
```sql
CREATE TABLE password_reset_tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_token_hash (token_hash),
  INDEX idx_user_id (user_id)
);
```

**Settings addition:** `settings_regional` MUST be extended OR a new `settings_smtp` table added:
```sql
CREATE TABLE settings_smtp (
  business_id  INT PRIMARY KEY,
  host         VARCHAR(255) NOT NULL,
  port         SMALLINT NOT NULL DEFAULT 587,
  username     VARCHAR(255) NOT NULL,
  password_enc TEXT NOT NULL,  -- encrypted at rest
  from_address VARCHAR(255) NOT NULL,
  from_name    VARCHAR(255) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 0
);
```

**Backend:**
- `src/Services/AuthService.php` — add `requestPasswordReset(string $email): void` and `resetPassword(string $token, string $newPassword): void`
- `src/Services/EmailService.php` — new service wrapping PHPMailer or native mail()
- `src/Controllers/AuthController.php` — add `POST /auth/forgot-password` and `POST /auth/reset-password` endpoints

**Frontend:**
- `public/html/Auth/forgotPassword.html` — update to show email input and "Send Reset Link" button
- New page: `public/html/Auth/resetPassword.html` + `public/assets/js/pages/Auth/reset-password.js`

---

### Design: REQ-018 — Cron job for recurring instances

**New file:** `public/api/cron/generate-recurrences.php`

```php
// Protected by CRON_SECRET header or query param
// Calls RecurrenceService::generateDueInstances(date: today, lookahead: 30 days)
// Marks overdue instances (due_date < today AND status = 'pending')
// Returns JSON summary: { generated: N, marked_overdue: N }
```

**Backend:**
- `src/Services/ScheduleGeneratorService.php` — (already exists) add `generateForAllBusinesses(string $upToDate): array`
- `src/Services/RecurrenceService.php` — add `markOverdueInstances(): int`

**Documentation:** Add `docs/cron-setup.md` with Windows Task Scheduler and Linux cron setup instructions.

---

### Design: REQ-019 — Discount fields

**Migration:** `database/migrations/add_discount_fields.sql`
```sql
ALTER TABLE si_details
  ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER quantity;

ALTER TABLE po_details
  ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER quantity;

ALTER TABLE sales_invoices
  ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER notes;

ALTER TABLE purchase_orders
  ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER notes;
```

**Line total formula update:**
```
line_total = unit_price × qty × (1 - discount_percent/100)
tax_amount = line_total × (tax_rate/100)
```

---

## Phase 4 — Architecture Improvements

### Design: REQ-020 — Environment variables

**New file:** `.env.example`
```
DB_HOST=localhost
DB_NAME=erp
DB_USER=root
DB_PASS=
JWT_SECRET=change-me-to-a-random-64-char-string
```

**Modified file:** `src/Config/config.php`
```php
// Load .env if it exists
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

$config = [
    'db' => [
        'host'   => $_ENV['DB_HOST']   ?? 'localhost',
        'dbname' => $_ENV['DB_NAME']   ?? 'erp',
        'user'   => $_ENV['DB_USER']   ?? 'root',
        'pass'   => $_ENV['DB_PASS']   ?? '',
    ],
    'jwt_secret' => $_ENV['JWT_SECRET'] ?? 'fallback-dev-secret-change-in-production',
];
```

**Update `.gitignore`:** Add `.env` entry.

---

### Design: REQ-021 — Fix settlement engine with payment allocations

**Migration:** `database/migrations/add_payment_allocations.sql`

```sql
-- 1. Create allocation tables
CREATE TABLE payment_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_slip_id INT NOT NULL,
  reference_type ENUM('PURCHASE_ORDER','EXPENSE','SALARY','PARTNER_PROFIT') NOT NULL,
  reference_id INT NOT NULL,
  amount_fc DECIMAL(12,2) NOT NULL,
  amount_bc DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pa_slip (payment_slip_id),
  KEY idx_pa_ref (reference_type, reference_id),
  CONSTRAINT fk_pa_slip FOREIGN KEY (payment_slip_id) REFERENCES payment_slips(id) ON DELETE CASCADE
);

CREATE TABLE collection_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  collection_slip_id INT NOT NULL,
  reference_type ENUM('SALES_INVOICE','PARTNER_CONTRIBUTION') NOT NULL,
  reference_id INT NOT NULL,
  amount_fc DECIMAL(12,2) NOT NULL,
  amount_bc DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ca_slip (collection_slip_id),
  KEY idx_ca_ref (reference_type, reference_id),
  CONSTRAINT fk_ca_slip FOREIGN KEY (collection_slip_id) REFERENCES collection_slips(id) ON DELETE CASCADE
);

-- 2. Migrate existing data from header-level references to allocations
INSERT INTO payment_allocations (payment_slip_id, reference_type, reference_id, amount_fc, amount_bc, created_at)
SELECT id, reference_type, reference_id, amount_fc, amount_bc, created_at
FROM payment_slips WHERE reference_id IS NOT NULL;

INSERT INTO collection_allocations (collection_slip_id, reference_type, reference_id, amount_fc, amount_bc, created_at)
SELECT id, 'SALES_INVOICE', reference_id, amount_fc, amount_bc, created_at
FROM collection_slips WHERE reference_type = 'SALES_INVOICE' AND reference_id IS NOT NULL;

-- 3. Drop old columns
ALTER TABLE payment_slips DROP COLUMN reference_type, DROP COLUMN reference_id;
ALTER TABLE collection_slips DROP COLUMN reference_type, DROP COLUMN reference_id;
```

**Backend — SettlementEngine rewrite:**

```php
class SettlementEngine {
    // Document-level settlement via allocations
    public function getInvoicePaymentStatus(int $invoiceId, float $totalBc): string {
        $paid = $this->collectionAllocRepo->sumForDocument('SALES_INVOICE', $invoiceId);
        if ($paid <= 0)        return 'UNPAID';
        if ($paid >= $totalBc) return 'PAID';
        return 'PARTIALLY_PAID';
    }

    public function getPoPaymentStatus(int $poId, float $totalBc): string {
        $paid = $this->paymentAllocRepo->sumForDocument('PURCHASE_ORDER', $poId);
        if ($paid <= 0)        return 'UNPAID';
        if ($paid >= $totalBc) return 'CLOSED';
        return 'PARTIALLY_PAID';
    }

    // FIFO auto-allocation: distributes amount across unpaid documents oldest-first
    public function autoAllocateFIFO(int $payeeId, int $businessId, float $amountBc, string $slipType): array {
        if ($slipType === 'collection') {
            $docs = $this->invoiceRepo->getUnpaidForPayee($payeeId, $businessId); // ORDER BY invoice_date ASC
        } else {
            $docs = $this->getUnpaidPayables($payeeId, $businessId); // POs + expenses + salaries, ORDER BY date ASC
        }

        $allocations = [];
        $remaining = $amountBc;
        foreach ($docs as $doc) {
            if ($remaining <= 0) break;
            $docRemaining = $doc['total_amount_bc'] - $doc['already_paid_bc'];
            $allocAmount = min($remaining, $docRemaining);
            $allocations[] = [
                'reference_type' => $doc['type'],
                'reference_id'   => $doc['id'],
                'amount_bc'      => $allocAmount,
            ];
            $remaining -= $allocAmount;
        }
        return $allocations;
    }
}
```

**Frontend — Payment form redesign:**

The payment form becomes a two-step flow:
1. Select payee + enter amount + select account + method
2. Allocation panel appears showing outstanding documents as a DataTable:

```
┌─────────────────────────────────────────────────────────────────┐
│ Payment to: Supplier X          Amount: $5,000.00               │
│ Account: Main Bank              Method: Transfer                │
├─────────────────────────────────────────────────────────────────┤
│ [Auto-fill FIFO]  Allocated: $5,000.00 / $5,000.00  ✓ Balanced │
├────┬──────────┬────────────┬──────────┬───────────┬────────────┤
│ ✓  │ Doc #    │ Date       │ Total    │ Remaining │ Allocate   │
├────┼──────────┼────────────┼──────────┼───────────┼────────────┤
│ ✓  │ PO-001   │ 2026-04-15 │ $2,000   │ $2,000    │ [$2,000 ] │
│ ✓  │ PO-003   │ 2026-05-01 │ $1,500   │ $1,500    │ [$1,500 ] │
│ ✓  │ PO-005   │ 2026-05-10 │ $1,500   │ $1,500    │ [$1,500 ] │
│    │ EXP-012  │ 2026-05-20 │ $800     │ $800      │ [$0     ] │
└────┴──────────┴────────────┴──────────┴───────────┴────────────┘
```

- "Auto-fill FIFO" button populates amounts top-to-bottom until the slip amount is exhausted
- User can manually edit any allocation amount
- Validation: sum of allocations must equal slip amount (shown as "Balanced" indicator)
- Same pattern for collection form with invoices

**Payment/Collection detail view:**

Each slip row in the list has an expandable section or "View Details" button showing:
```
Payment #PAY-042 — $5,000.00 — 2026-05-30 — Transfer
├── PO-001  $2,000.00  → CLOSED
├── PO-003  $1,500.00  → CLOSED
└── PO-005  $1,500.00  → CLOSED
```

**Payee statement view:**

A full ledger showing all activity for a payee:
```
┌────────────┬──────────────┬──────────┬──────────┬──────────┬────────┐
│ Date       │ Document     │ Debit    │ Credit   │ Balance  │ Status │
├────────────┼──────────────┼──────────┼──────────┼──────────┼────────┤
│ 2026-04-15 │ PO-001       │ $2,000   │          │ -$2,000  │ CLOSED │
│ 2026-05-01 │ PO-003       │ $1,500   │          │ -$3,500  │ CLOSED │
│ 2026-05-10 │ PO-005       │ $1,500   │          │ -$5,000  │ CLOSED │
│ 2026-05-20 │ EXP-012      │ $800     │          │ -$5,800  │ Unpaid │
│ 2026-05-30 │ PAY-042      │          │ $5,000   │ -$800    │        │
└────────────┴──────────────┴──────────┴──────────┴──────────┴────────┘
```

---

### Design: REQ-022 — Foreign key constraints

**Migration:** `database/migrations/add_foreign_keys.sql`

Add constraints in dependency order:
```sql
ALTER TABLE payees
  ADD CONSTRAINT fk_payees_people   FOREIGN KEY (people_id)   REFERENCES people(id)     ON DELETE RESTRICT,
  ADD CONSTRAINT fk_payees_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT;

ALTER TABLE sales_invoices
  ADD CONSTRAINT fk_si_business  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_si_customer  FOREIGN KEY (customer_id) REFERENCES payees(id)     ON DELETE RESTRICT;

ALTER TABLE si_details
  ADD CONSTRAINT fk_sid_invoice FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id) ON DELETE CASCADE;

ALTER TABLE purchase_orders
  ADD CONSTRAINT fk_po_business  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_po_supplier  FOREIGN KEY (supplier_id) REFERENCES payees(id)     ON DELETE RESTRICT;

ALTER TABLE po_details
  ADD CONSTRAINT fk_pod_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE;

ALTER TABLE user_roles
  ADD CONSTRAINT fk_ur_user     FOREIGN KEY (user_id)  REFERENCES users(id)      ON DELETE CASCADE,
  ADD CONSTRAINT fk_ur_role     FOREIGN KEY (role_id)  REFERENCES roles(id)      ON DELETE RESTRICT,
  ADD CONSTRAINT fk_ur_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE;
```

---

### Design: REQ-023 — Item price history UI

**Backend:** `src/Controllers/ItemPriceHistoryController.php` — already exists. Verify `GET /items/{id}/price-history` endpoint is registered in routes.

**Frontend:**
- `public/assets/js/pages/Items/items.js` — add "Price History" button per item row
- New modal: `public/html/Items/priceHistory.html` + `public/assets/js/pages/Items/priceHistory.modal.js`
- Modal shows a DataTable with columns: Date, Old Cost, New Cost, Old Sale Price, New Sale Price, Changed By

---

### Design: REQ-024 & REQ-025 — Credit notes and debit notes

**New tables migration:** `database/migrations/add_credit_debit_notes.sql`
```sql
CREATE TABLE credit_notes (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  business_id       INT NOT NULL,
  sales_invoice_id  INT NOT NULL,
  credit_note_number VARCHAR(100) NOT NULL,
  credit_note_date  DATE NOT NULL,
  status            ENUM('DRAFT','ISSUED') NOT NULL DEFAULT 'DRAFT',
  currency          VARCHAR(10) NOT NULL,
  exchange_rate     DECIMAL(12,6) NOT NULL DEFAULT 1.0,
  total_amount_fc   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount_bc   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes             TEXT NULL,
  created_at        DATETIME NOT NULL,
  created_by        INT NULL
);

CREATE TABLE credit_note_items (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  credit_note_id  INT NOT NULL,
  item_id         INT NULL,
  service_id      INT NULL,
  quantity        DECIMAL(12,3) NOT NULL,
  unit_price_fc   DECIMAL(12,2) NOT NULL,
  unit_price_bc   DECIMAL(12,2) NOT NULL,
  total_price_fc  DECIMAL(12,2) NOT NULL,
  total_price_bc  DECIMAL(12,2) NOT NULL
);

CREATE TABLE debit_notes (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  business_id         INT NOT NULL,
  purchase_order_id   INT NOT NULL,
  debit_note_number   VARCHAR(100) NOT NULL,
  debit_note_date     DATE NOT NULL,
  status              ENUM('DRAFT','ISSUED') NOT NULL DEFAULT 'DRAFT',
  currency            VARCHAR(10) NOT NULL,
  exchange_rate       DECIMAL(12,6) NOT NULL DEFAULT 1.0,
  total_amount_fc     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount_bc     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes               TEXT NULL,
  created_at          DATETIME NOT NULL,
  created_by          INT NULL
);

CREATE TABLE debit_note_items (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  debit_note_id   INT NOT NULL,
  item_id         INT NOT NULL,
  quantity        DECIMAL(12,3) NOT NULL,
  unit_price_fc   DECIMAL(12,2) NOT NULL,
  unit_price_bc   DECIMAL(12,2) NOT NULL,
  total_price_fc  DECIMAL(12,2) NOT NULL,
  total_price_bc  DECIMAL(12,2) NOT NULL
);
```

**Backend:** New controllers, services, and repositories following the existing pattern for each note type. Issuing a credit/debit note MUST:
1. Reverse stock for returned items
2. Adjust the payee balance
3. Create a reference entry in the customer/supplier statement

**Frontend:** New pages under `SalesInvoices/` and `PurchaseOrders/` modules.

---

## Phase 5 — Schema Cleanup

### Design: REQ-027 — Drop deprecated tables

**Migration:** `database/migrations/drop_deprecated_tables.sql`

Run AFTER tasks 11 (business_module_overrides) and 12 (user_notifications) are complete.

```sql
-- Verify zero rows before dropping (safety check)
DROP TABLE IF EXISTS notification_roles;
DROP TABLE IF EXISTS business_payees_types;
DROP TABLE IF EXISTS payee_types;
DROP TABLE IF EXISTS business_module_overrides;
DROP TABLE IF EXISTS user_notifications;
```

**Backend:** Grep for any remaining references to these table names in PHP files and remove them. Key files to check: `NotificationRepository.php`, `ModuleActivationService.php`, any repository that may reference `payee_types`.

---

### Design: REQ-028 — Merge item_auto_markup and item_cost_flags into items

**Migration:** `database/migrations/merge_item_auxiliary_tables.sql`

```sql
ALTER TABLE items
  ADD COLUMN markup_percent DECIMAL(5,2) NULL AFTER min_stock,
  ADD COLUMN markup_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER markup_percent;

UPDATE items i
JOIN item_auto_markup iam ON iam.item_id = i.id
SET i.markup_percent = iam.markup_percent,
    i.markup_enabled = iam.is_enabled;

DROP TABLE item_auto_markup;
DROP TABLE item_cost_flags;
```

**Backend:** Update `src/Repository/ItemRepository.php` — all SELECT queries must include `markup_percent` and `markup_enabled`; remove any queries to `item_auto_markup` or `item_cost_flags`.

---

### Design: REQ-029 — Add missing audit columns

**Migration:** `database/migrations/add_missing_audit_columns.sql`

```sql
ALTER TABLE item_price_history  ADD COLUMN created_by INT NULL, ADD CONSTRAINT fk_iph_created_by  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE stock_transactions  ADD COLUMN created_by INT NULL, ADD CONSTRAINT fk_st_created_by   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE batch_transactions  ADD COLUMN created_by INT NULL, ADD CONSTRAINT fk_bt_created_by   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE partner_equity      ADD COLUMN created_by INT NULL, ADD CONSTRAINT fk_pe_created_by   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE business_profit     ADD COLUMN created_by INT NULL, ADD CONSTRAINT fk_bp_created_by   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE assets              ADD COLUMN created_by INT NULL, ADD CONSTRAINT fk_assets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE people              ADD COLUMN updated_at DATETIME NULL AFTER created_at;
```

**Backend:** Update each affected Repository's INSERT method to pass `AuthContext::userId()` as `created_by`.

---

### Design: REQ-030 — Fix data consistency issues

**Migration:** `database/migrations/fix_data_consistency.sql`

Key changes (each in its own transaction block):

```sql
-- 1. Rename translations table
RENAME TABLE tbl_translations TO translations;

-- 2. Add account_type to business_accounts
ALTER TABLE business_accounts
  ADD COLUMN account_type ENUM('BANK','CASH','CREDIT','OTHER') NOT NULL DEFAULT 'CASH' AFTER name;

-- 3. Add document reference fields to expenses
ALTER TABLE expenses
  ADD COLUMN reference_number VARCHAR(100) NULL AFTER notes,
  ADD COLUMN reference_date DATE NULL AFTER reference_number,
  ADD COLUMN attachment_path VARCHAR(500) NULL AFTER reference_date;

-- 4. Fix supplier_item_prices.supplier_id to reference payees
ALTER TABLE supplier_item_prices DROP FOREIGN KEY fk_sip_supplier;
UPDATE supplier_item_prices sip
JOIN payees p ON p.people_id = sip.supplier_id AND p.business_id IN (
  SELECT id FROM businesses WHERE tenant_id = sip.tenant_id
)
SET sip.supplier_id = p.id;
ALTER TABLE supplier_item_prices ADD CONSTRAINT fk_sip_supplier FOREIGN KEY (supplier_id) REFERENCES payees(id) ON DELETE CASCADE;

-- 4. Standardize batch_transactions.transaction_type casing
ALTER TABLE batch_transactions MODIFY COLUMN transaction_type ENUM('IN','OUT','WRITE_OFF') NOT NULL;
UPDATE batch_transactions SET transaction_type = UPPER(REPLACE(transaction_type, 'write_off', 'WRITE_OFF'));

-- 5. Standardize recurrence entity_type casing
ALTER TABLE recurrence_schedules MODIFY COLUMN entity_type ENUM('EXPENSE','SALARY') NOT NULL;
ALTER TABLE recurrence_instances MODIFY COLUMN entity_type ENUM('EXPENSE','SALARY') NOT NULL;
UPDATE recurrence_schedules SET entity_type = UPPER(entity_type);
UPDATE recurrence_instances SET entity_type = UPPER(entity_type);

-- 6. Drop unused recurrence_instances columns
ALTER TABLE recurrence_instances
  DROP COLUMN is_advance_payment,
  DROP COLUMN bulk_advance_payment_id,
  DROP COLUMN metadata;

-- 7. Add recipient_id to notifications (backfill from user_notifications)
ALTER TABLE notifications ADD COLUMN recipient_id INT NULL AFTER business_id;
UPDATE notifications n
JOIN user_notifications un ON un.notification_id = n.id
SET n.recipient_id = un.user_id;
ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER recipient_id;
ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER is_read;
UPDATE notifications n
JOIN user_notifications un ON un.notification_id = n.id
SET n.is_read = un.is_read, n.read_at = un.read_at;
ALTER TABLE notifications MODIFY COLUMN recipient_id INT NOT NULL;
ALTER TABLE notifications ADD CONSTRAINT fk_notif_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE;

-- 8. Fix notification_preferences.type to use FK
ALTER TABLE notification_preferences ADD COLUMN notification_type_id INT NULL AFTER type;
UPDATE notification_preferences np
JOIN notification_types nt ON nt.code = np.type
SET np.notification_type_id = nt.id;
ALTER TABLE notification_preferences DROP COLUMN type;
ALTER TABLE notification_preferences MODIFY COLUMN notification_type_id INT NOT NULL;
ALTER TABLE notification_preferences ADD CONSTRAINT fk_np_type FOREIGN KEY (notification_type_id) REFERENCES notification_types(id) ON DELETE CASCADE;
```

NOTE: `asset_categories` creation and `assets.category` migration are handled by REQ-031 as part of the unified system-types pattern.

---

### Design: REQ-031 — System-defined types with module visibility gating

**Goal:** All classification tables follow a consistent pattern: immutable system-defined types (seeded on install) + user-defined types (scoped to tenant or business). System types with a `module_code` are only visible when that module is active for the business.

**Unified schema pattern applied to each classification table:**

```sql
-- Columns added to: item_types, payee_types, expense_categories, service_categories
-- New table: asset_categories (follows same pattern)
ALTER TABLE <table>
  ADD COLUMN code VARCHAR(50) NULL AFTER id,
  ADD COLUMN module_code VARCHAR(50) NULL,
  ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0,
  MODIFY COLUMN tenant_id INT NULL;  -- NULL = system type
```

**Migration:** `database/migrations/add_system_types_pattern.sql`

```sql
-- ═══════════════════════════════════════════════════════
-- 1. ITEM TYPES
-- ═══════════════════════════════════════════════════════
ALTER TABLE item_types
  ADD COLUMN code VARCHAR(50) NULL AFTER id,
  ADD COLUMN module_code VARCHAR(50) NULL,
  ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
  MODIFY COLUMN tenant_id INT NULL;

INSERT INTO item_types (tenant_id, code, name, module_code, is_system, created_at) VALUES
  (NULL, 'TRADING',       'Trading Good',   NULL,            1, NOW()),
  (NULL, 'CONSUMABLE',    'Consumable',     NULL,            1, NOW()),
  (NULL, 'FINISHED_GOOD', 'Finished Good',  NULL,            1, NOW()),
  (NULL, 'RAW_MATERIAL',  'Raw Material',   'manufacturing', 1, NOW()),
  (NULL, 'MANUFACTURED',  'Manufactured',   'manufacturing', 1, NOW()),
  (NULL, 'SERVICE_ITEM',  'Service Item',   'services',      1, NOW()),
  (NULL, 'ASSET_ITEM',    'Asset Item',     'assets',        1, NOW());

-- ═══════════════════════════════════════════════════════
-- 2. PAYEE TYPES (redesign existing table)
-- ═══════════════════════════════════════════════════════
ALTER TABLE payee_types
  ADD COLUMN code VARCHAR(50) NULL AFTER id,
  ADD COLUMN module_code VARCHAR(50) NULL,
  ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
  MODIFY COLUMN tenant_id INT NULL;

-- Update existing rows to be system types
UPDATE payee_types SET is_system = 1, tenant_id = NULL, code = 'CUSTOMER' WHERE name = 'Customer';
UPDATE payee_types SET is_system = 1, tenant_id = NULL, code = 'SUPPLIER' WHERE name = 'Supplier';
UPDATE payee_types SET is_system = 1, tenant_id = NULL, code = 'EMPLOYEE' WHERE name = 'Employee';
UPDATE payee_types SET is_system = 1, tenant_id = NULL, code = 'PARTNER', module_code = 'partners' WHERE name = 'Partner';

INSERT IGNORE INTO payee_types (tenant_id, code, name, module_code, is_system, created_at) VALUES
  (NULL, 'OTHER', 'Other', NULL, 1, NOW());

-- Rename junction table
RENAME TABLE business_payees_types TO people_types;

-- ═══════════════════════════════════════════════════════
-- 3. EXPENSE CATEGORIES
-- ═══════════════════════════════════════════════════════
ALTER TABLE expense_categories
  ADD COLUMN code VARCHAR(50) NULL AFTER id,
  ADD COLUMN module_code VARCHAR(50) NULL,
  ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
  MODIFY COLUMN tenant_id INT NULL;

INSERT INTO expense_categories (tenant_id, code, name, module_code, is_system, created_at) VALUES
  (NULL, 'RENT',        'Rent',        NULL, 1, NOW()),
  (NULL, 'UTILITIES',   'Utilities',   NULL, 1, NOW()),
  (NULL, 'MARKETING',   'Marketing',   NULL, 1, NOW()),
  (NULL, 'MAINTENANCE', 'Maintenance', NULL, 1, NOW()),
  (NULL, 'TRANSPORT',   'Transport',   NULL, 1, NOW()),
  (NULL, 'OTHER',       'Other',       NULL, 1, NOW());

-- ═══════════════════════════════════════════════════════
-- 4. ASSET CATEGORIES (new table)
-- ═══════════════════════════════════════════════════════
CREATE TABLE asset_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NULL,
  business_id INT NULL,
  code VARCHAR(50) NULL,
  name VARCHAR(150) NOT NULL,
  module_code VARCHAR(50) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ac_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_ac_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT
);

INSERT INTO asset_categories (tenant_id, code, name, module_code, is_system) VALUES
  (NULL, 'LAND_BUILDINGS',  'Land & Buildings',      'assets', 1),
  (NULL, 'EQUIPMENT',       'Equipment & Machinery', 'assets', 1),
  (NULL, 'VEHICLES',        'Vehicles',              'assets', 1),
  (NULL, 'FURNITURE',       'Furniture & Fixtures',  'assets', 1),
  (NULL, 'IT_EQUIPMENT',    'IT Equipment',          'assets', 1),
  (NULL, 'INTANGIBLE',      'Intangible Assets',     'assets', 1),
  (NULL, 'INVENTORY_ASSET', 'Inventory',             'assets', 1);

-- Migrate assets.category free text → asset_categories
INSERT INTO asset_categories (business_id, name, is_system, created_at)
SELECT DISTINCT business_id, category, 0, NOW()
FROM assets WHERE category IS NOT NULL
  AND category NOT IN ('Land & Buildings','Equipment & Machinery','Vehicles',
    'Furniture & Fixtures','IT Equipment','Intangible Assets','Inventory');

ALTER TABLE assets ADD COLUMN category_id INT NULL AFTER business_id;
UPDATE assets a JOIN asset_categories ac ON ac.name = a.category AND (ac.business_id = a.business_id OR ac.is_system = 1)
SET a.category_id = ac.id;
ALTER TABLE assets ADD CONSTRAINT fk_assets_category FOREIGN KEY (category_id) REFERENCES asset_categories(id);
ALTER TABLE assets DROP COLUMN category;

-- ═══════════════════════════════════════════════════════
-- 5. SERVICE CATEGORIES
-- ═══════════════════════════════════════════════════════
ALTER TABLE service_categories
  ADD COLUMN code VARCHAR(50) NULL AFTER id,
  ADD COLUMN module_code VARCHAR(50) NULL,
  ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
  MODIFY COLUMN tenant_id INT NULL;

INSERT INTO service_categories (tenant_id, code, name, module_code, is_system, business_id, created_by, created_at) VALUES
  (NULL, 'CONSULTING',   'Consulting',           'services', 1, NULL, NULL, NOW()),
  (NULL, 'MAINTENANCE',  'Maintenance & Repair', 'services', 1, NULL, NULL, NOW()),
  (NULL, 'DELIVERY',     'Delivery & Logistics', 'services', 1, NULL, NULL, NOW()),
  (NULL, 'INSTALLATION', 'Installation',         'services', 1, NULL, NULL, NOW()),
  (NULL, 'OTHER',        'Other',                'services', 1, NULL, NULL, NOW());
```

**Backend — generic module-gated query pattern:**

```php
// src/Repository/SystemTypeRepository.php
class SystemTypeRepository {
    /**
     * Returns all types visible to a business, filtering by active modules.
     */
    public function findForBusiness(string $table, int $businessId, int $tenantId): array {
        $sql = "SELECT t.* FROM {$table} t
                WHERE (t.is_system = 1 AND (
                    t.module_code IS NULL
                    OR t.module_code IN (
                        SELECT m.code FROM modules m
                        JOIN business_type_modules btm ON btm.module_id = m.id
                        JOIN businesses b ON b.business_type_id = btm.business_type_id
                        WHERE b.id = :businessId
                    )
                ))
                OR (t.tenant_id = :tenantId)
                OR (t.business_id = :businessId2)
                ORDER BY t.is_system DESC, t.name ASC";
        // ...
    }

    public function isSystemType(string $table, int $id): bool {
        // Returns true if is_system = 1 — used as guard in update/delete
    }
}
```

**Backend — BOM validation in ManufacturingService:**

```php
// Validate BOM components
foreach ($recipeItems as $item) {
    $type = $this->itemTypeRepo->getByItemId($item['component_item_id']);
    if (!in_array($type['code'], ['RAW_MATERIAL', 'CONSUMABLE', null])) {
        throw new \InvalidArgumentException(
            "Item '{$item['name']}' cannot be a BOM component (type: {$type['name']})"
        );
    }
}
// Validate BOM output
$outputType = $this->itemTypeRepo->getByItemId($recipe['manufactured_item_id']);
if (!in_array($outputType['code'], ['MANUFACTURED', 'FINISHED_GOOD', null])) {
    throw new \InvalidArgumentException(
        "Item cannot be a BOM output (type: {$outputType['name']})"
    );
}
```

**Frontend — type selector pattern:**

All type/category dropdowns MUST fetch from the module-gated endpoint:
```javascript
// Example: item type selector
const types = await apiFetch(`/item-types?business_id=${businessId}`);
// Only types visible for this business's active modules are returned
populateSelect('#item-type', types.data);
```

**People type management:**
- The people create/edit form MUST show checkboxes for available types (filtered by active modules)
- The invoice customer selector MUST filter: `GET /people?type=CUSTOMER&business_id=X`
- The PO supplier selector MUST filter: `GET /people?type=SUPPLIER&business_id=X`
- The salary payee selector MUST filter: `GET /people?type=EMPLOYEE&business_id=X`

---

### Design: REQ-032 — Fix partner equity and profit distribution architecture

**Migration:** `database/migrations/fix_partner_profit_schema.sql`

```sql
-- ═══════════════════════════════════════════════════════
-- 1. Redesign business_profit table
-- ═══════════════════════════════════════════════════════
-- Drop composite PK, add auto-increment id
ALTER TABLE business_profit DROP PRIMARY KEY;
ALTER TABLE business_profit ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST;
ALTER TABLE business_profit ADD UNIQUE KEY uq_bp_business_period (business_id, period_start);

-- Add new columns
ALTER TABLE business_profit
  ADD COLUMN total_revenue DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER period_end,
  ADD COLUMN total_cogs DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_revenue,
  ADD COLUMN gross_profit DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_cogs,
  ADD COLUMN total_expenses DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER gross_profit,
  ADD COLUMN net_profit DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total_expenses,
  ADD COLUMN safety_net_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER net_profit,
  ADD COLUMN safety_net_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER safety_net_percent,
  ADD COLUMN distributable_profit DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER safety_net_amount,
  ADD COLUMN status ENUM('DRAFT','FINALIZED','DISTRIBUTED') NOT NULL DEFAULT 'DRAFT' AFTER distributable_profit,
  ADD COLUMN updated_at DATETIME NULL;

-- Migrate old data to new columns (best effort)
UPDATE business_profit SET
  net_profit = profit_bc,
  safety_net_amount = safety_net_bc,
  distributable_profit = distributable_profit_bc,
  status = 'DISTRIBUTED';

-- Drop old columns
ALTER TABLE business_profit
  DROP COLUMN profit_bc,
  DROP COLUMN safety_net_type,
  DROP COLUMN safety_net_bc,
  DROP COLUMN distributable_profit_bc;

-- Rename period_end to allow NULL (already nullable)
-- Add period_end NOT NULL constraint for new records (handled in app layer)

-- ═══════════════════════════════════════════════════════
-- 2. Create profit_distributions table
-- ═══════════════════════════════════════════════════════
CREATE TABLE profit_distributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_profit_id INT NOT NULL,
  partner_id INT NOT NULL,
  share_percent DECIMAL(5,2) NOT NULL,
  amount_bc DECIMAL(12,2) NOT NULL,
  payment_slip_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pd_profit (business_profit_id),
  KEY idx_pd_partner (partner_id),
  CONSTRAINT fk_pd_profit FOREIGN KEY (business_profit_id) REFERENCES business_profit(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pd_partner FOREIGN KEY (partner_id) REFERENCES payees(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pd_payment FOREIGN KEY (payment_slip_id) REFERENCES payment_slips(id) ON DELETE RESTRICT
);

-- ═══════════════════════════════════════════════════════
-- 3. Fix partner_equity to link to slips
-- ═══════════════════════════════════════════════════════
ALTER TABLE partner_equity
  ADD COLUMN slip_type ENUM('COLLECTION_SLIP','PAYMENT_SLIP') NULL AFTER amount,
  ADD COLUMN slip_id INT NULL AFTER slip_type,
  ADD COLUMN notes TEXT NULL AFTER slip_id;

-- For existing rows, set slip_type based on equity_type (best effort)
UPDATE partner_equity SET slip_type = 'COLLECTION_SLIP' WHERE equity_type = 'CONTRIBUTION';
UPDATE partner_equity SET slip_type = 'PAYMENT_SLIP' WHERE equity_type = 'WITHDRAWAL';

-- Make NOT NULL after backfill (new rows will always have it)
-- ALTER TABLE partner_equity MODIFY slip_type ... NOT NULL; -- defer until all old data is linked

-- Drop old ambiguous columns
ALTER TABLE partner_equity DROP COLUMN reference_id;
-- reference_type was already added by REQ-030 migration — drop it too
ALTER TABLE partner_equity DROP COLUMN reference_type;
```

**Backend — Profit calculation (accrual basis):**

```php
// src/Services/BusinessProfitService.php::create()

// Revenue: sum of PAID invoices in period (accrual basis)
$totalRevenue = $this->invoiceRepo->sumPaidInvoicesForPeriod($businessId, $periodStart, $periodEnd);

// COGS: cost of goods sold for items on PAID invoices
$totalCogs = $this->invoiceRepo->sumCostOfGoodsSoldForPeriod($businessId, $periodStart, $periodEnd);
// SQL: SELECT SUM(sid.quantity * i.cost_price) FROM si_details sid
//      JOIN items i ON i.id = sid.item_id
//      JOIN sales_invoices si ON si.id = sid.sales_invoice_id
//      WHERE si.business_id = ? AND si.status = 'PAID'
//      AND si.invoice_date BETWEEN ? AND ?

$grossProfit = $totalRevenue - $totalCogs;

// Expenses: operating expenses in period (NOT payment slips — those are settlements)
$expenseAmount = $this->expenseRepo->sumAmountForPeriod($businessId, $periodStart, $periodEnd);
$salaryAmount  = $this->salaryRepo->sumNetAmountForPeriod($businessId, $periodStart, $periodEnd);
$totalExpenses = $expenseAmount + $salaryAmount;

$netProfit = $grossProfit - $totalExpenses;
$safetyNetAmount = round($netProfit * $safetyNetPercent / 100, 2);
$distributableProfit = round($netProfit - $safetyNetAmount, 2);
```

**Backend — Distribution with profit_distributions:**

```php
// src/Services/BusinessProfitService.php::distribute()

foreach ($shares as $share) {
    $amount = round($distributableProfit * ($share['ownership_pct'] / 100), 2);

    // Create payment slip for this partner
    $slipId = $this->paymentService->createForDistribution(
        $businessId, $share['partner_id'], $amount, $profitId
    );

    // Record in profit_distributions
    $this->distributionRepo->create([
        'business_profit_id' => $profitId,
        'partner_id'         => $share['partner_id'],
        'share_percent'      => $share['ownership_pct'],
        'amount_bc'          => $amount,
        'payment_slip_id'    => $slipId,
    ]);
}
```

**Backend — Equity with slip linkage:**

```php
// src/Services/PartnerEquityService.php::create()

if ($equityType === 'CONTRIBUTION') {
    // Create collection slip (money IN)
    $slipId = $this->collectionService->createForEquity(
        $businessId, $partnerId, $amount, $accountId, $currency, $exchangeRate
    );
    $slipType = 'COLLECTION_SLIP';
} else {
    // Create payment slip (money OUT)
    $slipId = $this->paymentService->createForEquity(
        $businessId, $partnerId, $amount, $accountId, $currency, $exchangeRate
    );
    $slipType = 'PAYMENT_SLIP';
}

// Create equity record linked to the slip
$equityId = $this->equityRepo->insert([
    'business_id' => $businessId,
    'partner_id'  => $partnerId,
    'equity_type' => $equityType,
    'amount'      => $amount,
    'slip_type'   => $slipType,
    'slip_id'     => $slipId,
    'notes'       => $notes,
    'created_by'  => AuthContext::userId(),
]);

// Update invested_capital
$adjustment = ($equityType === 'CONTRIBUTION') ? $amount : -$amount;
$this->shareRepo->updateInvestedCapital($shareId, $businessId, $adjustment);
```

**Frontend — Business Profit detail view:**

```
┌─────────────────────────────────────────────────────────────┐
│ Profit Period: 2026-01-01 → 2026-03-31    Status: [DRAFT]   │
├─────────────────────────────────────────────────────────────┤
│ PROFIT & LOSS BREAKDOWN                                     │
│                                                             │
│ Revenue (PAID invoices)          $125,000.00                │
│ Cost of Goods Sold               ($45,000.00)               │
│                                  ───────────                │
│ Gross Profit                      $80,000.00                │
│                                                             │
│ Operating Expenses               ($22,000.00)               │
│ Salaries                         ($18,000.00)               │
│                                  ───────────                │
│ Net Profit                        $40,000.00                │
│                                                             │
│ Safety Net (15%)                 ($6,000.00)                │
│                                  ═══════════                │
│ Distributable Profit              $34,000.00                │
│                                                             │
│ Safety Net %: [15___]  [Recalculate]                        │
│                                                             │
│ [Finalize]                                                  │
└─────────────────────────────────────────────────────────────┘
```

When DISTRIBUTED:
```
┌─────────────────────────────────────────────────────────────┐
│ DISTRIBUTION BREAKDOWN                                      │
├──────────────┬────────────┬────────────┬────────────────────┤
│ Partner      │ Ownership  │ Amount     │ Payment Slip       │
├──────────────┼────────────┼────────────┼────────────────────┤
│ Ahmad K.     │ 60%        │ $20,400.00 │ PP-1-1-3 (link)    │
│ Sara M.      │ 40%        │ $13,600.00 │ PP-1-1-5 (link)    │
├──────────────┼────────────┼────────────┼────────────────────┤
│ TOTAL        │ 100%       │ $34,000.00 │                    │
└──────────────┴────────────┴────────────┴────────────────────┘
```

**Frontend — Partner Equity ledger:**

```
┌─────────────────────────────────────────────────────────────┐
│ Partner: Ahmad K.    Invested Capital: $50,000.00            │
│ [+ Contribute]  [- Withdraw]                                │
├────────────┬──────────────┬──────────┬──────────┬───────────┤
│ Date       │ Type         │ Amount   │ Balance  │ Slip      │
├────────────┼──────────────┼──────────┼──────────┼───────────┤
│ 2026-01-15 │ Contribution │ +$30,000 │ $30,000  │ COL-001 ↗ │
│ 2026-03-01 │ Contribution │ +$25,000 │ $55,000  │ COL-008 ↗ │
│ 2026-04-20 │ Withdrawal   │ -$5,000  │ $50,000  │ PAY-012 ↗ │
└────────────┴──────────────┴──────────┴──────────┴───────────┘
```

**Contribute/Withdraw modal:**
```
┌─────────────────────────────────────────┐
│ Contribute Capital — Ahmad K.           │
├─────────────────────────────────────────┤
│ Amount:    [$________]                  │
│ Account:   [Main Bank Account    ▼]    │
│ Method:    [Transfer             ▼]    │
│ Notes:     [________________________]  │
│                                         │
│ [Cancel]              [Confirm]         │
└─────────────────────────────────────────┘
```

---

### Design: REQ-033 — Deployment database creation script

**Goal:** After all 32 prior tasks are complete, generate a single `database/schema.sql` that represents the clean end-state of the database. This is the authoritative schema for new deployments.

**File structure:**
```
database/
├── schema.sql              ← Complete CREATE TABLE script (target ERD)
├── seed_system_data.sql    ← System types, modules, permissions, languages
├── README.md               ← Deployment instructions
└── migrations/             ← Incremental migrations (for existing deployments)
```

**schema.sql generation approach:**

The script is NOT auto-generated from the live database (which may have migration artifacts). It is hand-written to match the target ERD exactly, using the following order:

1. `tenants` (no FKs)
2. `languages` (no FKs)
3. `users` (no FKs)
4. `modules` (no FKs)
5. `permissions` (no FKs)
6. `notification_types` (no FKs)
7. `businesses` (FK → tenants)
8. `business_types` (FK → tenants)
9. `business_type_modules` (FK → business_types, modules)
10. `roles` (FK → businesses)
11. `role_permissions` (FK → roles, permissions)
12. `user_roles` (FK → users, roles, businesses, tenants)
13. `refresh_tokens` (FK → users)
14. `password_reset_tokens` (FK → users)
15. `settings_regional` (FK → businesses, languages)
16. `settings_smtp` (FK → businesses)
17. `translations` (FK → languages)
18. `people` (FK → tenants, users)
19. `payee_types` (FK → tenants, businesses, modules)
20. `people_types` (FK → people, payee_types)
21. `payees` (FK → businesses, people)
22. `item_types` (FK → tenants, businesses, modules)
23. `items` (FK → tenants, businesses, people, item_types, users)
24. `stock` (FK → businesses, items)
25. `stock_transactions` (FK → businesses, items, users)
26. `item_price_history` (FK → items, tenants, users)
27. `item_batches` (FK → items, businesses)
28. `batch_transactions` (FK → item_batches, users)
29. `supplier_item_prices` (FK → payees, items, tenants)
30. `business_accounts` (FK → businesses)
31. `exchange_rates` (FK → tenants, businesses)
32. `expense_categories` (FK → tenants, businesses, modules)
33. `expenses` (FK → businesses, payees, expense_categories)
34. `salaries` (FK → businesses, payees)
35. `sales_invoices` (FK → businesses, payees, users)
36. `si_details` (FK → sales_invoices, items, services)
37. `collection_slips` (FK → businesses, business_accounts, payees, users)
38. `collection_allocations` (FK → collection_slips)
39. `purchase_orders` (FK → businesses, payees, users)
40. `po_details` (FK → purchase_orders, items)
41. `payment_slips` (FK → businesses, business_accounts, payees, users)
42. `payment_allocations` (FK → payment_slips)
43. `credit_notes` (FK → businesses, sales_invoices, users)
44. `credit_note_items` (FK → credit_notes, items, services)
45. `debit_notes` (FK → businesses, purchase_orders, users)
46. `debit_note_items` (FK → debit_notes, items)
47. `recipes` (FK → tenants, businesses, items, users)
48. `recipe_items` (FK → recipes, items)
49. `production_orders` (FK → tenants, businesses, recipes, items, users)
50. `production_order_items` (FK → production_orders, items)
51. `service_categories` (FK → tenants, businesses, modules, users)
52. `services` (FK → tenants, businesses, service_categories, users)
53. `asset_categories` (FK → tenants, businesses, modules)
54. `assets` (FK → businesses, asset_categories, users)
55. `partner_shares` (FK → businesses, payees)
56. `partner_equity` (FK → payees, businesses, users)
57. `business_profit` (FK → businesses, users)
58. `profit_distributions` (FK → business_profit, payees, payment_slips)
59. `recurrence_schedules` (FK → businesses)
60. `recurrence_instances` (FK → recurrence_schedules, businesses)
61. `notifications` (FK → businesses, notification_types, users)
62. `notification_preferences` (FK → users, businesses, notification_types)
63. `audit_logs` (FK → tenants, businesses, users)

**Validation:** After running `schema.sql` + `seed_system_data.sql` on a fresh database, the application should boot with zero tenants → the first-installation-flow spec takes over from there.
