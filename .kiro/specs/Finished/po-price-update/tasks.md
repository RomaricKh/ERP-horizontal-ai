# Implementation Plan: PO Price Update

## Overview

Implement automatic item cost price updates when a Purchase Order transitions to RECEIVED status. The implementation introduces a `PriceUpdater` service and `ItemPriceRepository`, modifies `ItemRepository` with a new method, and integrates the price update call into the existing `PurchaseOrderService::updateStatus()` transaction flow.

## Tasks

- [x] 1. Create ItemPriceRepository
  - [x] 1.1 Create `src/Repository/ItemPriceRepository.php` with constructor accepting PDO
    - Implement `insert(array $data): int` method that inserts a new record into `item_prices` table with columns: item_id, business_id, currency, cost_price, sale_price, valid_from, created_at
    - Implement `closePreviousRecord(int $itemId, int $businessId, string $newValidFrom): void` method that sets valid_to on the current open record (where valid_to IS NULL), using DATE_SUB logic for normal case and same-day closure for edge case
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [x] 1.2 Write property test for previous record closure date logic
    - **Property 4: Previous record closure**
    - **Validates: Requirements 2.3, 2.4**

- [x] 2. Add updateCostPrice method to ItemRepository
  - [x] 2.1 Add `updateCostPrice(int $itemId, int $tenantId, float $costPrice): void` method to `src/Repository/ItemRepository.php`
    - Execute UPDATE on items table setting cost_price and updated_at = NOW() where id and tenant_id match
    - _Requirements: 1.2, 1.4, 1.5_

- [x] 3. Create PriceUpdater service
  - [x] 3.1 Create `src/Services/PriceUpdater.php` with constructor accepting PDO
    - Instantiate ItemRepository, ItemPriceRepository, and AuditService in constructor
    - _Requirements: 1.1_

  - [x] 3.2 Implement `deduplicateLines(array $lines): array` method
    - Iterate lines in order, building an associative array keyed by item_id where last occurrence wins
    - Return the map of item_id => line data
    - _Requirements: 4.2_

  - [x] 3.3 Implement `updatePricesFromPO(array $lines, string $poDate, int $poId, string $poNumber): void` method
    - Call deduplicateLines to get unique items
    - For each unique item: fetch current item via ItemRepository::getById, compare unit_price_bc against current cost_price
    - If price differs or current is NULL: call ItemRepository::updateCostPrice, call ItemPriceRepository::closePreviousRecord, call ItemPriceRepository::insert with new price record, log audit entry via AuditService
    - Skip lines where item not found or unit_price_bc is NULL
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 4.1, 4.3, 5.1, 5.2, 5.3_

  - [x] 3.4 Write property test for deduplication logic
    - **Property 2: Deduplication — last line wins**
    - **Validates: Requirements 4.2**

  - [x] 3.5 Write property test for price update correctness
    - **Property 1: Price update correctness**
    - **Validates: Requirements 1.2, 1.3, 1.4**

  - [x] 3.6 Write property test for history record correctness
    - **Property 3: History record correctness**
    - **Validates: Requirements 2.1, 2.2, 4.3**

  - [x] 3.7 Write property test for audit entry correctness
    - **Property 5: Audit entry correctness**
    - **Validates: Requirements 5.1, 5.2, 5.3**

- [x] 4. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Integrate PriceUpdater into PurchaseOrderService
  - [x] 5.1 Modify `src/Services/PurchaseOrderService.php` updateStatus() method
    - In the RECEIVED block, after the stock adjustment loop and before the supplier balance update, instantiate PriceUpdater and call `updatePricesFromPO($lines, $po['po_date'], $id, $po['po_number'])`
    - Ensure no price revert logic is added to the CANCELLED block (prices are intentionally not reversed)
    - _Requirements: 1.1, 3.1, 3.2, 3.3, 6.1, 6.2_

  - [x] 5.2 Write integration tests for end-to-end PO receipt price update flow
    - Test full flow: create PO → approve → receive → verify items.cost_price updated, item_prices record inserted, audit_log entry created
    - Test multi-line PO with duplicate item_ids verifies deduplication
    - Test that cancelling a RECEIVED PO does not revert prices or modify item_prices records
    - Test transaction rollback: force failure in price update, verify entire PO receipt is rolled back
    - _Requirements: 1.1, 1.2, 1.3, 2.1, 3.1, 3.2, 4.1, 4.2, 6.1, 6.2_

- [x] 6. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- The PriceUpdater runs within the existing PurchaseOrderService transaction — no nested transactions
- PHP is the implementation language (matching the existing codebase and design document)
