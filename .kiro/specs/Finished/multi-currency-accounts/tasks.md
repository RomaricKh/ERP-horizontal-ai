# Implementation Plan: Multi-Currency Accounts

## Overview

This plan implements currency-aware account management across the ERP system. The work is structured in five phases: database migration, backend service/repository changes (currency validation, dual-balance tracking, currency filtering, currency immutability), frontend form integrations (account filtering by currency), frontend accounts page updates (balance_fc column, currency grouping, BC total), and property-based/unit tests woven in close to each implementation step.

## Tasks

- [x] 1. Database: Add `balance_fc` column to `business_accounts`
  - [x] 1.1 Create migration file `database/migrations/add_balance_fc_column.sql`
    - Add SQL: `ALTER TABLE business_accounts ADD COLUMN balance_fc DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER balance;`
    - Existing rows will initialize to `0` (no retroactive recalculation)
    - _Requirements: 5.1, 10.3_

- [x] 2. Backend: Modify AccountRepository for dual-balance and currency filtering
  - [x] 2.1 Update `findAllForBusiness()` in `src/Repository/AccountRepository.php`
    - Add optional `?string $currency = null` parameter
    - Include `balance_fc` in the SELECT column list
    - When `$currency` is provided, add `AND LOWER(currency) = LOWER(:currency)` to the WHERE clause
    - When `$currency` is null, return all accounts (existing behavior)
    - _Requirements: 9.1, 9.2, 9.3_

  - [x] 2.2 Update `findById()` in `src/Repository/AccountRepository.php`
    - Include `balance_fc` in the SELECT column list
    - _Requirements: 5.1_

  - [x] 2.3 Update `create()` in `src/Repository/AccountRepository.php`
    - Include `balance_fc` in the INSERT statement, initialized to `0`
    - _Requirements: 5.1_

  - [x] 2.4 Update `updateBalance()` in `src/Repository/AccountRepository.php`
    - Add `float $deltaFc = 0.0` parameter (defaults to `0.0` for backward compatibility)
    - Add `balance_fc = balance_fc + :delta_fc` to the UPDATE statement
    - _Requirements: 5.2, 5.4, 5.6, 6.5_

  - [ ]* 2.5 Write property test: Account filtering by currency
    - **Property 2: Account filtering by currency**
    - Create `tests/Property/AccountFilteringPropertyTest.php`
    - Generate random sets of accounts with varying currencies, insert via repository
    - Call `findAllForBusiness` with and without currency filter
    - Assert filtered results contain exactly those accounts matching the filter (case-insensitive)
    - Assert unfiltered results contain all accounts
    - Minimum 100 iterations
    - **Validates: Requirements 9.1, 9.2, 9.3**

- [x] 3. Backend: Modify AccountService for currency filtering and immutability
  - [x] 3.1 Update `list()` in `src/Services/AccountService.php`
    - Add optional `?string $currency = null` parameter
    - Pass `$currency` through to `AccountRepository::findAllForBusiness()`
    - _Requirements: 9.1, 9.2_

  - [x] 3.2 Update `update()` in `src/Services/AccountService.php`
    - Before calling `$this->repo->update()`, check if `$data` contains a `currency` key
    - If `$data['currency']` differs from the existing account's currency (case-insensitive), throw `\InvalidArgumentException('Account currency cannot be changed')`
    - If `$data['currency']` matches or is not present, proceed with the update
    - _Requirements: 8.2, 8.3_

  - [ ]* 3.3 Write property test: Currency immutability on account update
    - **Property 5: Currency immutability on account update**
    - Create `tests/Property/CurrencyImmutabilityPropertyTest.php`
    - Generate random accounts and random update payloads with different currency values
    - Assert that updates with a different currency throw `InvalidArgumentException`
    - Assert that updates with the same currency (any case) or without a currency key succeed
    - Minimum 100 iterations
    - **Validates: Requirements 8.2, 8.3**

