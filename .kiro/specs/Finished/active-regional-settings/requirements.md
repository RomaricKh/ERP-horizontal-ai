# Requirements Document

## Introduction

This feature activates the regional settings that are already stored in the `settings_regional` table but currently unused. The system will apply the saved number_format, date_format, currency_format, and phone_format to all displayed data throughout the application. Additionally, the system will leverage the existing `tbl_translations` table to enable full UI translation when the language setting is changed, loading translated strings for the selected language and applying them across the interface. The feature also respects text direction (LTR/RTL) based on the selected language.

## Glossary

- **Formatter**: A frontend JavaScript module that provides functions to format numbers, dates, currencies, and phone numbers according to the active regional settings.
- **Translation_Service**: A backend PHP service that retrieves translation key-value pairs from `tbl_translations` for a given language code.
- **Translation_Client**: A frontend JavaScript module that loads translations from the API, caches them, and provides a lookup function `t(key)` to replace UI text with translated values.
- **Regional_Settings_API**: The existing `/api/settings` endpoint extended to also serve formatting configuration and translations to the frontend.
- **Translations_API**: A new API endpoint that returns all translation key-value pairs for a requested language.
- **Format_Descriptor**: A parsed representation of a format pattern (e.g., number_format "1.234,56" parsed into thousands_separator="." and decimal_separator=",").
- **App_Shell**: The main SPA shell (`appShell.js`) that initializes the application and renders the sidebar, menus, and page content.
- **Page_Module**: A JavaScript ES module following the render/mount/unmount pattern that controls a specific page in the SPA.
- **Direction**: The text direction (LTR or RTL) associated with a language, stored in the `languages` table.

## Requirements

### Requirement 1: Load Regional Settings on Application Initialization

**User Story:** As a user, I want the application to load my business's regional settings when I log in, so that all data is displayed in my preferred formats from the start.

#### Acceptance Criteria

1. WHEN the App_Shell is activated, THE Translation_Client SHALL fetch the regional settings from the Regional_Settings_API and store them in memory for use by the Formatter.
2. WHEN the regional settings are loaded, THE Formatter SHALL parse the number_format field into a thousands_separator and decimal_separator.
3. WHEN the regional settings are loaded, THE Formatter SHALL store the date_format, currency_format, and phone_format for use in formatting functions.
4. IF the Regional_Settings_API returns an error, THEN THE Translation_Client SHALL fall back to default formats (number: "1,234.56", date: "Y-m-d", currency: "USD", phone: "+1 (###) ###-####").

### Requirement 2: Number Formatting

**User Story:** As a user, I want all numbers displayed in the application to follow my configured number format, so that I can read financial and quantity data in my locale's convention.

#### Acceptance Criteria

1. WHEN a numeric value is displayed, THE Formatter SHALL format the value using the configured thousands_separator and decimal_separator from the active regional settings.
2. WHEN the number_format setting is "1.234,56", THE Formatter SHALL use "." as thousands separator and "," as decimal separator.
3. WHEN the number_format setting is "1,234.56", THE Formatter SHALL use "," as thousands separator and "." as decimal separator.
4. WHEN the number_format setting is "1 234,56", THE Formatter SHALL use " " (space) as thousands separator and "," as decimal separator.
5. THE Formatter SHALL preserve the original numeric precision (number of decimal places) of the input value.

### Requirement 3: Date Formatting

**User Story:** As a user, I want all dates displayed in the application to follow my configured date format, so that I can read dates in my preferred convention.

#### Acceptance Criteria

1. WHEN a date value is displayed, THE Formatter SHALL format the date string according to the configured date_format pattern.
2. WHEN the date_format is "d/m/Y", THE Formatter SHALL display dates as day/month/four-digit-year (e.g., "31/01/2025").
3. WHEN the date_format is "Y-m-d", THE Formatter SHALL display dates as four-digit-year-month-day (e.g., "2025-01-31").
4. WHEN the date_format is "m/d/Y", THE Formatter SHALL display dates as month/day/four-digit-year (e.g., "01/31/2025").
5. IF an invalid or empty date value is provided, THEN THE Formatter SHALL return an empty string.

### Requirement 4: Currency Formatting

**User Story:** As a user, I want currency values to display with my configured currency symbol or code, so that financial data is clear and consistent.

#### Acceptance Criteria

1. WHEN a currency value is displayed, THE Formatter SHALL prepend or append the configured currency_format code/symbol to the number-formatted value.
2. THE Formatter SHALL apply the number_format rules to the numeric portion of the currency value.
3. WHEN the currency_format is a code (e.g., "USD", "EUR"), THE Formatter SHALL display it as a prefix followed by a space (e.g., "USD 1,234.56").
4. WHEN the currency_format is a symbol (e.g., "$", "€"), THE Formatter SHALL display it as a prefix without a space (e.g., "$1,234.56").

### Requirement 5: Phone Number Formatting

**User Story:** As a user, I want phone numbers to be displayed according to my configured phone format, so that contact information is readable in my local convention.

#### Acceptance Criteria

