// JavaScript Document
import { getToken, setToken, clearToken, logout, broadcastTokenUpdate } from './auth.js';
import { go } from '../router/router.js';

let refreshing = false;
let refreshQueue = [];

export async function apiFetch(url, options = {}) {
	if (window.__LOGGING_OUT__) {
		throw new Error('Logout in progress');
	}
    const token = getToken();
	const savedBusinessId = localStorage.getItem('business_id');

    const headers = {
        ...(options.headers || {}),
        ...(token ? { Authorization: `Bearer ${token}`,
				'X-Business-Id': savedBusinessId } : {})
    };

    try {
        const res = await fetch(url, {
            ...options,
            headers,
            credentials: 'include' // REQUIRED for refresh cookie
        });

        if (res.status !== 401) {
            return res;
        }

        // 401 → try refresh
        return await handle401(url, options);

    } catch (e) {
        throw e;
    }
}

async function handle401(url, options) {
    if (refreshing) {
        return new Promise((resolve, reject) => {
            refreshQueue.push({ resolve, reject, url, options });
        });
    }

    refreshing = true;

    try {
        const res = await fetch('/api/auth/refresh', {
            method: 'POST',
            credentials: 'include'
        });

        if (!res.ok) {
            throw new Error('Refresh failed');
        }

        const data = await res.json();
        setToken(data.data.token);
		broadcastTokenUpdate(data.data.token);
        refreshing = false;

        // Retry queued requests
        refreshQueue.forEach(p =>
            apiFetch(p.url, p.options)
                .then(p.resolve)
                .catch(p.reject)
        );
        refreshQueue = [];

        return apiFetch(url, options);

    } catch (e) {
        refreshing = false;
        refreshQueue = [];
        throw e;
    }
}
