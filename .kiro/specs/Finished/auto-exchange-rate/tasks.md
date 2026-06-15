# Implementation Plan: Auto Exchange Rate

## Overview

This plan implements automatic exchange rate population across all six financial document forms. The work is structured in four phases: backend service and endpoint changes, a shared frontend utility, frontend form integrations, and backend fallback rate resolution. Property-based tests and unit tests are woven in close to each implementation step.

## Tasks

- [x] 1. Backend: Add `getLatestForLookup` and `resolveRate` methods to ExchangeRateService
  - [x] 1.1 Add `getLatestForLookup(string $currency): array` method to `src/Services/ExchangeRateService.php`
    - Inject `BusinessRepository` as a new dependency in the constructor
    - Validate that `$currency` is non-empty; throw `InvalidArgumentException` if empty
    - Fetch the business record via `BusinessRepository::getBusinessByID()` using `AuthContext::get()->business_id`
    - If `$currency === $business['currency']`, return `['rate' => 1, 'rate_date' => null, 'is_base' => true]`
    - Otherwise call `$this->repo->getLatestRate($currency, $ctx->business_id)`
    - If no rate found, return `['rate' => null, 'rate_date' => null, 'is_base' => false]`
    - If rate found, return `['rate' => (float)$row['rate'], 'rate_date' => $row['rate_date'], 'is_base' => false]`
    - _Requirements: 1.1, 1.3, 2.1, 3.1, 3.2, 3.3_

  - [x] 1.2 Add `resolveRate(string $currency, ?float $providedRate): float` method to `src/Services/ExchangeRateService.php`
    - If `$providedRate` is not null, return it as-is
    - Otherwise call `$this->getLatestForLookup($currency)`
    - If the lookup returns a non-null rate, return it
    - If no rate is found, throw `InvalidArgumentException` with message "Exchange rate is required for currency {currency}"
    - _Requirements: 7.1, 7.2, 7.3_

  - [x] 1.3 Write property test: Latest rate lookup returns the most recent entry
    - **Property 1: Latest rate lookup returns the most recent entry**
    - Create `tests/Property/ExchangeRateLookupPropertyTest.php`
    - Generate random sets of exchange rate rows (varying currencies, dates, rates) and insert them via the repository
    - Call `getLatestForLookup` and assert the returned rate matches the row with the maximum `rate_date`
    - Minimum 100 iterations
    - **Validates: Requirements 1.1, 3.2**

  - [x] 1.4 Write property test: Base currency always resolves to rate 1
    - **Property 2: Base currency always resolves to rate 1**
    - Create or extend `tests/Property/ExchangeRateLookupPropertyTest.php`
    - Generate random business currency values, call `getLatestForLookup` with the same currency
    - Assert rate === 1 and `is_base === true`
    - Minimum 100 iterations
    - **Validates: Requirements 1.3, 3.3**

  - [x] 1.5 Write property test: Backend rate resolution preserves user-provided rates and falls back correctly
    - **Property 3: Backend rate resolution preserves user-provided rates and falls back correctly**
    - Create or extend `tests/Property/ExchangeRateLookupPropertyTest.php`
    - Generate random documents with and without exchange rates, and random rate tables
    - Call `resolveRate` and assert: provided rate is preserved; missing rate falls back to latest; missing rate + empty table throws
    - Minimum 100 iterations
    - **Validates: Requirements 7.1, 7.3**

  - [x] 1.6 Write property test: Base currency is always sourced from the business table
    - **Property 4: Base currency is always sourced from the business table**
    - Create or extend `tests/Property/ExchangeRateLookupPropertyTest.php`
    - Generate random businesses with different currency fields
    - Call `getLatestForLookup` with a foreign currency and assert the base currency comparison uses the business's currency field
    - Minimum 100 iterations
    - **Validates: Requirements 2.1**

  - [x] 1.7 Write unit tests for `getLatestForLookup` and `resolveRate`
    - Create `tests/Unit/ExchangeRateLookupTest.php`
    - Test `getLatestForLookup` with empty string throws `InvalidArgumentException`
    - Test `getLatestForLookup` with a currency that has no rates returns `rate: null`
    - Test `getLatestForLookup` with base currency returns `rate: 1, is_base: true`
    - Test `resolveRate` with provided rate returns that rate unchanged
    - Test `resolveRate` with null rate and existing table rate returns the table rate
    - Test `resolveRate` with null rate and no table rate throws exception
    - _Requirements: 1.1, 1.3, 1.4, 3.3, 3.4, 7.1, 7.2, 7.3_

