# Implementation Plan: Recurring Fixed Expenses

## Overview

This plan covers the remaining work for the Recurring Fixed Expenses feature after the model pivot from per-category to per-expense recurrence. The database schema, repositories, ScheduleGeneratorService, AccountingEntryService, controller, and route structure already exist and are correct. The primary work involves: (1) migrating the `payment_allocations` enum to include new recurring types, (2) updating `RecurrenceService` payment methods to use the allocation-based pattern (payment_slip + payment_allocation instead of the deprecated reference_type on slips), (3) updating the frontend expense form to add a "Make this recurring" toggle (replacing the old category-based approach), (4) completing the cron with notifications and expense recognition, (5) building the salary recurrence UI, prepaid assets page, and recurring expenses view, and (6) writing tests.

## Tasks

- [x] 1. Database migration: Extend payment_allocations reference_type enum
  - [x] 1.1 Create migration file `database/migrations/add_recurring_payment_allocation_types.sql`
    - ALTER TABLE `payment_allocations` to add 'RECURRING_EXPENSE' and 'RECURRING_SALARY' to the `reference_type` ENUM
    - SQL: `ALTER TABLE payment_allocations MODIFY COLUMN reference_type ENUM('PURCHASE_ORDER','EXPENSE','SALARY','PARTNER_PROFIT','RECURRING_EXPENSE','RECURRING_SALARY') NOT NULL;`
    - Also update `database/schema.sql` to reflect the new enum values for fresh installs
    - _Requirements: 19.3, 19.7_

- [x] 2. Update RecurrenceService payment methods to use allocation-based pattern
  - [x] 2.1 Update `markInstancePaid()` to create payment_slip + payment_allocation
    - In `src/Services/RecurrenceService.php`, modify `markInstancePaid()`:
    - Remove `reference_type` and `reference_id` from the `$this->paymentRepo->create()` call (those columns no longer exist on `payment_slips`)
    - After creating the payment slip, add: `$this->paymentAllocationRepo->createBatch($paymentRecordId, [['reference_type' => 'RECURRING_EXPENSE' or 'RECURRING_SALARY', 'reference_id' => $instanceId, 'amount_fc' => $actualAmount, 'amount_bc' => $amountBc]])`
    - Add `PaymentAllocationRepository` as a dependency in the constructor
    - Resolve `payee_id` from the source expense (for EXPENSE type) or employee payee_id (for SALARY type) and pass it to the payment slip creation
    - _Requirements: 19.2, 19.3, 19.4_

  - [x] 2.2 Update `payEarly()` to create payment_slip + payment_allocation
    - Same pattern as 2.1: remove `reference_type`/`reference_id` from slip creation, add allocation row after
    - Reference_type: 'RECURRING_EXPENSE' or 'RECURRING_SALARY', reference_id: instanceId
    - Resolve and pass `payee_id` to the payment slip
    - _Requirements: 15.2, 15.3, 19.3_

  - [x] 2.3 Update `bulkAdvancePayment()` to create payment_slip + payment_allocation
    - Remove `reference_type`/`reference_id` from the single payment slip creation
    - After creating the slip, create ONE allocation row with: reference_type = 'RECURRING_EXPENSE' or 'RECURRING_SALARY', reference_id = $bapId (the bulk_advance_payment.id), amount_fc = $totalAmount, amount_bc = $totalAmountBc
    - Resolve and pass `payee_id` to the payment slip
    - _Requirements: 19.6, 19.7_

  - [x] 2.4 Update `reverseInstancePayment()` and `reverseAdvancePayment()` to delete allocations
    - When reversing, also call `$this->paymentAllocationRepo->deleteBySlip($paymentRecordId)` before deleting the payment slip itself
    - This ensures the allocation row is cleaned up on reversal
    - _Requirements: 18.5, 19.5_

  - [x] 2.5 Update `resolveEntityName()` to resolve from expenses table (not categories)
    - The current `resolveEntityName()` calls `$this->expenseRepo->findCategoryById()` for EXPENSE entities
    - Change to query the `expenses` table joined with payee name and category name: `SELECT payee_name, category_name FROM expenses e LEFT JOIN payees py ON ... LEFT JOIN people p ON ... LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id WHERE e.id = :entity_id`
    - Return format: "Payee Name — Category Name" or "Expense #ID" as fallback
    - _Requirements: 8.7, 3.2_

