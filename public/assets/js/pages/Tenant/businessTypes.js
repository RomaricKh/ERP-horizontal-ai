// assets/js/pages/Tenant/businessTypes.js
// REQ-011: Tenant-owned business types management page

import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let container;
let unregister;
let dataTable = null;
let typesUpdatedHandler;
let currentEditTypeId = null;
let allModules = [];

export async function mount() {
    await loadAllModules();
    await fetchTypes();

    typesUpdatedHandler = async () => {
        await fetchTypes();
    };
    document.addEventListener('businessTypes.updated', typesUpdatedHandler);

    unregister = registerPageActions({
        'businessTypes.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Tenant/businessTypeForm.html',
                controller: '../pages/Tenant/businessTypeForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'businessTypes.editModules': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const typeId = rowData[0];
            currentEditTypeId = typeId;

            // Find the type data
            const res = await apiFetch('/api/tenant/business-types');
            const body = await res.json();
            if (!body.success) return;

            const types = body.data?.business_types || [];
            const type = types.find(t => t.id == typeId);
            if (!type) return;

            const activeModuleIds = (type.modules || []).map(m => m.id);

            // Populate the module toggle list
            const listEl = document.getElementById('moduleToggleList');
            listEl.innerHTML = '';

            allModules.forEach(mod => {
                const isActive = activeModuleIds.includes(mod.id);
                const col = document.createElement('div');
                col.className = 'col-md-6 col-lg-4 mb-2';
                col.innerHTML = `
                    <div class="form-check form-switch">
                        <input class="form-check-input module-toggle" type="checkbox"
                               id="mod_${mod.id}" value="${mod.id}" ${isActive ? 'checked' : ''}>
                        <label class="form-check-label" for="mod_${mod.id}">${mod.name}</label>
                    </div>
                `;
                listEl.appendChild(col);
            });

            // Show the modal
            const modalEl = document.getElementById('moduleToggleModal');
            modalEl.querySelector('.modal-title').textContent = `Modules — ${type.name}`;
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        },

        'businessTypes.saveModules': async () => {
            if (!currentEditTypeId) return;

            const checkboxes = document.querySelectorAll('#moduleToggleList .module-toggle:checked');
            const moduleIds = Array.from(checkboxes).map(cb => parseInt(cb.value, 10));

            try {
                const res = await apiFetch(`/api/tenant/business-types/${currentEditTypeId}/modules`, {
                    method: 'PUT',
                    body: JSON.stringify({ module_ids: moduleIds })
                });
                const body = await res.json();

                if (!res.ok || !body.success) {
                    await alert({ title: 'Error', alertText: body.message || 'Failed to update modules' });
                    return;
                }

                // Close modal
                const modalEl = document.getElementById('moduleToggleModal');
                bootstrap.Modal.getInstance(modalEl)?.hide();
                currentEditTypeId = null;

                await fetchTypes();
            } catch (err) {
                console.error('Save modules failed:', err);
            }
        },

        'businessTypes.delete': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const typeId = rowData[0];
            const typeName = rowData[1];

            const confirmed = await confirm({
                title: 'Delete Business Type',
                message: `Delete the business type "${typeName}"? This cannot be undone.`
            });

            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/tenant/business-types/${typeId}`, { method: 'DELETE' });
                const body = await res.json();

                if (!res.ok || !body.success) {
                    await alert({ title: 'Cannot Delete', alertText: body.message || 'Failed to delete' });
                } else {
                    await fetchTypes();
                }
            } catch (err) {
                console.error('Delete type failed:', err);
            }
        }
    });
}

export function unmount() {
    if (typesUpdatedHandler) {
        document.removeEventListener('businessTypes.updated', typesUpdatedHandler);
        typesUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    dataTable?.destroy();
    dataTable = null;
    closeModalsInContainer(container);
}

export async function render(cont) {
    container = cont;
    const html = await fetch('html/Tenant/businessTypes.html').then(r => r.text());
    container.innerHTML = html;
}

async function loadAllModules() {
    try {
        // Load system types to get the full module list
        const res = await apiFetch('/api/tenant/business-types/system');
        const body = await res.json();
        if (!body.success) return;

        const systemTypes = body.data?.system_types || [];
        // Collect unique modules from all system types
        const moduleMap = new Map();
        systemTypes.forEach(type => {
            (type.modules || []).forEach(mod => {
                moduleMap.set(mod.id, mod);
            });
        });
        allModules = Array.from(moduleMap.values());
    } catch (err) {
        console.error('Failed to load modules:', err);
    }
}

async function fetchTypes() {
    try {
        const res = await apiFetch('/api/tenant/business-types');
        const body = await res.json();
        if (!body.success) return;

        const types = body.data?.business_types || [];
        renderTable(types);
    } catch (err) {
        console.error('Failed to fetch business types:', err);
    }
}

function renderTable(types) {
    if (!dataTable) {
        dataTable = createDataTable('#businessTypesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: 3, orderable: false, searchable: false },
                { targets: 4, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    dataTable.clear();

    types.forEach(type => {
        const modules = (type.modules || []).map(m => 
            `<span class="badge bg-info me-1 mb-1">${m.name}</span>`
        ).join('');

        const actions = `
            <button data-action="businessTypes.editModules" title="Manage modules"
                class="btn btn-info btn-circle btn-sm me-1">
                <i class="fas fa-cogs"></i>
            </button>
            <button data-action="businessTypes.delete" title="Delete type"
                class="btn btn-danger btn-circle btn-sm">
                <i class="fas fa-trash"></i>
            </button>
        `;

        dataTable.row.add([
            type.id,
            type.name,
            type.code,
            modules || '<span class="text-muted">No modules</span>',
            actions
        ]);
    });

    dataTable.draw(false);
    dataTable.columns.adjust();
}
