# Requirements Document

## Introduction

Full code review and refactoring of the People module covering technical quality (security, validation, performance, clean architecture) and business logic correctness (domain rules, multi-type classification, payee lifecycle, delete safety, type-filtering consistency). The deliverable is direct refactoring applied to PeopleController, PeopleService, PeopleRepository, PayeeTypeRepository, InsightsController, PayeeStatementController, and their corresponding frontend pages/modals.

## Glossary

- **People_Module**: The backend and frontend subsystem managing People (contacts), Payees, Payee Types, Insights, and Payee Statements
- **PeopleController**: The HTTP request handler for People CRUD and Payee Type management endpoints
- **PeopleService**: The business logic orchestrator for People operations including create, update, delete, and payee type management
- **PeopleRepository**: The data access class responsible for people records, payee records, people_types junction, balance updates, and transaction counting
- **PayeeTypeRepository**: The data access class responsible for payee_types CRUD and people_types junction queries
- **InsightsController**: The HTTP request handler providing supplier, customer, partner, and general analytics for a payee
- **PayeeStatementController**: The HTTP request handler producing account statements (JSON, CSV, PDF) for a payee
- **Refactoring_Engine**: The process of applying code improvements while preserving existing functional behavior
- **Person**: A contact record scoped to a tenant, which can hold multiple type classifications simultaneously
- **Payee**: A person's financial identity within a specific business, holding balance and transaction references
- **Payee_Type**: A classification category (Customer, Supplier, Employee, Partner) that can be system-defined or tenant-defined
- **People_Types_Junction**: The `people_types` table linking a person to one or more payee types within a business context
- **AuthContext**: The server-side utility providing tenant_id, business_id, and user_id from the authenticated JWT session

## Requirements

### Requirement 1: Eliminate Duplicate Validation in PeopleService

**User Story:** As a developer, I want validation logic to appear exactly once in each method, so that the codebase is maintainable and free of dead code.

#### Acceptance Criteria

1. THE Refactoring_Engine SHALL remove the duplicate `payee_type_ids` empty-check in `PeopleService::create()` so that the validation appears only once before any database operation
2. THE Refactoring_Engine SHALL preserve the existing validation behavior where an empty or non-array `payee_type_ids` input throws an `InvalidArgumentException` with the message "At least one payee type must be selected."

### Requirement 2: Secure Controller Error Handling

**User Story:** As a security engineer, I want error responses to never expose internal exception messages to API consumers, so that implementation details remain hidden from potential attackers.

#### Acceptance Criteria

1. WHEN a `Throwable` is caught in `PeopleController::store()`, THE PeopleController SHALL return a generic error message without appending `$e->getMessage()` to the response
2. WHEN a `Throwable` is caught in `PeopleController::update()`, THE PeopleController SHALL return a generic error message without appending `$e->getMessage()` to the response
3. WHEN a `Throwable` is caught in `PeopleController::storePayeeType()`, THE PeopleController SHALL return a generic error message without appending `$e->getMessage()` to the response
4. WHEN a `Throwable` is caught in `InsightsController`, THE InsightsController SHALL return a generic error message without appending `$e->getMessage()` to the response
5. WHEN a `Throwable` is caught in `PayeeStatementController`, THE PayeeStatementController SHALL return a generic error message without appending `$e->getMessage()` to the response
6. WHEN any controller catches an exception, THE People_Module SHALL log the full exception message and stack trace server-side via `error_log()` before returning the generic response

### Requirement 3: Differentiate Business Exceptions from System Exceptions

**User Story:** As a developer, I want controllers to distinguish between expected business rule violations and unexpected system errors, so that the API returns appropriate HTTP status codes.

#### Acceptance Criteria

