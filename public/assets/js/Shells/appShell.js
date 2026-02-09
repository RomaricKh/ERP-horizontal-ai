// assets/js/Shells/appShell.js
import { MENU_CONFIG, USER_INFO_CONFIG } from './appShell.config.js';
import { getDefaultAuthRoute } from '../router/router.config.js';
import { determineDefaultRoute } from '../router/resolve.js';
import { getUser, hasPermission } from '../Auth/auth.js';
import { resolveRoute } from '../router/router.js';
import { registerPageActions, handlePageAction } from '../pages/Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeAllModals } from '../components/modals.js';

export let shellActive = false;

let unregisterShellActions = null;
let shellChannel = null;
let businessChangeInProgress = false;

export function mountAppShell() {
    // Delegated shell actions
    unregisterShellActions = registerPageActions({
		'businessChanged': async ({ el }) => {
			const id = el.closest('a')?.value;
			const name = el.closest('a')?.textContent;
			if (id != localStorage.getItem('business_id')){
				localStorage.setItem('business_id', id);
				document.getElementById('business').textContent = name;

				await handleBusinessChange();
			}
		},
		'open.logout.modal': async () => await openModal({
			scope: MODAL_SCOPE.GLOBAL,
			template: 'html/Auth/logoutModal.html',
			controller: '../pages/Auth/logout.modal.js',
			data: {},
			options: { backdrop: 'static', keyboard: true, focus: true }
		})
    });

    // Broadcast channel
    shellChannel = new BroadcastChannel('shell');
    shellChannel.onmessage = async (event) => {
        if (event.data === 'BUSINESS_CHANGED') {
            shellActive = false;
            await resolveRoute();
        }
    };

    // Other global listeners if needed
}

export function unmountAppShell() {
    if (unregisterShellActions) {
        unregisterShellActions();
        unregisterShellActions = null;
    }
		
	closeAllModals();

    if (shellChannel) {
        shellChannel.close();
        shellChannel = null;
    }

    // Clear shell-only state
    shellActive = false;
}

export async function activateAppShell(path) {
    if (shellActive) {
		setActiveMenu(path);
		return; // never render again
	}
	
	document.getElementById("authViews").style.display = "none";
	document.getElementById("authViews").innerHTML = '';
	document.getElementById("appShell").style.display = "block";
			
    const user = getUser();
	
	determineDefaultRoute(user);
	renderSidebarBrand();
	renderMenu();
	renderBusinessSelector(user);
	renderUserInfo(user);
	
	delegateEvents();
	
	setActiveMenu(path);
	
	mountAppShell();
	
    shellActive = true;
}

export function deactivateAppShell() {
    if (!shellActive) return; // never remove if not rendered
	
	document.getElementById("authViews").style.display = "block";
	document.getElementById("appShell").style.display = "none";
	
	resetShellEventListeners();
	
	unmountAppShell();
	
	shellActive = false;
}

export function setActiveMenu(hash) {
    document.querySelectorAll('.nav-item')
        .forEach(el => el.classList.remove('active'));

    const el = document.querySelector(
        `.nav-item[data-route="${hash}"]`
    );

    if (el) el.classList.add('active');
}

export function delegateEvents() {
    // prevent double-binding
    if (document.body.dataset.sbDelegated) return;
    document.body.dataset.sbDelegated = '1';

    document.body.addEventListener('click', shellClickHandler);
}

async function shellClickHandler(e) {
	const actionEl = e.target.closest('[data-action]');
	if (!actionEl) return;

	const handled = await handlePageAction({
		action: actionEl.dataset.action,
		el: actionEl,
		event: e
	});

	if (handled) {
		e.preventDefault();
		e.stopPropagation();
	}
}

// Call this when deactivating/unmounting the shell
export function resetShellEventListeners() {
    if (!document.body.dataset.sbDelegated) return;
    document.body.removeEventListener('click', shellClickHandler);
    document.body.dataset.sbDelegated = '';
}

