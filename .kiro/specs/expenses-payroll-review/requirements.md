# Requirements Document

## Introduction

Full code review and refactoring of the Expenses & Payroll module covering technical quality (security, validation, performance, clean architecture) and business logic correctness (payee balance adjustments, period overlap detection, delete safety, recurrence lifecycle, file upload security). The deliverable is direct refactoring applied to ExpensesController, SalariesController, RecurrenceController, ExpenseService, SalaryService, RecurrenceService, and their corresponding frontend pages/modals.

## Glossary

- **Expenses_Module**: The backend and frontend subsystem managing Expenses, Expense Categories, file attachments, and related CRUD operations
- **Salaries_Module**: The backend and frontend subsystem managing Salary records, payslip PDF generation, batch payroll, and salary CRUD
- **Recurrence_Module**: The backend subsystem managing recurring expense/salary schedules, instances, advance payments, prepaid assets, and accounting entries
- **ExpensesController**: The HTTP request handler for Expense CRUD, Expense Categories, and file upload endpoints
- **SalariesController**: The HTTP request handler for Salary CRUD, payslip generation, and batch payroll endpoints
- **RecurrenceController**: The HTTP request handler for recurrence schedules, instances, advance payments, prepaid assets, and audit log endpoints
- **ExpenseService**: The business logic orchestrator for expenses and expense categories
- **SalaryService**: The business logic orchestrator for salary records including period overlap, amount calculation, and batch payroll generation
- **RecurrenceService**: The business logic orchestrator for recurring schedules, instance lifecycle, bulk advance payments, and notifications
- **Refactoring_Engine**: The process of applying code improvements while preserving existing functional behavior
- **AuthContext**: The server-side utility providing tenant_id, business_id, and user_id from the authenticated JWT session
- **PayeeBalance**: The running balance on a payee record that tracks amounts owed/receivable, adjusted by expense/salary create/update/delete operations
- **PaymentAllocation**: A record in `payment_allocations` distributing a payment slip's amount to expenses or salaries

## Requirements

### Requirement 1: Secure Controller Error Handling — Prevent Exception Message Leakage

**User Story:** As a security engineer, I want error responses to never expose internal exception messages to API consumers, so that implementation details remain hidden from potential attackers.

#### Acceptance Criteria

1. WHEN a `Throwable` is caught in `ExpensesController::store()`, THE ExpensesController SHALL return a generic error message "Failed to create expense" without appending `$e->getMessage()` to the response
2. WHEN a `Throwable` is caught in `ExpensesController::storeCategory()`, THE ExpensesController SHALL return a generic error message "Failed to create expense category" without appending `$e->getMessage()`
3. WHEN a `Throwable` is caught in `ExpensesController::uploadAttachment()`, THE ExpensesController SHALL return a generic error message "Upload failed" without appending `$e->getMessage()`
4. WHEN a `Throwable` is caught in `SalariesController::store()`, THE SalariesController SHALL return a generic error message "Failed to create salary record" without appending `$e->getMessage()`
5. WHEN a `Throwable` is caught in `SalariesController::generatePayroll()`, THE SalariesController SHALL return a generic error message "Failed to generate payroll" without appending `$e->getMessage()`
6. WHEN a `Throwable` is caught in `SalariesController::payslip()`, THE SalariesController SHALL return a generic error message "Failed to generate payslip" without appending `$e->getMessage()`
7. WHEN a `Throwable` is caught in `RecurrenceController::createExpenseRecurrence()`, THE RecurrenceController SHALL return a generic error message without appending `$e->getMessage()`
8. WHEN a `Throwable` is caught in `RecurrenceController::evaluate()`, THE RecurrenceController SHALL return a generic error message without appending `$e->getMessage()`

### Requirement 2: Structured Error Logging Across All Controllers

**User Story:** As an operations engineer, I want all exceptions in the Expenses & Payroll module to be logged with sufficient context, so that production issues can be diagnosed from log files.

#### Acceptance Criteria

1. WHEN any controller in the Expenses & Payroll module catches a `Throwable`, THE module SHALL log the exception message, exception class, and stack trace using `error_log()`
2. THE module SHALL include the authenticated user_id and business_id in log entries where AuthContext is available
3. THE ExpensesController SHALL use a consistent log format: `[Expenses] {method}: {ExceptionClass} - {message} (user={user_id}, business={business_id})`
4. THE SalariesController SHALL use a consistent log format: `[Salaries] {method}: {ExceptionClass} - {message} (user={user_id}, business={business_id})`
5. THE RecurrenceController SHALL use a consistent log format: `[Recurrence] {method}: {ExceptionClass} - {message} (user={user_id}, business={business_id})`

### Requirement 3: Consistent Tiered Exception Handling

**User Story:** As a developer, I want controllers to distinguish between expected business rule violations and unexpected system errors, so that the API returns appropriate HTTP status codes.