1. WHEN PeopleController::store() catches an `InvalidArgumentException`, THE PeopleController SHALL return HTTP 422 with the exception message as the response message
2. WHEN PeopleController::update() catches an `InvalidArgumentException`, THE PeopleController SHALL return HTTP 422 with the exception message as the response message
3. WHEN PeopleController::store() catches a `RuntimeException`, THE PeopleController SHALL return HTTP 422 with the exception message as the response message
4. WHEN PeopleController catches any other `Throwable`, THE PeopleController SHALL return HTTP 500 with a generic error message

### Requirement 4: Extract InsightsController SQL into a Repository

**User Story:** As a developer, I want data access logic separated from request handling, so that the InsightsController follows the project's 3-layer architecture pattern.

#### Acceptance Criteria

1. THE Refactoring_Engine SHALL create an `InsightsRepository` class in `src/Repository/` containing all SQL queries currently inline in InsightsController
2. THE Refactoring_Engine SHALL create an `InsightsService` class in `src/Services/` that coordinates calls to `InsightsRepository` and encapsulates any data-transformation logic
3. THE InsightsController SHALL delegate all data retrieval to InsightsService without directly instantiating PDO or executing SQL statements
4. THE InsightsRepository SHALL accept PDO via its constructor and scope all queries using the provided `businessId` parameter
5. THE Refactoring_Engine SHALL preserve the existing API response shape and data content for all four insight endpoints (supplier, customer, general, partner) and the customerItems endpoint

### Requirement 5: Extract PayeeStatementController SQL into a Repository

**User Story:** As a developer, I want PayeeStatementController to follow the 3-layer architecture, so that the 400+ lines of inline SQL become testable and reusable.

#### Acceptance Criteria

1. THE Refactoring_Engine SHALL create a `PayeeStatementRepository` class in `src/Repository/` containing the `getTransactions()` and `calculateOpeningBalance()` query logic
2. THE Refactoring_Engine SHALL create a `PayeeStatementService` class in `src/Services/` that orchestrates statement data retrieval, running-balance computation, and payee verification
3. THE PayeeStatementController SHALL delegate all data retrieval and business logic to PayeeStatementService
4. THE PayeeStatementController SHALL retain only HTTP-layer concerns: input extraction, response formatting (JSON/CSV/PDF), and header management
5. THE Refactoring_Engine SHALL preserve the existing statement calculation semantics including signed debit/credit conventions and opening balance computation

### Requirement 6: Resolve Overlapping Junction-Table Management

**User Story:** As a developer, I want a single authoritative location for managing the `people_types` junction table, so that concurrent modifications or inconsistent queries are eliminated.

#### Acceptance Criteria

1. THE Refactoring_Engine SHALL consolidate people_types junction management so that only one repository class owns write operations (INSERT/DELETE) to the `people_types` table
2. THE Refactoring_Engine SHALL designate `PeopleRepository` as the owner of business-scoped junction writes (called during payee create/update/delete flows)
3. THE PayeeTypeRepository SHALL retain read-only junction queries (`getTypesForPerson`, `personHasType`, `findPeopleByType`) and the `countUsage` query
4. THE PayeeTypeRepository SHALL remove its `setTypesForPerson()` method or delegate to PeopleRepository to eliminate the duplicate write path
5. THE Refactoring_Engine SHALL verify that no other caller invokes the removed method before deletion

### Requirement 7: Input Validation and Sanitization

**User Story:** As a security engineer, I want all People module inputs to be validated and sanitized at the service layer, so that invalid or malicious data cannot reach the database.

#### Acceptance Criteria

1. WHEN a person is created or updated, THE PeopleService SHALL validate that `name` is a non-empty string after trimming, with a maximum length of 255 characters
2. WHEN an email is provided, THE PeopleService SHALL validate that the email conforms to a valid email format using `filter_var(FILTER_VALIDATE_EMAIL)`
3. WHEN a phone number is provided, THE PeopleService SHALL validate that the phone contains only digits, spaces, dashes, plus signs, and parentheses, with a maximum length of 50 characters
4. WHEN `payee_type_ids` are provided, THE PeopleService SHALL validate that each element is a positive integer
5. WHEN a payee type name is created or updated, THE PeopleService SHALL validate that the name is a non-empty string after trimming, with a maximum length of 100 characters
6. IF any validation fails, THEN THE PeopleService SHALL throw an `InvalidArgumentException` with a descriptive message identifying the invalid field

