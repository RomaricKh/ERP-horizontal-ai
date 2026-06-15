# Requirements Document

## Introduction

This feature extends the existing expense and salary management modules to support recurring obligations through a shared Recurrence_Engine. When a user creates or edits an individual expense and marks it as recurring via the "Make this recurring" toggle on the Expense_Form, the system prompts for a recurrence schedule (monthly, weekly, bi-weekly, quarterly, yearly). Similarly, when configuring a salary employee for recurring payments, the user sets the frequency explicitly. Based on these schedules, the system automatically generates upcoming expense or salary instances and notifies the user when a payment is due. Additionally, users can pay obligations early (before the due date) or pay multiple future periods in advance (bulk advance payment), with the Recurrence_Engine correctly marking those future instances as paid. This eliminates manual re-entry of predictable obligations and ensures timely payments across both expenses and salaries.

The system integrates with the existing Payments module using the allocation-based payment pattern: when a recurring instance is marked as paid, the system creates a payment_slip (header) and a payment_allocation (detail row) with reference_type 'RECURRING_EXPENSE' or 'RECURRING_SALARY' pointing to the recurrence_instance.id. This follows the same pattern used for normal expenses and salaries, ensuring the financial ledger remains consistent. All status changes are tracked through a comprehensive audit trail for compliance and accountability. Overdue instances are automatically escalated with daily reminder notifications and prominent dashboard visibility. For bulk advance payments, the system follows accrual accounting standards by recording the full amount as a prepaid asset at payment time and recognizing each period's portion as an expense when the due date arrives.

## Glossary