function renderMenu() {
    const container = document.getElementById('accordionSidebar');
    if (!container) return;
	
    // Clear existing menu items
    container.innerHTML = '';
	
    MENU_CONFIG.forEach(item => {
        if (!hasPermission(item.permission)) return;

        const li = document.createElement('li');
        li.className = 'nav-item';
        li.id = item.id;
		li.setAttribute('data-route', item.hash);
        li.innerHTML = `
            <a class="nav-link" href="${item.hash}">
                <i class="${item.icon}"></i>
                <span>${item.label}</span>
            </a>
        `;

        container.appendChild(li);
    });
}

function renderBusinessSelector(user) {
    /* ===============================
       BUSINESS DROPDOWN
    =============================== */
    const bContainer = document.getElementById('businessList');
    const bLabel = document.getElementById('business');

    if (bContainer && Array.isArray(user.roles)) {
        bContainer.innerHTML = '';

        const businesses = new Map();

        // collect unique businesses from roles
        for (const role of user.roles) {
            if (!role.business_id) continue;

            businesses.set(role.business_id, role.business_name);
        }

        const savedBusinessId = localStorage.getItem('business_id');

        businesses.forEach((name, id) => {
            const a = document.createElement('a');
			a.role = 'button';
            a.className = 'dropdown-item';
			a.setAttribute('data-action', 'businessChanged');
            a.textContent = name;
			a.value = id;

            bContainer.appendChild(a);

            // restore label on refresh
            if (savedBusinessId && String(id) === savedBusinessId) {
                bLabel.textContent = name;
            }
        });

        // fallback label
        if (!savedBusinessId && businesses.size > 0) {
            const [firstId, firstName] = businesses.entries().next().value;
            localStorage.setItem('business_id', firstId);
            bLabel.textContent = firstName;
        }
    }
}

async function handleBusinessChange(shouldBroadcast = true) {
	if (businessChangeInProgress) return;
	businessChangeInProgress = true;

	try {
		if (shouldBroadcast) {
			shellChannel.postMessage('BUSINESS_CHANGED');
		}
		
		await invalidateAndReconnect();

	} finally {
		businessChangeInProgress = false;
	}
}
	
export async function invalidateAndReconnect(){		
		deactivateAppShell();
		await resolveRoute();
}

function renderUserInfo(user) {
    /* ===============================
       USER MENU
    =============================== */
	const usernameEl = document.getElementById('username');
	if (usernameEl && user) {
		usernameEl.innerHTML = user.username;
	}
	
    const container = document.getElementById('userInfo');
    if (!container) return;

    container.innerHTML = '';
    let renderedItems = 0;

    USER_INFO_CONFIG.forEach(item => {
        if (!hasPermission(item.permission)) return;

        const a = document.createElement('a');
        a.className = 'dropdown-item';
        a.href = item.hash;

        a.innerHTML = `
			<i class="${item.icon}"></i>
			<span>${item.label}</span>
        `;

        container.appendChild(a);
        renderedItems++;
    });    
	// Add divider only if at least one item exists
    if (renderedItems > 0) {
        const divider = document.createElement('div');
        divider.className = 'dropdown-divider';
        container.appendChild(divider);
    }
	
	const logout = document.createElement('a');
	logout.className = 'dropdown-item';
	logout.role = 'button';
	logout.setAttribute('data-action', 'open.logout.modal');
	logout.innerHTML = `
		<i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
		<span>Logout</span>
	`;
	
	container.appendChild(logout);
}


function renderSidebarBrand(containerId = 'topbar') {
    const container = document.getElementById(containerId);
    if (!container) return;

    let brandLink = container.querySelector('.navbar-brand');
    if (!brandLink) {
        // create if missing
        brandLink = document.createElement('a');
        brandLink.className = 'navbar-brand d-flex align-items-center justify-content-center';
        brandLink.innerHTML = `
            <div class="navbar-brand-icon rotate-n-15">
				<img class="rounded-circle"
					src="assets/img/cost_primary.png"
					alt="...">
            </div>
            <div class="navbar-brand-text mx-3">ERP System</div>
        `;		
        container.prepend(brandLink); // always on top
    }

    // Update href dynamically
    brandLink.href = getDefaultAuthRoute();
}

