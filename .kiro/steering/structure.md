# Project Structure

```
ERP-horizontal/
├── bootstrap.php              # App bootstrap (autoload, config, DB connect, session)
├── composer.json              # PHP dependencies & PSR-4 autoload
├── package.json               # JS dev dependencies (vitest, fast-check)
├── phpunit.xml                # PHPUnit configuration
├── .env.example               # Environment variable template (committed)
├── .env                       # Local secrets (gitignored)
│
├── src/                       # Backend PHP source (PSR-4: App\)
│   ├── Config/                # Database connection, app config (.env loader)
│   ├── Controllers/           # Request handlers (extend BaseController)
│   ├── Middleware/            # AuthMiddleware, ModuleGateMiddleware
│   ├── Repository/            # Data access layer (raw PDO queries)
│   ├── Services/              # Business logic layer
│   ├── Templates/             # PDF HTML templates (InvoicePdf, PurchaseOrderPdf)
│   └── Support/               # Utilities (Router, AuthContext)
│
├── public/                    # Web root (Apache DocumentRoot)
│   ├── index.html             # SPA entry point
│   ├── api/
│   │   ├── index.php          # API entry point (creates Router, includes routes)
│   │   ├── cron/              # Cron-accessible endpoints (generate-recurrences.php)
│   │   └── routes/            # Route definitions (one file per module)
│   ├── html/                  # HTML page templates (one folder per module)
│   └── assets/
│       ├── js/
│       │   ├── app.js         # SPA bootstrap
│       │   ├── Auth/          # Authentication utilities (apiFetch, auth, reauth)
│       │   ├── components/    # Shared UI components (modals, loading, notifications)
│       │   ├── i18n/          # Internationalization (formatter, translationClient)
│       │   ├── pages/         # Page scripts (one folder per module)
│       │   ├── router/        # Client-side SPA router
│       │   ├── services/      # Frontend services (modules, polling)
│       │   ├── Shells/        # App shell layout management
│       │   ├── templates/     # SB Admin 2 template scripts (minified only)
│       │   └── utils/         # Shared JS utilities
│       ├── css/               # Stylesheets (sb-admin-2.min.css + main.css)
│       ├── scss/              # SCSS source
│       └── vendor/            # Frontend vendor libs (Bootstrap, DataTables, FontAwesome)
│
├── database/
│   ├── schema.sql             # Complete CREATE TABLE script (target state, for fresh installs)
│   ├── seed_system_data.sql   # System types, modules, permissions, languages
│   ├── README.md              # Deployment instructions
│   └── migrations/            # Incremental SQL migration scripts (for existing deployments)
│
├── docs/
│   ├── erp-documentation.html # Full system documentation (HTML)
│   ├── erd-current-state.md   # ERD of the database before spec execution
│   ├── erd-target-state.md    # ERD of the target database (detailed text)
│   ├── erd-target-mermaid.md  # ERD in Mermaid format (visual)
│   └── cron-setup.md          # Cron job setup instructions
│
└── tests/
    ├── Unit/                  # PHP unit tests
    ├── Property/              # PHP property-based tests
    ├── Integration/           # PHP integration tests
    ├── frontend/              # JS frontend unit tests
    └── {feature}/             # JS property tests organized by feature
```

## Architecture Pattern

**Controller → Service → Repository** (3-layer)

1. **Route file** (`public/api/routes/*.php`) — registers endpoints on the Router with permission requirements
2. **Controller** — validates input, calls service, returns JSON via `$this->json()`
3. **Service** — orchestrates business logic, calls one or more repositories
4. **Repository** — executes PDO prepared statements, returns raw data

## Settlement Architecture

Payments and collections use an **allocation-based** system:

1. **Payment/Collection slip** = the cash movement (header: amount, date, account, payee)
2. **Allocation rows** = how that cash is distributed across documents (detail: reference_type, reference_id, amount)
3. **FIFO auto-allocation** = system distributes amount across unpaid documents oldest-first by default
4. **Manual allocation** = user can override and pick specific documents + amounts
5. **Settlement status** = determined by SUM(allocations) vs document total (not payee balance)

Tables: `payment_slips` → `payment_allocations`, `collection_slips` → `collection_allocations`

## System Types Pattern

All classification tables follow a unified pattern:

- `is_system = 1, tenant_id = NULL` → immutable system-defined types (seeded on install)
- `is_system = 0, tenant_id = X` → tenant-defined types (freely editable)
- `module_code` → if set, type is only visible when that module is active for the business
- Applied to: `item_types`, `payee_types`, `expense_categories`, `asset_categories`, `service_categories`

## Modal Architecture

Modals use the `openModal()` system from `components/modals.js`:

1. **Page JS** calls `openModal({ scope, template, controller, data, options })`
2. **Modal HTML template** (separate file in `html/Module/`) starts at `<div class="modal-dialog">` — no outer `.modal.fade` wrapper
3. **Modal controller JS** (separate file in `pages/Module/`) exports `mount({ modalRoot, data })` and `unmount()`
4. The system dynamically creates the outer `.modal.fade` wrapper, fetches the HTML, imports the controller, and manages lifecycle
5. Modal controllers use `registerPageActions()` for button handlers and dispatch custom events (e.g., `document.dispatchEvent(new CustomEvent('module.updated'))`) to notify the parent page
6. Parent pages listen for these events to refresh data, and call `closeModalsInContainer(container)` in `unmount()`

**Key modal utilities:**
- `openModal()` — opens a modal with external template + controller
- `confirm()` — promise-based confirmation dialog
- `alert()` — promise-based alert dialog
- `closeModalsInContainer()` — cleanup on page unmount
- `MODAL_SCOPE.PAGE` / `MODAL_SCOPE.GLOBAL` — determines where the modal is appended

## Conventions

- One route file per module in `public/api/routes/`
- Controllers instantiate their own service/repository dependencies (no DI container)
- All API responses use the shape: `{ success: bool, data: ?array, message: ?string }`
- Route permissions are declared inline: `$router->get('/path', $handler, ['permission.name'])`
- `{id}` in route paths captures a numeric parameter
- Tenant/business scoping is enforced in repositories via `AuthContext::tenantId()` / `AuthContext::businessId()`
- SQL migrations are plain `.sql` files applied manually; `database/schema.sql` is the authoritative source for fresh installs
- Frontend pages follow the pattern: HTML template in `public/html/Module/` + JS in `public/assets/js/pages/Module/`
- Each page module has both `index.js`/`index.html` and a named variant (e.g., `assets.js`/`assets.html`) — the router loads the named variant, the index variant is a fallback
- Modal forms are always in separate files: `html/Module/formName.html` + `pages/Module/formName.modal.js`
- Delete confirmations use the built-in `confirm()` utility, not custom modals
- System types (`is_system = 1`) are never editable or deletable via the API — enforced at the service layer
- Multi-currency: each transaction stores its own `exchange_rate` snapshot; the `exchange_rates` table provides auto-fill defaults only
- Partner equity operations always create a corresponding collection/payment slip (cash movement is always recorded)
