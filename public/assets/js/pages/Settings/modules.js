// assets/js/pages/Settings/modules.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { confirm, alert } from '../../components/modals.js';
import { invalidateModuleCache, fetchActiveModules } from '../../services/ModuleService.js';

let container = null;
let unregister = null;
let modulesData = [];

const CATEGORY_LABELS = {
    core: 'Core',
    operations: 'Operations',
    finance: 'Finance',
    other: 'Other'
};

const CATEGORY_ORDER = ['core', 'operations', 'finance', 'other'];

const STATUS_LABELS = {
    type_default: 'Active (type default)',
    override_activate: 'Active (override)',
    override_deactivate: 'Inactive (override)',
    inactive: 'Inactive (type default)'
};

const STATUS_BADGE_CLASSES = {
    type_default: 'badge bg-success',
    override_activate: 'badge bg-info',
    override_deactivate: 'badge bg-warning text-dark',
    inactive: 'badge bg-secondary'
};

export async function render(target) {
    container = target;
    const html = await fetch('html/Settings/modules.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    await loadModules();

    unregister = registerPageActions({
        'module.toggle': handleModuleToggle
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    container = null;
    modulesData = [];
}

async function loadModules() {
    const loadingEl = container.querySelector('#modulesLoading');
    const containerEl = container.querySelector('#modulesContainer');

    try {
        const res = await apiFetch('/api/settings/modules');
        const data = await res.json();

        if (!data.success) {
            loadingEl.innerHTML = '<p class="text-danger">Failed to load modules.</p>';
            return;
        }

        modulesData = data.data.modules;
        renderModules();

        loadingEl.style.display = 'none';
        containerEl.style.display = 'block';
    } catch (err) {
        console.error('Failed to load modules:', err);
        loadingEl.innerHTML = '<p class="text-danger">Failed to load modules.</p>';
    }
}

function renderModules() {
    const containerEl = container.querySelector('#modulesContainer');
    containerEl.innerHTML = '';

    // Group modules by category
    const grouped = {};
    for (const mod of modulesData) {
        const cat = mod.category || 'other';
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(mod);
    }

    for (const category of CATEGORY_ORDER) {
        const modules = grouped[category];
        if (!modules || modules.length === 0) continue;

        const card = document.createElement('div');
        card.className = 'card shadow mb-4';
        card.innerHTML = `
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">${escHtml(CATEGORY_LABELS[category] || category)}</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:40px;"></th>
                                <th>Module</th>
                                <th>Status</th>
                                <th style="width:80px;" class="text-center">Toggle</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${modules.map(mod => renderModuleRow(mod)).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        containerEl.appendChild(card);
    }
}

function renderModuleRow(mod) {
    const isActive = mod.status === 'type_default' || mod.status === 'override_activate';
    const disabled = mod.is_core ? 'disabled' : '';
    const checked = isActive ? 'checked' : '';

    return `
        <tr>
            <td class="text-center align-middle">
                <i class="${escHtml(mod.icon || 'fas fa-cube')} text-gray-500"></i>
            </td>
            <td class="align-middle">
                <strong>${escHtml(mod.name)}</strong>
                <small class="text-muted d-block">${escHtml(mod.code)}</small>
            </td>
            <td class="align-middle">
                <span class="${STATUS_BADGE_CLASSES[mod.status] || 'badge bg-secondary'}">
                    ${escHtml(STATUS_LABELS[mod.status] || mod.status)}
                </span>
            </td>
            <td class="text-center align-middle">
                <div class="form-check form-switch d-flex justify-content-center mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                        id="toggle-${escHtml(mod.code)}"
                        data-action="module.toggle"
                        data-module-code="${escHtml(mod.code)}"
                        data-module-name="${escHtml(mod.name)}"
                        ${checked} ${disabled}>
                </div>
            </td>
        </tr>
    `;
}

/**
 * Handle module toggle action.
 * When deactivating:
 *   1. Call API without force
 *   2. If 409 warning returned, show confirmation dialog
 *   3. On confirm, re-send with force=true
 *   4. On cancel, revert toggle
 */
async function handleModuleToggle({ el, event }) {
    const toggle = el;
    if (!toggle || !toggle.dataset.moduleCode) return;

    const moduleCode = toggle.dataset.moduleCode;
    const moduleName = toggle.dataset.moduleName || moduleCode;
    const isNowChecked = toggle.checked;

    // Determine the action: if toggled ON → activate, if toggled OFF → deactivate
    const action = isNowChecked ? 'activate' : 'deactivate';

    try {
        // First attempt without force
        const res = await apiFetch('/api/settings/modules/override', {
            method: 'POST',
            body: JSON.stringify({ module_code: moduleCode, action })
        });

        const data = await res.json();

        // Handle 409 warning (module has existing data)
        if (res.status === 409 && data.data && data.data.warning) {
            const recordCount = data.data.record_count;

            const confirmed = await confirm({
                title: 'Confirm Deactivation',
                message: `The module "${moduleName}" has ${recordCount} existing record${recordCount !== 1 ? 's' : ''}. Are you sure you want to deactivate it? Data will be preserved but inaccessible while the module is inactive.`
            });

            if (confirmed) {
                // Re-send with force=true
                const forceRes = await apiFetch('/api/settings/modules/override', {
                    method: 'POST',
                    body: JSON.stringify({ module_code: moduleCode, action, force: true })
                });

                const forceData = await forceRes.json();

                if (!forceRes.ok || !forceData.success) {
                    // Force request failed - revert toggle
                    toggle.checked = !isNowChecked;
                    await alert({
                        title: 'Error',
                        alertText: forceData.message || 'Failed to save module override.'
                    });
                    return;
                }

                // Success with force - refresh sidebar
                await refreshSidebarAfterToggle();
                await loadModules();
            } else {
                // User cancelled - revert toggle
                toggle.checked = !isNowChecked;
            }
            return;
        }

        // Handle other errors
        if (!res.ok || !data.success) {
            toggle.checked = !isNowChecked;
            await alert({
                title: 'Error',
                alertText: data.message || 'Failed to save module override.'
            });
            return;
        }

        // Success - refresh sidebar and reload modules
        await refreshSidebarAfterToggle();
        await loadModules();

    } catch (err) {
        console.error('Module toggle failed:', err);
        toggle.checked = !isNowChecked;
        await alert({
            title: 'Error',
            alertText: 'An unexpected error occurred while saving the module override.'
        });
    }
}

/**
 * Refresh the sidebar after a module toggle by invalidating the cache
 * and re-fetching active modules.
 */
async function refreshSidebarAfterToggle() {
    invalidateModuleCache();
    await fetchActiveModules();
    // Dispatch event so appShell can re-render the sidebar
    window.dispatchEvent(new CustomEvent('modules:changed'));
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