- [x] 4. Backend: Modify AccountsController for currency query parameter
  - [x] 4.1 Update `index()` in `src/Controllers/AccountsController.php`
    - Read `$_GET['currency']` (default to `null` if not present)
    - Pass the currency value to `$this->service->list($currency)`
    - _Requirements: 9.1, 3.1, 4.1_

- [x] 5. Checkpoint - Verify account repository, service, and controller changes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Backend: Modify PaymentService for currency validation and dual-balance updates
  - [x] 6.1 Update `create()` in `src/Services/PaymentService.php`
    - After existing field validation and before `$this->db->beginTransaction()`, fetch the account via `$this->accountRepo->findById($accountId, $ctx->business_id)`
    - If account not found, throw `\RuntimeException('Account not found')`
    - Compare `strtolower($account['currency'])` with `strtolower(trim($data['currency']))`
    - On mismatch, throw `\InvalidArgumentException("Currency mismatch: transaction currency is '{txCurrency}' but account currency is '{acctCurrency}'")`
    - Inside the transaction, update the `updateBalance()` call to pass both `$deltaBc = -$amountBc` and `$deltaFc = -$amountFc`
    - _Requirements: 1.1, 1.2, 1.3, 5.2, 6.1_

  - [x] 6.2 Update `delete()` in `src/Services/PaymentService.php`
    - Read `amount_fc` from the existing payment record
    - Update the `updateBalance()` call to pass both `$deltaBc = +$amountBc` and `$deltaFc = +$amountFc`
    - No currency validation on deletion (backward compatibility)
    - _Requirements: 5.3, 6.2, 10.1, 10.2_

  - [ ]* 6.3 Write property test: Currency match validation
    - **Property 1: Currency match validation**
    - Create `tests/Property/CurrencyValidationPropertyTest.php`
    - Generate random payment/collection creation requests with matching and mismatching currencies
    - Assert that matching currencies succeed and mismatching currencies throw `InvalidArgumentException` with both currencies in the message
    - Assert no database changes occur on mismatch
    - Minimum 100 iterations
    - **Validates: Requirements 1.1, 1.2, 1.3, 2.1, 2.2, 2.3**

  - [ ]* 6.4 Write property test: Dual balance update on slip creation
    - **Property 3: Dual balance update on slip creation**
    - Create `tests/Property/BalanceUpdatePropertyTest.php`
    - Generate random valid payments and collections with varying amount_fc and exchange_rate
    - Assert account `balance` changes by exactly `round(amount_fc * exchange_rate, 2)` and `balance_fc` changes by exactly `amount_fc`
    - Assert payments decrease and collections increase
    - Minimum 100 iterations
    - **Validates: Requirements 5.2, 5.4, 6.1, 6.3**

- [x] 7. Backend: Modify CollectionService for currency validation and dual-balance updates
  - [x] 7.1 Update `create()` in `src/Services/CollectionService.php`
    - Same currency validation pattern as PaymentService: fetch account, compare currencies, throw on mismatch
    - Update the `updateBalance()` call to pass both `$deltaBc = +$amountBc` and `$deltaFc = +$amountFc`
    - _Requirements: 2.1, 2.2, 2.3, 5.4, 6.3_

  - [x] 7.2 Update `delete()` in `src/Services/CollectionService.php`
    - Read `amount_fc` from the existing collection record
    - Update the `updateBalance()` call to pass both `$deltaBc = -$amountBc` and `$deltaFc = -$amountFc`
    - No currency validation on deletion (backward compatibility)
    - _Requirements: 5.5, 6.4, 10.1, 10.2_

  - [ ]* 7.3 Write property test: Dual balance round-trip on slip create/delete
    - **Property 4: Dual balance round-trip on slip create/delete**
    - Create `tests/Property/BalanceRoundTripPropertyTest.php`
    - Generate random payments and collections, create then delete each slip
    - Assert both `balance` and `balance_fc` return to their original values after create + delete
    - Minimum 100 iterations
    - **Validates: Requirements 5.3, 5.5, 6.2, 6.4**

  - [ ]* 7.4 Write unit tests for PaymentService and CollectionService currency validation
    - Create `tests/Unit/CurrencyValidationTest.php`
    - Test payment creation with matching currency succeeds
    - Test payment creation with mismatching currency throws `InvalidArgumentException`
    - Test collection creation with matching currency succeeds
    - Test collection creation with mismatching currency throws `InvalidArgumentException`
    - Test payment deletion with mismatched currency succeeds (backward compatibility)
    - Test collection deletion with mismatched currency succeeds (backward compatibility)
    - Test account not found during validation throws `RuntimeException`
    - _Requirements: 1.1, 1.2, 2.1, 2.2, 10.1, 10.2_

