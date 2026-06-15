# Implementation Plan: Active Regional Settings

## Overview

This plan implements the regional formatting, translation, and text direction features by building backend API support first (TranslationRepository, TranslationService, TranslationController, SettingsService enhancement), then the frontend Formatter module (pure functions), followed by the TranslationClient module (singleton with init/t/applyTranslations), and finally wiring everything into the app shell and page modules with settings change propagation.

## Tasks

- [x] 1. Backend: Create TranslationRepository
  - [x] 1.1 Create `src/Repository/TranslationRepository.php` with `languageExists(string $lang): bool` and `findByLanguage(string $lang): array` methods
    - `languageExists` queries the `languages` table to check if the code exists
    - `findByLanguage` queries `tbl_translations` WHERE `lang = $lang` and returns an associative array `[key => value]`
    - Follow existing repository patterns (PDO, prepared statements)
    - _Requirements: 10.2, 10.3_

- [x] 2. Backend: Create TranslationService and TranslationController
  - [x] 2.1 Create `src/Services/TranslationService.php` with `getByLanguage(string $lang): array`
    - Inject TranslationRepository
    - Call `languageExists()` first; throw `\InvalidArgumentException` if not found
    - Return the key-value array from `findByLanguage()`
    - _Requirements: 10.2, 10.3_

  - [x] 2.2 Create `src/Controllers/TranslationController.php` extending BaseController
    - Implement `index(string $lang): void` method
    - Require JWT authentication via AuthContext
    - On success: respond with `{ "success": true, "data": { ... } }`
    - On invalid language: respond with 404 `{ "success": false, "data": null, "message": "Language not found" }`
    - On unauthorized: respond with 401
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5_

  - [x] 2.3 Register the route in `public/api/routes/` — add `translations.php` route file mapping GET `/translations/{lang}` to `TranslationController::index`
    - _Requirements: 10.1_

  - [x] 2.4 Write PHPUnit integration tests for the translations endpoint
    - Test GET `/api/translations/en` returns key-value pairs with correct shape
    - Test GET `/api/translations/xx` (invalid) returns 404
    - Test GET `/api/translations/en` without JWT returns 401
    - Test response format matches `{ success, data }` shape
    - _Requirements: 10.2, 10.3, 10.4, 10.5_

- [x] 3. Backend: Enhance SettingsService to include direction
  - [x] 3.1 Modify the existing `SettingsService::get()` method to JOIN the `languages` table on `settings_regional.language = languages.code` and include the `direction` field in the returned array
    - _Requirements: 11.1, 11.2_

  - [x] 3.2 Write PHPUnit integration test verifying GET `/api/settings` response includes the `direction` field
    - _Requirements: 11.1_

- [x] 4. Checkpoint - Backend complete
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Frontend: Implement Formatter module
  - [x] 5.1 Create `public/assets/js/i18n/formatter.js` with `parseNumberFormat(pattern)`, `configure(settings)`, `formatNumber(value, decimals)`, `formatDate(dateStr)`, `formatCurrency(value, decimals)`, and `formatPhone(rawDigits)` functions
    - `parseNumberFormat`: extract thousands_sep and decimal_sep from pattern strings ("1,234.56", "1.234,56", "1 234,56")
    - `configure`: parse settings and store config (thousands_sep, decimal_sep, date_format, currency_format, phone_format)
    - `formatNumber`: group integer part in thousands, use configured separators, preserve decimal precision
    - `formatDate`: parse ISO date string and reformat to configured pattern (d/m/Y, Y-m-d, m/d/Y); return "" for invalid/empty input
    - `formatCurrency`: format number then prepend currency code with space (multi-letter) or symbol without space (single non-alphanumeric char)
    - `formatPhone`: replace "#" placeholders with digits; return raw if fewer digits than placeholders; append extra digits; return "" for null/empty
    - _Requirements: 1.2, 1.3, 2.1, 2.2, 2.3, 2.4, 2.5, 3.1, 3.2, 3.3, 3.4, 3.5, 4.1, 4.2, 4.3, 4.4, 5.1, 5.2, 5.3, 5.4_

  - [x] 5.2 Write property test for number format parsing and formatting round-trip
    - **Property 1: Number format parsing and formatting round-trip**
    - **Validates: Requirements 1.2, 2.1**

  - [x] 5.3 Write property test for decimal precision preservation
    - **Property 2: Decimal precision preservation**
    - **Validates: Requirements 2.5**

  - [x] 5.4 Write property test for date formatting correctness
    - **Property 3: Date formatting correctness**
    - **Validates: Requirements 3.1**

  - [x] 5.5 Write property test for currency formatting
    - **Property 4: Currency formatting combines number formatting with prefix rules**
    - **Validates: Requirements 4.1, 4.2, 4.3, 4.4**

  - [x] 5.6 Write property test for phone template formatting
    - **Property 5: Phone template formatting**
    - **Validates: Requirements 5.1**

  - [x] 5.7 Write unit tests for Formatter edge cases
    - Test parse "1.234,56" → thousands=".", decimal=","
    - Test parse "1,234.56" → thousands=",", decimal="."
    - Test parse "1 234,56" → thousands=" ", decimal=","
    - Test format date "2025-01-31" with each pattern
    - Test invalid date → ""
    - Test null/empty phone → ""
    - Test short phone digits → raw string
    - Test long phone digits → extra appended
    - _Requirements: 2.2, 2.3, 2.4, 3.2, 3.3, 3.4, 3.5, 5.2, 5.3, 5.4_

