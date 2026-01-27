// assets/js/router/router.js

import { DEFAULT_GUEST_ROUTE } from './router.config.js';
import { activateAppShell, deactivateAppShell } from '../Shells/appShell.js';
import { resolveRouteDecision } from './resolve.js';
import { renderPage } from './renderPage.js';
import { showLoading, hideLoading } from '../components/loading.js';
import { startSessionWatcher } from '../SessionWatcher.js';
import { logout } from '../Auth/auth.js';


export async function initRouter() {
    window.addEventListener('hashchange', resolveRoute);
    await resolveRoute();
}

export async function destroyRouter() {
    window.removeEventListener('hashchange', resolveRoute);
}

export function go(path) {
    if (window.location.hash !== path) {
        window.location.hash = path;
    }
}

export async function resolveRoute() {
	showLoading();	
	try{
		const hash = window.location.hash || DEFAULT_GUEST_ROUTE;

		const decision = await resolveRouteDecision(hash);
		
		if(decision.logout){
			await logout(); // ensure token cleanup completes
			return;
		}

		if (handleRedirect(decision)) return;

		// Shell responsibility
		if (decision.shell === 'authenticated') {
			await activateAppShell(decision.route.path);
    		// Session watcher only runs for authenticated users
			startSessionWatcher();
		} else {
			deactivateAppShell();
		}

		// Page rendering responsibility
		await renderPage(decision.route, decision.shell);
	} finally{
		hideLoading();
	}
	
}


function handleRedirect(decision) {
    if (!decision || !decision.redirect) return false;

    const target = decision.redirect;

    // Prevent useless hash rewrites
    if (window.location.hash !== target) {
        window.location.hash = target;
    }

    // Tell caller: "stop everything, router will re-run via hashchange"
    return true;
}