- [x] 2. Backend: Update `latest` endpoint and route permissions
  - [x] 2.1 Update `ExchangeRatesController::latest()` in `src/Controllers/ExchangeRatesController.php`
    - Validate that `$_GET['currency']` is non-empty; return 422 with "Currency is required" if empty
    - Call `$this->service->getLatestForLookup($currency)` instead of `$this->service->getLatest($currency)`
    - Return structured response: `{ success: true, data: { rate, rate_date, is_base } }`
    - Catch `InvalidArgumentException` and return 422 error
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

  - [x] 2.2 Relax route permission for `GET /exchange-rates/latest` in `public/api/routes/exchange_rates.php`
    - Change the permission array from `['exchange-rates.manage']` to `[]` (any authenticated user)
    - Keep all other exchange rate routes unchanged
    - _Requirements: 3.1_

  - [x] 2.3 Write unit tests for the updated `latest` endpoint
    - Test valid currency returns `{ rate, rate_date, is_base }`
    - Test base currency returns `{ rate: 1, is_base: true }`
    - Test empty currency returns 422 error
    - Test currency with no rates returns `{ rate: null }`
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [x] 3. Checkpoint - Verify backend changes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Frontend: Create shared `exchangeRateHelper.js` utility
  - [x] 4.1 Create `public/assets/js/utils/exchangeRateHelper.js`
    - Import `apiFetch` from `../Auth/apiFetch.js`
    - Implement `fetchAndApplyRate(currency, rateInput, warningEl = null)`:
      - If `currency` is empty, clear `rateInput.value`, hide warning, return
      - Call `GET /api/exchange-rates/latest?currency={currency}` via `apiFetch`
      - If response has `rate !== null`: set `rateInput.value = rate`, dispatch `new Event('input', { bubbles: true })`, hide warning
      - If `rate === null`: clear `rateInput.value`, show warning "No exchange rate found for {currency}"
      - On fetch error: log to console, leave field unchanged
    - Implement `attachRateAutoFetch(currencyEl, rateInput, warningEl = null)`:
      - Add a `change` event listener on `currencyEl` that calls `fetchAndApplyRate`
      - Return a cleanup function that removes the listener
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 5.3, 6.1_

  - [x] 4.2 Write unit tests for `exchangeRateHelper.js`
    - Create `tests/Unit/exchangeRateHelper.test.js`
    - Test `fetchAndApplyRate` with empty currency clears the field and returns early
    - Test `fetchAndApplyRate` with a valid currency sets the field value and dispatches `input` event
    - Test `fetchAndApplyRate` with a currency that has no rate clears the field and shows warning
    - Test `fetchAndApplyRate` with base currency sets field to 1
    - Test `attachRateAutoFetch` returns a cleanup function that removes the listener
    - _Requirements: 1.1, 1.2, 1.3, 1.4_

- [x] 5. Frontend: Integrate auto-fetch into all six form modals
  - [x] 5.1 Integrate into `paymentForm.modal.js`
    - Import `attachRateAutoFetch` from `../../utils/exchangeRateHelper.js`
    - In `mount()`, call `attachRateAutoFetch(F.currency(), F.exchangeRate())` and store the cleanup function
    - In `unmount()`, call the cleanup function
    - Exchange rate field remains editable for manual override
    - _Requirements: 4.1, 5.1, 5.2, 6.1_

  - [x] 5.2 Integrate into `collectionForm.modal.js`
    - Import `attachRateAutoFetch` from `../../utils/exchangeRateHelper.js`
    - In `mount()`, call `attachRateAutoFetch(F.currency(), F.exchangeRate())` and store the cleanup function
    - In `unmount()`, call the cleanup function
    - _Requirements: 4.2, 5.1, 5.2, 6.1_

  - [x] 5.3 Integrate into `expenseForm.modal.js`
    - Import `attachRateAutoFetch` from `../../utils/exchangeRateHelper.js`
    - In `mount()`, call `attachRateAutoFetch(F.currency(), F.exchangeRate())` and store the cleanup function
    - In `unmount()`, call the cleanup function
    - _Requirements: 4.3, 5.1, 5.2, 6.1_

  - [x] 5.4 Integrate into `salaryForm.modal.js`
    - Import `attachRateAutoFetch` from `../../utils/exchangeRateHelper.js`
    - In `mount()`, call `attachRateAutoFetch(F.currency(), F.exchangeRate())` and store the cleanup function
    - In `unmount()`, call the cleanup function
    - _Requirements: 4.4, 5.1, 5.2, 6.1_

  - [x] 5.5 Integrate into `poForm.modal.js`
    - Import `attachRateAutoFetch` from `../../utils/exchangeRateHelper.js`
    - In `mount()`, call `attachRateAutoFetch(F.currency(), F.exchangeRate())` and store the cleanup function
    - In `unmount()`, call the cleanup function
    - Existing `recalcAllLines()` will fire automatically via the dispatched `input` event on the exchange rate field
    - _Requirements: 4.5, 5.1, 5.2, 6.1, 6.2_

  - [x] 5.6 Integrate into `invoiceForm.modal.js`
    - Import `attachRateAutoFetch` from `../../utils/exchangeRateHelper.js`
    - In `mount()`, call `attachRateAutoFetch(F.currency(), F.exchangeRate())` and store the cleanup function
    - In `unmount()`, call the cleanup function
    - Existing `recalcAllLines()` will fire automatically via the dispatched `input` event on the exchange rate field
    - _Requirements: 4.6, 5.1, 5.2, 6.1, 6.2_

