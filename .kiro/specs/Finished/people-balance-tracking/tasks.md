# Implementation Plan: People Balance Tracking

## Overview

Extend the payee balance tracking system so that all financial document types (Purchase Orders, Sales Invoices, Expenses, Salaries) update the payee balance consistently. The approach is inline service-layer modifications — each service already owns a PDO transaction, so balance updates are added inside those existing transactions. No new classes, tables, or event systems are introduced.

## Tasks

- [x] 1. Modify PurchaseOrderService — add cancellation balance reversal
  - [x] 1.1 Add balance reversal logic to `PurchaseOrderService::updateStatus()` for CANCELLED from RECEIVED
    - Inside the `updateStatus()` method, after `$this->repo->updateStatus(...)`, add a new block for the CANCELLED status
    - When `$newStatus === 'CANCELLED'` and `$current === 'RECEIVED'`, decrease the supplier's payee balance by `total_amount_bc`
    - Use the existing `$this->peopleRepo` dependency (already injected) and call `updatePayeeBalance((int) $supplierId, -$totalBc)`
    - Guard with null check on `supplier_id` and `$totalBc > 0` check, matching the existing RECEIVED block pattern
    - _Requirements: 1.2, 1.3, 1.4, 8.1_

  - [x] 1.2 Write property test: PO receive-then-cancel round-trip
    - Create `tests/Property/PurchaseOrderBalancePropertyTest.php`
    - **Property 1: Purchase Order receive-then-cancel round-trip**
    - Use PHPUnit data provider generating 100+ random PO amounts and supplier IDs
    - Mock `PeopleRepository::updatePayeeBalance()` and verify that after RECEIVED then CANCELLED, the net delta is zero
    - **Validates: Requirements 1.1, 1.2, 8.1**

  - [x] 1.3 Write property test: non-balance-impacting PO cancellation
    - In `tests/Property/PurchaseOrderBalancePropertyTest.php`
    - **Property 9 (PO part): Non-balance-impacting cancellation is a no-op**
    - Verify that cancelling a PO from APPROVED status does NOT call `updatePayeeBalance()`
    - **Validates: Requirements 1.3, 8.3**

  - [x] 1.4 Write unit tests for PurchaseOrderService balance edge cases
    - Create `tests/Unit/PurchaseOrderBalanceTest.php`
    - Test: CANCELLED from RECEIVED with null `supplier_id` — no balance call
    - Test: CANCELLED from RECEIVED with zero `total_amount_bc` — no balance call
    - Test: CANCELLED from APPROVED — no balance call
    - Test: RECEIVED correctly increases balance (existing behavior regression)
    - _Requirements: 1.2, 1.3, 1.4_

- [x] 2. Checkpoint — Verify PurchaseOrderService changes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 3. Modify SalesInvoiceService — add cancellation balance reversal
  - [x] 3.1 Add balance reversal logic to `SalesInvoiceService::updateStatus()` for CANCELLED from APPROVED
    - Inside the `updateStatus()` method, after `$this->repo->updateStatus(...)`, add a new block for the CANCELLED status
    - When `$newStatus === 'CANCELLED'` and `$current === 'APPROVED'`, decrease the customer's payee balance by `total_amount_bc`
    - Use the existing `$this->peopleRepo` dependency (already injected) and call `updatePayeeBalance((int) $customerId, -$totalBc)`
    - Guard with null check on `customer_id` and `$totalBc > 0` check, matching the existing APPROVED block pattern
    - Note: CANCELLED from DRAFT should NOT touch balance (no prior balance increase happened)
    - _Requirements: 2.2, 2.4, 8.2_

  - [x] 3.2 Write property test: SI approve-then-cancel round-trip
    - Create `tests/Property/SalesInvoiceBalancePropertyTest.php`
    - **Property 2: Sales Invoice approve-then-cancel round-trip**
    - Use PHPUnit data provider generating 100+ random invoice amounts and customer IDs
    - Mock `PeopleRepository::updatePayeeBalance()` and verify that after APPROVED then CANCELLED, the net delta is zero
    - **Validates: Requirements 2.1, 2.2, 8.2**

  - [x] 3.3 Write property test: non-balance-impacting SI cancellation
    - In `tests/Property/SalesInvoiceBalancePropertyTest.php`
    - **Property 9 (SI part): Non-balance-impacting cancellation is a no-op**
    - Verify that cancelling an SI from DRAFT status does NOT call `updatePayeeBalance()`
    - **Validates: Requirements 2.4, 8.3**

  - [x] 3.4 Write unit tests for SalesInvoiceService balance edge cases
    - Create `tests/Unit/SalesInvoiceBalanceTest.php`
    - Test: CANCELLED from APPROVED with null `customer_id` — no balance call
    - Test: CANCELLED from APPROVED with zero `total_amount_bc` — no balance call
    - Test: CANCELLED from DRAFT — no balance call
    - Test: APPROVED correctly increases balance (existing behavior regression)
    - _Requirements: 2.2, 2.4, 8.2, 8.3_

