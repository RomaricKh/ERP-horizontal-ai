import { routes, setDefaultAuthRoute, getDefaultAuthRoute, DEFAULT_GUEST_ROUTE } from './router.config.js';
import { getToken, ensureSession, getUser, hasPermission, isTenantOwner } from '../Auth/auth.js';
import { isModuleActive } from '../services/ModuleService.js';

export async function resolveRouteDecision(hash) {
    // Strip query parameters from hash for route matching (e.g. #/people/statement?id=8 → #/people/statement)
    const hashPath = hash.includes('?') ? hash.split('?')[0] : hash;
    const route = routes.find(r => r.path === hashPath);
    const token = getToken();
    const isAuth = await ensureSession();

    // If user has a token but session is invalid, force logout first
    if (token && !isAuth) {
        return { logout: true };
    }

    // Unknown route → redirect
    if (!route) {
        return isAuth
				? { redirect: getDefaultAuthRoute() }
				: { logout: true };
    }

    // Route auth rules
    if (route.auth && !isAuth) {
        return { logout: true };
    }

    if (!route.auth && isAuth) {
        return { redirect: getDefaultAuthRoute() };
    }

    // Handle redirectTo routes (e.g. #/businesses → #/tenant/businesses or #/dashboard)
    if (route.redirectTo) {
        const target = typeof route.redirectTo === 'function'
            ? route.redirectTo(isTenantOwner())
            : route.redirectTo;
        return { redirect: target };
    }

    // Check tenant owner requirement
    if (route.tenantOwner && !isTenantOwner()) {
        return { redirect: getDefaultAuthRoute() };
    }

    // Check permission if route requires it
    if (route.permission && !hasPermission(route.permission)) {
        return { redirect: getDefaultAuthRoute() };
    }

    // Check if route's module is active (do not redirect, flag for message display)
    if (route.module && !isModuleActive(route.module)) {
        return {
            route,
            shell: 'authenticated',
            moduleInactive: true
        };
    }

    return {
        route,
        shell: isAuth ? 'authenticated' : 'guest'
    };
}

export function determineDefaultRoute(user = getUser()) {
    if (!user || !Array.isArray(user.roles)) return;

    // Tenant owners default to tenant management
    if (isTenantOwner()) {
        setDefaultAuthRoute('#/tenant/businesses');
        return;
    }

    const firstAllowed = routes.find(r => r.auth && r.permission && hasPermission(r.permission));
    if (firstAllowed)
        setDefaultAuthRoute(firstAllowed.path);
}