// assets/js/pages/Tenant/tenants.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

let container;
let unregister;
let dataTable = null;
let updatedHandler;

export async function render(cont) {
    container = cont;
    const html = await fetch('html/Tenant/tenants.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    await loadTenants();

    updatedHandler = () => loadTenants();
    document.addEventListener('tenants.updated', updatedHandler);

    unregister = registerPageActions({
        'tenants.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Tenant/tenantForm.html',
                controller: '../pages/Tenant/tenantForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'tenants.edit': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const tenantId = rowData[0];
            if (!tenantId) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Tenant/tenantForm.html',
                controller: '../pages/Tenant/tenantForm.modal.js',
                data: { tenant_id: tenantId },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'tenants.toggleActive': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const tenantId = rowData[0];
            const name = rowData[1];
            if (!tenantId) return;

            const isCurrentlyActive = rowData[3] && rowData[3].includes('bg-success');
            const newState = !isCurrentlyActive;

            const message = newState
                ? `Activate the tenant "${name}"? Users will regain access to its businesses.`
                : `Deactivate the tenant "${name}"? All users will lose access to its businesses. Data is preserved.`;

            const confirmed = await confirm({
                title: `${newState ? 'Activate' : 'Deactivate'} Tenant`,
                message
            });

            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/tenant/tenants/${tenantId}/active`, {
                    method: 'PATCH',
                    body: JSON.stringify({ active: newState })
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    await alert({
                        title: 'Error',
                        alertText: data.message || 'Failed to toggle tenant status'
                    });
                } else {
                    await loadTenants();
                }
            } catch (err) {
                console.error('Toggle tenant active failed:', err);
            }
        }
    });
}

export function unmount() {
    if (updatedHandler) {
        document.removeEventListener('tenants.updated', updatedHandler);
        updatedHandler = null;
    }
    unregister?.();
    unregister = null;
    dataTable?.destroy();
    dataTable = null;
    closeModalsInContainer(container);
}

async function loadTenants() {
    try {
        const res = await apiFetch('/api/tenant/tenants');
        const body = await res.json();

        if (!res.ok || !body.success) {
            console.error('Failed to load tenants:', body.message);
            return;
        }

        renderTable(body.data?.tenants ?? []);
    } catch (err) {
        console.error('Tenants load error:', err);
    }
}

function renderTable(tenants) {
    if (!dataTable) {
        dataTable = createDataTable('#tenantsTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 3, className: 'text-center' },
                { targets: 5, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    dataTable.clear();

    tenants.forEach(tenant => {
        const isActive = tenant.is_active !== undefined ? !!Number(tenant.is_active) : true;
        const statusBadge = isActive
            ? '<span class="badge bg-success">Active</span>'
            : '<span class="badge bg-secondary">Inactive</span>';

        const toggleIcon = isActive ? 'fa-toggle-on text-success' : 'fa-toggle-off text-secondary';
        const toggleTitle = isActive ? 'Deactivate tenant' : 'Activate tenant';

        const actions = `
            <button class="btn btn-sm btn-outline-primary me-1" data-action="tenants.edit" title="Edit tenant">
                <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-light" data-action="tenants.toggleActive" title="${toggleTitle}">
                <i class="fas ${toggleIcon}"></i>
            </button>`;

        dataTable.row.add([
            tenant.id,
            escHtml(tenant.name || ''),
            escHtml(tenant.description || '—'),
            statusBadge,
            tenant.created_at || '',
            actions
        ]);
    });

    dataTable.draw(false);
    dataTable.columns.adjust();
}