1. WHEN a phone number is displayed, THE Formatter SHALL apply the configured phone_format template, replacing each "#" placeholder with the corresponding digit from the raw phone number.
2. WHEN the raw phone number has fewer digits than the number of "#" placeholders in the phone_format, THE Formatter SHALL return the raw phone number unformatted.
3. WHEN the raw phone number has more digits than the number of "#" placeholders, THE Formatter SHALL append the remaining digits at the end of the formatted string.
4. IF the phone number is empty or null, THEN THE Formatter SHALL return an empty string.

### Requirement 6: Translation Loading

**User Story:** As a user, I want the UI to display text in my configured language, so that I can use the application in my preferred language.

#### Acceptance Criteria

1. WHEN the App_Shell is activated, THE Translation_Client SHALL fetch translations for the configured language from the Translations_API.
2. THE Translations_API SHALL return all key-value pairs from `tbl_translations` where the `lang` column matches the requested language code.
3. WHEN translations are loaded, THE Translation_Client SHALL store them in a local dictionary (key → value map) for lookup.
4. IF the Translations_API returns an error or no translations exist for the language, THEN THE Translation_Client SHALL use the original hardcoded English text as fallback.
5. THE Translation_Client SHALL expose a function `t(key)` that returns the translated value for the given key, or the key itself if no translation is found.

### Requirement 7: Apply Translations to the UI

**User Story:** As a user, I want all UI labels, buttons, and messages to appear in my selected language, so that the entire interface is localized.

#### Acceptance Criteria

1. WHEN a page is rendered, THE Page_Module SHALL call the Translation_Client `t(key)` function to resolve text for all translatable elements.
2. THE Translation_Client SHALL identify translatable elements using a `data-i18n` attribute on HTML elements, where the attribute value is the translation key.
3. WHEN the Translation_Client applies translations, THE Translation_Client SHALL set the `textContent` of each element with a `data-i18n` attribute to the resolved translation value.
4. WHEN the Translation_Client applies translations to elements with a `data-i18n-placeholder` attribute, THE Translation_Client SHALL set the `placeholder` attribute to the resolved translation value.
5. WHEN a translation key is not found in the loaded dictionary, THE Translation_Client SHALL leave the element's existing text unchanged.

### Requirement 8: Text Direction Support

**User Story:** As a user who reads RTL languages, I want the interface layout to flip to right-to-left when I select an RTL language, so that the UI is natural to read.

#### Acceptance Criteria

1. WHEN the regional settings are loaded, THE Translation_Client SHALL fetch the direction (LTR or RTL) for the configured language from the languages data.
2. WHEN the direction is "RTL", THE App_Shell SHALL set the `dir` attribute on the `<html>` element to "rtl".
3. WHEN the direction is "LTR", THE App_Shell SHALL set the `dir` attribute on the `<html>` element to "ltr".
4. WHEN the direction changes, THE App_Shell SHALL add a CSS class "rtl" to the `<body>` element for RTL or remove it for LTR, enabling direction-aware styling.

### Requirement 9: Settings Change Propagation

**User Story:** As a user, I want the application to immediately reflect my new regional settings after I save them, so that I do not need to refresh the page.

#### Acceptance Criteria

1. WHEN the user saves regional settings successfully, THE Settings Page_Module SHALL trigger a reload of the regional settings and translations in the Translation_Client.
2. WHEN the Translation_Client reloads settings, THE Translation_Client SHALL re-apply formatting rules and translations to the currently visible page.
3. WHEN the language setting changes, THE Translation_Client SHALL fetch new translations for the updated language and re-apply them to all translatable elements on the current page.
4. WHEN the language direction changes after a settings save, THE App_Shell SHALL update the `dir` attribute and body CSS class immediately.

### Requirement 10: Translations API Endpoint

**User Story:** As a frontend developer, I want a dedicated API endpoint to fetch translations for a given language, so that the frontend can load localized strings efficiently.

#### Acceptance Criteria

1. THE Translations_API SHALL expose a GET endpoint at `/api/translations/{lang}` that accepts a language code as a path parameter.
2. WHEN a valid language code is provided, THE Translations_API SHALL return a JSON object with all key-value pairs from `tbl_translations` for that language.
3. IF the language code does not exist in the `languages` table, THEN THE Translations_API SHALL return a 404 response with an appropriate error message.
4. THE Translations_API SHALL require authentication (valid JWT token) before returning translations.
5. THE Translations_API SHALL return the response in the format `{ "success": true, "data": { "key1": "value1", "key2": "value2", ... } }`.

### Requirement 11: Regional Settings API Enhancement

**User Story:** As a frontend developer, I want the settings API to also return the language direction, so that the frontend can determine text direction without a separate API call.

#### Acceptance Criteria

1. WHEN the Regional_Settings_API returns settings, THE Regional_Settings_API SHALL include a `direction` field containing the direction value ("LTR" or "RTL") from the `languages` table for the configured language.
2. THE Regional_Settings_API SHALL join the `languages` table to retrieve the direction when returning settings.