- **Recurrence_Engine**: The backend subsystem responsible for evaluating recurring schedules (for both expenses and salaries), generating due instances, triggering payment notifications, and processing advance payments.
- **Recurrence_Schedule**: A configuration attached to an individual expense record or a salary employee defining the frequency (weekly, bi-weekly, monthly, quarterly, yearly), start date, and optional end date for automatic repetition. The entity_type is 'EXPENSE' (entity_id references expenses.id) or 'SALARY' (entity_id references the employee's payee_id).
- **Recurring_Expense_Instance**: A single generated instance created by the Recurrence_Engine based on a Recurrence_Schedule attached to a specific expense. Each instance represents one occurrence of the recurring expense with the same amount, currency, payee, and category as the source expense.
- **Recurring_Salary_Instance**: A single generated salary record created by the Recurrence_Engine based on a salary Recurrence_Schedule. Each instance represents one pay period for an employee.
- **Salary_Schedule**: A Recurrence_Schedule specifically attached to an employee (payee of type Employee), defining the recurring salary payment configuration including gross amount, tax, currency, exchange rate, and period boundaries.
- **Due_Date**: The date on which a Recurring_Expense_Instance or Recurring_Salary_Instance is expected to be paid.
- **Advance_Payment**: A payment made before the Due_Date of an instance, marking it as paid early.
- **Bulk_Advance_Payment**: A single action that pays multiple consecutive future instances at once (e.g., 3 months of rent or 2 months of salary in advance).
- **Notification_System**: The existing notification subsystem that delivers alerts to users (as defined in the user-notifications spec).
- **Expense_Form**: The existing modal form used to create and edit individual expenses. Contains the "Make this recurring" toggle for configuring recurrence on a specific expense.
- **Salary_Form**: The existing modal form used to create and edit individual salary records.
- **Employee**: A payee entity of type "Employee" in the people module, representing a person who receives salary payments.
- **Payment_Slip**: A formal financial record in the existing Payments module representing the cash movement (header), containing payment_number, payment_date, payee_id, account_id, amount_fc, amount_bc, method, and currency. Payment_Slips deduct from the source account balance when created.
- **Payment_Allocation**: A detail row in the existing Payments module linking a Payment_Slip to a specific document. Contains reference_type (enum), reference_id, amount_fc, and amount_bc. The reference_type enum will be extended with 'RECURRING_EXPENSE' and 'RECURRING_SALARY' values.
- **Source_Account**: An account from the existing Accounts module selected by the user as the funding source for a payment. Each account tracks balance_fc (foreign currency balance) and balance (base currency balance).
- **Audit_Log**: A timestamped record of a status change or significant action performed on a recurring schedule or instance, capturing the actor (user), action type, previous state, new state, and timestamp.
- **Overdue_Instance**: A Recurring_Expense_Instance or Recurring_Salary_Instance whose due_date has passed while the instance status remains "pending", automatically transitioned to "overdue" status by the Recurrence_Engine.
- **Prepaid_Asset**: An accounting asset representing the full amount of a bulk advance payment recorded at payment time. The Prepaid_Asset is amortized (reduced) as each covered period's due date arrives and the corresponding portion is recognized as an expense.
- **Expense_Recognition**: The accounting action of converting a portion of a Prepaid_Asset into an actual expense entry when the corresponding period's due date arrives, following accrual accounting standards.
- **Accounting_Entry**: A structured financial record representing a debit or credit to a specific account, generated by the system to maintain double-entry bookkeeping integrity for prepaid expense handling.

## Requirements

### Requirement 1: Recurrence Schedule Configuration on Individual Expenses

**User Story:** As a user, I want to mark an individual expense as recurring when creating or editing it on the Expense_Form, so that the system knows how often that specific expense repeats.

#### Acceptance Criteria

1. THE Expense_Form SHALL display a "Make this recurring" checkbox when creating or editing an expense.
2. WHEN the user checks "Make this recurring" on the Expense_Form, THE Expense_Form SHALL display additional fields for recurrence configuration: frequency and start date, with an optional end date field.
3. THE Expense_Form SHALL offer the following frequency options: weekly, bi-weekly, monthly, quarterly, and yearly.
4. WHEN the user saves an expense with recurrence enabled, THE Recurrence_Engine SHALL create a Recurrence_Schedule with entity_type 'EXPENSE' and entity_id referencing the saved expense's id, using the expense's own amount, currency, payee, and category as the defaults for generated instances.
5. IF the user does not provide a start date for the recurrence, THEN THE Expense_Form SHALL default the start date to the expense's expense_date.
6. IF the user provides an end date that is before the start date, THEN THE Expense_Form SHALL display a validation error and prevent saving.
7. WHEN the user unchecks "Make this recurring" on an existing recurring expense, THE Expense_Form SHALL prompt for confirmation and deactivate the Recurrence_Schedule for that expense.

### Requirement 2: Automatic Recurring Expense Generation

**User Story:** As a user, I want the system to automatically create expense instances based on my recurrence schedule, so that I do not have to manually re-enter predictable expenses each period.

#### Acceptance Criteria

1. THE Recurrence_Engine SHALL evaluate all active expense Recurrence_Schedules daily and generate Recurring_Expense_Instances for any due dates that have arrived but do not yet have a corresponding instance record.
2. WHEN a Recurring_Expense_Instance is generated, THE Recurrence_Engine SHALL create an instance with the source expense's amount, currency, the computed due date, and a status of "pending".
3. THE Recurrence_Engine SHALL calculate the next due date based on the frequency: weekly (every 7 days), bi-weekly (every 14 days), monthly (same day of month), quarterly (every 3 months), yearly (every 12 months).
4. WHILE a Recurrence_Schedule has an end date that has passed, THE Recurrence_Engine SHALL stop generating new instances for that schedule.
5. THE Recurrence_Engine SHALL not generate duplicate instances for the same due date and schedule combination within the same business.
6. WHEN a monthly schedule falls on a day that does not exist in the target month (e.g., the 31st in February), THE Recurrence_Engine SHALL use the last day of that month as the due date.

### Requirement 3: Payment Notification for Recurring Expenses

**User Story:** As a user, I want to be notified when a recurring expense is due for payment, so that I can pay it on time and avoid late fees.

#### Acceptance Criteria

1. WHEN a Recurring_Expense_Instance due date is within 3 days from the current date, THE Notification_System SHALL create a notification of type "EXPENSE_DUE" for all users with the "expenses.view" permission in the same business.
2. THE Notification_System SHALL include the expense payee name, category name, due date, and amount in the notification body.
3. WHEN the user clicks the expense due notification, THE Notification_System SHALL navigate the user to the expenses page.
4. THE Recurrence_Engine SHALL not send duplicate notifications for the same Recurring_Expense_Instance.
5. WHEN a Recurring_Expense_Instance is marked as "paid", THE Notification_System SHALL not send further reminders for that instance.

### Requirement 4: Recurring Expense Status Management

**User Story:** As a user, I want to mark recurring expenses as paid or skip them, so that I can track which recurring obligations have been fulfilled.

#### Acceptance Criteria

1. THE Expense_Form SHALL display a status indicator for recurring expenses showing one of: "pending", "paid", "skipped", or "overdue".
2. WHEN the user marks a Recurring_Expense_Instance as "paid", THE Recurrence_Engine SHALL update the instance status to "paid" and record the payment date.
3. WHEN the user marks a Recurring_Expense_Instance as "skipped", THE Recurrence_Engine SHALL update the instance status to "skipped" and stop sending notifications for that instance.
4. THE Expense_Form SHALL allow the user to edit the amount of a Recurring_Expense_Instance before marking it as paid (to accommodate price changes).
5. WHEN a Recurring_Expense_Instance is edited, THE Recurrence_Engine SHALL preserve the original scheduled amount for reference alongside the actual paid amount.

### Requirement 5: Recurring Expenses Dashboard View

**User Story:** As a user, I want to see all my upcoming recurring expenses in one view, so that I can plan my cash flow and know what payments are coming.

#### Acceptance Criteria

1. THE Expense_Form SHALL provide a filtered view on the expenses page showing only recurring expense instances grouped by status (pending, paid, skipped).
2. WHEN the user views the expenses list, THE Expense_Form SHALL visually distinguish recurring expenses from one-time expenses with a recurrence icon or badge.
3. THE Expense_Form SHALL display the next due date for each active Recurrence_Schedule in the recurring expenses view.
4. WHEN the user filters expenses by a specific recurring expense, THE Expense_Form SHALL show all instances (past and upcoming) for that schedule.

### Requirement 6: Recurrence Schedule Modification

**User Story:** As a user, I want to modify or cancel a recurrence schedule on an individual expense, so that I can adapt to changes in my recurring obligations (e.g., rent increase, cancelled subscription).

#### Acceptance Criteria

1. WHEN the user edits a recurring expense's recurrence schedule via the Expense_Form, THE Expense_Form SHALL apply the changes to all future instances only, preserving past instances unchanged.
2. WHEN the user sets an end date on an active Recurrence_Schedule, THE Recurrence_Engine SHALL stop generating new instances after that end date.
3. WHEN the user changes the default amount on a Recurrence_Schedule (by editing the source expense amount), THE Recurrence_Engine SHALL use the new amount for all future generated instances.
4. WHEN the user deletes an expense that has a Recurrence_Schedule, THE Expense_Form SHALL prompt for confirmation explaining that all future recurring instances will be cancelled.
5. IF the user confirms deletion of an expense with active recurrences, THEN THE Recurrence_Engine SHALL cancel all pending future instances and stop generating new ones.

### Requirement 7: Recurrence Data Storage

**User Story:** As a developer, I want the recurrence data stored in a structured and queryable format, so that the system can efficiently evaluate schedules and generate instances.

#### Acceptance Criteria

1. THE Recurrence_Engine SHALL store recurrence schedules in a dedicated database table with columns: id, entity_type (EXPENSE or SALARY), entity_id (references expenses.id for EXPENSE type, references payee_id for SALARY type), business_id, frequency, default_amount, default_currency, start_date, end_date (nullable), is_active, created_at, and updated_at.
2. THE Recurrence_Engine SHALL store generated instances in a dedicated table with columns: id, recurrence_schedule_id, entity_type, business_id, due_date, scheduled_amount, actual_amount (nullable), currency, status, paid_date (nullable), notification_sent flag, advance_payment_id (nullable), payment_record_id (nullable), period_start (nullable), period_end (nullable), created_at, and updated_at.
3. THE Recurrence_Engine SHALL index the instances table on (business_id, due_date, status) for efficient querying of upcoming and pending obligations.
4. THE Recurrence_Engine SHALL enforce a unique constraint on (recurrence_schedule_id, due_date) to prevent duplicate instance generation.

### Requirement 8: API Endpoints for Recurrence Management

**User Story:** As a frontend developer, I want API endpoints to manage recurrence schedules and instances, so that the UI can create, read, update, and display recurring data.

#### Acceptance Criteria

1. THE Recurrence_Engine SHALL expose a GET /api/recurrences endpoint that returns all active recurrence schedules for the current business, filterable by entity_type (EXPENSE or SALARY).
2. THE Recurrence_Engine SHALL expose a POST /api/expenses/{id}/recurrence endpoint to create a recurrence schedule for a specific expense (entity_type='EXPENSE', entity_id=expense id).
3. THE Recurrence_Engine SHALL expose a GET /api/expenses/{id}/recurrence endpoint to retrieve the active recurrence schedule for a specific expense.
4. THE Recurrence_Engine SHALL expose a DELETE /api/expenses/{id}/recurrence endpoint to deactivate a recurrence schedule for a specific expense.
5. THE Recurrence_Engine SHALL expose a GET /api/recurring-instances endpoint that returns generated instances with optional filters for entity_type, status, and date range.
6. THE Recurrence_Engine SHALL expose a PUT /api/recurring-instances/{id}/status endpoint to update an instance's status (paid, skipped).
7. IF an expense ID that does not exist or does not belong to the current business is provided to the expense recurrence creation endpoint, THEN THE Recurrence_Engine SHALL return a 404 Not Found response with a descriptive error message.

### Requirement 9: Recurring Salary Schedule Configuration

**User Story:** As a user, I want to define a recurrence schedule for an employee's salary, so that the system automatically generates salary payment entries each pay period without manual re-entry.

#### Acceptance Criteria

1. THE Salary_Form SHALL display a "Configure Recurrence" option when creating or editing a salary record for an Employee.
2. WHEN the user enables recurrence on a salary, THE Salary_Form SHALL display fields for: frequency, start date, optional end date, gross amount (foreign currency), tax amount (foreign currency), currency, and exchange rate.
3. THE Salary_Form SHALL offer the following frequency options: weekly, bi-weekly, monthly, quarterly, and yearly.
4. WHEN the user saves a salary recurrence schedule, THE Recurrence_Engine SHALL store the schedule with entity_type "SALARY", referencing the employee's payee_id.
5. IF the user does not provide a start date for a salary recurrence, THEN THE Salary_Form SHALL default the start date to the current date.
6. IF the user provides an end date that is before the start date, THEN THE Salary_Form SHALL display a validation error and prevent saving.
7. THE Salary_Form SHALL pre-populate recurrence fields with the current salary record's values (gross_amount_fc, tax_fc, currency, exchange_rate) as defaults.
8. THE Salary_Form SHALL require the user to explicitly select a frequency — no auto-default frequency is applied.

### Requirement 10: Automatic Recurring Salary Generation

**User Story:** As a user, I want the system to automatically create salary entries based on the recurrence schedule, so that I do not have to manually create a salary record for each pay period.

#### Acceptance Criteria

1. THE Recurrence_Engine SHALL evaluate all active salary Recurrence_Schedules daily and generate Recurring_Salary_Instances for any due dates that have arrived but do not yet have a corresponding salary record.
2. WHEN a Recurring_Salary_Instance is generated, THE Recurrence_Engine SHALL create a salary record with the schedule's gross amount, tax amount, currency, exchange rate, computed period_start, period_end, the employee's payee_id, and a status of "pending".
3. THE Recurrence_Engine SHALL compute period_start and period_end based on the frequency: monthly (1st to last day of month or matching the configured day boundaries), weekly (7-day window), bi-weekly (14-day window), quarterly (3-month window), yearly (12-month window).
4. WHILE a salary Recurrence_Schedule has an end date that has passed, THE Recurrence_Engine SHALL stop generating new salary instances for that schedule.
5. THE Recurrence_Engine SHALL not generate duplicate salary instances for the same due date and employee combination within the same business.
6. WHEN a monthly salary schedule falls on a day that does not exist in the target month, THE Recurrence_Engine SHALL use the last day of that month as the due date.

### Requirement 11: Salary Payment Notification

**User Story:** As a user, I want to be notified when a salary payment is due, so that I can process payroll on time.

#### Acceptance Criteria

1. WHEN a Recurring_Salary_Instance due date is within 3 days from the current date, THE Notification_System SHALL create a notification of type "SALARY_DUE" for all users with the "salaries.view" permission in the same business.
2. THE Notification_System SHALL include the employee name, due date, net amount, and currency in the notification body.
3. WHEN the user clicks the salary due notification, THE Notification_System SHALL navigate the user to the salaries page.
4. THE Recurrence_Engine SHALL not send duplicate notifications for the same Recurring_Salary_Instance.
5. WHEN a Recurring_Salary_Instance is marked as "paid", THE Notification_System SHALL not send further reminders for that instance.

### Requirement 12: Recurring Salary Status Management

**User Story:** As a user, I want to mark recurring salary instances as paid or skip them, so that I can track which salary obligations have been fulfilled.

#### Acceptance Criteria

1. THE Salary_Form SHALL display a status indicator for recurring salary instances showing one of: "pending", "paid", "skipped", or "overdue".
2. WHEN the user marks a Recurring_Salary_Instance as "paid", THE Recurrence_Engine SHALL update the instance status to "paid" and record the payment date.
3. WHEN the user marks a Recurring_Salary_Instance as "skipped", THE Recurrence_Engine SHALL update the instance status to "skipped" and stop sending notifications for that instance.
4. THE Salary_Form SHALL allow the user to edit the gross amount, tax amount, and exchange rate of a Recurring_Salary_Instance before marking it as paid (to accommodate salary adjustments or rate changes).
5. WHEN a Recurring_Salary_Instance is edited, THE Recurrence_Engine SHALL preserve the original scheduled amounts for reference alongside the actual paid amounts.

### Requirement 13: Salary Recurrence Schedule Modification

**User Story:** As a user, I want to modify or cancel a salary recurrence schedule, so that I can adapt to changes (e.g., salary raise, employee departure).

#### Acceptance Criteria

1. WHEN the user edits a salary recurrence schedule, THE Salary_Form SHALL apply the changes to all future instances only, preserving past instances unchanged.
2. WHEN the user sets an end date on an active salary Recurrence_Schedule, THE Recurrence_Engine SHALL stop generating new salary instances after that end date.
3. WHEN the user changes the gross amount or tax on a salary Recurrence_Schedule, THE Recurrence_Engine SHALL use the new values for all future generated instances.
4. WHEN the user deactivates a salary recurrence schedule, THE Salary_Form SHALL prompt for confirmation explaining that all future recurring salary instances will be cancelled.
5. IF the user confirms deactivation of a salary recurrence, THEN THE Recurrence_Engine SHALL cancel all pending future salary instances and stop generating new ones.

### Requirement 14: Salary Recurrence API Endpoints

**User Story:** As a frontend developer, I want API endpoints to manage salary recurrence schedules and instances, so that the UI can create, read, update, and display recurring salary data.

#### Acceptance Criteria

1. THE Recurrence_Engine SHALL expose a POST /api/salaries/employees/{payee_id}/recurrence endpoint to create or update a salary recurrence schedule for an employee.
2. THE Recurrence_Engine SHALL expose a GET /api/salaries/employees/{payee_id}/recurrence endpoint to retrieve the active salary recurrence schedule for an employee.
3. THE Recurrence_Engine SHALL expose a DELETE /api/salaries/employees/{payee_id}/recurrence endpoint to deactivate a salary recurrence schedule.
4. THE Recurrence_Engine SHALL expose a GET /api/salaries/recurring-instances endpoint that returns generated salary instances with optional filters for employee, status, and date range.
5. THE Recurrence_Engine SHALL expose a PUT /api/salaries/recurring-instances/{id}/status endpoint to update a salary instance's status (paid, skipped).
6. IF a payee_id that is not of type "Employee" is provided to the salary recurrence endpoint, THEN THE Recurrence_Engine SHALL return a 400 Bad Request response with a descriptive error message.

### Requirement 15: Early Single Advance Payment

**User Story:** As a user, I want to mark a recurring expense or salary as "paid early" before its due date arrives, so that I can record payments made ahead of schedule.

#### Acceptance Criteria

1. WHEN a Recurring_Expense_Instance or Recurring_Salary_Instance has a status of "pending" and a due date in the future, THE Expense_Form or Salary_Form SHALL display a "Pay Early" action.
2. WHEN the user triggers "Pay Early" on a future instance, THE Recurrence_Engine SHALL update the instance status to "paid" and record the current date as the payment date.
3. WHEN an instance is paid early, THE Recurrence_Engine SHALL mark the instance with an advance_payment flag set to true.
4. WHEN an instance is paid early, THE Notification_System SHALL not send a due notification for that instance.
5. THE Expense_Form and Salary_Form SHALL allow the user to edit the payment amount before confirming an early payment (to accommodate partial or adjusted amounts).
6. THE Recurrence_Engine SHALL continue generating future instances on schedule regardless of whether a prior instance was paid early.

### Requirement 16: Bulk Advance Payment (Multiple Periods)

**User Story:** As a user, I want to pay multiple upcoming periods at once (e.g., 3 months of rent in advance, or 2 months of salary), so that I can record lump-sum advance payments efficiently.

#### Acceptance Criteria

1. THE Expense_Form and Salary_Form SHALL display a "Pay Multiple Periods in Advance" action on any active Recurrence_Schedule.
2. WHEN the user triggers "Pay Multiple Periods in Advance", THE Expense_Form or Salary_Form SHALL prompt for the number of future periods to pay (minimum 2, maximum 12).
3. WHEN the user confirms a bulk advance payment, THE Recurrence_Engine SHALL generate any not-yet-created instances for the specified number of future periods and mark all of them as "paid" with the current date as payment date.
4. THE Recurrence_Engine SHALL create a single Bulk_Advance_Payment record linking all instances paid in the same bulk action, storing the total amount, payment date, and number of periods covered.
5. WHEN instances are paid via bulk advance, THE Notification_System SHALL not send due notifications for any of those instances.
6. THE Expense_Form and Salary_Form SHALL display a summary confirmation before executing the bulk advance payment, showing: number of periods, per-period amount, total amount, and the date range covered.
7. IF the Recurrence_Schedule has an end date and the requested number of periods extends beyond it, THEN THE Recurrence_Engine SHALL only generate and pay instances up to the end date and inform the user of the adjusted count.

### Requirement 17: Advance Payment Tracking and Reporting

**User Story:** As a user, I want to see which payments were made in advance, so that I can distinguish early payments from regular on-time payments in my records.

#### Acceptance Criteria

1. THE Expense_Form and Salary_Form SHALL visually distinguish advance-paid instances from regularly-paid instances with an "Advance" badge or indicator.
2. WHEN the user views a bulk advance payment group, THE Expense_Form or Salary_Form SHALL display all instances covered by that bulk payment as a linked group.
3. THE Recurrence_Engine SHALL expose a GET /api/recurring-instances/advance-payments endpoint that returns all advance payment records for the current business, filterable by entity_type and date range.
4. THE Recurrence_Engine SHALL expose a GET /api/recurring-instances/advance-payments/{id} endpoint that returns the details of a specific bulk advance payment including all linked instances.
5. WHEN the user views the recurring obligations dashboard, THE Expense_Form and Salary_Form SHALL show the count of advance-paid periods remaining (future instances already paid).

### Requirement 18: Advance Payment Interaction with Recurrence Engine

**User Story:** As a developer, I want the Recurrence_Engine to correctly handle advance payments so that it does not generate or overwrite instances that have already been paid in advance.

#### Acceptance Criteria

1. WHEN the Recurrence_Engine evaluates schedules during daily generation, THE Recurrence_Engine SHALL skip generation for any due date that already has an instance marked as "paid" (whether paid on time, early, or via bulk advance).
2. WHEN a Recurrence_Schedule's default amount changes and future instances have already been paid in advance, THE Recurrence_Engine SHALL preserve the advance-paid instances with their original amounts unchanged.
3. WHEN a Recurrence_Schedule is deactivated and advance-paid future instances exist, THE Recurrence_Engine SHALL retain the advance-paid instances as valid paid records and only cancel unpaid pending instances.
4. THE Recurrence_Engine SHALL store advance payment metadata (advance_payment flag, bulk_advance_payment_id) on each instance to enable accurate reporting and prevent re-processing.
5. IF the user requests a refund or reversal of an advance payment, THEN THE Recurrence_Engine SHALL revert the affected instances to "pending" status and clear the advance payment metadata.

### Requirement 19: Payment Module Integration (Allocation-Based)

**User Story:** As a user, I want the system to create a formal payment record using the existing allocation-based payment pattern when I mark a recurring instance as paid, so that my financial ledger stays consistent and account balances are accurately updated.

#### Acceptance Criteria

1. WHEN the user marks a Recurring_Expense_Instance or Recurring_Salary_Instance as "paid", THE Recurrence_Engine SHALL prompt the user to select a Source_Account from the Accounts module and a payment method before completing the payment.
2. WHEN the user confirms payment with a selected Source_Account, THE Recurrence_Engine SHALL create a Payment_Slip with: an auto-generated payment_number, the current date as payment_date, the payee_id (from the source expense's payee for expenses, or the employee's payee_id for salaries), the selected account_id, the paid amount as amount_fc, the base-currency equivalent as amount_bc, the user-selected payment method, and the schedule's currency.
3. WHEN a Payment_Slip is created for a recurring instance, THE Recurrence_Engine SHALL create a Payment_Allocation row with: the Payment_Slip's id as payment_slip_id, reference_type set to 'RECURRING_EXPENSE' (for expense instances) or 'RECURRING_SALARY' (for salary instances), reference_id set to the recurrence_instance.id, and the amount_fc and amount_bc matching the payment.
4. WHEN a Payment_Slip is created for a recurring instance, THE Recurrence_Engine SHALL deduct the amount_fc from the Source_Account's balance_fc and the amount_bc from the Source_Account's base currency balance.
5. THE Recurrence_Engine SHALL store the created Payment_Slip ID on the recurring instance record (payment_record_id) to maintain a link between the instance and its payment.
6. IF the Source_Account's balance_fc is insufficient to cover the payment amount, THEN THE Recurrence_Engine SHALL display a warning to the user but still allow the payment to proceed (permitting negative balances as per existing payment module behavior).
7. WHEN a bulk advance payment is executed, THE Recurrence_Engine SHALL create a single Payment_Slip for the total amount (sum of all periods) and a single Payment_Allocation row with reference_type 'RECURRING_EXPENSE' or 'RECURRING_SALARY' and reference_id pointing to the bulk_advance_payment record id, rather than creating individual Payment_Slips per period.
8. WHEN a Payment_Slip linked to a recurring instance is deleted from the Payments module, THE Recurrence_Engine SHALL revert the linked recurring instance status back to "pending" and clear the payment linkage.
9. THE Recurrence_Engine SHALL expose a GET /api/recurring-instances/{id}/payment endpoint that returns the linked Payment_Slip and Payment_Allocation details for a specific recurring instance.
10. THE Recurrence_Engine SHALL require a database migration to extend the payment_allocations.reference_type enum from ('PURCHASE_ORDER','EXPENSE','SALARY','PARTNER_PROFIT') to include 'RECURRING_EXPENSE' and 'RECURRING_SALARY'.

### Requirement 20: Audit Trail for Recurring Operations

**User Story:** As a user, I want all status changes and significant actions on recurring schedules and instances to be logged with who did what and when, so that I have a complete compliance and accountability trail.

#### Acceptance Criteria

1. WHEN a Recurring_Expense_Instance or Recurring_Salary_Instance status changes (from "pending" to "paid", "pending" to "skipped", "pending" to "overdue", or "paid" to "pending" via reversal), THE Recurrence_Engine SHALL create an Audit_Log entry recording: the user_id who performed the action, the instance_id, the previous status, the new status, and the timestamp.
2. WHEN a Recurrence_Schedule is created, THE Recurrence_Engine SHALL create an Audit_Log entry recording: the user_id, the schedule_id, action type "schedule_created", the schedule configuration details, and the timestamp.
3. WHEN a Recurrence_Schedule is modified (frequency, amount, dates changed), THE Recurrence_Engine SHALL create an Audit_Log entry recording: the user_id, the schedule_id, action type "schedule_modified", the previous values, the new values, and the timestamp.
4. WHEN a Recurrence_Schedule is deactivated or deleted, THE Recurrence_Engine SHALL create an Audit_Log entry recording: the user_id, the schedule_id, action type "schedule_deactivated" or "schedule_deleted", and the timestamp.
5. WHEN a bulk advance payment is executed, THE Recurrence_Engine SHALL create an Audit_Log entry recording: the user_id, the bulk_advance_payment_id, action type "bulk_advance_payment", the number of periods, total amount, and the timestamp.
6. THE Recurrence_Engine SHALL store Audit_Log entries in a dedicated table with columns: id, business_id, user_id, entity_type (schedule or instance), entity_id, action_type, previous_state (JSON nullable), new_state (JSON nullable), metadata (JSON nullable), and created_at.
7. THE Recurrence_Engine SHALL expose a GET /api/recurring-audit-log endpoint that returns audit entries for the current business, filterable by entity_type, entity_id, action_type, user_id, and date range, ordered by created_at descending.
8. THE Recurrence_Engine SHALL retain Audit_Log entries indefinitely and not allow deletion or modification of audit records.
9. WHEN the user views a specific recurring instance or schedule detail, THE Expense_Form or Salary_Form SHALL display the audit history for that entity showing all logged actions in chronological order.

### Requirement 21: Overdue Status and Escalation

**User Story:** As a user, I want recurring instances that pass their due date without being paid to automatically become "overdue" with escalated daily notifications, so that I am alerted to missed obligations and can take corrective action promptly.

#### Acceptance Criteria

1. WHEN a Recurring_Expense_Instance or Recurring_Salary_Instance has a status of "pending" and the current date exceeds the due_date, THE Recurrence_Engine SHALL automatically transition the instance status to "overdue".
2. THE Recurrence_Engine SHALL evaluate all pending instances daily and transition any instance whose due_date has passed to "overdue" status.
3. WHILE a Recurring_Expense_Instance or Recurring_Salary_Instance has a status of "overdue", THE Notification_System SHALL send a daily reminder notification of type "EXPENSE_OVERDUE" or "SALARY_OVERDUE" to all users with the relevant view permission in the same business.
4. THE Notification_System SHALL include the expense payee name or employee name, due date, amount, and number of days overdue in the overdue notification body.
5. WHEN the user marks an overdue instance as "paid", THE Recurrence_Engine SHALL update the status to "paid", record the payment date, and THE Notification_System SHALL stop sending overdue reminders for that instance.
6. WHEN the user marks an overdue instance as "skipped", THE Recurrence_Engine SHALL update the status to "skipped" and THE Notification_System SHALL stop sending overdue reminders for that instance.
7. THE Expense_Form and Salary_Form SHALL visually distinguish overdue instances from pending instances with a prominent "Overdue" badge displayed in a warning or danger color.
8. WHEN the user views the recurring obligations dashboard, THE Expense_Form and Salary_Form SHALL display overdue instances in a dedicated "Overdue" section at the top of the view, sorted by the number of days overdue (most overdue first).
9. THE Recurrence_Engine SHALL expose overdue instances through the existing GET /api/recurring-instances endpoint by supporting "overdue" as a valid status filter value.
10. THE Recurrence_Engine SHALL include a "days_overdue" computed field in the API response for instances with "overdue" status, calculated as the difference between the current date and the due_date.

### Requirement 22: Accounting Entry Generation for Prepaid Expenses

**User Story:** As a user, I want bulk advance payments to be handled as prepaid assets following accrual accounting standards, so that the full payment is recorded as an asset at payment time and each period's portion is recognized as an expense when the due date arrives.

#### Acceptance Criteria

1. WHEN a bulk advance payment is executed, THE Recurrence_Engine SHALL create a Prepaid_Asset record with the total payment amount, the payment date, the number of periods covered, the per-period amount, and a reference to the Bulk_Advance_Payment record.
2. WHEN a bulk advance payment is executed, THE Recurrence_Engine SHALL generate an Accounting_Entry debiting the Prepaid_Asset account for the total amount and crediting the Source_Account for the same amount (recording the cash outflow as a prepaid asset rather than an immediate expense).
3. WHEN the due_date of a period covered by a Prepaid_Asset arrives, THE Recurrence_Engine SHALL perform Expense_Recognition by generating an Accounting_Entry debiting the appropriate expense account for the per-period amount and crediting the Prepaid_Asset account for the same amount.
4. THE Recurrence_Engine SHALL evaluate Prepaid_Asset records daily and perform Expense_Recognition for any covered periods whose due_date has arrived but have not yet been recognized.
5. WHEN all periods of a Prepaid_Asset have been recognized as expenses, THE Recurrence_Engine SHALL mark the Prepaid_Asset record as "fully_amortized".
6. THE Recurrence_Engine SHALL store Prepaid_Asset records in a dedicated table with columns: id, business_id, bulk_advance_payment_id, total_amount, total_amount_bc, per_period_amount, per_period_amount_bc, currency, periods_total, periods_recognized, status (active, fully_amortized), created_at, and updated_at.
7. THE Recurrence_Engine SHALL store Accounting_Entries in a dedicated table with columns: id, business_id, entry_date, entry_type (prepaid_asset_creation, expense_recognition), debit_account, credit_account, amount_fc, amount_bc, currency, reference_type, reference_id, description, created_at.
8. THE Recurrence_Engine SHALL expose a GET /api/prepaid-assets endpoint that returns all Prepaid_Asset records for the current business, filterable by status (active, fully_amortized) and date range.
9. THE Recurrence_Engine SHALL expose a GET /api/prepaid-assets/{id} endpoint that returns the details of a specific Prepaid_Asset including its amortization schedule (list of periods with recognition status and dates).
10. THE Recurrence_Engine SHALL expose a GET /api/accounting-entries endpoint that returns all Accounting_Entries for the current business, filterable by entry_type, reference_type, and date range.
11. WHEN the user views a bulk advance payment detail, THE Expense_Form or Salary_Form SHALL display the prepaid asset amortization schedule showing which periods have been recognized as expenses and which are still pending recognition.
12. IF a bulk advance payment is reversed (all instances reverted to pending), THEN THE Recurrence_Engine SHALL reverse the Prepaid_Asset by generating a contra Accounting_Entry crediting the Prepaid_Asset account and debiting the Source_Account, and mark the Prepaid_Asset as "reversed".
