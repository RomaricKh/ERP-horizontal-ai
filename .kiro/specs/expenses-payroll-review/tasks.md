# Implementation Plan

## Overview

Refactoring of the Expenses & Payroll module covering security hardening, input validation, architectural cleanup (3-layer pattern), HTTP status code consistency, and frontend form validation improvements. All changes preserve existing functional behavior while adding safety and maintainability.

## Tasks

- [x] 1. Add `logError(string $module, string $method, \Throwable $e): void` protected method to `BaseController` that formats and logs exceptions using the pattern `[{Module}] {method}: {Class} - {message} (user={id}, business={id})`
- [x] 2. Refactor `ExpensesController` catch blocks: remove `$e->getMessage()` from Throwable responses in `store()`, `storeCategory()`, `uploadAttachment()`; add `logError()` calls; ensure tiered pattern (InvalidArgumentException→422, RuntimeException→422, Throwable→500 generic)
- [x] 3. Refactor `SalariesController` catch blocks: remove `$e->getMessage()` from Throwable responses in `store()`, `generatePayroll()`, `payslip()`; add `logError()` calls; ensure tiered pattern
- [x] 4. Refactor all `RecurrenceController` catch blocks: change `InvalidArgumentException` from HTTP 400 to 422, remove `$e->getMessage()` from Throwable responses, add `logError()` calls, ensure consistent tiered pattern across all methods
- [x] 5. Add date format validation helper to ExpenseService: `validateDate(string $date, string $fieldName): void` — checks YYYY-MM-DD format via regex and validates with `checkdate()`; throws InvalidArgumentException on failure
- [x] 6. Add input validation to `ExpenseService::create()`: verify `amount_fc` is numeric and > 0, verify `exchange_rate` (if provided) is > 0, validate `expense_date` format, validate `reference_date` format if non-empty
- [x] 7. Add full input validation to `ExpenseService::update()`: validate expense_date non-empty, currency non-empty after trim, amount_fc numeric and > 0, exchange_rate > 0 if provided, expense_date format, reference_date format if non-empty
- [x] 8. Enhance `SalaryService::validateData()`: add gross_amount_fc > 0 check, tax_fc ≥ 0 check, exchange_rate > 0 check (if provided), period_start/period_end YYYY-MM-DD format validation via `checkdate()`
- [x] 9. Add category name validation to `ExpenseService::createCategory()` and `updateCategory()`: reject names > 100 characters after trimming, reject names containing ASCII control characters (0x00-0x1F, 0x7F)
- [x] 10. Add MIME type verification in `ExpensesController::uploadAttachment()` using `finfo_file()` — reject files where detected MIME doesn't match allowed list; return HTTP 422 with "File content does not match expected type"
- [x] 11. Add filename sanitization in `ExpensesController::uploadAttachment()`: strip `../`, `..\\`, null bytes, and characters outside `[a-zA-Z0-9_\-.]` from the original filename extension extraction
- [x] 12. Add `verifyPayeeBelongsToBusiness(int $payeeId, int $businessId): void` helper (in PeopleRepository or as shared validation method) that throws InvalidArgumentException("Employee not found in this business") if payee not found for business
- [x] 13. Call payee ownership verification in `ExpenseService::create()` and `update()` when `payee_id` is provided; call in `SalaryService::create()` and `update()` when `payee_id` is provided
- [x] 14. Add entity/schedule ownership verification in `RecurrenceService::createSchedule()` — verify expense belongs to current business (EXPENSE type) or payee belongs to business and is Employee (SALARY type); throw RuntimeException with "not found" on failure
- [x] 15. Add schedule ownership check in RecurrenceService instance status update and bulk advance payment operations — verify `schedule.business_id = AuthContext::businessId()`; throw RuntimeException with "not found" on failure
- [x] 16. Add `findBusinessInfo(int $businessId): array` to `SalaryRepository` — returns name, legal_name, address, phone, email from businesses table
- [x] 17. Add `generatePayslipPdf(int $salaryId): array` to `SalaryService` — orchestrates: getById → business info via repo → PayslipPdf::render → Dompdf render → returns `['content' => bytes, 'filename' => sanitized]`
- [x] 18. Refactor `SalariesController::payslip()` to call `$this->service->generatePayslipPdf($id)`, set Content-Type/Content-Disposition headers, echo content — remove all direct PDO/Dompdf instantiation
- [x] 19. Add `findEmployeesWithRecurrences(int $businessId): array` to `SalaryRepository` — the employee+recurrence JOIN query currently inline in generatePayroll()
- [x] 20. Add `existsOverlappingPeriod(int $payeeId, int $businessId, string $start, string $end): bool` to `SalaryRepository` — period overlap check
- [x] 21. Add `findLatestForPayee(int $payeeId, int $businessId): ?array` to `SalaryRepository` — latest salary for template lookup
- [x] 22. Add `sumPaymentAllocationsForSalary(int $salaryId): float` to `SalaryRepository` — sum of payment allocations for delete guard
- [x] 23. Refactor `SalaryService::generatePayroll()` to use repository methods (tasks 19-21) instead of inline SQL — preserve existing logic
- [x] 24. Refactor `SalaryService::delete()` to use `sumPaymentAllocationsForSalary()` (task 22) instead of inline SQL — preserve guard behavior
- [x] 25. Add enrichment methods to `RecurrenceScheduleRepository`: query for entity_name (payee+category for expenses, people name for salaries), payee_id, and exchange_rate
- [x] 26. Add `listSchedulesEnriched(array $filters): array` to `RecurrenceService` — calls repo for schedules and enriches with entity metadata
- [x] 27. Add `listInstancesEnriched(array $filters): array` to `RecurrenceService` — calls repo for instances, enriches with entity metadata + computes days_overdue
- [x] 28. Refactor `RecurrenceController::listSchedules()` to call `listSchedulesEnriched()` — remove inline SQL and DB access
- [x] 29. Refactor `RecurrenceController::listInstances()` to call `listInstancesEnriched()` — remove inline SQL, resolveEntityDetails usage
- [x] 30. Remove `resolveEntityDetails()` and `resolveEntityNameFromSchedule()` private methods from RecurrenceController
- [x] 31. Audit and verify `ExpenseService` payee balance logic: create → decrease by amount_bc, update same payee → delta, update payee change → reverse old + apply new, delete → reverse
- [x] 32. Audit and verify `SalaryService` payee balance logic using net_amount_bc — fix any discrepancies found
- [x] 33. Update `validateForm()` in `expenseForm.modal.js`: check amount_fc is positive number (parseFloat > 0), exchange_rate is positive number, expense_date is valid date format
- [x] 34. Update `unmount()` in `expenseForm.modal.js`: remove ALL event listeners including `input` events on amountFc and exchangeRate, `change` event on date field
- [x] 35. Update `validateForm()` in `salaryForm.modal.js`: validate period_end ≥ period_start, gross_amount_fc > 0, tax_fc ≥ 0 and ≤ gross_amount_fc, exchange_rate > 0
- [x] 36. Update `unmount()` in `salaryForm.modal.js`: remove ALL event listeners including `input` events on grossAmountFc and taxFc, `change` event on employee select
- [x] 37. Update `expenseCategories.modal.js`: block submission when name is empty/whitespace-only or exceeds 100 characters; show inline Bootstrap validation feedback (.is-invalid + .invalid-feedback); ensure proper unmount() cleanup
- [x] 38. Write PHP property-based tests (Property 3, 4): generate invalid expense/salary data combinations → verify InvalidArgumentException is thrown before DB write
- [x] 39. Write PHP property-based tests (Property 5): generate filenames with dangerous characters → verify sanitized output contains no path traversal, null bytes, or disallowed chars
- [x] 40. Write PHP property-based tests (Property 6): generate category names > 100 chars or with control characters → verify InvalidArgumentException
- [-] 41. Write PHP property-based tests (Property 7): generate sequences of create/update/delete operations with random amounts → verify cumulative payee balance adjustment is algebraically correct
- [x] 42. Write PHP property-based tests (Property 8): generate invalid date strings → verify rejection; generate valid YYYY-MM-DD dates → verify acceptance
- [-] 43. Write PHP property-based tests (Property 12, 13): generate cross-business payee/entity references → verify ownership exceptions
- [x] 44. Write JS property-based tests (Vitest + fast-check) for Property 9: generate non-positive/non-numeric values for expense form → verify validateForm returns false
- [-] 45. Write JS property-based tests (Vitest + fast-check) for Property 10: generate invalid salary form combinations → verify validateForm returns false
- [-] 46. Write JS property-based tests (Vitest + fast-check) for Property 11: generate invalid category names (whitespace-only, >100 chars) → verify form blocks submission
- [-] 47. Write unit tests for controller error handling: verify generic messages (no leakage), verify structured log format, verify HTTP status codes for each exception type
- [x] 48. Write integration tests for payslip PDF generation, batch payroll, and recurrence enrichment to verify behavior preservation after refactoring

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1", "5", "9", "10", "11", "12", "16", "19", "20", "21", "22", "25", "31", "32"] },
    { "id": 1, "tasks": ["2", "3", "4", "6", "7", "8", "13", "14", "15", "17", "23", "24", "26", "27", "33", "34", "35", "36", "37"] },
    { "id": 2, "tasks": ["18", "28", "29", "30"] },
    { "id": 3, "tasks": ["38", "39", "40", "41", "42", "43", "44", "45", "46", "47", "48"] }
  ]
}
```

**Wave 1** — Foundation: BaseController helper, date validation helper, category validation, file upload security, payee ownership helper, repository methods, enrichment queries, balance audits
**Wave 2** — Application: Controller refactoring, service validation additions, ownership calls, service methods using repo, frontend validation
**Wave 3** — Controller delegation: Payslip controller slim-down, recurrence controller delegation, cleanup of removed methods
**Wave 4** — Testing: All property-based tests, unit tests, and integration tests

## Notes

- All refactoring tasks preserve existing functional behavior — the test suite should pass before and after each change
- Property-based tests use PHPUnit with custom generators (PHP side) and Vitest + fast-check (JS side), consistent with project conventions
- The tiered exception pattern (InvalidArgumentException→422, RuntimeException→404/422, Throwable→500) is already established in other modules (People, Items) and is being standardized here
- Frontend modals use the existing `openModal()` system with `mount()`/`unmount()` lifecycle — validation additions follow the existing `markInvalid()` helper pattern
- No database migrations needed — all changes operate on existing schema
