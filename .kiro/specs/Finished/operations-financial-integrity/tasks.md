# Implementation Plan: Operations & Financial Integrity

## Overview

This plan implements systemic integrity improvements across the ERP's Purchase Order, Sales Invoice, Production, and Payment/Collection modules. The implementation follows a bottom-up approach: shared utilities first (StockValidator, SettlementEngine, BalanceRecalculator), then modifications to existing services, then API routes and frontend integration. Each task builds incrementally on previous work.

## Tasks

- [x] 1. Create StockValidator service
  - [x] 1.1 Implement StockValidator class with assertAvailable and checkBatch methods
    - Create `src/Services/StockValidator.php`
    - Constructor accepts PDO instance
    - `assertAvailable(int $itemId, float $requestedQty, int $businessId): void` — throws RuntimeException if stock insufficient
    - `checkBatch(array $requirements, int $businessId): array` — returns array of shortfall messages, empty if all sufficient
    - Query stock table for current quantity, compare against requested
    - Error format for assertAvailable: "Insufficient stock for [item_name]: available [available_qty], requested [requested_qty]"
    - Error format for checkBatch: "Insufficient stock for: [item_name] (need [required], have [available])"
    - _Requirements: 4.1, 4.2, 4.4, 5.1, 5.2, 5.3_

  - [x] 1.2 Write property test for stock validation (Property 5: Production Order Stock Sufficiency Gate)
    - **Property 5: Production Order Stock Sufficiency Gate**
    - **Validates: Requirements 4.1, 4.2, 4.4**
    - Create `tests/Property/StockValidationTest.php`
    - Generate random recipes with 1-10 components, random stock levels (0-10000), random production quantities
    - Assert: order accepted iff every component has stock >= (recipe_qty / yield_qty) × production_qty
    - Assert: rejection message lists every deficient component

  - [x] 1.3 Write property test for invoice line item stock validation (Property 6: Invoice Line Item Stock Validation)
    - **Property 6: Invoice Line Item Stock Validation**
    - **Validates: Requirements 5.1, 5.2, 5.3**
    - Generate random item stock levels and requested quantities
    - Assert: operation succeeds iff requested_qty <= available_stock
    - Assert: rejection message matches format "Insufficient stock for [item_name]: available [available_qty], requested [requested_qty]"

  - [x] 1.4 Write unit tests for StockValidator
    - Test assertAvailable with exact stock match (passes)
    - Test assertAvailable with insufficient stock (throws with correct message)
    - Test checkBatch with all items sufficient (returns empty array)
    - Test checkBatch with multiple deficient items (returns all shortfall messages)
    - Test with zero stock, zero requested quantity edge cases
    - _Requirements: 4.1, 4.2, 4.4, 5.1, 5.2, 5.3_

- [x] 2. Create SettlementEngine service
  - [x] 2.1 Implement SettlementEngine class with evaluateSupplierSettlement and evaluateCustomerSettlement
    - Create `src/Services/SettlementEngine.php`
    - Constructor accepts PDO instance
    - `evaluateSupplierSettlement(int $payeeId, int $businessId): array` — checks supplier balance >= 0, marks RECEIVED POs as settled
    - `evaluateCustomerSettlement(int $payeeId, int $businessId): array` — checks customer balance <= 0, marks DELIVERED invoices as settled
    - Both methods participate in caller's transaction (no own transaction management)
    - Return array of settled document IDs for audit logging
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 10.1, 10.2, 10.3, 10.4_

  - [x] 2.2 Write property test for customer settlement (Property 12: Customer Settlement on Zero Balance)
    - **Property 12: Customer Settlement on Zero Balance**
    - **Validates: Requirements 9.2, 9.3**
    - Generate random customer balances and sets of DELIVERED invoices
    - Assert: when balance <= 0 after collection, all DELIVERED invoices with UNPAID/PARTIALLY_PAID are marked settled

  - [x] 2.3 Write property test for supplier settlement (Property 13: Supplier Settlement on Zero Balance)
    - **Property 13: Supplier Settlement on Zero Balance**
    - **Validates: Requirements 10.2, 10.3**
    - Generate random supplier balances and sets of RECEIVED POs
    - Assert: when balance >= 0 after payment, all RECEIVED POs with UNPAID/PARTIALLY_PAID are marked settled

  - [x] 2.4 Write unit tests for SettlementEngine
    - Test evaluateSupplierSettlement when balance is positive (settles POs)
    - Test evaluateSupplierSettlement when balance is still negative (no settlement)
    - Test evaluateCustomerSettlement when balance is zero (settles invoices)
    - Test evaluateCustomerSettlement when balance is still positive (no settlement)
    - Test with no outstanding documents (returns empty array)
    - _Requirements: 9.1, 9.2, 9.3, 10.1, 10.2, 10.3_