- [x] 3. Checkpoint — Verify backend payment allocation integration
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Frontend: Add "Make this recurring" toggle to expense form
  - [x] 4.1 Add recurrence toggle and fields to `expenseForm.modal.js`
    - In `public/assets/js/pages/Expenses/expenseForm.modal.js`, add a "Make this recurring" checkbox
    - When checked, show additional fields: frequency (select: weekly, bi-weekly, monthly, quarterly, yearly), start_date (date input, defaults to expense_date), end_date (optional date input)
    - Validate end_date >= start_date client-side
    - On save (after expense is created/updated), if recurrence is enabled, POST to `/api/expenses/{id}/recurrence` with {frequency, default_amount: expense amount, default_currency: expense currency, start_date, end_date}
    - When editing an existing recurring expense, pre-populate the toggle as checked and load existing schedule data via GET `/api/expenses/{id}/recurrence`
    - On uncheck, prompt confirmation via `confirm()` and DELETE `/api/expenses/{id}/recurrence`
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7_

  - [x] 4.2 Add corresponding HTML for recurrence fields in `expenseForm.html`
    - In `public/html/Expenses/expenseForm.html`, add the HTML template for the recurrence section: checkbox, frequency select, start_date, end_date inputs
    - Place it after the main expense fields but before the save button
    - Use Bootstrap 5 form groups consistent with existing form patterns
    - _Requirements: 1.1, 1.2_

- [x] 5. Frontend: Recurring expenses view enhancements
  - [x] 5.1 Add overdue badges and dedicated overdue section in `recurring.js`
    - In `public/assets/js/pages/Expenses/recurring.js`, add an "Overdue" section at the top of the view
    - Sort overdue instances by days_overdue descending (most overdue first)
    - Display a prominent danger-colored "Overdue" badge on overdue instances
    - Show `days_overdue` field from the API response
    - Visually distinguish recurring expenses from one-time with a recurrence icon
    - _Requirements: 5.1, 5.2, 21.7, 21.8_

  - [x] 5.2 Add advance payment badges and grouped view
    - Display "Advance" badge on instances with `is_advance_payment = 1`
    - When viewing a bulk advance payment group, show all linked instances together
    - Show count of advance-paid periods remaining for each active schedule
    - _Requirements: 17.1, 17.2, 17.5_

  - [x] 5.3 Add audit history display in instance/schedule detail views
    - When viewing a specific instance or schedule detail, fetch GET /api/recurring-audit-log filtered by entity_type and entity_id
    - Display audit entries in chronological order with action_type, user, previous/new state, and timestamp
    - _Requirements: 20.9_

- [x] 6. Frontend: Salary recurrence UI
  - [x] 6.1 Add "Configure Recurrence" section to the salary form modal
    - In `public/assets/js/pages/Salaries/salaryForm.modal.js`, add a toggle/section for recurrence configuration
    - Fields: frequency (select), start_date, end_date (optional), default_amount (gross), default_currency, exchange_rate
    - Pre-populate from the current salary record values
    - On save, POST to `/api/salaries/employees/{payee_id}/recurrence`
    - On deactivate, prompt confirmation and DELETE the recurrence
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.7_

  - [x] 6.2 Add recurring salary instance status management to salary views
    - Display status indicators (pending, paid, skipped, overdue) on recurring salary instances
    - Allow editing gross_amount, tax_amount, exchange_rate before marking paid
    - Wire "Mark Paid" to PUT /api/recurring-instances/{id}/status with account_id selection
    - Wire "Skip" action
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5_

- [x] 7. Frontend: Prepaid assets page
  - [x] 7.1 Build prepaid assets list page at `public/html/Finance/prepaid-assets.html`
    - Fetch GET /api/prepaid-assets and display as a DataTable
    - Show columns: ID, total_amount, currency, periods_total, periods_recognized, status, created_at
    - Support filtering by status (active, fully_amortized)
    - _Requirements: 22.8_

  - [x] 7.2 Build prepaid asset detail view with amortization schedule
    - Fetch GET /api/prepaid-assets/{id} and display full details
    - Show amortization table: period number, due_date, amount, recognition status (recognized/pending), recognition date
    - Link back to the bulk advance payment and accounting entries
    - _Requirements: 22.9, 22.11_

