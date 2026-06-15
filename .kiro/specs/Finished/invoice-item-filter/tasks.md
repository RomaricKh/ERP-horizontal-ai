# Implementation Plan: Invoice Item Filter

## Overview

Add an optional `exclude_types` query parameter to the `/api/items` endpoint so the Sales Invoice form can filter out "Raw Material" items from the item selector dropdown. The implementation progresses from backend (repository → service → controller) to frontend (invoice form changes), followed by property-based and unit tests.

## Tasks

- [x] 1. Implement backend type exclusion filtering
  - [x] 1.1 Add `parseExcludeTypes` method to `ItemService`
    - Open `src/Services/ItemService.php`
    - Add a private method `parseExcludeTypes(?string $param): array` that:
      - Returns empty array if `$param` is null or whitespace-only
      - Splits on comma, trims each segment, removes empty segments
      - Returns the cleaned array of type names
    - _Requirements: 1.3, 4.1, 4.2, 4.3_

  - [x] 1.2 Update `ItemRepository::getAllForBusiness` to accept and apply exclusion filter
    - Open `src/Repository/ItemRepository.php`
    - Add an optional `array $excludeTypes = []` parameter to `getAllForBusiness`
    - When `$excludeTypes` is non-empty, build a parameterized `AND LOWER(TRIM(it.name)) NOT IN (...)` clause
    - Lowercase each exclusion value before binding (`strtolower(trim($type))`)
    - Use named placeholders (`:excl_0`, `:excl_1`, etc.) to prevent SQL injection
    - _Requirements: 1.1, 1.4, 4.1, 4.2_

  - [x] 1.3 Update `ItemService::listForCurrentBusiness` to pass exclusion filter
    - Open `src/Services/ItemService.php`
    - Add optional `?string $excludeTypesParam = null` parameter to `listForCurrentBusiness`
    - Call `parseExcludeTypes` and pass the result to the repository method
    - _Requirements: 1.1, 1.2, 1.5_

  - [x] 1.4 Update `ItemsController::index` to read `exclude_types` from query string
    - Open `src/Controllers/ItemsController.php`
    - Read `$_GET['exclude_types'] ?? null` and pass to `$this->service->listForCurrentBusiness()`
    - _Requirements: 1.1, 1.2_

- [x] 2. Checkpoint - Verify backend changes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 3. Implement frontend changes for Sales Invoice form
  - [x] 3.1 Update `loadItems()` to pass `exclude_types` parameter
    - Open `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js`
    - Change the `apiFetch('/api/items')` call to `apiFetch('/api/items?exclude_types=Raw Material')`
    - _Requirements: 2.1, 2.2, 2.3_

  - [x] 3.2 Update `buildItemOptions()` to support fallback for excluded items
    - In `invoiceForm.modal.js`, modify `buildItemOptions(selectedId)` to accept an optional `fallbackName` parameter
    - When `selectedId` is provided but not found in `itemsList`, and `fallbackName` is provided, append a manual `<option>` with that name marked as selected
    - _Requirements: 5.1, 5.2_

  - [x] 3.3 Update `fetchAndFill()` to pass `item_name` for fallback display
    - In `invoiceForm.modal.js`, update the `fetchAndFill` function to pass `item_name` from the invoice line data to `addLineRow`
    - Update `addLineRow` to pass `prefill.item_name` as the fallback name to `buildItemOptions`
    - _Requirements: 5.1, 5.2_

- [x] 4. Checkpoint - Verify frontend integration
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Write property-based tests for type exclusion filter
  - [x] 5.1 Write property test for type exclusion filter correctness
    - Create `tests/Property/ItemTypeExclusionPropertyTest.php`
    - Use PHPUnit `@dataProvider` pattern (matching `PriceUpdateCorrectnessPropertyTest.php` style)
    - Generate 120+ random iterations with:
      - Random item sets with random type names (varied casing, whitespace)
      - Random `exclude_types` parameter strings with commas, empty segments, whitespace
    - Test `parseExcludeTypes` + filtering logic: assert returned items are exactly those whose trimmed/lowercased type name does NOT match any non-empty trimmed/lowercased entry in the exclusion list
    - **Property 1: Type exclusion filter correctness**
    - **Validates: Requirements 1.1, 1.3, 1.4, 4.1, 4.2, 4.3**

  - [x] 5.2 Write property test for empty exclusion preserving all items
    - In the same test file, add a second data provider and test method
    - Generate 120+ random iterations with:
      - Random item sets
      - Absent, empty, whitespace-only, or commas-only `exclude_types` values
    - Assert that all active items are returned (identical to unfiltered result)
    - **Property 2: Empty exclusion preserves all items**
    - **Validates: Requirements 1.2, 1.5**

- [x] 6. Write unit tests for parsing and repository logic
  - [x] 6.1 Write unit tests for `parseExcludeTypes` method
    - Create `tests/Unit/ItemServiceParseExcludeTypesTest.php`
    - Test specific examples:
      - `null` → empty array
      - `""` → empty array
      - `"   "` → empty array
      - `"Raw Material"` → `["Raw Material"]`
      - `"Raw Material,,Finished Goods"` → `["Raw Material", "Finished Goods"]`
      - `" Raw Material , "` → `["Raw Material"]`
      - `",,,,"` → empty array
    - _Requirements: 1.3, 4.1, 4.2, 4.3_

  - [x] 6.2 Write unit tests for `ItemRepository::getAllForBusiness` with exclusion
    - Create `tests/Unit/ItemRepositoryExcludeTypesTest.php`
    - Test that items with excluded types are not returned
    - Test case-insensitive matching (e.g., "raw material" excludes "Raw Material")
    - Test that non-existent type names are ignored without error
    - _Requirements: 1.1, 1.4, 1.5, 4.1, 4.2_

- [x] 7. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- The PO form (`poForm.modal.js`) requires NO changes — it continues calling `/api/items` without parameters
- No database schema changes are needed; filtering uses existing `item_types.name` column
- The `LOWER(TRIM())` comparison on `item_types` is safe for performance since the table is small (<20 rows per business)
- Property tests follow the existing project pattern in `tests/Property/PriceUpdateCorrectnessPropertyTest.php`
- Each task references specific requirements for traceability
