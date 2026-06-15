// assets/js/pages/Tenant/tenants.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, alert } from '../../components/modals.js';

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
                { targets: 3, orderable: false, searchable: false }
            ]
        });
    }

    dataTable.clear();

    tenants.forEach(tenant => {
        dataTable.row.add([
            tenant.id,
            escHtml(tenant.name || ''),
            escHtml(tenant.description || '—'),
            tenant.created_at || ''
        ]);
    });

    dataTable.draw(false);
    dataTable.columns.adjust();
}
