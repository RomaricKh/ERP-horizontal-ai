# Requirements Document

## Introduction

This feature integrates four existing but unused database tables — `assets`, `business_profit`, `partner_equity`, and `partner_shares` — into the ERP system. These tables already exist in the schema and are referenced by payment/collection slip reference types (`PARTNER_PROFIT`, `PARTNER_CONTRIBUTION`), but no backend services, API routes, or frontend pages currently manage them. The goal is to build complete CRUD modules for asset management, profit calculation and distribution, partner share tracking, and partner equity recording, following the established MVC architecture (Controller → Service → Repository) with audit logging, permission-based access, and multi-currency support.

## Glossary

- **Asset_Module**: The backend subsystem (controller, service, repository) and frontend page responsible for managing business assets.
- **Profit_Module**: The backend subsystem and frontend page responsible for calculating, reviewing, and distributing business profits to partners.
- **Equity_Module**: The backend subsystem and frontend page responsible for recording partner equity contributions and withdrawals.
- **Shares_Module**: The backend subsystem and frontend page responsible for managing partner ownership percentages and invested capital.
- **Partner**: A payee with a partner share record, representing a business co-owner entitled to profit distribution.
- **Business_Currency**: The base currency configured for a business, used for all `_bc` (base currency) calculations.
- **Depreciation**: The systematic reduction of an asset's recorded value over its useful life.
- **Distributable_Profit**: The net profit remaining after deducting the safety net amount, available for distribution to partners.
- **Safety_Net**: A reserved portion of profit retained by the business and not distributed to partners.

## Requirements

### Requirement 1: Asset Management CRUD

**User Story:** As a business owner, I want to register, view, update, and delete business assets, so that I can track what the business owns and their current values.

#### Acceptance Criteria

1. WHEN a user submits a valid asset creation request, THE Asset_Module SHALL insert a new record into the `assets` table with the provided name, category, asset_type, purchase_date, purchase_value, currency, exchange_rate, depreciation_method, useful_life, and notes, scoped to the current business.
2. THE Asset_Module SHALL calculate and store `value_bc` as `purchase_value * exchange_rate` upon creation.
3. THE Asset_Module SHALL set `current_value_bc` equal to `value_bc` upon creation (no depreciation yet accumulated).
4. WHEN a user requests the asset list, THE Asset_Module SHALL return all assets for the current business ordered by purchase_date descending.
5. WHEN a user requests a single asset by ID, THE Asset_Module SHALL return the asset only if it belongs to the current business.
6. WHEN a user submits a valid asset update request, THE Asset_Module SHALL update the allowed fields (name, category, asset_type, depreciation_method, useful_life, notes) for the specified asset.
7. THE Asset_Module SHALL prevent modification of purchase_value, currency, and exchange_rate after creation.
8. WHEN a user requests deletion of an asset, THE Asset_Module SHALL remove the asset record from the database.
9. THE Asset_Module SHALL log all create, update, and delete operations via the AuditService.

### Requirement 2: Asset Depreciation Calculation

**User Story:** As a business owner, I want the system to calculate asset depreciation, so that I can see the current book value of my assets.

#### Acceptance Criteria

1. WHEN a user triggers depreciation calculation for an asset, THE Asset_Module SHALL calculate depreciation based on the asset's depreciation_method.
2. WHILE the depreciation_method is STRAIGHT_LINE, THE Asset_Module SHALL calculate annual depreciation as `purchase_value / useful_life`.
3. WHILE the depreciation_method is DECLINING_BALANCE, THE Asset_Module SHALL calculate annual depreciation as `current_value_bc * (2 / useful_life)`.
4. THE Asset_Module SHALL update `accumulated_depreciation` by adding the calculated depreciation amount.
5. THE Asset_Module SHALL update `current_value_bc` as `value_bc - accumulated_depreciation`.
6. IF accumulated_depreciation would exceed value_bc, THEN THE Asset_Module SHALL cap accumulated_depreciation at value_bc and set current_value_bc to zero.
7. THE Asset_Module SHALL not apply depreciation to assets with asset_type CURRENT.

### Requirement 3: Asset Filtering and Summary

**User Story:** As a business owner, I want to filter assets by type and category and see a summary of total asset values, so that I can get a quick financial overview.

#### Acceptance Criteria

1. WHEN the user specifies an asset_type filter, THE Asset_Module SHALL return only assets matching that type (FIXED, CURRENT, or INTANGIBLE).
2. WHEN the user specifies a category filter, THE Asset_Module SHALL return only assets matching that category.
3. WHEN the user requests the asset list, THE Asset_Module SHALL include a summary object containing total_purchase_value_bc, total_current_value_bc, and total_accumulated_depreciation for the filtered result set.

### Requirement 4: Partner Shares Management

**User Story:** As a business owner, I want to define and manage partner ownership percentages, so that profits can be distributed proportionally.

#### Acceptance Criteria

1. WHEN a user creates a partner share record, THE Shares_Module SHALL store the partner_id, business_id, share_percent (ownership_pct), and invested_capital.
2. THE Shares_Module SHALL validate that the partner_id references a valid payee within the current business.
3. THE Shares_Module SHALL validate that the total of all share_percent values for a business does not exceed 100.00.
4. IF adding or updating a share would cause the total to exceed 100.00, THEN THE Shares_Module SHALL reject the request with a descriptive error message.
5. WHEN a user requests the partner shares list, THE Shares_Module SHALL return all share records for the current business with the partner name included.
6. WHEN a user updates a partner share record, THE Shares_Module SHALL allow modification of share_percent and invested_capital.
7. WHEN a user deletes a partner share record, THE Shares_Module SHALL remove the record from the database.
8. THE Shares_Module SHALL log all create, update, and delete operations via the AuditService.