#### Acceptance Criteria

1. WHEN `ExpensesController::store()` catches an `InvalidArgumentException`, THE ExpensesController SHALL return HTTP 422 with the exception message as the response message
2. WHEN `SalariesController::store()` catches an `InvalidArgumentException`, THE SalariesController SHALL return HTTP 422 with the exception message; WHEN it catches a generic `Throwable`, THE SalariesController SHALL return HTTP 500 with a generic message (not the exception message)
3. WHEN `SalariesController::payslip()` catches a `RuntimeException`, THE SalariesController SHALL return HTTP 404; WHEN it catches a generic `Throwable`, it SHALL return HTTP 500 with a generic message
4. WHEN `RecurrenceController` methods catch an `InvalidArgumentException`, they SHALL return HTTP 422 (not 400) for consistency with the project's established pattern
5. ALL controllers in the module SHALL follow the tiered pattern: `InvalidArgumentException` → 422 (with message), `RuntimeException` → 422 (with message), `Throwable` → 500 (generic message only)

### Requirement 4: Extract Inline SQL from SalariesController::payslip()

**User Story:** As a developer, I want the payslip endpoint to follow the 3-layer architecture, so that business logic and data access are not mixed into the controller.

#### Acceptance Criteria

1. THE Refactoring_Engine SHALL move the business info query (`SELECT name, legal_name, address, phone, email FROM businesses`) out of `SalariesController::payslip()` into the service layer
2. THE SalariesController::payslip() SHALL delegate all data retrieval and PDF rendering orchestration to `SalaryService`
3. THE SalariesController::payslip() SHALL retain only HTTP concerns: content-type headers, filename generation, and output streaming
4. THE SalaryService SHALL expose a method `generatePayslipPdf(int $salaryId): array` that returns the PDF content and filename
5. THE Refactoring_Engine SHALL preserve the existing payslip behavior including filename sanitization and A4 portrait rendering

### Requirement 5: Extract Inline SQL from SalaryService::generatePayroll()

**User Story:** As a developer, I want batch payroll generation to use repository methods for all data access, so that the service layer contains only business logic.

#### Acceptance Criteria

1. THE SalaryRepository SHALL expose a method to find all employees with their recurrence schedules for a given business
2. THE SalaryRepository SHALL expose a method to check if a salary already exists overlapping a given period for a payee
3. THE SalaryRepository SHALL expose a method to find the latest salary record for a given payee/business (for template lookup)
4. THE SalaryService::generatePayroll() SHALL NOT contain inline SQL queries — all data access SHALL be via repository methods
5. THE Refactoring_Engine SHALL preserve the existing batch payroll logic: employee lookup, period overlap skip, template salary or recurrence fallback, payee balance updates

### Requirement 6: Extract Inline SQL from RecurrenceController

**User Story:** As a developer, I want RecurrenceController to follow the 3-layer architecture, so that inline SQL queries in controller helper methods are moved to the service/repository layer.

#### Acceptance Criteria

1. THE RecurrenceController::listSchedules() SHALL NOT contain inline SQL for enriching schedules with entity_name, payee_id, and exchange_rate
2. THE RecurrenceController::listInstances() SHALL NOT contain inline SQL for resolving entity details
3. THE RecurrenceController SHALL remove `resolveEntityDetails()` and `resolveEntityNameFromSchedule()` private helper methods that contain inline SQL
4. THE RecurrenceService or a dedicated repository SHALL expose methods for enriching schedules and instances with entity metadata
5. THE Refactoring_Engine SHALL preserve the existing response shape and enrichment behavior (entity_name, payee_id, exchange_rate, days_overdue)

### Requirement 7: Input Validation for ExpenseService::update()

**User Story:** As a security engineer, I want expense update operations to validate required fields the same way as create operations, so that invalid data cannot reach the database.

#### Acceptance Criteria

1. WHEN an expense is updated, THE ExpenseService SHALL validate that `expense_date` is non-empty
2. WHEN an expense is updated, THE ExpenseService SHALL validate that `currency` is a non-empty string after trimming
3. WHEN an expense is updated, THE ExpenseService SHALL validate that `amount_fc` is present and numeric
4. WHEN an expense is updated, THE ExpenseService SHALL validate that `amount_fc` is greater than zero
5. IF any validation fails during update, THEN THE ExpenseService SHALL throw an `InvalidArgumentException` with a descriptive message

### Requirement 8: Input Validation for Expense Amounts

**User Story:** As a domain logic owner, I want expense amounts to be validated for logical correctness, so that negative or zero amounts cannot be recorded.

#### Acceptance Criteria

