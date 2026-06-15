# Tasks

## Task 1: Extend Collection Form to Accept Pre-fill Data

- [x] 1.1 Add `applyPrefill` function to `collectionForm.modal.js` that accepts a prefill object and sets form field values (payee, reference_type, reference_id, currency, exchange_rate, amount_fc)
- [x] 1.2 Modify the `mount` function in `collectionForm.modal.js` to call `applyPrefill(data.prefill)` after dropdowns are loaded (after `populatePayeeSelect` and `loadAccounts` complete)
- [x] 1.3 In `applyPrefill`, after setting currency, call `loadAccounts(prefill.currency)` to filter accounts for the pre-filled currency
- [x] 1.4 In `applyPrefill`, after setting amount_fc and exchange_rate, call `recalcBc()` to calculate and display amount_bc
- [x] 1.5 Handle edge case: if `prefill.payee_id` does not match any option in the payee dropdown, leave the field unselected

## Task 2: Add Shortcut Button to Sales Invoices Table

- [x] 2.1 In `salesInvoices.js` `renderTable` function, add a `canCollect` permission check using `hasPermission('collections.create')`
- [x] 2.2 In the `renderTable` forEach loop, add a conditional block that renders the "Create Collection Slip" button (`data-action="invoice.collect"`) for invoices with status `PAID` and when `canCollect` is true
- [x] 2.3 Style the button with `btn-secondary btn-circle btn-sm` classes and a `fas fa-receipt` icon to visually distinguish it from other action buttons

## Task 3: Implement Shortcut Button Action Handler

- [x] 3.1 Add `invoice.collect` action handler in the `registerPageActions` call in `salesInvoices.js`
- [x] 3.2 In the handler, extract the invoice ID from the DataTable row data and fetch full invoice details via `GET /api/sales-invoices/{id}`
- [x] 3.3 Call `openModal` with the collection form template (`html/Finance/collectionForm.html`), controller (`../pages/Finance/collectionForm.modal.js`), and a `data` object containing the prefill fields mapped from the invoice response
- [x] 3.4 Map invoice fields to prefill object: `customer_payee_id` → `payee_id`, `"CUSTOMER"` → `reference_type`, `invoice_number` → `reference_id`, `currency` → `currency`, `exchange_rate` → `exchange_rate`, `total_amount_fc` → `amount_fc`

## Task 4: Ensure Backend Returns Customer Payee ID

- [x] 4.1 Verify the `GET /api/sales-invoices/{id}` response includes `customer_payee_id` (the payee_id from the people table for the invoice's customer)
- [x] 4.2 If not present, update the `SalesInvoicesController::viewById` method to join/include the customer's `payee_id` in the response

## Task 5: Handle Post-Save Behavior

- [x] 5.1 In `salesInvoices.js`, add an event listener for `collections.updated` that calls `fetchInvoices()` to refresh the table after a collection slip is created
- [x] 5.2 Ensure the event listener is cleaned up in the `unmount` function

## Task 6: Write Tests

- [x] 6.1 Write property-based tests using fast-check for Property 1 (status-based button visibility): generate random invoices with random statuses, verify button appears only for PAID
- [x] 6.2 Write property-based tests using fast-check for Property 2 (permission-based button visibility): generate random PAID invoices with permission flag, verify button hidden when permission is false
- [x] 6.3 Write property-based tests using fast-check for Property 3 (pre-fill data mapping): generate random invoice objects, verify form fields match after applyPrefill
- [x] 6.4 Write property-based tests using fast-check for Property 4 (amount BC calculation): generate random amount_fc and exchange_rate, verify amount_bc equals calcBc result
- [x] 6.5 Write unit tests for edge cases: missing payee in dropdown, empty prefill object, reference_type always "CUSTOMER"