- [x] 3. Create BalanceRecalculator service
  - [x] 3.1 Implement BalanceRecalculator class with recalculate method
    - Create `src/Services/BalanceRecalculator.php`
    - Constructor accepts PDO instance
    - `recalculate(?int $accountId = null): array` — recalculates one or all accounts
    - Logic: reset balance/balance_fc to 0, replay all payments (subtract), replay all collections (add)
    - Execute within single transaction for atomicity
    - Return report: [{account_id, prev_balance, new_balance, prev_balance_fc, new_balance_fc}, ...]
    - When accountId provided, only process that single account
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 12.1, 12.2, 12.3, 12.4, 12.6_

  - [x] 3.2 Write property test for balance recalculation correctness (Property 14: Balance Recalculation Correctness)
    - **Property 14: Balance Recalculation Correctness**
    - **Validates: Requirements 11.1, 11.2, 11.3**
    - Generate random sets of payment and collection slips with various amounts
    - Assert: after recalculation, balance = sum(collections.amount_bc) - sum(payments.amount_bc)
    - Assert: after recalculation, balance_fc = sum(collections.amount_fc) - sum(payments.amount_fc)

  - [x] 3.3 Write property test for balance recalculation idempotence (Property 15: Balance Recalculation Idempotence)
    - **Property 15: Balance Recalculation Idempotence**
    - **Validates: Requirements 12.6**
    - Generate random account states and transaction histories
    - Assert: running recalculation N times produces same result as running once

  - [x] 3.4 Write property test for single-account recalculation isolation (Property 16: Single-Account Recalculation Isolation)
    - **Property 16: Single-Account Recalculation Isolation**
    - **Validates: Requirements 12.2, 12.3**
    - Generate multiple accounts with different transaction histories
    - Assert: recalculating one account leaves all other accounts unchanged

  - [x] 3.5 Write unit tests for BalanceRecalculator
    - Test recalculate with no slips (balances stay at zero)
    - Test recalculate with only payments (negative balance)
    - Test recalculate with only collections (positive balance)
    - Test recalculate with mixed slips (correct net balance)
    - Test single account recalculation
    - Test report format includes before/after values
    - Test transaction rollback on error
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 12.2, 12.3, 12.4_