- [x] 4. Checkpoint — Verify SalesInvoiceService changes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Modify ExpenseService — add PeopleRepository dependency and balance tracking
  - [x] 5.1 Add `PeopleRepository` dependency to `ExpenseService` constructor
    - Add `use App\Repository\PeopleRepository;` import
    - Add `private PeopleRepository $peopleRepo;` property
    - Instantiate in constructor: `$this->peopleRepo = new PeopleRepository($db);` (same pattern as `PaymentService`)
    - _Requirements: 3.1_

  - [x] 5.2 Add balance increase on `ExpenseService::create()`
    - After the `$this->repo->create(...)` call and before the audit log, add payee balance increase
    - If `payee_id` is set, call `$this->peopleRepo->updatePayeeBalance((int) $payeeId, $amountBc)`
    - _Requirements: 3.1, 3.5_

  - [x] 5.3 Add balance adjustment on `ExpenseService::update()`
    - After `$this->repo->update(...)` and before the audit log, compare old and new payee/amount
    - Determine old payee ID from `$before['payee_id']` and new payee ID from the update data
    - Determine old `amount_bc` from `$before['amount_bc']` and new `amount_bc` from the recalculated value
    - If payee unchanged and amount changed: adjust by delta `(new_amount_bc - old_amount_bc)`
    - If payee changed: decrease old payee by old `amount_bc`, increase new payee by new `amount_bc`
    - If payee removed (was set, now null): decrease old payee by old `amount_bc`
    - If payee added (was null, now set): increase new payee by new `amount_bc`
    - _Requirements: 3.2, 3.3, 3.5, 9.1_

  - [x] 5.4 Add balance decrease on `ExpenseService::delete()`
    - After `$this->repo->delete(...)` and before the audit log, if `payee_id` is set on `$before`, decrease payee balance by `amount_bc`
    - Call `$this->peopleRepo->updatePayeeBalance((int) $before['payee_id'], -$amountBc)`
    - _Requirements: 3.4, 3.5_

  - [x] 5.5 Write property test: Expense create-then-delete round-trip
    - Create `tests/Property/ExpenseBalancePropertyTest.php`
    - **Property 3: Expense create-then-delete round-trip**
    - Use PHPUnit data provider generating 100+ random expense amounts, exchange rates, and payee IDs
    - Verify that after create then delete, the net balance delta is zero
    - **Validates: Requirements 3.1, 3.4**

  - [x] 5.6 Write property test: Expense amount edit adjusts balance by exact delta
    - In `tests/Property/ExpenseBalancePropertyTest.php`
    - **Property 5: Expense amount edit adjusts balance by exact delta**
    - Generate random old and new `amount_bc` values, verify balance changes by exactly `(new - old)`
    - **Validates: Requirements 3.2, 9.1**

  - [x] 5.7 Write property test: Expense payee change transfers balance
    - In `tests/Property/ExpenseBalancePropertyTest.php`
    - **Property 6: Expense payee change transfers balance**
    - Generate random payee A, payee B, old amount, new amount; verify old payee decreases by old amount and new payee increases by new amount
    - **Validates: Requirements 3.3**

  - [x] 5.8 Write unit tests for ExpenseService balance edge cases
    - Create `tests/Unit/ExpenseBalanceTest.php`
    - Test: create with null `payee_id` — no balance call
    - Test: update with payee removed (set → null) — old payee decreased
    - Test: update with payee added (null → set) — new payee increased
    - Test: delete with null `payee_id` — no balance call
    - _Requirements: 3.2, 3.3, 3.5_

