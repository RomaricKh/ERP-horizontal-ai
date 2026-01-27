// JavaScript Document
// assets/js/router/router.config.js
export const routes = [
    {path: '#/login', auth: false, permission: '', load: () => import('../pages/Auth/login.js')},
    {path: '#/forgot-password', auth: false, permission: '', load: () => import('../pages/Auth/forgot-password.js')},
    {path: '#/dashboard', auth: true, permission: 'dashboard.view', load: () => import('../pages/Others/dashboard.js')},
    {path: '#/charts', auth: true, permission: 'charts.view', load: () => import('../pages/Others/charts.js')},
    {path: '#/tables', auth: true, permission: 'tables.view', load: () => import('../pages/Others/tables.js')},
    {path: '#/profile', auth: true, permission: 'profile.view', load: () => import('../pages/Others/404.js')},
    {path: '#/settings', auth: true, permission: 'settings.view', load: () => import('../pages/Others/404.js')},
    {path: '#/activityLog', auth: true, permission: 'activityLog.view', load: () => import('../pages/Others/404.js')},
    {path: '#/users', auth: true, permission: 'users.view', load: () => import('../pages/Users/users.js')},
    {path: '#/businesses', auth: true, permission: 'businesses.view', load: () => import('../pages/Business/businesses.js')}
];

export let DEFAULT_AUTH_ROUTE = '#/dashboard';
export const DEFAULT_GUEST_ROUTE = '#/login';

export function setDefaultAuthRoute(route) {
    DEFAULT_AUTH_ROUTE = route;
}

export function getDefaultAuthRoute() {
    return DEFAULT_AUTH_ROUTE;
}