- [x] 4. Checkpoint - Ensure all new service tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Modify PurchaseOrderService for lifecycle simplification and computed payment status
  - [x] 5.1 Update PO status transitions to remove CLOSED and make RECEIVED terminal
    - Modify `updateStatus()` in PurchaseOrderService
    - Allowed transitions: DRAFT→{APPROVED,CANCELLED}, APPROVED→{RECEIVED,CANCELLED}, RECEIVED→{CANCELLED with guards}
    - Remove CLOSED from all valid status sets
    - Return error "CLOSED status is no longer valid; RECEIVED is the terminal state." if CLOSED is attempted
    - _Requirements: 1.1, 1.2, 1.4, 1.5, 1.6_

  - [x] 5.2 Implement cancellation guard for RECEIVED POs with payments
    - Before allowing RECEIVED→CANCELLED, compute payment status
    - If PARTIALLY_PAID or PAID, reject with "Cannot cancel: purchase order has associated payments. Reverse payments first."
    - If UNPAID, allow cancellation and reverse stock IN (decrease stock by line quantities) and reverse supplier payee balance
    - _Requirements: 13.1, 13.3_

  - [x] 5.3 Implement computePaymentStatus method for POs
    - Add `computePaymentStatus(int $poId): string` method
    - Query payment_slips where reference_type='PURCHASE_ORDER' AND reference_id=poId
    - Return PAID if sum(amount_bc) >= total_amount_bc, PARTIALLY_PAID if > 0, UNPAID if = 0
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.6_

  - [x] 5.4 Implement getPaymentShortcutData method for POs
    - Add `getPaymentShortcutData(int $poId): array` method
    - Return: supplier_id as payee, total_amount_bc as amount, po_number as reference, reference_type "PURCHASE_ORDER", reference_id as PO ID
    - Only available for RECEIVED POs
    - _Requirements: 3.1, 3.2, 3.3_

  - [x] 5.5 Update PO delete to ensure line item cleanup in transaction
    - Modify `delete()` method to delete po_details first, then PO header, within single transaction
    - Only allow deletion of DRAFT POs; reject non-DRAFT with "Only DRAFT purchase orders can be deleted."
    - _Requirements: 15.1, 15.3, 15.5, 15.6_

  - [x] 5.6 Write property test for PO state machine (Property 1: PO State Machine Validity)
    - **Property 1: PO State Machine Validity**
    - **Validates: Requirements 1.1, 1.2, 1.4, 1.5**
    - Create `tests/Property/POStateMachineTest.php`
    - Generate random source statuses and target statuses
    - Assert: transition succeeds iff target is in allowed set for source

  - [x] 5.7 Write property test for PO RECEIVED side effects (Property 2: PO RECEIVED Transition Side Effects)
    - **Property 2: PO RECEIVED Transition Side Effects**
    - **Validates: Requirements 1.3**
    - Generate random POs with 1-20 line items
    - Assert: stock increases by exactly each line's quantity
    - Assert: supplier balance decreases by exactly total_amount_bc

  - [x] 5.8 Write property test for PO payment status computation (Property 3: PO Payment Status Computation)
    - **Property 3: PO Payment Status Computation**
    - **Validates: Requirements 2.1, 2.2, 2.3, 2.4**
    - Create `tests/Property/POPaymentStatusTest.php`
    - Generate random PO amounts and sets of payment slips
    - Assert: PAID when sum >= total, PARTIALLY_PAID when 0 < sum < total, UNPAID when sum = 0

  - [x] 5.9 Write property test for PO payment shortcut data (Property 4: PO Payment Shortcut Data Correctness)
    - **Property 4: PO Payment Shortcut Data Correctness**
    - **Validates: Requirements 3.1, 3.2**
    - Generate random RECEIVED POs
    - Assert: shortcut data contains correct supplier_id, total_amount_bc, po_number, reference_type, reference_id

  - [x] 5.10 Write property test for cancellation guard (Property 17: Cancellation Guard for Paid Documents — PO portion)
    - **Property 17: Cancellation Guard for Paid Documents**
    - **Validates: Requirements 13.1**
    - Generate RECEIVED POs with various payment statuses
    - Assert: cancellation rejected when PARTIALLY_PAID or PAID

  - [x] 5.11 Write property test for cancellation reversal (Property 18: Cancellation Reversal of Unpaid Documents — PO portion)
    - **Property 18: Cancellation Reversal of Unpaid Documents**
    - **Validates: Requirements 13.3**
    - Generate RECEIVED POs with UNPAID status and line items
    - Assert: stock decreases by line quantities, supplier balance reversed

  - [x] 5.12 Write property test for DRAFT PO deletion (Property 20: DRAFT Document Deletion — PO portion)
    - **Property 20: DRAFT Document Deletion with Cleanup**
    - **Validates: Requirements 15.1, 15.3**
    - Generate POs in various statuses with line items
    - Assert: deletion succeeds only for DRAFT, all po_details removed