1. WHEN an expense is created, THE ExpenseService SHALL validate that `amount_fc` is a positive number (greater than zero)
2. WHEN an expense is created, THE ExpenseService SHALL validate that `exchange_rate` (if provided) is a positive number
3. WHEN a salary is created or updated, THE SalaryService SHALL validate that `gross_amount_fc` is a positive number (greater than zero)
4. WHEN a salary is created or updated, THE SalaryService SHALL validate that `tax_fc` (if provided) is not negative
5. WHEN a salary is created or updated, THE SalaryService SHALL validate that `exchange_rate` (if provided) is a positive number

### Requirement 9: File Upload Security Enhancement

**User Story:** As a security engineer, I want file upload validation to verify actual file content type (MIME), not just extension, so that disguised malicious files are rejected.

#### Acceptance Criteria

1. WHEN a file is uploaded via `ExpensesController::uploadAttachment()`, THE controller SHALL verify the MIME type using `finfo_file()` (or PHP's fileinfo extension) in addition to the extension check
2. THE controller SHALL reject files where the detected MIME type does not match the allowed MIME types list (`application/pdf`, `image/jpeg`, `image/png`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`)
3. THE controller SHALL sanitize the uploaded filename to prevent path traversal attacks (remove `../`, null bytes, and other dangerous characters)
4. THE controller SHALL generate a unique filename server-side (current behavior preserved: `uniqid() + timestamp + extension`)
5. IF the MIME type verification fails, THEN THE controller SHALL return HTTP 422 with a message "File content does not match expected type"

### Requirement 10: Expense Category Name Validation

**User Story:** As a developer, I want expense category names to be validated for length and content, so that excessively long or empty names cannot be stored.

#### Acceptance Criteria

1. WHEN a category is created or updated, THE ExpenseService SHALL validate that the name does not exceed 100 characters after trimming
2. WHEN a category is created or updated, THE ExpenseService SHALL validate that the name contains only printable characters (no control characters)
3. IF category name validation fails, THEN THE ExpenseService SHALL throw an `InvalidArgumentException` with a descriptive message

### Requirement 11: Delete Safety for SalaryService

**User Story:** As a developer, I want the salary delete guard to use a repository method rather than inline SQL in the service, so that the delete-safety check follows the 3-layer architecture.

#### Acceptance Criteria

1. THE SalaryRepository SHALL expose a method `sumPaymentAllocationsForSalary(int $salaryId): float` to check payment allocations
2. THE SalaryService::delete() SHALL call the repository method instead of executing inline SQL to check for allocations
3. THE Refactoring_Engine SHALL preserve the existing guard behavior: reject deletion if paid amount > 0

### Requirement 12: Payee Balance Adjustment Correctness

**User Story:** As a domain logic owner, I want payee balance adjustments during expense/salary create/update/delete to be algebraically correct in all edge cases, so that the running balance never drifts.

#### Acceptance Criteria

1. WHEN an expense is created with a payee, THE ExpenseService SHALL decrease the payee balance by `amount_bc` (the business-currency amount)
2. WHEN an expense is updated and the payee remains the same but the amount changes, THE ExpenseService SHALL adjust the balance by the delta (`new_amount_bc - old_amount_bc`)
3. WHEN an expense is updated and the payee changes, THE ExpenseService SHALL reverse the old payee's balance (increase by old amount) AND apply the new payee's balance (decrease by new amount)
4. WHEN an expense is deleted, THE ExpenseService SHALL reverse the payee's balance (increase by the stored amount)
5. THE same balance adjustment rules SHALL apply to `SalaryService` using `net_amount_bc` instead of `amount_bc`
6. THE Refactoring_Engine SHALL verify the existing implementations match these rules and fix any discrepancies

### Requirement 13: Expense Date Format Validation

**User Story:** As a developer, I want date fields in expense and salary inputs to be validated for format and logical correctness, so that malformed dates cannot produce SQL errors or incorrect records.

#### Acceptance Criteria

1. WHEN `expense_date` is provided, THE ExpenseService SHALL validate that it matches the format `YYYY-MM-DD` and represents a valid calendar date
2. WHEN `period_start` or `period_end` is provided to SalaryService, THE SalaryService SHALL validate that each matches the format `YYYY-MM-DD` and represents a valid calendar date
3. WHEN `reference_date` is provided for an expense, THE ExpenseService SHALL validate format if the field is non-empty
4. IF date validation fails, THEN the service SHALL throw an `InvalidArgumentException` with a descriptive message

### Requirement 14: RecurrenceController HTTP Status Code Consistency

**User Story:** As a developer, I want all RecurrenceController methods to use consistent HTTP status codes matching the project convention, so that frontend error handling is predictable.

#### Acceptance Criteria

1. WHEN `RecurrenceController` catches an `InvalidArgumentException`, it SHALL return HTTP 422 (not 400) — aligning with the project's established convention from People, Items, and other modules
2. WHEN `RecurrenceController` catches a `RuntimeException` containing "not found", it SHALL return HTTP 404
3. WHEN `RecurrenceController` catches a `RuntimeException` not containing "not found", it SHALL return HTTP 409 (conflict/business rule)
4. WHEN `RecurrenceController` catches a generic `Throwable`, it SHALL return HTTP 500 with a generic message
5. THE Refactoring_Engine SHALL update all `RecurrenceController` methods to follow this consistent pattern

### Requirement 15: Payslip PDF Controller Separation

**User Story:** As a developer, I want the payslip PDF endpoint to not directly interact with the database or PDF library, so that controller-level testing is simpler and the code is maintainable.

#### Acceptance Criteria

1. THE SalariesController::payslip() SHALL NOT instantiate PDO/Database directly
2. THE SalariesController::payslip() SHALL NOT instantiate `\Dompdf\Dompdf` directly
3. THE SalaryService or a dedicated PayslipService SHALL orchestrate: salary lookup → business info → HTML rendering → PDF generation
4. THE controller SHALL only handle HTTP headers and streaming the PDF bytes
5. THE Refactoring_Engine SHALL preserve existing behavior: A4 portrait, inline display, sanitized filename

### Requirement 16: Frontend Expense Form Validation Improvements

**User Story:** As a developer, I want the expense form modal to validate all fields that the backend requires, so that users receive immediate feedback on invalid input.

#### Acceptance Criteria

1. THE expense form modal SHALL validate that `amount_fc` is a positive number (not just non-empty)
2. THE expense form modal SHALL validate that `exchange_rate` is a positive number when present
3. THE expense form modal SHALL validate `expense_date` format as a valid date
4. THE expense form modal SHALL ensure `unmount()` removes ALL event listeners including `input` events on `amountFc` and `exchangeRate`, and the `change` event on `date`
5. THE expense form modal SHALL show inline Bootstrap validation feedback (`.is-invalid` + `.invalid-feedback`) for each invalid field

### Requirement 17: Frontend Salary Form Validation Improvements

**User Story:** As a developer, I want the salary form modal to validate date logic and numeric fields, so that invalid data cannot be submitted.

#### Acceptance Criteria

1. THE salary form modal SHALL validate that `period_end` is greater than or equal to `period_start`
2. THE salary form modal SHALL validate that `gross_amount_fc` is a positive number
3. THE salary form modal SHALL validate that `tax_fc` (if provided) is not negative and does not exceed `gross_amount_fc`
4. THE salary form modal SHALL validate that `exchange_rate` is a positive number
5. THE salary form modal SHALL ensure `unmount()` removes ALL event listeners including `input` events on `grossAmountFc` and `taxFc`, and the `change` event on `employee`

### Requirement 18: Expense Category Modal Validation

**User Story:** As a developer, I want the expense categories modal to prevent submission of empty or excessively long category names, so that users get immediate client-side feedback.

#### Acceptance Criteria

1. THE expense categories modal SHALL block submission when the name is empty or whitespace-only
2. THE expense categories modal SHALL block submission when the name exceeds 100 characters
3. THE expense categories modal SHALL show inline validation feedback using Bootstrap's `.is-invalid` + `.invalid-feedback`
4. THE expense categories modal SHALL ensure proper `unmount()` cleanup

### Requirement 19: Salary Payee Verification

**User Story:** As a domain logic owner, I want salary operations to verify the payee belongs to the current business before proceeding, so that cross-business data access is prevented.

#### Acceptance Criteria

1. WHEN a salary is created with a `payee_id`, THE SalaryService SHALL verify that the payee exists and belongs to the current business before creating the record
2. WHEN a salary is updated with a changed `payee_id`, THE SalaryService SHALL verify the new payee belongs to the current business
3. IF the payee does not belong to the current business, THEN THE SalaryService SHALL throw an `InvalidArgumentException` with message "Employee not found in this business"
4. THE same verification SHALL apply to `ExpenseService` when a `payee_id` is provided during create or update

### Requirement 20: Recurrence Schedule Ownership Verification

**User Story:** As a security engineer, I want recurrence operations to verify the referenced entity belongs to the current business, so that users cannot create or manipulate schedules for entities they don't own.

#### Acceptance Criteria

1. WHEN `RecurrenceController::createExpenseRecurrence(expenseId)` is called, THE RecurrenceService SHALL verify the expense belongs to the current business before creating the schedule
2. WHEN `RecurrenceController::createSalaryRecurrence(payeeId)` is called, THE RecurrenceService SHALL verify the payee belongs to the current business and is of type Employee
3. WHEN instance status updates or bulk advance payments reference a schedule, THE RecurrenceService SHALL verify the schedule belongs to the current business
4. IF any ownership check fails, THEN the service SHALL throw a `RuntimeException` with "not found" to produce HTTP 404