### Requirement 8: Validate Payee Type Ownership on Assignment

**User Story:** As a domain logic owner, I want payee type assignments to only accept types that belong to the current tenant, so that cross-tenant data leakage is prevented.

#### Acceptance Criteria

1. WHEN `payee_type_ids` are provided during person create or update, THE PeopleService SHALL verify that each type ID exists and belongs to the current tenant (either `is_system = 1` or `tenant_id` matches the authenticated tenant)
2. IF any provided payee_type_id does not belong to the current tenant or does not exist, THEN THE PeopleService SHALL throw an `InvalidArgumentException` identifying the invalid type ID
3. THE PeopleService SHALL perform this validation within the database transaction, before inserting into the people_types junction

### Requirement 9: Delete Safety with Comprehensive Transaction Check

**User Story:** As a business user, I want the delete operation to prevent data loss by checking all possible transaction references, so that no orphaned records are left in the system.

#### Acceptance Criteria

1. WHEN a person delete is requested, THE PeopleService SHALL check for linked records across all transaction tables: `expenses`, `sales_invoices`, `purchase_orders`, `payment_slips`, `collection_slips`, `salaries`, `profit_distributions`, and `partner_shares`
2. IF any linked transaction record exists for the payee, THEN THE PeopleService SHALL reject the deletion with a descriptive error message indicating which transaction type blocks the delete
3. THE PeopleRepository::countTransactionsForPayee() SHALL include `salaries` and `profit_distributions` in its count query in addition to the existing five tables

### Requirement 10: Performance Optimization for InsightsController Queries

**User Story:** As a developer, I want insight queries to avoid N+1 patterns, so that payee analytics load efficiently regardless of data volume.

#### Acceptance Criteria

1. THE InsightsRepository SHALL retrieve supplier item data including last price in a single query or at most two queries, eliminating the current per-item loop that executes one query per row
2. THE InsightsRepository SHALL retrieve customer item data including last price in a single query or at most two queries, eliminating any per-item loop pattern
3. THE InsightsRepository SHALL use window functions or correlated subqueries to fetch "last price" data within the aggregate query where MySQL 8+ features are available

### Requirement 11: Consistent Payee Verification Pattern

**User Story:** As a developer, I want all People module endpoints that accept a payee ID to use the same verification pattern, so that authorization checks are consistent and not duplicated inline.

#### Acceptance Criteria

1. THE InsightsService SHALL verify that the provided payee ID belongs to the current business before executing any insight query
2. THE PayeeStatementService SHALL verify that the provided payee ID belongs to the current business before executing any statement query
3. THE People_Module SHALL implement payee verification in the service layer using a shared method or repository call, rather than inline SQL in each controller method
4. IF the payee does not belong to the current business, THEN THE People_Module SHALL return HTTP 404 with the message "Payee not found"

### Requirement 12: Statement Date Parameter Validation

**User Story:** As a developer, I want date parameters to be validated for format and logical correctness, so that malformed or illogical date ranges cannot produce incorrect statements or SQL injection vectors.

#### Acceptance Criteria

1. WHEN `start_date` or `end_date` is provided to the statement endpoints, THE PayeeStatementService SHALL validate that each matches the format `YYYY-MM-DD` and represents a valid calendar date
2. WHEN both dates are provided, THE PayeeStatementService SHALL validate that `start_date` is less than or equal to `end_date`
3. IF date validation fails, THEN THE PayeeStatementController SHALL return HTTP 422 with a descriptive error message

### Requirement 13: Repository Separation of Concerns in PeopleRepository