- [ ] 6. Modify SalesInvoiceService for lifecycle restructuring and computed payment status
  - [x] 6.1 Update invoice status transitions to DRAFT→DELIVERED flow
    - Modify `updateStatus()` in SalesInvoiceService
    - Allowed transitions: DRAFT→{DELIVERED,CANCELLED}, DELIVERED→{CANCELLED with guards}
    - Remove APPROVED and PAID from valid status sets
    - Reject manual PAID with "Payment status is computed from collection slips and cannot be set manually."
    - On DELIVERED transition: execute stock OUT for each line item, increase customer payee balance by total_amount_bc
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6_

  - [x] 6.2 Add stock validation to addLine and updateLine methods
    - Before adding/editing line items, call StockValidator.assertAvailable
    - Enforce at all invoice statuses including DRAFT
    - Check against current stock level at time of operation
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

  - [x] 6.3 Implement cancellation guard for DELIVERED invoices with collections
    - Before allowing DELIVERED→CANCELLED, compute payment status
    - If PARTIALLY_PAID or PAID, reject with "Cannot cancel: invoice has associated collections. Reverse collections first."
    - If UNPAID, allow cancellation and reverse stock OUT (increase stock by line quantities) and reverse customer payee balance
    - _Requirements: 13.2, 13.4_

  - [x] 6.4 Implement computePaymentStatus method for invoices
    - Add `computePaymentStatus(int $invoiceId): string` method
    - Query collection_slips where reference_type='SALES_INVOICE' AND reference_id=invoiceId
    - Return PAID if sum(amount_bc) >= total_amount_bc, PARTIALLY_PAID if > 0, UNPAID if = 0
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.6_

  - [x] 6.5 Update invoice delete to ensure line item cleanup in transaction
    - Modify `delete()` method to delete si_details first, then invoice header, within single transaction
    - Only allow deletion of DRAFT invoices; reject non-DRAFT with "Only DRAFT sales invoices can be deleted."
    - _Requirements: 15.2, 15.4, 15.5, 15.6_

  - [x] 6.6 Write property test for invoice state machine (Property 7: Invoice State Machine Validity)
    - **Property 7: Invoice State Machine Validity**
    - **Validates: Requirements 6.1, 6.4, 6.5**
    - Create `tests/Property/InvoiceStateMachineTest.php`
    - Generate random source statuses and target statuses
    - Assert: transition succeeds iff target is in allowed set; APPROVED and PAID always rejected

  - [x] 6.7 Write property test for invoice DELIVERED side effects (Property 8: Invoice DELIVERED Transition Side Effects)
    - **Property 8: Invoice DELIVERED Transition Side Effects**
    - **Validates: Requirements 6.2, 6.3**
    - Generate random invoices with 1-20 line items
    - Assert: stock decreases by exactly each line's quantity
    - Assert: customer balance increases by exactly total_amount_bc

  - [x] 6.8 Write property test for invoice payment status (Property 9: Invoice Payment Status Computation)
    - **Property 9: Invoice Payment Status Computation**
    - **Validates: Requirements 7.1, 7.2, 7.3, 7.4**
    - Create `tests/Property/InvoicePaymentStatusTest.php`
    - Generate random invoice amounts and sets of collection slips
    - Assert: PAID when sum >= total, PARTIALLY_PAID when 0 < sum < total, UNPAID when sum = 0

  - [x] 6.9 Write property test for fully paid invoice rejection (Property 10: Fully Paid Invoice Rejects Additional Collections)
    - **Property 10: Fully Paid Invoice Rejects Additional Collections**
    - **Validates: Requirements 7.6**
    - Generate invoices where sum(collection slips) >= total
    - Assert: new collection slip referencing that invoice is rejected

  - [x] 6.10 Write property test for cancellation guard (Property 17: Cancellation Guard — Invoice portion)
    - **Property 17: Cancellation Guard for Paid Documents**
    - **Validates: Requirements 13.2**
    - Generate DELIVERED invoices with various payment statuses
    - Assert: cancellation rejected when PARTIALLY_PAID or PAID

  - [x] 6.11 Write property test for cancellation reversal (Property 18: Cancellation Reversal — Invoice portion)
    - **Property 18: Cancellation Reversal of Unpaid Documents**
    - **Validates: Requirements 13.4**
    - Generate DELIVERED invoices with UNPAID status and line items
    - Assert: stock increases by line quantities, customer balance reversed

  - [x] 6.12 Write property test for DRAFT invoice deletion (Property 20: DRAFT Document Deletion — Invoice portion)
    - **Property 20: DRAFT Document Deletion with Cleanup**
    - **Validates: Requirements 15.2, 15.4**
    - Generate invoices in various statuses with line items
    - Assert: deletion succeeds only for DRAFT, all si_details removed

- [x] 7. Checkpoint - Ensure PO and Invoice service tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 8. Modify PaymentService for PO reference validation and settlement trigger
  - [x] 8.1 Add PO reference validation to payment slip creation
    - In `create()` method, when reference_type is "PURCHASE_ORDER":
    - Validate referenced PO exists, belongs to same business, is in RECEIVED status
    - Reject with descriptive error if validation fails
    - Allow payment slips without reference_id (general payments)
    - Allow multiple payment slips referencing same PO
    - _Requirements: 14.1, 14.2, 14.3, 14.4_

  - [x] 8.2 Integrate SettlementEngine into PaymentService
    - After successful payment slip creation, call `SettlementEngine->evaluateSupplierSettlement(payeeId, businessId)`
    - Execute within same transaction as payment creation
    - Log settlement via AuditService if documents were settled
    - _Requirements: 10.1, 10.4, 10.5_

  - [x] 8.3 Write property test for payment slip PO reference validation (Property 19: Payment Slip PO Reference Validation)
    - **Property 19: Payment Slip PO Reference Validation**
    - **Validates: Requirements 14.1, 14.4**
    - Create `tests/Property/PaymentReferenceTest.php`
    - Generate payment slips with various reference scenarios
    - Assert: creation succeeds iff referenced PO exists, same business, RECEIVED status
    - Assert: multiple slips can reference same PO

  - [x] 8.4 Write unit tests for PaymentService modifications
    - Test payment with valid PO reference (succeeds)
    - Test payment with non-existent PO reference (rejected)
    - Test payment with PO in DRAFT status (rejected)
    - Test payment with PO from different business (rejected)
    - Test payment without reference_id (succeeds)
    - Test settlement trigger is called after creation
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 10.1_

