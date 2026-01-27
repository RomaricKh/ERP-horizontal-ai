// JavaScript Document
// assets/js/SessionWatcher.js
import { getToken, logout } from './Auth/auth.js';
import { renderComponent, unrenderComponent } from './components/componentManager.js';

let warned = false;
let watcherId = null;

window.addEventListener('tab:deactivated', () => {
  hideLastWindowWarning();
  warned = false; // allow re-show when user returns
});

window.addEventListener('tab:activated', () => {
  // do nothing immediately
  // the next interval tick will decide if warning is needed
});

export function startSessionWatcher() {
  if (watcherId) return;

  watcherId = setInterval(checkSession, 60_000); //checks the session every 60 seconds.
}

export function stopSessionWatcher() {
  clearInterval(watcherId);
  watcherId = null;
}

function isActiveTab() {
  return localStorage.getItem('active_tab') === window.APP.TAB_ID;
}

async function checkSession() {
	const token = getToken();
	const savedBusinessId = localStorage.getItem('business_id');
	if (!token) return;
	const res = await fetch('/api/auth/session-status', {
		method: 'GET',
        credentials: 'include',
		headers: {
			'Authorization': `Bearer ${token}`,
			'X-Business-Id': savedBusinessId,
			'Accept': 'application/json'
		}
	});
	if (!res.ok) return;

	const status = await res.json();

	// 1️⃣ HARD LOGOUT (no recovery)
	if (status.data.access_expires_in <= 0 && status.data.refresh_expires_in <= 0) {
		logout(); // centralized, idempotent
		return;
	}

	// 2️⃣ WARNING WINDOW (only once, only active tab)
	if (status.data.is_last_window && status.data.access_expires_in <= 300 && !warned && window.APP.isActiveTab()) {
		warned = true;
		await showLastWindowWarning();
	}
}

async function showLastWindowWarning() {
	await renderComponent(document.getElementById("appShell"), 
    					  'session-warning',
						  'html/components/SessionWarningBanner.html', 
						  './SessionWarningBanner.js');
    document.getElementById('lastWindowWarning').style.display = 'block';
}

export async function hideLastWindowWarning() {	
	unrenderComponent('session-warning');
}