- [x] 8. Checkpoint — Verify frontend and backend integration
  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Complete cron job with notifications and expense recognition
  - [x] 9.1 Implement `sendOverdueReminders()` notification creation
    - Verify `RecurrenceService::sendOverdueReminders()` correctly creates notifications via NotificationService
    - Add notification types `EXPENSE_OVERDUE` and `SALARY_OVERDUE` to the notification_types table if not already registered (create a migration or seed if needed)
    - Include entity name, due date, amount, and days_overdue in the notification body
    - The cron endpoint already calls this method — just verify it works end-to-end
    - _Requirements: 21.3, 21.4_

  - [x] 9.2 Implement `sendDueNotifications()` notification creation
    - Verify `RecurrenceService::sendDueNotifications()` correctly creates notifications for pending instances due within 3 days
    - Add notification types `EXPENSE_DUE` and `SALARY_DUE` to the notification_types table if not already registered
    - Ensure idempotency: only send if `notification_sent = 0`, then mark `notification_sent = 1`
    - The cron endpoint already calls this method — verify it works end-to-end
    - _Requirements: 3.1, 3.2, 3.4, 11.1, 11.2, 11.4_

  - [x] 9.3 Add notification type registration migration
    - Create `database/migrations/add_recurrence_notification_types.sql` to insert EXPENSE_DUE, EXPENSE_OVERDUE, SALARY_DUE, SALARY_OVERDUE notification types into the notification system
    - _Requirements: 3.1, 11.1, 21.3_

- [ ] 10. Write property-based tests (PHP)
  - [ ]* 10.1 Write property test for ScheduleGeneratorService date calculations
    - **Property 3: Due Date Calculation Correctness**
    - Test `calculateNextDueDate()` produces correct intervals for all frequencies
    - Test month-end clamping: resulting day never exceeds last day of target month
    - Generate random valid dates × frequencies (100+ iterations)
    - File: `tests/Property/RecurringExpenses/ScheduleGeneratorPropertyTest.php`
    - **Validates: Requirements 2.3, 2.6, 10.3, 10.6**

  - [ ]* 10.2 Write property test for generation respects end_date
    - **Property 5: Generation Respects End Date**
    - For any schedule with end_date, no generated due date exceeds end_date
    - Use `calculateDueDatesInRange()` with random start/end dates
    - File: `tests/Property/RecurringExpenses/ScheduleGeneratorPropertyTest.php`
    - **Validates: Requirements 2.4, 6.2, 10.4, 13.2**

  - [ ]* 10.3 Write property test for idempotent generation
    - **Property 6: Idempotent Generation**
    - Running `calculateDueDatesInRange()` multiple times with same inputs produces identical arrays
    - File: `tests/Property/RecurringExpenses/ScheduleGeneratorPropertyTest.php`
    - **Validates: Requirements 2.5, 10.5, 18.1**

  - [ ]* 10.4 Write property test for RecurrenceService state transitions
    - **Property 9: Payment Transition Invariants**
    - After marking paid: status='paid', paid_date set, payment_record_id set
    - If due_date > today: is_advance_payment = 1
    - File: `tests/Property/RecurringExpenses/RecurrenceServicePropertyTest.php`
    - **Validates: Requirements 4.2, 12.2, 15.2, 15.3**

  - [ ]* 10.5 Write property test for schedule modification preserves immutable instances
    - **Property 11: Schedule Modification Preserves Immutable Instances**
    - After schedule update, paid instances and advance-paid instances remain unchanged
    - File: `tests/Property/RecurringExpenses/RecurrenceServicePropertyTest.php`
    - **Validates: Requirements 6.1, 6.3, 13.1, 13.3, 18.2**

  - [ ]* 10.6 Write property test for bulk advance payment correctness
    - **Property 14: Bulk Advance Payment Correctness**
    - Verify correct number of instances marked paid, total_amount = per_period × actual_periods
    - File: `tests/Property/RecurringExpenses/RecurrenceServicePropertyTest.php`
    - **Validates: Requirements 16.3, 16.4, 16.7**

  - [ ]* 10.7 Write property test for payment allocation correctness
    - **Property 15: Payment Creates Slip and Allocation with Balance Deduction**
    - After payment: a payment_slips row exists, a payment_allocations row exists with correct reference_type and reference_id, account balance decreased by amount_bc
    - **Property 16: Bulk Advance Creates Single Payment Slip and Single Allocation**
    - After bulk advance: exactly one payment_slips row, exactly one payment_allocations row with reference_id = bulk_advance_payment.id
    - File: `tests/Property/RecurringExpenses/PaymentAllocationPropertyTest.php`
    - **Validates: Requirements 19.2, 19.3, 19.4, 19.6, 19.7**

  - [ ]* 10.8 Write property test for AccountingEntryService prepaid asset invariant
    - **Property 18: Prepaid Asset Amount Invariant**
    - total_amount = per_period_amount × periods_total
    - **Property 19: Double-Entry Accounting Balance**
    - Each accounting entry has balanced debit/credit amounts
    - File: `tests/Property/RecurringExpenses/AccountingEntryPropertyTest.php`
    - **Validates: Requirements 22.1, 22.2, 22.3**

  - [ ]* 10.9 Write property test for expense recognition and full amortization
    - **Property 20: Expense Recognition on Period Due Date**
    - periods_recognized matches count of due periods
    - **Property 21: Fully Amortized When All Periods Recognized**
    - status='fully_amortized' when periods_recognized = periods_total
    - File: `tests/Property/RecurringExpenses/AccountingEntryPropertyTest.php`
    - **Validates: Requirements 22.3, 22.4, 22.5**

