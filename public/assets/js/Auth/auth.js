// assets/js/Auth/auth.js

import { apiFetch } from './apiFetch.js';
import { initRouter, destroyRouter } from '../router/router.js';
import { DEFAULT_GUEST_ROUTE, getDefaultAuthRoute } from '../router/router.config.js';
import { startSessionWatcher, stopSessionWatcher } from '../SessionWatcher.js';
import { determineDefaultRoute } from '../router/resolve.js';

const TOKEN_KEY = 'erp_jwt';

const authChannel = new BroadcastChannel('auth');

window.__LOGGING_OUT__ = false;

let sessionPromise = false;

authChannel.onmessage = (event) => {
	if (event.data === 'LOGIN') {
		forceLogin(false); // false = don’t rebroadcast
	}
	if (event.data === 'LOGOUT') {
		forceLogout(false); // false = don’t rebroadcast
	}
	if (event.data.type === 'ACCESS_TOKEN_REFRESHED') {
		setToken(event.data.token);
	}
};

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
    localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken() {
    localStorage.removeItem(TOKEN_KEY);
}

export async function ensureSession() {
    if (sessionPromise) return sessionPromise;

    sessionPromise = (async () => {
        try {
            const res = await apiFetch('api/auth/me'); // raw fetch Response
            const data = await res.json();                 // parse JSON
            if (!data.success) throw new Error(data.message);
			
            return true; // resolved value of the promise
        } catch (err) {
            clearToken();
            return false;
        } finally {
            sessionPromise = null; // reset so next call always checks again
        }
    })();

    return sessionPromise;
}

export function getUser() {
    const token = getToken();
    if (!token) return null;

    try {
        const payload = JSON.parse(atob(token.split('.')[1]));

        let permissions = payload.permissions;
        let roles = payload.roles;

        // Normalize permissions
        if (typeof permissions === 'string') {
            permissions = JSON.parse(permissions);
        }

        // Normalize roles
        if (typeof roles === 'string') {
            roles = JSON.parse(roles);
        }
        return {
            user_id: payload.user_id,
            username: payload.username,
            roles
        };
    } catch (e) {
        clearToken();
        return null;
    }
}

export async function login(username, password, remember) {
	try {
		const res = await fetch('api/auth/login', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'include',
			body: JSON.stringify({ username, password, remember })
		});

		const data = await res.json();

		if (!data.success) {
			alert(data.message);
			return;
		}

		setToken(data.data.token);
		forceLogin(true);
	} catch (err) {
		console.error(err);
		alert("Login failed. Try again.");
	}
}

export async function logout() {
    if (window.__LOGGING_OUT__) return;
    window.__LOGGING_OUT__ = true;
	const token = getToken();
	const savedBusinessId = localStorage.getItem('business_id');

    try {
        await fetch('api/auth/logout', {
            method: 'POST',
            credentials: 'include',
			headers: { 
					'Authorization': `Bearer ${token}`,
					'X-Business-Id': savedBusinessId,
					'Content-Type': 'application/json' }
        });

        if (!res.ok) {
            console.warn('Logout failed on server');
        }
    } catch (e) {
        // ignore
        console.warn('Logout request failed', e);
    } finally {
    	forceLogout(true); // ONE exit path
    }
}

export function broadcastLogin() {
  authChannel.postMessage('LOGIN');
}

export function broadcastLogout() {
  authChannel.postMessage('LOGOUT');
}

export function broadcastTokenUpdate(token) {
  authChannel.postMessage({
    type: 'ACCESS_TOKEN_REFRESHED',
    token
  });
}

export async function forceLogin(shouldBroadcast = true) {

	if (shouldBroadcast) {
		broadcastLogin();
	}
	
	determineDefaultRoute();
	
	window.location.hash = getDefaultAuthRoute();
	startSessionWatcher();
}

export async function forceLogout(shouldBroadcast = true) {

	if (shouldBroadcast) {
		broadcastLogout();
	}

	clearToken();
	stopSessionWatcher();
	destroyRouter();

	window.location.replace(DEFAULT_GUEST_ROUTE);
	await initRouter();
	window.__LOGGING_OUT__ = false;
}

export function hasPermission(code) {
    const user = getUser();
    if (!user || !Array.isArray(user.roles)) return false;

    let business_id = localStorage.getItem('business_id');

    // collect businesses from roles
    const businessRoles = user.roles.filter(r => r.business_id);

    // check if stored business_id is valid for this user
    const validBusinessIds = businessRoles.map(r => String(r.business_id));
    if (!business_id || !validBusinessIds.includes(String(business_id))) {
        // pick the first available business if any
        if (businessRoles.length > 0) {
            business_id = String(businessRoles[0].business_id);
            localStorage.setItem('business_id', business_id);
        }
    }
	
    // check each role that matches the current business (or system/global roles)
    for (const role of user.roles) {
        if (role.business_id && String(role.business_id) !== String(business_id)) continue;

        if (Array.isArray(role.permissions) && role.permissions.includes(code)) {
            return true;
        }
    }

    return false;
}