- [x] 6. Checkpoint — Verify ExpenseService changes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Modify SalaryService — add PeopleRepository dependency and balance tracking
  - [x] 7.1 Add `PeopleRepository` dependency to `SalaryService` constructor
    - Add `use App\Repository\PeopleRepository;` import
    - Add `private PeopleRepository $peopleRepo;` property
    - Instantiate in constructor: `$this->peopleRepo = new PeopleRepository($db);` (same pattern as `PaymentService`)
    - _Requirements: 4.1_

  - [x] 7.2 Add balance increase on `SalaryService::create()`
    - After the `$this->repo->create(...)` call and before the audit log, add payee balance increase
    - If `payee_id` is set, call `$this->peopleRepo->updatePayeeBalance((int) $payeeId, $calculated['net_amount_bc'])`
    - Note: uses `net_amount_bc` (not `gross_amount_bc`) per design
    - _Requirements: 4.1, 4.5, 7.3_

  - [x] 7.3 Add balance adjustment on `SalaryService::update()`
    - After `$this->repo->update(...)` and before the audit log, compare old and new payee/amount
    - Determine old payee ID from `$before['payee_id']` and new payee ID from the update data
    - Determine old `net_amount_bc` from `$before['net_amount_bc']` and new `net_amount_bc` from `$calculated['net_amount_bc']`
    - If payee unchanged and net_amount_bc changed: adjust by delta `(new - old)`
    - If payee changed: decrease old payee by old `net_amount_bc`, increase new payee by new `net_amount_bc`
    - If payee removed (was set, now null): decrease old payee by old `net_amount_bc`
    - If payee added (was null, now set): increase new payee by new `net_amount_bc`
    - _Requirements: 4.2, 4.3, 4.5, 9.2_

  - [x] 7.4 Add balance decrease on `SalaryService::delete()`
    - After `$this->repo->delete(...)` and before the audit log, if `payee_id` is set on `$before`, decrease payee balance by `net_amount_bc`
    - Call `$this->peopleRepo->updatePayeeBalance((int) $before['payee_id'], -(float) $before['net_amount_bc'])`
    - _Requirements: 4.4, 4.5_

  - [x] 7.5 Write property test: Salary create-then-delete round-trip
    - Create `tests/Property/SalaryBalancePropertyTest.php`
    - **Property 4: Salary create-then-delete round-trip**
    - Use PHPUnit data provider generating 100+ random gross amounts, tax amounts, exchange rates, and payee IDs
    - Verify that after create then delete, the net balance delta is zero
    - **Validates: Requirements 4.1, 4.4**

  - [x] 7.6 Write property test: Salary amount edit adjusts balance by exact delta
    - In `tests/Property/SalaryBalancePropertyTest.php`
    - **Property 7: Salary amount edit adjusts balance by exact delta**
    - Generate random old and new `net_amount_bc` values, verify balance changes by exactly `(new - old)`
    - **Validates: Requirements 4.2, 9.2**

  - [x] 7.7 Write property test: Salary payee change transfers balance
    - In `tests/Property/SalaryBalancePropertyTest.php`
    - **Property 8: Salary payee change transfers balance**
    - Generate random payee A, payee B, old net amount, new net amount; verify old payee decreases by old amount and new payee increases by new amount
    - **Validates: Requirements 4.3**

  - [x] 7.8 Write unit tests for SalaryService balance edge cases
    - Create `tests/Unit/SalaryBalanceTest.php`
    - Test: create with null `payee_id` — no balance call
    - Test: update with payee removed (set → null) — old payee decreased
    - Test: update with payee added (null → set) — new payee increased
    - Test: delete with null `payee_id` — no balance call
    - _Requirements: 4.2, 4.3, 4.5_

- [x] 8. Checkpoint — Verify SalaryService changes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Write cross-cutting property and regression tests
  - [x] 9.1 Write property test: Business currency amount computation
    - Create `tests/Unit/BalanceCurrencyTest.php`
    - **Property 10: Business currency amount computation**
    - Generate 100+ random `amount_fc` and `exchange_rate` pairs, verify `amount_bc === round(amount_fc * exchange_rate, 2)`
    - **Validates: Requirements 7.2**

  - [x] 9.2 Write property test: Payment create-then-delete round-trip (regression)
    - Create `tests/Property/PaymentBalanceRegressionPropertyTest.php`
    - **Property 11: Payment create-then-delete round-trip**
    - Verify existing PaymentService balance behavior: create decreases, delete increases, net is zero
    - **Validates: Requirements 6.1, 6.2**

  - [x] 9.3 Write property test: Collection create-then-delete round-trip (regression)
    - Create `tests/Property/CollectionBalanceRegressionPropertyTest.php`
    - **Property 12: Collection create-then-delete round-trip**
    - Verify existing CollectionService balance behavior: create decreases, delete increases, net is zero
    - **Validates: Requirements 6.3, 6.4**

- [x] 10. Write integration tests for transaction atomicity and audit logging
  - [x] 10.1 Write integration test for transaction rollback on balance failure
    - Create `tests/Integration/BalanceTransactionTest.php`
    - Test: If `updatePayeeBalance()` throws during expense create, the expense insert is also rolled back
    - Test: If `updatePayeeBalance()` throws during salary create, the salary insert is also rolled back
    - Test: If `updatePayeeBalance()` throws during PO status change, the status change is also rolled back
    - _Requirements: 5.1, 5.2_

  - [x] 10.2 Write integration test for audit logging of balance changes
    - Create `tests/Integration/BalanceAuditTest.php`
    - Test: Expense create audit entry includes amount_bc
    - Test: Salary create audit entry includes net_amount_bc
    - Test: PO status change audit entry includes status transition
    - Test: SI status change audit entry includes status transition
    - _Requirements: 10.1, 10.2_

- [x] 11. Final checkpoint — Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation after each service modification
- Property tests validate universal correctness properties from the design document (Properties 1–12)
- Unit tests validate specific edge cases (null payees, zero amounts, non-impacting status transitions)
- The existing `PeopleRepository::updatePayeeBalance()` method requires no changes — it already works correctly
- `PurchaseOrderService` and `SalesInvoiceService` already have `PeopleRepository` injected; only `ExpenseService` and `SalaryService` need the new dependency
