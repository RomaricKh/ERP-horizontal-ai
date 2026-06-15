// assets/js/components/tenantSwitcher.js

import { apiFetch } from '../Auth/apiFetch.js';
import { isTenantOwner } from '../Auth/auth.js';
import { invalidateAndReconnect } from '../Shells/appShell.js';

const ACTIVE_TENANT_KEY = 'activeTenantId';

/**
 * Initialize the Tenant Switcher dropdown in the top navigation bar.
 * Fetches the list of tenants the user owns and renders a Bootstrap 5 dropdown.
 * On selection change: updates localStorage, sets X-Tenant-Id header context,
 * reloads sidebar, resets business selector, and re-renders the current page.
 */
export async function init() {
    // Only show tenant switcher for tenant owners
    if (!isTenantOwner()) {
        removeSwitcher();
        return;
    }

    try {
        const tenants = await fetchTenants();
        renderSwitcher(tenants);
        bindEvents();
    } catch (err) {
        console.error('TenantSwitcher: Failed to initialize', err);
    }
}

/**
 * Fetch the list of tenants the current user owns.
 * @returns {Promise<Array<{id: number, name: string, description?: string}>>}
 */
async function fetchTenants() {
    const res = await apiFetch('/api/tenant/tenants');
    const data = await res.json();

    if (!data.success) {
        throw new Error(data.message || 'Failed to fetch tenants');
    }

    return data.data?.tenants || [];
}

/**
 * Render the tenant switcher dropdown in the top navigation bar.
 * Inserts before the business dropdown.
 * @param {Array<{id: number, name: string}>} tenants
 */
function renderSwitcher(tenants) {
    const navbarNav = document.querySelector('#topbar .navbar-nav');
    if (!navbarNav) return;

    // Remove existing switcher if present (re-render scenario)
    removeSwitcher();

    const activeTenantId = getActiveTenantId(tenants);
    const activeTenant = tenants.find(t => String(t.id) === String(activeTenantId));
    const activeLabel = activeTenant ? activeTenant.name : (tenants[0]?.name || 'Tenant');

    // Ensure activeTenantId is persisted
    if (activeTenantId) {
        localStorage.setItem(ACTIVE_TENANT_KEY, activeTenantId);
    }

    // Create the dropdown list item
    const li = document.createElement('li');
    li.className = 'nav-item dropdown no-arrow me-2';
    li.id = 'tenantSwitcherContainer';

    // Build dropdown items
    const itemsHtml = tenants.map(tenant => {
        const isActive = String(tenant.id) === String(activeTenantId);
        return `<a class="dropdown-item tenant-switch-item${isActive ? ' active' : ''}" 
                   role="button" 
                   data-tenant-id="${tenant.id}"
                   data-tenant-name="${escapeHtml(tenant.name)}">
                    ${escapeHtml(tenant.name)}
                </a>`;
    }).join('');

    li.innerHTML = `
        <a class="btn btn-secondary btn-icon-split dropdown-toggle mt-3" 
           role="button" id="tenantDropdown" data-bs-toggle="dropdown" 
           aria-haspopup="true" aria-expanded="false">
            <span class="text mr-2 d-none d-lg-inline" id="tenantLabel">${escapeHtml(activeLabel)}</span>
            <span class="icon">
                <i class="fas fa-building"></i>
            </span>
        </a>
        <div class="dropdown-menu dropdown-menu-end shadow animated--grow-in"
             aria-labelledby="tenantDropdown" id="tenantList">
            ${itemsHtml}
        </div>
    `;

    // Insert before the business dropdown (first item in the navbar-nav)
    const businessDropdownItem = navbarNav.querySelector('#businessDropdown')?.closest('.nav-item');
    if (businessDropdownItem) {
        navbarNav.insertBefore(li, businessDropdownItem);
    } else {
        // Fallback: prepend to the nav
        navbarNav.prepend(li);
    }
}

/**
 * Bind click events on tenant dropdown items.
 */
function bindEvents() {
    const container = document.getElementById('tenantList');
    if (!container) return;

    container.addEventListener('click', async (e) => {
        const item = e.target.closest('.tenant-switch-item');
        if (!item) return;

        const tenantId = item.dataset.tenantId;
        const tenantName = item.dataset.tenantName;
        const currentTenantId = localStorage.getItem(ACTIVE_TENANT_KEY);

        // No-op if same tenant selected
        if (String(tenantId) === String(currentTenantId)) return;

        await switchTenant(tenantId, tenantName);
    });
}

/**
 * Switch to a different tenant context.
 * Updates localStorage, resets business selector, reloads sidebar, re-renders page.
 * @param {string|number} tenantId
 * @param {string} tenantName
 */
async function switchTenant(tenantId, tenantName) {
    // 1. Update localStorage with new active tenant
    localStorage.setItem(ACTIVE_TENANT_KEY, tenantId);

    // 2. Update the dropdown label
    const label = document.getElementById('tenantLabel');
    if (label) {
        label.textContent = tenantName;
    }

    // 3. Update active state in dropdown
    const items = document.querySelectorAll('.tenant-switch-item');
    items.forEach(item => {
        item.classList.toggle('active', String(item.dataset.tenantId) === String(tenantId));
    });

    // 4. Reset business selector — clear stored business_id so the shell picks the first one
    localStorage.removeItem('business_id');

    // 5. Reload sidebar and re-render current page by invalidating the shell
    await invalidateAndReconnect();
}

/**
 * Get the currently active tenant ID from localStorage, or default to the first tenant.
 * @param {Array<{id: number, name: string}>} tenants
 * @returns {string|null}
 */
function getActiveTenantId(tenants) {
    const stored = localStorage.getItem(ACTIVE_TENANT_KEY);
    if (stored && tenants.some(t => String(t.id) === String(stored))) {
        return stored;
    }
    // Default to first tenant
    return tenants.length > 0 ? String(tenants[0].id) : null;
}

/**
 * Remove the tenant switcher from the DOM.
 */
function removeSwitcher() {
    const existing = document.getElementById('tenantSwitcherContainer');
    if (existing) {
        existing.remove();
    }
}

/**
 * Get the current active tenant ID (for use by other modules).
 * @returns {string|null}
 */
export function getActiveTenant() {
    return localStorage.getItem(ACTIVE_TENANT_KEY);
}

/**
 * Escape HTML to prevent XSS in rendered content.
 * @param {string} str
 * @returns {string}
 */
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