- [x] 6. Checkpoint - Verify frontend integration
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Backend: Add fallback rate resolution to financial document services
  - [x] 7.1 Add fallback rate resolution to `PaymentService::create()` in `src/Services/PaymentService.php`
    - Inject `ExchangeRateService` as a dependency in the constructor
    - Before the existing exchange rate validation, check if `exchange_rate` is missing/null/empty
    - If missing, call `$this->exchangeRateService->resolveRate($data['currency'], null)` to get the fallback rate
    - If provided, call `$this->exchangeRateService->resolveRate($data['currency'], (float)$data['exchange_rate'])` to pass through
    - Replace the existing `exchange_rate` validation with the resolved value
    - _Requirements: 7.1, 7.2, 7.3_

  - [x] 7.2 Add fallback rate resolution to `CollectionService::create()` in `src/Services/CollectionService.php`
    - Same pattern as PaymentService: inject `ExchangeRateService`, call `resolveRate` before validation
    - _Requirements: 7.1, 7.2, 7.3_

  - [x] 7.3 Add fallback rate resolution to `ExpenseService::create()` and `update()` in `src/Services/ExpenseService.php`
    - Inject `ExchangeRateService` as a dependency in the constructor
    - In both `create()` and `update()`, call `resolveRate` to resolve the exchange rate before using it
    - _Requirements: 7.1, 7.2, 7.3_

  - [x] 7.4 Add fallback rate resolution to `SalaryService::create()` and `update()` in `src/Services/SalaryService.php`
    - Inject `ExchangeRateService` as a dependency in the constructor
    - In both `create()` and `update()`, call `resolveRate` to resolve the exchange rate before using it
    - _Requirements: 7.1, 7.2, 7.3_

  - [x] 7.5 Add fallback rate resolution to `PurchaseOrderService::create()` in `src/Services/PurchaseOrderService.php`
    - Inject `ExchangeRateService` as a dependency in the constructor
    - Call `resolveRate` to resolve the exchange rate before using it
    - _Requirements: 7.1, 7.2, 7.3_

  - [x] 7.6 Add fallback rate resolution to `SalesInvoiceService::create()` in `src/Services/SalesInvoiceService.php`
    - Inject `ExchangeRateService` as a dependency in the constructor
    - Call `resolveRate` to resolve the exchange rate before using it
    - _Requirements: 7.1, 7.2, 7.3_

  - [x] 7.7 Write unit tests for fallback rate resolution in financial document services
    - Create `tests/Unit/FallbackRateResolutionTest.php`
    - Test that submitting a document without exchange rate uses the looked-up rate
    - Test that submitting a document with an exchange rate preserves the user-provided value
    - Test that submitting without exchange rate and no rate in table throws exception
    - Test at least two services (e.g., PaymentService and ExpenseService) to verify the pattern
    - _Requirements: 7.1, 7.2, 7.3_

- [x] 8. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- The frontend exchange rate field remains editable in all forms, preserving manual override capability
- The `input` event dispatch in `fetchAndApplyRate` ensures existing `recalcBc` / `recalcAllLines` listeners fire automatically
