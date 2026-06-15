// assets/js/pages/Tenant/owners.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';

let container;
let unregister;
let dataTable = null;
let updatedHandler;

export async function render(cont) {
    container = cont;
    const html = await fetch('html/Tenant/owners.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    await loadOwners();

    updatedHandler = () => loadOwners();
    document.addEventListener('tenant-owners.updated', updatedHandler);

    unregister = registerPageActions({
        'tenant-owners.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Tenant/ownerForm.html',
                controller: '../pages/Tenant/ownerForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'tenant-owners.revoke': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const userId = rowData[0];
            const name = rowData[2];

            const confirmed = await confirm({
                title: 'Revoke Tenant Owner',
                message: `Remove Tenant Owner privileges from "${name}"? They will lose access to all businesses in this tenant.`
            });

            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/tenant/owners/${userId}`, { method: 'DELETE' });
                const body = await res.json();

                if (!res.ok || !body.success) {
                    await alert({ title: 'Error', alertText: body.message || 'Failed to revoke owner.' });
                    return;
                }

                await loadOwners();
            } catch (err) {
                console.error('Revoke owner error:', err);
            }
        }
    });
}

export function unmount() {
    document.removeEventListener('tenant-owners.updated', updatedHandler);
    updatedHandler = null;
    unregister?.();
    unregister = null;
    dataTable?.destroy();
    dataTable = null;
    closeModalsInContainer(container);
}

async function loadOwners() {
    try {
        const res = await apiFetch('/api/tenant/owners');
        const body = await res.json();

        if (!res.ok || !body.success) {
            console.error('Failed to load tenant owners:', body.message);
            return;
        }

        renderTable(body.data?.owners || []);
    } catch (err) {
        console.error('Tenant owners load error:', err);
    }
}

function renderTable(owners) {
    if (!dataTable) {
        dataTable = createDataTable('#ownersTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 5, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    dataTable.clear();

    owners.forEach(owner => {
        dataTable.row.add([
            owner.user_id,
            escHtml(owner.username || ''),
            escHtml(owner.full_name || ''),
            escHtml(owner.email || ''),
            owner.designated_at || '',
            `<button class="btn btn-sm btn-outline-danger" data-action="tenant-owners.revoke" title="Revoke ownership">
                <i class="fas fa-user-times"></i>
            </button>`
        ]);
    });

    dataTable.draw(false);
    dataTable.columns.adjust();
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