- [ ] 9. Modify CollectionService for invoice reference validation and settlement trigger
  - [x] 9.1 Add invoice reference validation to collection slip creation
    - In `create()` method, when reference_type is "SALES_INVOICE":
    - Validate referenced invoice exists, belongs to same business, is in DELIVERED status
    - Check if invoice is already fully paid; reject with "Invoice is already fully paid." if so
    - Allow partial amounts (less than invoice total)
    - Allow multiple collection slips referencing same invoice until fully paid
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 7.6_

  - [x] 9.2 Integrate SettlementEngine into CollectionService
    - After successful collection slip creation, call `SettlementEngine->evaluateCustomerSettlement(payeeId, businessId)`
    - Execute within same transaction as collection creation
    - Log settlement via AuditService if documents were settled
    - _Requirements: 9.1, 9.4, 9.5_

  - [x] 9.3 Write property test for collection slip invoice reference validation (Property 11: Collection Slip Invoice Reference Validation)
    - **Property 11: Collection Slip Invoice Reference Validation**
    - **Validates: Requirements 8.1, 8.2, 8.4, 8.5**
    - Create `tests/Property/InvoicePaymentStatusTest.php` (add to existing)
    - Generate collection slips with various reference scenarios
    - Assert: creation succeeds iff invoice exists, same business, DELIVERED, not fully paid
    - Assert: partial amounts accepted, multiple slips allowed until fully paid

  - [x] 9.4 Write unit tests for CollectionService modifications
    - Test collection with valid invoice reference (succeeds)
    - Test collection with non-existent invoice (rejected)
    - Test collection with DRAFT invoice (rejected)
    - Test collection with fully paid invoice (rejected with correct message)
    - Test partial collection amount (succeeds)
    - Test multiple collections until fully paid (succeeds then rejects)
    - Test settlement trigger is called after creation
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 7.6, 9.1_

- [ ] 10. Modify ManufacturingService to use StockValidator
  - [x] 10.1 Integrate StockValidator into production order creation
    - Replace existing stock check logic with StockValidator.checkBatch call
    - Ensure check is within same transaction as stock deduction (atomicity)
    - Format error using checkBatch output: "Insufficient stock for: [item_name] (need [required], have [available])"
    - _Requirements: 4.1, 4.2, 4.3, 4.4_

  - [x] 10.2 Write unit tests for ManufacturingService stock validation
    - Test production order with sufficient stock (succeeds)
    - Test production order with one deficient component (rejected with message)
    - Test production order with multiple deficient components (all listed)
    - Test atomicity: stock check and deduction in same transaction
    - _Requirements: 4.1, 4.2, 4.3, 4.4_

- [x] 11. Checkpoint - Ensure all service modification tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 12. Implement API routes for computed payment status and balance recalculation
  - [x] 12.1 Add PO payment status and payment shortcut API routes
    - Add GET `/purchase-orders/{id}/payment-status` route → calls computePaymentStatus
    - Add GET `/purchase-orders/{id}/payment-shortcut` route → calls getPaymentShortcutData
    - Include computed Payment_Status in PO list and detail responses
    - _Requirements: 2.5, 3.1, 3.2, 3.3_

  - [x] 12.2 Add invoice payment status API route
    - Add GET `/sales-invoices/{id}/payment-status` route → calls computePaymentStatus
    - Include computed Payment_Status in invoice list and detail responses
    - _Requirements: 7.5_

  - [x] 12.3 Add balance recalculation admin API routes
    - Add POST `/admin/recalculate-balances` route → calls BalanceRecalculator.recalculate(null)
    - Add POST `/admin/recalculate-balances/{accountId}` route → calls BalanceRecalculator.recalculate(accountId)
    - Require administrative permission
    - Return recalculation report as JSON response
    - Log action via AuditService
    - _Requirements: 12.1, 12.4, 12.5, 11.6_

  - [x] 12.4 Write unit tests for API routes
    - Test PO payment status endpoint returns correct format
    - Test payment shortcut endpoint returns pre-filled data
    - Test invoice payment status endpoint returns correct format
    - Test balance recalculation requires admin permission
    - Test balance recalculation returns report format
    - Test single account recalculation endpoint
    - _Requirements: 2.5, 3.1, 7.5, 12.4, 12.5_

