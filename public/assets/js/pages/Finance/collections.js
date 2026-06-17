// assets/js/pages/Finance/collections.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

let collections_container;
let unregister;
let collectionsUpdatedHandler;
let collectionsDataTable = null;

export async function mount() {
    await fetchCollections();

    collectionsUpdatedHandler = async () => { await fetchCollections(); };
    document.addEventListener('collections.updated', collectionsUpdatedHandler);

    unregister = registerPageActions({

        'collections.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/collectionForm.html',
                controller: '../pages/Finance/collectionForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'collections.view': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = collectionsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/collectionForm.html',
                controller: '../pages/Finance/collectionForm.modal.js',
                data: { collection_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'collections.allocations': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = collectionsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/collectionAllocations.html',
                controller: '../pages/Finance/collectionAllocations.modal.js',
                data: {
                    collection_id: id,
                    collection: {
                        slip_number: rowData[1],
                        slip_date: rowData[2],
                        payee_name: rowData[3],
                        amount_fc: rowData[5],
                    }
                },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'collections.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = collectionsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete collection',
                message: 'Delete this collection? This will reverse the account balance addition and cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/collections/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete collection' });
                } else {
                    await fetchCollections();
                }
            } catch (err) {
                console.error('Delete collection failed:', err);
            }
        }
    });
}

export function unmount() {
    if (collectionsUpdatedHandler) {
        document.removeEventListener('collections.updated', collectionsUpdatedHandler);
        collectionsUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    collectionsDataTable?.destroy();
    collectionsDataTable = null;
    closeModalsInContainer(collections_container);
}

export async function render(container) {
    collections_container = container;
    const html = await fetch('html/Finance/collections.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'collections.create' && !hasPermission('collections.create')) {
            el.remove();
        }
    });
}

async function fetchCollections() {
    const res  = await apiFetch('/api/collections');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.collections ?? []);
}

function renderTable(collections) {
    if (!collectionsDataTable) {
        collectionsDataTable = createDataTable('#collectionsTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 8, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    collectionsDataTable.clear();

    const canDelete = hasPermission('collections.delete');
    const canView   = hasPermission('collections.view');

    collections.forEach(collection => {
        let actions = '';
        if (canView) {
            actions += `<button data-action="collections.view" class="btn btn-light btn-circle btn-sm me-1" title="View">
                            <i class="fas fa-eye text-info"></i></button>`;
            actions += `<button data-action="collections.allocations" class="btn btn-light btn-circle btn-sm me-1" title="Allocations">
                            <i class="fas fa-layer-group text-secondary"></i></button>`;
        }
        if (canDelete) {
            actions += `<button data-action="collections.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash text-danger"></i></button>`;
        }

        const amountFc = collection.amount_fc != null ? getFormatter().formatNumber(collection.amount_fc, 2) : '0.00';
        const amountBc = collection.amount_bc != null ? getFormatter().formatNumber(collection.amount_bc, 2) : '0.00';

        collectionsDataTable.row.add([
            collection.id,
            escHtml(collection.slip_number    ?? ''),
            getFormatter().formatDate(collection.slip_date ?? ''),
            escHtml(collection.payee_name     ?? '—'),
            escHtml(collection.account_name   ?? '—'),
            amountFc,
            amountBc,
            escHtml(collection.method         ?? ''),
            actions
        ]);
    });

    collectionsDataTable.draw(false);
    collectionsDataTable.columns.adjust();
}