- [ ] 11. Write property-based tests (JavaScript)
  - [ ]* 11.1 Write JS property test for date calculation logic
    - **Property 3: Due Date Calculation Correctness (JS port)**
    - Replicate frequency interval and month-end clamping tests in fast-check
    - Use `fc.date()` arbitrary to generate random start dates
    - File: `tests/recurring-fixed-expenses/dateCalculation.property.test.js`
    - **Validates: Requirements 2.3, 2.6, 10.3, 10.6**

  - [ ]* 11.2 Write JS unit tests for bulk advance modal interactions
    - Test period selection validation (2-12 range)
    - Test summary calculation display (periods × per_period = total)
    - Test end_date adjustment messaging
    - File: `tests/recurring-fixed-expenses/bulkAdvanceModal.test.js`
    - **Validates: Requirements 16.2, 16.6, 16.7**

  - [ ]* 11.3 Write JS unit tests for recurring UI status rendering
    - Test overdue badge rendering for overdue instances
    - Test advance payment badge rendering
    - Test status grouping (pending/paid/skipped/overdue sections)
    - File: `tests/recurring-fixed-expenses/recurringUI.test.js`
    - **Validates: Requirements 5.1, 17.1, 21.7, 21.8**

- [ ] 12. Write unit tests (PHP)
  - [ ]* 12.1 Write unit tests for payment allocation integration
    - Test markInstancePaid creates both payment_slip and payment_allocation with correct reference_type
    - Test bulkAdvancePayment creates single slip + single allocation with reference_id = bap.id
    - Test reverseInstancePayment deletes allocation and slip
    - File: `tests/Unit/RecurringExpenses/PaymentAllocationTest.php`
    - _Requirements: 19.2, 19.3, 19.6, 19.7_

  - [ ]* 12.2 Write unit tests for RecurrenceService edge cases
    - Test duplicate schedule creation rejection
    - Test currency mismatch error on payment
    - Test early payment rejected when due_date <= today
    - Test skip only from pending/overdue status
    - Test overdue → paid transition
    - File: `tests/Unit/RecurringExpenses/RecurrenceServiceTest.php`
    - _Requirements: 4.2, 4.3, 15.2, 18.1, 21.5_

  - [ ]* 12.3 Write unit tests for ScheduleGeneratorService edge cases
    - Test Feb 28/29 leap year handling
    - Test 31st of month clamping (Jan 31 → Feb 28 → Mar 31)
    - Test empty range when start > end
    - Test single-day range
    - File: `tests/Unit/RecurringExpenses/ScheduleGeneratorTest.php`
    - _Requirements: 2.3, 2.6, 10.6_

- [x] 13. Final checkpoint — Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- **Already complete and unchanged:** AccountingEntryService (all methods), RecurrenceInstanceRepository, BulkAdvancePaymentRepository, PrepaidAssetRepository, RecurrenceScheduleRepository, ScheduleGeneratorService, database tables, route file structure, controller methods
- **Critical fix (Task 2):** The current `markInstancePaid()`, `payEarly()`, and `bulkAdvancePayment()` pass `reference_type`/`reference_id` to `PaymentRepository::create()` — but those columns were DROPPED from `payment_slips` in the allocation migration. They must be updated to use `PaymentAllocationRepository::createBatch()` instead.
- The `validateEntity()` method already validates against the `expenses` table (not categories) — no change needed there
- The routes already point to `/expenses/{id}/recurrence` — no change needed there
- The frontend expense form needs the "Make this recurring" toggle (task 4), replacing the old category-based `is_fixed` approach (old task 5.1)

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["2.1", "2.2", "2.3", "2.4", "2.5"] },
    { "id": 2, "tasks": ["4.1", "4.2", "9.1", "9.2", "9.3"] },
    { "id": 3, "tasks": ["5.1", "5.2", "5.3", "6.1", "6.2"] },
    { "id": 4, "tasks": ["7.1", "7.2"] },
    { "id": 5, "tasks": ["10.1", "10.2", "10.3", "10.4", "10.5", "10.6", "10.7", "10.8", "10.9"] },
    { "id": 6, "tasks": ["11.1", "11.2", "11.3", "12.1", "12.2", "12.3"] }
  ]
}
```
