// JavaScript Document
// assets/js/router/router.config.js
export const routes = [
    {path: '#/login', auth: false, permission: '', load: () => import('../pages/Auth/login.js')},
    {path: '#/forgot-password', auth: false, permission: '', load: () => import('../pages/Auth/forgot-password.js')},
    {path: '#/reset-password', auth: false, permission: '', load: () => import('../pages/Auth/reset-password.js')},
    {path: '#/dashboard', auth: true, permission: 'dashboard.view', module: 'dashboard', load: () => import('../pages/Dashboard/dashboard.js')},
    {path: '#/profile', auth: true, permission: 'profile.view', load: () => import('../pages/Profile/profile.js')},
    {path: '#/settings', auth: true, permission: 'settings.view', module: 'settings', load: () => import('../pages/Settings/settings.js')},
    {path: '#/settings/expiry-threshold', auth: true, permission: 'settings.manage', module: 'settings', load: () => import('../pages/Settings/expiryThreshold.js')},
    {path: '#/activityLog', auth: true, permission: 'activityLog.view', module: 'activity_log', load: () => import('../pages/ActivityLog/activityLog.js')},
    {path: '#/people', auth: true, permission: 'people.view', module: 'people', load: () => import('../pages/People/people.js')},
    {path: '#/people/statement', auth: true, permission: 'people.view', module: 'people', load: () => import('../pages/People/statement.js')},
    {path: '#/items', auth: true, permission: 'items.view', module: 'items', load: () => import('../pages/Items/items.js')},
    {path: '#/users', auth: true, permission: 'users.view', load: () => import('../pages/Users/users.js')},
    {path: '#/businesses', auth: true, redirectTo: (isTenantOwner) => isTenantOwner ? '#/tenant/businesses' : '#/dashboard'},
    {path: '#/exchange-rates', auth: true, permission: 'exchange-rates.manage', module: 'exchange_rates', load: () => import('../pages/Finance/exchangeRates.js')},
    {path: '#/accounts', auth: true, permission: 'accounts.manage', module: 'accounts', load: () => import('../pages/Finance/accounts.js')},
    {path: '#/expenses', auth: true, permission: 'expenses.view', module: 'expenses', load: () => import('../pages/Expenses/expenses.js')},
    {path: '#/expenses/recurring', auth: true, permission: 'expenses.view', module: 'expenses', load: () => import('../pages/Expenses/recurring.js')},
    {path: '#/purchase-orders', auth: true, permission: 'po.view', module: 'purchase_orders', load: () => import('../pages/PurchaseOrders/purchaseOrders.js')},
    {path: '#/invoices', auth: true, permission: 'invoices.view', module: 'sales_invoices', load: () => import('../pages/SalesInvoices/salesInvoices.js')},
    {path: '#/payments', auth: true, permission: 'payments.view', module: 'payments', load: () => import('../pages/Finance/payments.js')},
    {path: '#/collections', auth: true, permission: 'collections.view', module: 'collections', load: () => import('../pages/Finance/collections.js')},
    {path: '#/ar-aging', auth: true, permission: 'invoices.view', module: 'sales_invoices', load: () => import('../pages/Finance/arAging.js')},
    {path: '#/profit-loss', auth: true, permission: 'reports.view', load: () => import('../pages/Finance/profitLoss.js')},
    {path: '#/cash-flow', auth: true, permission: 'reports.view', load: () => import('../pages/Finance/cashFlow.js')},
    {path: '#/balance-sheet', auth: true, permission: 'reports.view', load: () => import('../pages/Finance/balanceSheet.js')},
    {path: '#/prepaid-assets', auth: true, permission: 'expenses.view', module: 'expenses', load: () => import('../pages/Finance/prepaid-assets.js')},
    {path: '#/prepaid-assets/detail', auth: true, permission: 'expenses.view', module: 'expenses', load: () => import('../pages/Finance/prepaidAssetDetail.js')},
    {path: '#/salaries',    auth: true, permission: 'salaries.view',    module: 'salaries', load: () => import('../pages/Salaries/salaries.js')},
    {path: '#/salaries/recurring', auth: true, permission: 'salaries.view', module: 'salaries', load: () => import('../pages/Salaries/recurring.js')},
    {path: '#/services', auth: true, permission: 'services.view', module: 'services', load: () => import('../pages/Services/services.js')},
    {path: '#/production-orders', auth: true, permission: 'manufacturing.view', module: 'manufacturing', load: () => import('../pages/Items/productionOrders.js')},
    {path: '#/roles', auth: true, permission: 'roles.view', module: 'roles', load: () => import('../pages/Roles/roles.js')},
    {path: '#/notifications', auth: true, permission: 'notifications.view', module: 'notifications', load: () => import('../pages/Notifications/notifications.js')},

    // Assets module route
    {path: '#/assets', auth: true, permission: 'assets.manage', module: 'assets', load: () => import('../pages/Assets/assets.js')},

    // Partner module routes
    {path: '#/partner-equity', auth: true, permission: 'partners.manage', module: 'partners', load: () => import('../pages/PartnerEquity/partnerEquity.js')},
    {path: '#/partner-shares', auth: true, permission: 'partners.manage', module: 'partners', load: () => import('../pages/PartnerShares/partnerShares.js')},

    // Tenant Owner routes (visible only to Tenant Owners)
    {path: '#/tenant/tenants', auth: true, tenantOwner: true, load: () => import('../pages/Tenant/tenants.js')},
    {path: '#/tenant/businesses', auth: true, tenantOwner: true, load: () => import('../pages/Tenant/businesses.js')},
    {path: '#/tenant/business-types', auth: true, tenantOwner: true, load: () => import('../pages/Tenant/business-types.js')},
    {path: '#/tenant/owners', auth: true, tenantOwner: true, load: () => import('../pages/Tenant/owners.js')},
    {path: '#/tenant/users', auth: true, tenantOwner: true, load: () => import('../pages/Tenant/users.js')},
    {path: '#/tenant/reports', auth: true, tenantOwner: true, load: () => import('../pages/Tenant/reports.js')}
];

export let DEFAULT_AUTH_ROUTE = '#/dashboard';
export const DEFAULT_GUEST_ROUTE = '#/login';

export function setDefaultAuthRoute(route) {
    DEFAULT_AUTH_ROUTE = route;
}

export function getDefaultAuthRoute() {
    return DEFAULT_AUTH_ROUTE;
}