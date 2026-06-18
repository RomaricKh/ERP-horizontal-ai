// assets/js/Shells/appShell.config.js
export const MENU_CONFIG = [
    // Tenant Owner section (visible only to Tenant Owners)
    {
        type: 'group',
        id: 'tenantOwnerGroup',
        label: 'Tenant Management',
        i18nKey: 'nav.tenant_management',
        icon: 'fas fa-fw fa-building',
        tenantOwner: true,
        children: [
            { id: 'goToTenantTenants',        label: 'Tenants',           i18nKey: 'nav.tenant_tenants',         icon: 'fas fa-fw fa-building',       hash: '#/tenant/tenants',         tenantOwner: true },
            { id: 'goToTenantBusinesses',     label: 'Businesses',        i18nKey: 'nav.tenant_businesses',      icon: 'fas fa-fw fa-store',          hash: '#/tenant/businesses',      tenantOwner: true },
            { id: 'goToTenantBusinessTypes',  label: 'Business Types',    i18nKey: 'nav.tenant_business_types',  icon: 'fas fa-fw fa-tags',           hash: '#/tenant/business-types',  tenantOwner: true },
            { id: 'goToTenantOwners',         label: 'Owners',            i18nKey: 'nav.tenant_owners',          icon: 'fas fa-fw fa-user-shield',    hash: '#/tenant/owners',          tenantOwner: true },
            { id: 'goToTenantUsers',          label: 'Users',             i18nKey: 'nav.tenant_users',           icon: 'fas fa-fw fa-users',          hash: '#/tenant/users',           tenantOwner: true },
            { id: 'goToTenantReports',        label: 'Reports',           i18nKey: 'nav.tenant_reports',         icon: 'fas fa-fw fa-file-alt',       hash: '#/tenant/reports',         tenantOwner: true }
        ]
    },
    // Dashboard
    {
        type: 'item',
        id: 'goToDashboard',
        label: 'Dashboard',
        i18nKey: 'nav.dashboard',
        icon: 'fas fa-fw fa-tachometer-alt',
        hash: '#/dashboard',
        permission: 'dashboard.view',
        module: 'dashboard'
    },
    // Sales (revenue-generating)
    {
        type: 'group',
        id: 'salesGroup',
        label: 'Sales',
        i18nKey: 'nav.sales',
        icon: 'fas fa-fw fa-file-invoice-dollar',
        children: [
            { id: 'goToInvoices',     label: 'Invoices',    i18nKey: 'nav.invoices',    icon: 'fas fa-fw fa-file-invoice',     hash: '#/invoices',     permission: 'invoices.view',    module: 'sales_invoices' },
            { id: 'goToCollections',  label: 'Collections', i18nKey: 'nav.collections', icon: 'fas fa-fw fa-hand-holding-usd', hash: '#/collections',  permission: 'collections.view', module: 'collections' }
        ]
    },
    // Purchasing (cost-generating)
    {
        type: 'group',
        id: 'purchasingGroup',
        label: 'Purchasing',
        i18nKey: 'nav.purchasing',
        icon: 'fas fa-fw fa-shopping-cart',
        children: [
            { id: 'goToPurchaseOrders', label: 'Purchase Orders', i18nKey: 'nav.purchase_orders', icon: 'fas fa-fw fa-shopping-cart', hash: '#/purchase-orders', permission: 'po.view',       module: 'purchase_orders' },
            { id: 'goToPayments',       label: 'Payments',        i18nKey: 'nav.payments',        icon: 'fas fa-fw fa-money-bill',    hash: '#/payments',        permission: 'payments.view', module: 'payments' }
        ]
    },
    // Expenses & Payroll
    {
        type: 'group',
        id: 'expensesGroup',
        label: 'Expenses & Payroll',
        i18nKey: 'nav.expenses_payroll',
        icon: 'fas fa-fw fa-receipt',
        children: [
            { id: 'goToExpenses',          label: 'Expenses',           i18nKey: 'nav.expenses',           icon: 'fas fa-fw fa-receipt',   hash: '#/expenses',           permission: 'expenses.view', module: 'expenses' },
            { id: 'goToRecurringExpenses', label: 'Recurring Expenses', i18nKey: 'nav.recurring_expenses', icon: 'fas fa-fw fa-redo',      hash: '#/expenses/recurring', permission: 'expenses.view', module: 'expenses' },
            { id: 'goToSalaries',          label: 'Salaries',           i18nKey: 'nav.salaries',           icon: 'fas fa-fw fa-user-tie',  hash: '#/salaries',           permission: 'salaries.view', module: 'salaries' }
        ]
    },
    // Inventory & Services
    {
        type: 'group',
        id: 'inventoryGroup',
        label: 'Inventory',
        i18nKey: 'nav.inventory',
        icon: 'fas fa-fw fa-boxes',
        children: [
            { id: 'goToInventory',        label: 'Items',             i18nKey: 'nav.items',             icon: 'fas fa-fw fa-boxes',          hash: '#/items',             permission: 'items.view',           module: 'items' },
            { id: 'goToProductionOrders', label: 'Production Orders', i18nKey: 'nav.production_orders', icon: 'fas fa-fw fa-industry',       hash: '#/production-orders', permission: 'manufacturing.view',   module: 'manufacturing' },
            { id: 'goToServices',         label: 'Services',          i18nKey: 'nav.services',          icon: 'fas fa-fw fa-concierge-bell', hash: '#/services',          permission: 'services.view',        module: 'services' }
        ]
    },
    // Finance (accounts, capital, rates)
    {
        type: 'group',
        id: 'financeGroup',
        label: 'Finance',
        i18nKey: 'nav.finance',
        icon: 'fas fa-fw fa-university',
        children: [
            { id: 'goToAccounts',       label: 'Accounts',        i18nKey: 'nav.accounts',        icon: 'fas fa-fw fa-university',   hash: '#/accounts',       permission: 'accounts.manage',       module: 'accounts' },
            { id: 'goToExchangeRates',  label: 'Exchange Rates',  i18nKey: 'nav.exchange_rates',  icon: 'fas fa-fw fa-exchange-alt',  hash: '#/exchange-rates', permission: 'exchange-rates.manage', module: 'exchange_rates' },
            { id: 'goToAssets',         label: 'Assets',          i18nKey: 'nav.assets',          icon: 'fas fa-fw fa-landmark',      hash: '#/assets',         permission: 'assets.manage',         module: 'assets' },
            { id: 'goToPartnerShares',  label: 'Partner Shares',  i18nKey: 'nav.partner_shares',  icon: 'fas fa-fw fa-chart-pie',     hash: '#/partner-shares', permission: 'partners.manage',       module: 'partners' },
            { id: 'goToPartnerEquity',  label: 'Partner Equity',  i18nKey: 'nav.partner_equity',  icon: 'fas fa-fw fa-handshake',     hash: '#/partner-equity', permission: 'partners.manage',       module: 'partners' },
            { id: 'goToBusinessProfit', label: 'Business Profit', i18nKey: 'nav.business_profit', icon: 'fas fa-fw fa-coins',         hash: '#/business-profit', permission: 'partners.manage',      module: 'partners' }
        ]
    },
    // Reports (analytical)
    {
        type: 'group',
        id: 'reportsGroup',
        label: 'Reports',
        i18nKey: 'nav.reports',
        icon: 'fas fa-fw fa-chart-bar',
        children: [
            { id: 'goToProfitLoss',   label: 'Profit & Loss',  i18nKey: 'nav.profit_loss',   icon: 'fas fa-fw fa-file-invoice-dollar', hash: '#/profit-loss',   permission: 'reports.view' },
            { id: 'goToCashFlow',     label: 'Cash Flow',      i18nKey: 'nav.cash_flow',     icon: 'fas fa-fw fa-water',               hash: '#/cash-flow',     permission: 'reports.view' },
            { id: 'goToBalanceSheet', label: 'Balance Sheet',  i18nKey: 'nav.balance_sheet', icon: 'fas fa-fw fa-balance-scale',       hash: '#/balance-sheet', permission: 'reports.view' },
            { id: 'goToArAging',      label: 'AR Aging',       i18nKey: 'nav.ar_aging',      icon: 'fas fa-fw fa-clock',               hash: '#/ar-aging',      permission: 'invoices.view', module: 'sales_invoices' }
        ]
    },
    // Contacts
    {
        type: 'item',
        id: 'goToPeople',
        label: 'People',
        i18nKey: 'nav.people',
        icon: 'fas fa-fw fa-address-book',
        hash: '#/people',
        permission: 'people.view',
        module: 'people'
    },
    // Administration
    {
        type: 'group',
        id: 'adminGroup',
        label: 'Administration',
        i18nKey: 'nav.administration',
        icon: 'fas fa-fw fa-shield-alt',
        children: [
            { id: 'goToUsers', label: 'Users', i18nKey: 'nav.users', icon: 'fas fa-fw fa-users',       hash: '#/users', permission: 'users.view' },
            { id: 'goToRoles', label: 'Roles', i18nKey: 'nav.roles', icon: 'fas fa-fw fa-user-shield', hash: '#/roles', permission: 'roles.view', module: 'roles' }
        ]
    }
];

export const USER_INFO_CONFIG = [
    {
        label: 'Profile',
        i18nKey: 'nav.profile',
        icon: 'fas fa-user fa-sm fa-fw mr-2 text-gray-400',
        hash: '#/profile',
        permission: 'profile.view'
    },
    {
        label: 'Settings',
        i18nKey: 'nav.settings',
        icon: 'fas fa-cogs fa-sm fa-fw mr-2 text-gray-400',
        hash: '#/settings',
        permission: 'settings.view',
        module: 'settings'
    },
    {
        label: 'Activity Log',
        i18nKey: 'nav.activity_log',
        icon: 'fas fa-list fa-sm fa-fw mr-2 text-gray-400',
        hash: '#/activityLog',
        permission: 'activityLog.view',
        module: 'activity_log'
    }
];