- [x] 8. Checkpoint - Verify payment and collection service changes
  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Frontend: Update payment form to filter accounts by currency
  - [x] 9.1 Update `loadAccounts()` in `public/assets/js/pages/Finance/paymentForm.modal.js`
    - Accept an optional `currency` parameter
    - When `currency` is provided, call `/api/accounts?currency=${currency}` via `apiFetch`
    - When `currency` is empty/null, call `/api/accounts` (returns all accounts)
    - When the filtered list is empty, show a single disabled option: "No accounts for this currency"
    - _Requirements: 3.1, 3.3, 3.4_

  - [x] 9.2 Add currency change listener in `mount()` of `paymentForm.modal.js`
    - Add a `change` event listener on `F.currency()` that calls `loadAccounts(selectedCurrency)`
    - Reset the account dropdown selection when currency changes
    - _Requirements: 3.2_

- [x] 10. Frontend: Update collection form to filter accounts by currency
  - [x] 10.1 Update `loadAccounts()` in `public/assets/js/pages/Finance/collectionForm.modal.js`
    - Same changes as paymentForm: accept optional `currency` parameter, call filtered API endpoint
    - When the filtered list is empty, show a single disabled option: "No accounts for this currency"
    - _Requirements: 4.1, 4.3, 4.4_

  - [x] 10.2 Add currency change listener in `mount()` of `collectionForm.modal.js`
    - Add a `change` event listener on `F.currency()` that calls `loadAccounts(selectedCurrency)`
    - Reset the account dropdown selection when currency changes
    - _Requirements: 4.2_

- [x] 11. Checkpoint - Verify frontend form filtering
  - Ensure all tests pass, ask the user if questions arise.

- [x] 12. Frontend: Update accounts page for multi-currency display
  - [x] 12.1 Update table header in `public/html/Finance/accounts.html`
    - Change columns to: `ID | Name | Currency | Balance (FC) | Balance (BC) | Actions`
    - Add a `<tfoot>` row for the total BC balance
    - _Requirements: 7.1, 7.2_

  - [x] 12.2 Update `renderTable()` in `public/assets/js/pages/Finance/accounts.js`
    - Add a "Balance (FC)" column displaying `account.balance_fc` formatted to 2 decimal places
    - Rename the existing "Balance" column to "Balance (BC)"
    - Update `columnDefs` to account for the new column index
    - Add currency grouping using DataTables `rowGroup` extension or a simple currency header row
    - Calculate and display the total BC balance in the footer row
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

  - [ ]* 12.3 Write property test: Total BC balance is sum of individual balances
    - **Property 6: Total BC balance is sum of individual balances**
    - Create `tests/Property/TotalBalancePropertyTest.php`
    - Generate random sets of accounts with varying BC balances
    - Assert the computed total equals the sum of all individual `balance` (BC) values
    - Minimum 100 iterations
    - **Validates: Requirements 7.4**

- [x] 13. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- The `balance_fc` column initializes to `0` for existing accounts — no retroactive recalculation
- Currency validation applies only to new slip creation, not to deletion (backward compatibility)
- The `updateBalance()` method's `$deltaFc` parameter defaults to `0.0` to maintain backward compatibility with any existing callers
