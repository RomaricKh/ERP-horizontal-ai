# Tech Stack

## Backend
- **Language:** PHP 8+ (no framework — custom lightweight architecture)
- **Database:** MySQL via PDO (utf8mb4 charset)
- **Auth:** JWT (firebase/php-jwt) with Bearer token + session fallback
- **PDF Generation:** DOMPDF (server-side HTML → PDF)
- **Email:** PHPMailer (transactional emails, password reset)
- **Server:** Apache with mod_rewrite (WAMP stack)
- **Autoloading:** Composer PSR-4 (`App\` → `src/`)

## Frontend
- **Approach:** Vanilla JavaScript SPA (no build step, no framework)
- **UI Template:** SB Admin 2 (Bootstrap 5)
- **Routing:** Custom client-side router (`public/assets/js/router/`)
- **Modals:** External template + controller pattern via `openModal()` system
- **i18n:** Custom translation client + formatter
- **Pages:** Each module has HTML templates + JS page scripts + modal controllers

## Testing
- **PHP Unit/Integration/Property tests:** PHPUnit 9.6
- **JS Property/Unit tests:** Vitest 3.2 + fast-check 4.1 + jsdom
- Property-based testing is a core practice in this project

## Key Libraries
| Library | Purpose |
|---------|---------|
| firebase/php-jwt | JWT encode/decode |
| dompdf/dompdf | PDF generation from HTML |
| phpmailer/phpmailer | SMTP email sending |
| phpunit/phpunit | PHP testing |
| vitest | JS test runner |
| fast-check | JS property-based testing |
| jsdom | DOM simulation for JS tests |

## Frontend Vendor Libraries (in `public/assets/vendor/`)
| Library | Purpose |
|---------|---------|
| Bootstrap 5 | CSS framework + JS components |
| DataTables | Table rendering with sorting/search/pagination |
| FontAwesome Free | Icon library |

## Common Commands

```bash
# PHP tests (all suites)
vendor/bin/phpunit

# PHP tests by suite
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Property
vendor/bin/phpunit --testsuite Integration

# JS tests
npx vitest --run

# JS tests (specific file)
npx vitest --run tests/path/to/file.test.js

# Composer autoload regeneration
composer dump-autoload
```

## Environment
- Runs on WAMP (Windows + Apache + MySQL + PHP)
- Entry point: `public/api/index.php` (API), `public/index.html` (SPA)
- Config: `src/Config/config.php` loads from `.env` file with fallback defaults
- `.env.example` committed with placeholder values; `.env` is gitignored
- `.htaccess` rewrites route all API requests through `public/api/index.php`

## Database Access
- **Host:** localhost
- **Database:** `erp`
- **User:** `root` (no password)
- **MySQL binary:** `C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe`
- **CLI command:** `& "C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe" -u root erp -e "YOUR SQL HERE"`
- Always use the full path above when running MySQL queries from the command line in this project.

## Database Deployment
- **Fresh install:** `database/schema.sql` (full schema) + `database/seed_system_data.sql` (system types, modules, permissions)
- **Existing upgrades:** `database/migrations/*.sql` (incremental, applied manually)
- **Target ERD:** `docs/erd-target-state.md` (detailed) and `docs/erd-target-mermaid.md` (visual)

## Key Architectural Patterns
- **Settlement:** Allocation-based (payment_allocations / collection_allocations) with FIFO auto-distribution
- **Multi-currency:** Each transaction stores its own exchange_rate snapshot; `exchange_rates` table provides defaults for auto-fill
- **System types:** Classification tables (item_types, payee_types, expense_categories, etc.) use `is_system` + `module_code` for immutable system-defined types with module visibility gating
- **Business types:** Tenant-owned copies of system templates; businesses only assigned to tenant-owned types
- **Profit calculation:** Accrual-basis (PAID invoices = revenue, not collection sums)