### Requirement 5: Partner Equity Tracking

**User Story:** As a business owner, I want to record partner capital contributions and withdrawals, so that I can track each partner's equity position in the business.

#### Acceptance Criteria

1. WHEN a user records a partner equity entry, THE Equity_Module SHALL store the partner_id, business_id, equity_type (CONTRIBUTION or WITHDRAWAL), amount, reference_id, and notes.
2. THE Equity_Module SHALL validate that the partner_id references a valid payee with an active partner share in the current business.
3. WHEN equity_type is CONTRIBUTION, THE Equity_Module SHALL add the amount to the partner's invested_capital in the partner_shares table.
4. WHEN equity_type is WITHDRAWAL, THE Equity_Module SHALL subtract the amount from the partner's invested_capital in the partner_shares table.
5. IF a WITHDRAWAL would reduce invested_capital below zero, THEN THE Equity_Module SHALL reject the request with a descriptive error message.
6. WHEN a user requests the equity list, THE Equity_Module SHALL return all equity entries for the current business ordered by created_at descending, with partner name included.
7. WHEN a user requests equity entries filtered by partner_id, THE Equity_Module SHALL return only entries for that partner.
8. THE Equity_Module SHALL log all create operations via the AuditService.
9. THE Equity_Module SHALL link CONTRIBUTION entries to collection slips via reference_id when a collection slip of type PARTNER_CONTRIBUTION is created.

### Requirement 6: Business Profit Calculation

**User Story:** As a business owner, I want to calculate the business profit for a given period, so that I know how much is available for distribution.

#### Acceptance Criteria

1. WHEN a user initiates profit calculation for a period (period_start to period_end), THE Profit_Module SHALL compute total_revenue from collection slips within that period for the current business.
2. THE Profit_Module SHALL compute total_expenses from expenses and payment slips (excluding PARTNER_PROFIT payments) within that period for the current business.
3. THE Profit_Module SHALL calculate net_profit as total_revenue minus total_expenses.
4. THE Profit_Module SHALL calculate safety_net_amount based on the configured safety_net_percent applied to net_profit.
5. THE Profit_Module SHALL calculate distributable_profit as net_profit minus safety_net_amount.
6. THE Profit_Module SHALL store the profit record with status DRAFT.
7. IF a profit record already exists for an overlapping period in the same business, THEN THE Profit_Module SHALL reject the request with a descriptive error message.
8. THE Profit_Module SHALL log the create operation via the AuditService.

### Requirement 7: Profit Distribution to Partners

**User Story:** As a business owner, I want to distribute profits to partners based on their ownership percentages, so that each partner receives their fair share.

#### Acceptance Criteria

1. WHEN a user finalizes a DRAFT profit record, THE Profit_Module SHALL change the status to FINALIZED and prevent further edits to revenue, expenses, or safety net values.
2. WHEN a user triggers distribution on a FINALIZED profit record, THE Profit_Module SHALL calculate each partner's share as `distributable_profit * (partner_ownership_pct / 100)`.
3. THE Profit_Module SHALL change the profit record status to DISTRIBUTED after successful distribution.
4. IF the profit record status is not FINALIZED, THEN THE Profit_Module SHALL reject the distribution request.
5. IF no partner shares are configured for the business, THEN THE Profit_Module SHALL reject the distribution request with a descriptive error message.
6. THE Profit_Module SHALL create payment slip records with reference_type PARTNER_PROFIT for each partner's distribution amount.
7. THE Profit_Module SHALL log the finalize and distribute operations via the AuditService.

### Requirement 8: Profit Record Management

**User Story:** As a business owner, I want to view and manage profit records, so that I can review historical profit calculations and their distribution status.

#### Acceptance Criteria

1. WHEN a user requests the profit list, THE Profit_Module SHALL return all profit records for the current business ordered by period_start descending.
2. WHEN a user requests a single profit record, THE Profit_Module SHALL return the record with its full details including computed partner distribution amounts if status is DISTRIBUTED.
3. WHILE the profit record status is DRAFT, THE Profit_Module SHALL allow the user to update safety_net_percent and recalculate distributable_profit.
4. WHILE the profit record status is DRAFT, THE Profit_Module SHALL allow the user to delete the record.
5. IF the user attempts to delete a FINALIZED or DISTRIBUTED profit record, THEN THE Profit_Module SHALL reject the request.

### Requirement 9: Permission-Based Access Control

**User Story:** As a system administrator, I want each financial module to be protected by specific permissions, so that only authorized users can access sensitive financial data.

#### Acceptance Criteria

1. THE Asset_Module SHALL require the "assets.manage" permission for all asset endpoints.
2. THE Shares_Module SHALL require the "partners.manage" permission for all partner share endpoints.
3. THE Equity_Module SHALL require the "partners.manage" permission for all partner equity endpoints.
4. THE Profit_Module SHALL require the "profit.manage" permission for all profit endpoints.
5. IF a user without the required permission attempts to access a protected endpoint, THEN the system SHALL return a 403 Forbidden response.
6. THE system SHALL add the new permissions (assets.manage, partners.manage, profit.manage) to the permissions table and assign them to the Business_Owner role.

### Requirement 10: Multi-Currency Support for Assets

**User Story:** As a business owner operating in multiple currencies, I want assets to support foreign currency values with exchange rate conversion, so that all asset values are consistently reported in the business currency.

#### Acceptance Criteria

1. WHEN creating an asset, THE Asset_Module SHALL accept a currency and exchange_rate.
2. THE Asset_Module SHALL calculate value_bc as purchase_value multiplied by exchange_rate.
3. THE Asset_Module SHALL store both the original purchase_value (in foreign currency) and value_bc (in business currency).
4. WHEN displaying assets, THE Asset_Module SHALL show both the original currency amount and the business currency equivalent.
