# Bugfix Requirements Document

## Introduction

The regional settings feature (active-regional-settings) was recently implemented but has several polish issues that prevent it from delivering a professional ERP experience. Three distinct problems exist: (1) incomplete translations — many UI elements still show English text after switching languages because translation keys are missing from the seed data, (2) the settings page help/example text lost its colored styling and now appears entirely in gray (`text-muted`) instead of using distinct colors for examples/hints, and (3) all monetary amounts across the application use `formatNumber()` instead of `formatCurrency()`, meaning financial values display without the configured currency prefix (e.g., "1,234.56" instead of "USD 1,234.56"). Together these issues make the regional settings feel incomplete and unprofessional.

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN the language is changed to Arabic or French THEN the system leaves multiple UI elements untranslated in English because their translation keys are missing from `tbl_translations` — specifically: settings page help text keys (`settings.language_help`, `settings.timezone_help`, `settings.date_format_help`, `settings.number_format_help`, `settings.currency_format_help`, `settings.phone_format_help`), activity log filter/column keys (`activity_log.event_type`, `activity_log.from`, `activity_log.to`, `activity_log.apply_filters`, `activity_log.user`, `activity_log.table`, `activity_log.action`, `activity_log.record_id`, `activity_log.details`, `activity_log.old_values`, `activity_log.new_values`, `activity_log.all_types`), 404 page keys (`common.page_not_found`, `common.page_not_found_desc`, `common.back_to_default`), auth page keys (`auth.forgot_description`, `auth.reset_password_btn`, `auth.remember_login`, `auth.username_placeholder`), and user menu items (Profile, Settings, Activity Log, Logout) which are rendered without `data-i18n` attributes in `renderUserInfo()`

1.2 WHEN the settings page is rendered THEN the system displays all help/example text (e.g., "PHP date format string used to display dates. Example: Y-m-d → 2025-01-31") in gray (`text-muted`) instead of using a distinct color for the example portions, making the page look flat and lifeless

1.3 WHEN monetary amounts are displayed on the dashboard (revenue, expenses, net profit, account balances, transaction amounts) THEN the system formats them with `formatNumber()` which produces plain numbers like "1,234.56" without any currency prefix

1.4 WHEN monetary amounts are displayed on the payments page (amount_fc, amount_bc columns) THEN the system formats them with `formatNumber()` producing values without currency prefix

1.5 WHEN monetary amounts are displayed on the collections page (amount_fc, amount_bc columns) THEN the system formats them with `formatNumber()` producing values without currency prefix

1.6 WHEN monetary amounts are displayed on the expenses page (amount_fc, amount_bc columns) THEN the system formats them with `formatNumber()` producing values without currency prefix

1.7 WHEN monetary amounts are displayed on the salaries page (gross_amount_fc, tax_fc, net_amount_fc, net_amount_bc columns) THEN the system formats them with `formatNumber()` producing values without currency prefix

1.8 WHEN monetary amounts are displayed on the purchase orders page (total_amount_fc, amount_paid_fc, balance_fc columns) THEN the system formats them with `formatNumber()` producing values without currency prefix

1.9 WHEN monetary amounts are displayed on the sales invoices page (total_amount_fc, amount_paid_fc, balance_fc columns) THEN the system formats them with `formatNumber()` producing values without currency prefix

1.10 WHEN monetary amounts are displayed on the accounts page (balance_fc, balance columns, and total footer) THEN the system formats them with `formatNumber()` producing values without currency prefix

1.11 WHEN the settings page balance recalculation report is displayed (prev_balance, new_balance, prev_balance_fc, new_balance_fc) THEN the system formats them with `formatNumber()` producing values without currency prefix

### Expected Behavior (Correct)

2.1 WHEN the language is changed to Arabic or French THEN the system SHALL translate ALL static UI text elements including settings help text, activity log labels/columns, 404 page text, auth page text, and user menu items (Profile, Settings, Activity Log, Logout) — no English text shall remain visible for any translatable UI element across the entire application

2.1.1 WHEN settings are saved successfully and the language has changed THEN the system SHALL re-fetch translations for the new language and re-apply them to the entire visible page including the sidebar navigation, user menu, and current page content so that the UI immediately reflects the new language without requiring a page refresh

2.2 WHEN the settings page is rendered THEN the system SHALL display help/example text with the example portions (e.g., format patterns like "Y-m-d → 2025-01-31", "1,234.56 or 1.234,56", "+1 (###) ###-####") styled in a distinct color (such as `text-info` or `text-primary`) to visually differentiate them from the descriptive text, giving the page a professional and lively appearance

2.3 WHEN monetary amounts are displayed on the dashboard THEN the system SHALL format them using `formatCurrency()` which produces values with the configured currency prefix (e.g., "USD 1,234.56" or "$1,234.56")

2.4 WHEN monetary amounts are displayed on the payments page THEN the system SHALL format them using `formatCurrency()` to include the currency prefix

2.5 WHEN monetary amounts are displayed on the collections page THEN the system SHALL format them using `formatCurrency()` to include the currency prefix

2.6 WHEN monetary amounts are displayed on the expenses page THEN the system SHALL format them using `formatCurrency()` to include the currency prefix

2.7 WHEN monetary amounts are displayed on the salaries page THEN the system SHALL format them using `formatCurrency()` to include the currency prefix

2.8 WHEN monetary amounts are displayed on the purchase orders page THEN the system SHALL format them using `formatCurrency()` to include the currency prefix

2.9 WHEN monetary amounts are displayed on the sales invoices page THEN the system SHALL format them using `formatCurrency()` to include the currency prefix

2.10 WHEN monetary amounts are displayed on the accounts page THEN the system SHALL format them using `formatCurrency()` to include the currency prefix

2.11 WHEN the settings page balance recalculation report is displayed THEN the system SHALL format monetary values using `formatCurrency()` to include the currency prefix

### Unchanged Behavior (Regression Prevention)

3.1 WHEN the language is set to English (en) THEN the system SHALL CONTINUE TO display all UI text in English using the hardcoded fallback text (since no English translations are stored in `tbl_translations`)

3.2 WHEN non-monetary numeric values are displayed (e.g., stock quantities on the dashboard low-stock table, production quantities) THEN the system SHALL CONTINUE TO use `formatNumber()` without a currency prefix

3.3 WHEN the formatter is configured with regional settings THEN the system SHALL CONTINUE TO correctly parse number format patterns and apply thousands/decimal separators to both `formatNumber()` and `formatCurrency()` calls

3.4 WHEN translation keys exist in the dictionary THEN the system SHALL CONTINUE TO apply them via `applyTranslations()` using the `data-i18n` attribute mechanism

3.5 WHEN the settings page form fields are rendered THEN the system SHALL CONTINUE TO populate them with the current saved values from the API

3.6 WHEN the user saves settings THEN the system SHALL CONTINUE TO dispatch the `settings:changed` event and re-apply translations and formatting to the visible page