- [ ] 13. Update frontend JavaScript for PO lifecycle changes
  - [x] 13.1 Update PO form modal and list page for new lifecycle
    - Remove CLOSED status option from PO status dropdown in `public/assets/js/pages/PurchaseOrders/poForm.modal.js`
    - Update status badge rendering in `public/assets/js/pages/PurchaseOrders/purchaseOrders.js`
    - Display computed Payment_Status badge on RECEIVED POs
    - Add "Record Payment" shortcut button on RECEIVED POs that opens payment form pre-filled
    - Hide/disable payment shortcut when PO is not RECEIVED
    - _Requirements: 1.5, 1.6, 2.5, 3.1, 3.3, 3.4_

- [ ] 14. Update frontend JavaScript for Sales Invoice lifecycle changes
  - [x] 14.1 Update invoice form modal and list page for new lifecycle
    - Remove APPROVED and PAID status options from invoice status dropdown in `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js`
    - Replace with DELIVERED as the progression from DRAFT
    - Update status badge rendering in `public/assets/js/pages/SalesInvoices/salesInvoices.js`
    - Display computed Payment_Status badge on DELIVERED invoices
    - Show stock validation error messages when adding/editing line items
    - _Requirements: 6.1, 6.4, 6.5, 7.5, 5.3_

- [ ] 15. Update frontend for payment and collection forms
  - [x] 15.1 Update payment form to support PO reference
    - Modify `public/assets/js/pages/Finance/paymentForm.modal.js`
    - Add reference_type and reference_id fields (hidden, populated by shortcut)
    - When opened via PO shortcut, pre-fill supplier, amount, and reference fields
    - Allow user to modify pre-filled amount (partial payments)
    - _Requirements: 3.1, 3.2, 3.4, 14.1_

  - [x] 15.2 Update collection form to support invoice reference
    - Modify `public/assets/js/pages/Finance/collectionForm.modal.js`
    - Add reference_type and reference_id fields for invoice linking
    - Display validation errors from backend (invoice not found, already paid, etc.)
    - _Requirements: 8.1, 8.2, 8.3_

- [ ] 16. Add admin balance recalculation UI
  - [x] 16.1 Add balance recalculation controls to admin/settings page
    - Add "Recalculate All Balances" button to settings or admin page
    - Add optional account selector for single-account recalculation
    - Display recalculation report (before/after balances) on completion
    - Require admin role to see/use the controls
    - _Requirements: 12.1, 12.2, 12.4, 12.5_

- [x] 17. Checkpoint - Full integration verification
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 18. Integration tests for complete workflows
  - [x] 18.1 Write integration test for PO full lifecycle
    - Test DRAFT → APPROVED → RECEIVED → payment → settlement flow
    - Verify stock changes, balance changes, and payment status at each step
    - Test cancellation of unpaid RECEIVED PO with reversal
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 2.1, 13.1, 13.3_

  - [x] 18.2 Write integration test for Invoice full lifecycle
    - Test DRAFT → DELIVERED → collection → settlement flow
    - Verify stock changes, balance changes, and payment status at each step
    - Test cancellation of unpaid DELIVERED invoice with reversal
    - _Requirements: 6.1, 6.2, 6.3, 7.1, 13.2, 13.4_

  - [x] 18.3 Write integration test for settlement engine
    - Test single large payment settling multiple POs
    - Test single large collection settling multiple invoices
    - Verify audit logging of settlement actions
    - _Requirements: 9.2, 9.3, 9.5, 10.2, 10.3, 10.5_

  - [x] 18.4 Write integration test for balance recalculation
    - Test full recalculation with mixed payment/collection history
    - Test single account recalculation isolation
    - Test idempotence (run twice, same result)
    - Test transaction rollback on error
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 12.2, 12.3, 12.6_

- [x] 19. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document (20 properties total)
- Unit tests validate specific examples and edge cases
- The implementation language is PHP with PHPUnit + Eris for property-based testing
- Frontend changes use vanilla JavaScript following the existing SPA pattern
- All database operations follow the existing PDO transaction pattern