- [x] 6. Frontend: Implement TranslationClient module
  - [x] 6.1 Create `public/assets/js/i18n/translationClient.js` with `init()`, `t(key)`, `applyTranslations(container)`, and `getFormatter()` functions
    - `init()`: fetch `/api/settings` via apiFetch, call `Formatter.configure(settings)`, fetch `/api/translations/{lang}`, store dictionary, apply direction to `<html>` dir attribute and `<body>` rtl class
    - `t(key)`: return dictionary[key] if exists, else return key
    - `applyTranslations(container)`: query `[data-i18n]` elements and set textContent; query `[data-i18n-placeholder]` elements and set placeholder attribute; leave unchanged if key not in dictionary
    - `getFormatter()`: expose formatter functions for use by page modules
    - On settings API error: use defaults (number "1,234.56", date "Y-m-d", currency "USD", phone "+1 (###) ###-####")
    - On translations API error: dictionary stays empty, t(key) returns key
    - Listen for `settings:changed` custom event to re-run init() and re-apply translations to document.body
    - _Requirements: 1.1, 1.4, 6.1, 6.2, 6.3, 6.4, 6.5, 7.2, 7.3, 7.4, 7.5, 8.1, 8.2, 8.3, 8.4, 9.2, 9.3, 9.4_

  - [x] 6.2 Write property test for translation lookup
    - **Property 6: Translation lookup returns value or key**
    - **Validates: Requirements 6.5**

  - [x] 6.3 Write property test for DOM translation application
    - **Property 7: DOM translation application**
    - **Validates: Requirements 7.2, 7.3, 7.4, 7.5**

  - [x] 6.4 Write unit tests for TranslationClient
    - Test API error → default settings applied
    - Test translation API error → fallback to English (key returned)
    - Test direction "RTL" → html dir="rtl", body class "rtl"
    - Test direction "LTR" → html dir="ltr", no "rtl" class
    - _Requirements: 1.4, 6.4, 8.2, 8.3, 8.4_

- [x] 7. Checkpoint - Frontend modules complete
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Integration: Wire TranslationClient into App Shell
  - [x] 8.1 Modify `public/assets/js/app.js` (appShell) to import and call `TranslationClient.init()` inside `activateAppShell()`
    - Call `await TranslationClient.init()` after authentication is confirmed but before page rendering
    - _Requirements: 1.1, 6.1, 8.1_

  - [x] 8.2 Modify page modules to call `TranslationClient.applyTranslations(container)` after rendering their HTML content
    - Update the page module pattern so that after `render()` populates the container, `applyTranslations()` is called on it
    - _Requirements: 7.1, 7.2, 7.3_

- [x] 9. Integration: Wire settings change propagation
  - [x] 9.1 Modify the Settings page module to dispatch `window.dispatchEvent(new CustomEvent('settings:changed'))` after a successful settings save
    - _Requirements: 9.1_

  - [x] 9.2 Ensure TranslationClient's `settings:changed` listener re-runs `init()` and calls `applyTranslations(document.body)` to refresh the visible page
    - _Requirements: 9.2, 9.3, 9.4_

- [x] 10. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- Backend is built first so frontend modules can be tested against real API responses
- The Formatter module is pure functions with no dependencies, making it the easiest to test in isolation
- The TranslationClient depends on Formatter and apiFetch, tested with mocks for the network layer
