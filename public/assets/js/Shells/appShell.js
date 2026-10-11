// assets/js/Shells/appShell.js
import { MENU_CONFIG, USER_INFO_CONFIG } from './appShell.config.js';
import { getDefaultAuthRoute } from '../router/router.config.js';
import { determineDefaultRoute } from '../router/resolve.js';
import { getUser, hasPermission, isTenantOwner } from '../Auth/auth.js';
import { apiFetch } from '../Auth/apiFetch.js';
import { resolveRoute } from '../router/router.js';
import { registerPageActions, handlePageAction } from '../pages/Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeAllModals } from '../components/modals.js';
import { init as initTranslations, applyTranslations } from '../i18n/translationClient.js';
import notificationPollingService from '../services/NotificationPollingService.js';
import { init as initNotificationCenter, cleanup as cleanupNotificationCenter } from '../components/NotificationCenter.js';
import { isModuleActive } from '../services/ModuleService.js';
import { init as initTenantSwitcher } from '../components/tenantSwitcher.js';

export let shellActive = false;

let unregisterShellActions = null;
let shellChannel = null;
let businessChangeInProgress = false;
let modulesChangedHandler = null;

export function mountAppShell() {
    // Delegated shell actions
    unregisterShellActions = registerPageActions({
		'businessChanged': async ({ el }) => {
			const anchor = el.closest('a');
			const id = anchor?.dataset?.businessId;
			const name = anchor?.textContent;
			if (id && id != localStorage.getItem('business_id')){
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

    // Listen for module changes to re-render sidebar
    modulesChangedHandler = () => {
        renderMenu();
        applyTranslations(document.getElementById('accordionSidebar'));
    };
    window.addEventListener('modules:changed', modulesChangedHandler);

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

    // Remove modules:changed listener
    if (modulesChangedHandler) {
        window.removeEventListener('modules:changed', modulesChangedHandler);
        modulesChangedHandler = null;
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
	
	// Initialize translations and regional formatting before rendering shell content
	await initTranslations();
	
	determineDefaultRoute(user);
	renderSidebarBrand();
	renderMenu();
	await renderBusinessSelector(user);
	await initTenantSwitcher();
	renderUserInfo(user);
	
	// Apply translations to the shell (sidebar, menus, etc.)
	applyTranslations(document.getElementById('appShell'));
	
	delegateEvents();
	
	setActiveMenu(path);
	
	mountAppShell();
	
    shellActive = true;

	// Start notification polling and initialize the NotificationCenter UI
	notificationPollingService.start();
	initNotificationCenter();
}

export function deactivateAppShell() {
    if (!shellActive) return; // never remove if not rendered

	// Stop notification polling and cleanup the NotificationCenter UI
	notificationPollingService.stop();
	cleanupNotificationCenter();

	document.getElementById("authViews").style.display = "block";
	document.getElementById("appShell").style.display = "none";
	
	resetShellEventListeners();
	
	unmountAppShell();
	
	shellActive = false;
}

export function setActiveMenu(hash) {
    // Clear all active states
    document.querySelectorAll('#accordionSidebar .nav-item')
        .forEach(el => el.classList.remove('active'));
    document.querySelectorAll('#accordionSidebar .dropdown-item')
        .forEach(el => el.classList.remove('active'));
    document.querySelectorAll('#accordionSidebar .dropdown-toggle')
        .forEach(el => el.classList.remove('active'));

    // Check standalone items first
    const standaloneItem = document.querySelector(
        `#accordionSidebar > .nav-item:not(.dropdown)[data-route="${hash}"]`
    );
    if (standaloneItem) {
        standaloneItem.classList.add('active');
        return;
    }

    // Check dropdown items
    const dropdownItem = document.querySelector(
        `#accordionSidebar .dropdown-item[data-route="${hash}"]`
    );
    if (dropdownItem) {
        dropdownItem.classList.add('active');
        const parentDropdown = dropdownItem.closest('.nav-item.dropdown');
        if (parentDropdown) {
            parentDropdown.classList.add('active');
            const toggle = parentDropdown.querySelector('.dropdown-toggle');
            if (toggle) toggle.classList.add('active');
        }
    }
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

    const userIsTenantOwner = isTenantOwner();
	
    MENU_CONFIG.forEach(entry => {
        if (entry.type === 'item') {
            // Tenant owner items: check tenantOwner flag instead of permission/module
            if (entry.tenantOwner) {
                if (!userIsTenantOwner) return;
            } else {
                if (!hasPermission(entry.permission)) return;
                if (!isModuleActive(entry.module)) return;
            }

            const li = document.createElement('li');
            li.className = 'nav-item';
            li.id = entry.id;
            li.setAttribute('data-route', entry.hash);
            li.innerHTML = `
                <a class="nav-link" href="${entry.hash}">
                    <i class="${entry.icon}"></i>
                    <span data-i18n="${entry.i18nKey}">${entry.label}</span>
                </a>
            `;

            container.appendChild(li);
        } else if (entry.type === 'group') {
            // Filter children through permission and module activation checks
            const visibleChildren = (entry.children || []).filter(child => {
                if (child.tenantOwner) {
                    return userIsTenantOwner;
                }
                return hasPermission(child.permission) && isModuleActive(child.module);
            });

            // For tenant owner groups, also check the group-level flag
            if (entry.tenantOwner && !userIsTenantOwner) return;

            // Skip group if no children are visible
            if (visibleChildren.length === 0) return;

            const toggleId = entry.id + 'Toggle';

            const li = document.createElement('li');
            li.className = 'nav-item dropdown';
            li.id = entry.id;

            // Build dropdown items HTML
            const childrenHtml = visibleChildren.map(child =>
                `<li><a class="dropdown-item" href="${child.hash}" data-route="${child.hash}">
                    <i class="${child.icon}"></i> <span data-i18n="${child.i18nKey}">${child.label}</span>
                </a></li>`
            ).join('');

            li.innerHTML = `
                <a class="nav-link dropdown-toggle" href="#" role="button"
                   data-bs-toggle="dropdown" aria-expanded="false"
                   id="${toggleId}">
                    <i class="${entry.icon}"></i>
                    <span data-i18n="${entry.i18nKey}">${entry.label}</span>
                </a>
                <ul class="dropdown-menu" aria-labelledby="${toggleId}">
                    ${childrenHtml}
                </ul>
            `;

            container.appendChild(li);
        } else {
            console.warn(`renderMenu: unrecognized menu entry type "${entry.type}" for entry`, entry);
        }
    });
}

async function renderBusinessSelector(user) {
    /* ===============================
       BUSINESS DROPDOWN
    =============================== */
    const bContainer = document.getElementById('businessList');
    const bLabel = document.getElementById('business');

    if (!bContainer) return;

    bContainer.innerHTML = '';

    const businesses = new Map();

    // For regular users: collect unique businesses from roles in the JWT
    if (Array.isArray(user.roles)) {
        for (const role of user.roles) {
            if (!role.business_id) continue;
            businesses.set(role.business_id, role.business_name);
        }
    }

    // For tenant owners with no business roles in JWT: fetch businesses from API
    if (businesses.size === 0 && isTenantOwner()) {
        try {
            const res = await apiFetch('/api/tenant/businesses?per_page=100');
            const data = await res.json();
            // The endpoint returns a paginated envelope: { data: [...], total, ... }
            const list = data.data?.data;
            if (data.success && Array.isArray(list)) {
                for (const biz of list) {
                    if (biz.id && biz.name && biz.is_active != 0) {
                        businesses.set(biz.id, biz.name);
                    }
                }
            }
        } catch (err) {
            console.error('Failed to fetch tenant businesses for switcher:', err);
        }
    }

    const savedBusinessId = localStorage.getItem('business_id');

    businesses.forEach((name, id) => {
        const a = document.createElement('a');
        a.role = 'button';
        a.className = 'dropdown-item';
        a.setAttribute('data-action', 'businessChanged');
        a.setAttribute('data-business-id', id);
        a.textContent = name;

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
        if (item.module && !isModuleActive(item.module)) return;

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

    // Update href dynamically — always navigate to dashboard
    brandLink.href = '#/dashboard';
}

