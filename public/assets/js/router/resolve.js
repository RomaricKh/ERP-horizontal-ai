import { routes, setDefaultAuthRoute, getDefaultAuthRoute, DEFAULT_GUEST_ROUTE } from './router.config.js';
import { getToken, ensureSession, getUser, hasPermission } from '../Auth/auth.js';

export async function resolveRouteDecision(hash) {
    const route = routes.find(r => r.path === hash);
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

    // Check permission if route requires it
    if (route.permission && !hasPermission(route.permission)) {
        return { redirect: getDefaultAuthRoute() };
    }

    return {
        route,
        shell: isAuth ? 'authenticated' : 'guest'
    };
}

export function determineDefaultRoute(user = getUser()) {
    if (!user || !user.permissions) return;

    const firstAllowed = routes.find(r => r.auth && user.permissions.includes(r.permission));
    if(firstAllowed) 
		setDefaultAuthRoute(firstAllowed.path);
}