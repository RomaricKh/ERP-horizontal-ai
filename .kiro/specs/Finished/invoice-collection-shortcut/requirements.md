# Requirements Document

## Introduction

This feature adds a "Create Collection Slip" shortcut button to the Sales Invoices page. When an invoice has been marked as paid, the user can quickly open the collection slip form with the customer, invoice reference, amount, currency, and exchange rate pre-filled from the invoice data. This streamlines the workflow for users who copy invoices from a physical invoice book where many invoices are already paid at the time of entry.

## Glossary

- **Sales_Invoices_Page**: The page that displays a DataTable of all sales invoices with action buttons per row (salesInvoices.js)
- **Collection_Form**: The modal form used to create a new collection slip (collectionForm.modal.js)
- **Invoice**: A sales invoice record containing customer_id, invoice_number, currency, exchange_rate, total_amount_fc, total_amount_bc, and status
- **Collection_Slip**: A financial record representing money collected from a customer, linked via reference_type and reference_id
- **Shortcut_Button**: A new action button rendered in the invoice row's actions column that opens the Collection_Form with pre-filled data
- **Pre-fill_Data**: The set of invoice fields (customer_id, invoice_number, currency, exchange_rate, total_amount_fc) passed to the Collection_Form for automatic population

## Requirements

### Requirement 1: Display Collection Shortcut Button

**User Story:** As an ERP user, I want to see a "Create Collection Slip" button on paid invoice rows, so that I can quickly create a collection slip without navigating away from the invoices page.

#### Acceptance Criteria

1. WHEN an invoice has status PAID, THE Sales_Invoices_Page SHALL display the Shortcut_Button in the actions column of that invoice row
2. WHILE an invoice has status DRAFT or APPROVED or CANCELLED, THE Sales_Invoices_Page SHALL NOT display the Shortcut_Button for that invoice row
3. THE Shortcut_Button SHALL be visually distinct from other action buttons using an appropriate icon and color that indicates collection or receipt
4. WHEN the user lacks the permission to create collection slips, THE Sales_Invoices_Page SHALL hide the Shortcut_Button for all invoice rows

### Requirement 2: Open Collection Form with Pre-filled Data

**User Story:** As an ERP user, I want the collection form to open with invoice data pre-filled when I click the shortcut button, so that I do not have to manually re-enter customer and amount information.

#### Acceptance Criteria

1. WHEN the user clicks the Shortcut_Button on a paid invoice row, THE Sales_Invoices_Page SHALL open the Collection_Form modal
2. WHEN the Collection_Form opens via the Shortcut_Button, THE Collection_Form SHALL set the payee field to the customer associated with the invoice
3. WHEN the Collection_Form opens via the Shortcut_Button, THE Collection_Form SHALL set the reference_type field to CUSTOMER
4. WHEN the Collection_Form opens via the Shortcut_Button, THE Collection_Form SHALL set the reference_id field to the invoice ID
5. WHEN the Collection_Form opens via the Shortcut_Button, THE Collection_Form SHALL set the currency field to the invoice currency
6. WHEN the Collection_Form opens via the Shortcut_Button, THE Collection_Form SHALL set the exchange_rate field to the invoice exchange rate
7. WHEN the Collection_Form opens via the Shortcut_Button, THE Collection_Form SHALL set the amount_fc field to the invoice total_amount_fc
8. WHEN the Collection_Form opens via the Shortcut_Button, THE Collection_Form SHALL calculate and display the amount_bc based on the pre-filled amount_fc and exchange_rate

### Requirement 3: Collection Form Accepts Pre-fill Data

**User Story:** As a developer, I want the collection form modal to accept and apply pre-fill data from its caller, so that it can be reused as a shortcut from different contexts.

#### Acceptance Criteria

1. WHEN the Collection_Form mount function receives Pre-fill_Data in the data parameter, THE Collection_Form SHALL populate the corresponding form fields with the provided values after loading dropdowns
2. WHEN Pre-fill_Data includes a payee_id, THE Collection_Form SHALL select the matching option in the payee dropdown
3. WHEN Pre-fill_Data includes a currency value, THE Collection_Form SHALL set the currency field and trigger account filtering for that currency
4. IF the payee_id from Pre-fill_Data does not exist in the payee dropdown options, THEN THE Collection_Form SHALL leave the payee field unselected and allow manual selection

### Requirement 4: Collection Slip Creation Refreshes Invoice List

**User Story:** As an ERP user, I want the invoices list to remain current after I create a collection slip via the shortcut, so that I can continue working without manually refreshing.

#### Acceptance Criteria

1. WHEN a collection slip is successfully saved via the Collection_Form opened from the Shortcut_Button, THE Sales_Invoices_Page SHALL remain on the current page without navigation
2. WHEN a collection slip is successfully saved via the Collection_Form opened from the Shortcut_Button, THE Collection_Form modal SHALL close automatically
