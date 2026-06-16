// assets/js/pages/Assets/assets.js

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

let assets_container;
let unregister;
let dataTable = null;
let updatedHandler = null;

export async function render(container) {
    assets_container = container;
    const html = await fetch('html/Assets/assets.html').then(r => r.text());
    container.innerHTML = html;

    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'assets.create' && !hasPermission('assets.manage')) {
            el.remove();
        }
    });
}

export async function mount() {
    await fetchAssets();

    updatedHandler = () => fetchAssets();
    document.addEventListener('assets.updated', updatedHandler);

    unregister = registerPageActions({
        'assets.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Assets/assetForm.html',
                controller: '../pages/Assets/assetForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'assets.view': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Assets/assetForm.html',
                controller: '../pages/Assets/assetForm.modal.js',
                data: { asset_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'assets.depreciate': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Record Depreciation',
                message: 'Record a depreciation entry for this asset?'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/assets/${id}/depreciate`, { method: 'POST' });
                const body = await res.json();
                if (!res.ok || !body.success) {
                    await alert({ title: 'Error', alertText: body.message || 'Depreciation failed' });
                } else {
                    await fetchAssets();
                }
            } catch (err) {
                console.error('Depreciate error:', err);
            }
        },

        'assets.delete': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Delete Asset',
                message: 'Delete this asset? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/assets/${id}`, { method: 'DELETE' });
                const body = await res.json();
                if (!res.ok || !body.success) {
                    await alert({ title: 'Error', alertText: body.message || 'Failed to delete asset' });
                } else {
                    await fetchAssets();
                }
            } catch (err) {
                console.error('Delete asset error:', err);
            }
        }
    });
}

export function unmount() {
    if (updatedHandler) {
        document.removeEventListener('assets.updated', updatedHandler);
        updatedHandler = null;
    }
    unregister?.();
    unregister = null;
    dataTable?.destroy();
    dataTable = null;
    closeModalsInContainer(assets_container);
}

async function fetchAssets() {
    try {
        const res = await apiFetch('/api/assets');
        const body = await res.json();
        if (!body.success) return;
        renderTable(body.data?.assets ?? []);
    } catch (err) {
        console.error('Failed to fetch assets:', err);
    }
}

function renderTable(assets) {
    const canManage = hasPermission('assets.manage');

    if (!dataTable) {
        dataTable = createDataTable('#assetsTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 7, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    dataTable.clear();

    assets.forEach(asset => {
        let actions = '';

        actions += `<button data-action="assets.view" class="btn btn-light btn-circle btn-sm me-1" title="View / Edit">
                        <i class="fas fa-eye text-info"></i></button>`;

        if (canManage) {
            actions += `<button data-action="assets.depreciate" class="btn btn-light btn-circle btn-sm me-1" title="Depreciate">
                            <i class="fas fa-chart-line text-warning"></i></button>`;
            actions += `<button data-action="assets.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash text-danger"></i></button>`;
        }

        dataTable.row.add([
            asset.id,
            escHtml(asset.name || ''),
            escHtml(asset.category_name || asset.category || ''),
            escHtml(asset.asset_type || ''),
            asset.purchase_date || '',
            getFormatter().formatNumber(asset.purchase_value || 0, 2),
            getFormatter().formatNumber(asset.current_value ?? asset.purchase_value ?? 0, 2),
            actions
        ]);
    });

    dataTable.draw(false);
    dataTable.columns.adjust();
}
