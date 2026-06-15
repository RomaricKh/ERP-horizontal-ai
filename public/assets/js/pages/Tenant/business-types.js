// assets/js/pages/Tenant/business-types.js

import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';

let bt_container;
let unregister;
let dataTable = null;
let loadedTypes = [];
let updatedHandler = null;

export async function render(container) {
    bt_container = container;
    const html = await fetch('html/Tenant/business-types.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    await loadBusinessTypes();

    updatedHandler = async () => { await loadBusinessTypes(); };
    document.addEventListener('businessTypes.updated', updatedHandler);

    unregister = registerPageActions({
        'tenant-business-types.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Tenant/businessTypeForm.html',
                controller: '../pages/Tenant/businessTypeForm.modal.js',
                data: { type_id: null, type: null, modules: [] },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'tenant-business-types.edit': async ({ el }) => {
            const typeId = parseInt(el.dataset.typeId, 10);
            if (!typeId) return;
            const type = loadedTypes.find(t => t.id === typeId);
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Tenant/businessTypeForm.html',
                controller: '../pages/Tenant/businessTypeForm.modal.js',
                data: { type_id: typeId, type, modules: type?.modules || type?.module_codes || [] },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'tenant-business-types.delete': async ({ el }) => {
            const typeId = parseInt(el.dataset.typeId, 10);
            if (!typeId) return;
            const type = loadedTypes.find(t => t.id === typeId);

            const confirmed = await confirm({
                title: 'Delete Business Type',
                message: `Delete the business type "${type?.name || typeId}"? This action cannot be undone.`
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/tenant/business-types/${typeId}`, { method: 'DELETE' });
                const body = await res.json();
                if (!res.ok || !body.success) {
                    await alert({ title: 'Error', alertText: body.message || 'Failed to delete business type.' });
                } else {
                    await loadBusinessTypes();
                }
            } catch (err) {
                console.error('Delete business type error:', err);
            }
        }
    });
}

export function unmount() {
    if (updatedHandler) {
        document.removeEventListener('businessTypes.updated', updatedHandler);
        updatedHandler = null;
    }
    unregister?.();
    unregister = null;
    dataTable?.destroy();
    dataTable = null;
    loadedTypes = [];
    closeModalsInContainer(bt_container);
}

// ─── Data Loading ────────────────────────────────────────────────────────────

async function loadBusinessTypes() {
    try {
        // Only load tenant-owned custom types (system types are just templates for creation)
        const res = await apiFetch('/api/tenant/business-types');
        const body = await res.json();

        const customTypes = (body.success ? body.data?.business_types : []) || [];
        customTypes.forEach(t => { t.is_system = false; });

        loadedTypes = customTypes;
        renderTable(loadedTypes);
    } catch (err) {
        console.error('Tenant business types load error:', err);
    }
}

// ─── Table Rendering ─────────────────────────────────────────────────────────

function renderTable(types) {
    if (!dataTable) {
        dataTable = createDataTable('#businessTypesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 4, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    dataTable.clear();

    types.forEach(type => {
        const moduleCount = Array.isArray(type.modules) ? type.modules.length : (type.module_count || 0);

        const actions = `
            <button class="btn btn-sm btn-outline-primary me-1" data-action="tenant-business-types.edit"
                    data-type-id="${type.id}" title="Edit type">
                <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" data-action="tenant-business-types.delete"
                    data-type-id="${type.id}" title="Delete type">
                <i class="fas fa-trash"></i>
            </button>`;

        dataTable.row.add([
            type.id,
            escHtml(type.name),
            escHtml(type.description || '—'),
            moduleCount,
            actions
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
