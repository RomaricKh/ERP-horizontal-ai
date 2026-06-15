# Requirements Document

## Introduction

The Sales Invoice item selector currently displays all active items regardless of type, including "Raw Material" items that are almost never sold directly. This feature filters out excluded item types from the Sales Invoice item dropdown to reduce clutter and prevent accidental selection of non-sellable items. The filtering must be configurable (not hardcoded to a single type name), must only apply to the Sales Invoice context, and must not affect other forms such as Purchase Orders where raw materials are expected.

## Glossary

- **Items_API**: The `/api/items` REST endpoint served by `ItemsController::index()` that returns active items for the current business
- **Invoice_Form**: The Sales Invoice creation/editing modal (`invoiceForm.modal.js`) that presents an item selector dropdown for adding line items
- **PO_Form**: The Purchase Order creation/editing modal (`poForm.modal.js`) that presents an item selector dropdown for adding line items
- **Item_Type**: A user-defined classification for items stored in the `item_types` table (e.g., "Raw Material", "Finished Goods")
- **Excluded_Type**: An item type whose items should be hidden from the Sales Invoice item selector
- **Type_Filter**: A query parameter (`exclude_types`) accepted by the Items_API to exclude items belonging to specified type names

## Requirements

### Requirement 1: API Support for Type Exclusion Filtering

**User Story:** As a frontend developer, I want the Items_API to accept an optional type exclusion parameter, so that I can request items with certain types filtered out at the database level.

#### Acceptance Criteria

1. WHEN the Items_API receives a request with the `exclude_types` query parameter, THE Items_API SHALL return only items whose type_name does not match any of the specified excluded type names
2. WHEN the Items_API receives a request without the `exclude_types` query parameter, THE Items_API SHALL return all active items (preserving current behavior)
3. WHEN the `exclude_types` parameter contains multiple type names separated by commas, THE Items_API SHALL exclude items matching any of the listed type names
4. THE Items_API SHALL perform case-insensitive comparison when matching type names against the `exclude_types` parameter
5. WHEN the `exclude_types` parameter contains a type name that does not exist in the system, THE Items_API SHALL ignore the non-existent type name and return results normally without error

### Requirement 2: Sales Invoice Form Excludes Raw Materials

**User Story:** As a sales user, I want the item selector in the Sales Invoice form to hide raw material items, so that I only see items that are appropriate for selling.

#### Acceptance Criteria

1. WHEN the Invoice_Form loads items for the item selector dropdown, THE Invoice_Form SHALL request items from the Items_API with `exclude_types=Raw Material`
2. THE Invoice_Form SHALL display only items that are not of an excluded type in the item selector dropdown
3. WHEN a new item type is added to the exclusion list, THE Invoice_Form SHALL exclude items of that type on the next form load without requiring code changes to the frontend

### Requirement 3: Purchase Order Form Remains Unaffected

**User Story:** As a procurement user, I want the PO item selector to continue showing all item types including raw materials, so that I can create purchase orders for any item.

#### Acceptance Criteria

1. THE PO_Form SHALL continue to request items from the Items_API without the `exclude_types` parameter
2. THE PO_Form SHALL display all active items including those of type "Raw Material" in the item selector dropdown

### Requirement 4: Flexible Type Name Matching

**User Story:** As a system administrator, I want the type exclusion filter to handle variations in type naming, so that the filter works reliably even if type names have inconsistent casing or whitespace.

#### Acceptance Criteria

1. WHEN matching type names for exclusion, THE Items_API SHALL trim leading and trailing whitespace from both the filter parameter values and the stored type names before comparison
2. WHEN matching type names for exclusion, THE Items_API SHALL use case-insensitive comparison (e.g., "raw material", "Raw Material", and "RAW MATERIAL" are treated as equivalent)
3. WHEN the `exclude_types` parameter contains empty segments (e.g., `"Raw Material,,Finished Goods"`), THE Items_API SHALL ignore empty segments and process only non-empty type names

### Requirement 5: Existing Invoice Line Items Display Correctly

**User Story:** As a sales user, I want to view existing invoices that contain raw material items (created before this filter was applied), so that historical data remains accessible.

#### Acceptance Criteria

1. WHEN the Invoice_Form loads an existing invoice that contains line items of an excluded type, THE Invoice_Form SHALL display those line items correctly with their item name and details
2. WHEN the Invoice_Form loads an existing invoice with excluded-type line items, THE Invoice_Form SHALL show the item name in the selector for that line even though the item would not appear in the dropdown for new line selections