**User Story:** As a developer, I want PeopleRepository to focus on person-level and payee-level data access without mixing unrelated concerns, so that the class has a clear single responsibility.

#### Acceptance Criteria

1. THE PeopleRepository SHALL retain person CRUD methods (`create`, `findById`, `findAllByBusiness`, `update`, `delete`, `searchTenantPeople`, `findByPhoneInTenant`)
2. THE PeopleRepository SHALL retain payee lifecycle methods (`createPayee`, `findPayee`, `deletePayee`, `updatePayeeNotes`, `countPayeesForPerson`)
3. THE PeopleRepository SHALL retain business-scoped junction methods (`setPayeeTypes`, `getPayeeTypeIds`) as the authoritative owner of junction writes
4. THE PeopleRepository SHALL retain transaction-awareness methods (`countTransactionsForPayee`, `updatePayeeBalance`) since these are payee-scoped operations
5. THE Refactoring_Engine SHALL add clear section comments (docblocks or region markers) grouping methods by concern: Person CRUD, Payee Lifecycle, Junction Management, Transaction Queries

### Requirement 14: Type-Filtering Consistency Across Module Boundaries

**User Story:** As a domain logic owner, I want payee type filtering to respect module activation gating everywhere it is used, so that deactivated modules do not leak type visibility.

#### Acceptance Criteria

1. WHEN payee types are listed via `GET /people/payee-types`, THE People_Module SHALL return only types whose `module_code` is active for the current business or whose `module_code` is NULL
2. WHEN payee types are displayed in person create/edit forms, THE People_Module SHALL apply the same module-gated filter used by the listing endpoint
3. WHEN `PeopleRepository::findAllByBusiness()` joins to `payee_types`, THE PeopleRepository SHALL filter out types linked to inactive modules so that the listing does not display deactivated type labels

### Requirement 15: Frontend Code Quality Improvements

**User Story:** As a developer, I want frontend People module code to follow consistent patterns for error handling, data refresh, and modal lifecycle, so that the UI is robust and maintainable.

#### Acceptance Criteria

1. THE Refactoring_Engine SHALL ensure all API calls in People frontend pages use `apiFetch()` with proper error handling that displays user-friendly notifications via the notification system
2. THE Refactoring_Engine SHALL ensure all modal controllers properly implement `unmount()` cleanup including event listener removal
3. THE Refactoring_Engine SHALL ensure the person form modal validates required fields client-side before submitting to the API
4. THE Refactoring_Engine SHALL ensure the payee types modal prevents submission of empty or whitespace-only type names

### Requirement 16: Consistent Error Logging Across the Module

**User Story:** As an operations engineer, I want all exceptions in the People module to be logged with sufficient context, so that production issues can be diagnosed from log files.

#### Acceptance Criteria

1. WHEN any controller in the People module catches a `Throwable`, THE People_Module SHALL log the exception message, exception class, and stack trace using `error_log()`
2. THE People_Module SHALL include the authenticated user_id and business_id in log entries where AuthContext is available
3. THE People_Module SHALL use a consistent log format: `[People] {method}: {ExceptionClass} - {message} (user={user_id}, business={business_id})`

### Requirement 17: Remove Redundant Payee Verification Queries

**User Story:** As a developer, I want verification logic centralized rather than copy-pasted across controller methods, so that changes to the verification logic need only be made once.

#### Acceptance Criteria

1. THE InsightsController supplier, customer, general, partner, and customerItems methods SHALL NOT contain inline payee-verification SQL
2. THE PayeeStatementController show, export, and downloadPdf methods SHALL NOT contain inline payee-verification SQL
3. THE People_Module SHALL implement a single `verifyPayeeBelongsToBusiness(int $payeeId, int $businessId): array` method in the service or repository layer that returns the payee record or throws a not-found exception
4. THE Refactoring_Engine SHALL replace all inline verification queries with calls to this shared method
