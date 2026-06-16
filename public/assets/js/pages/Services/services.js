// assets/js/pages/Services/services.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';
import { createMultiSelect } from '../../components/multiSelect.js';

let services_container;
let unregister;
let servicesUpdatedHandler;
let servicesDataTable = null;
let servicesCache = [];
let categoriesCache = [];
let categoryFilterSelect = null;

export async function mount() {
    await loadCategories();
    await fetchServices();

    servicesUpdatedHandler = async () => {
        await loadCategories();
        await fetchServices();
    };
    document.addEventListener('services.updated', servicesUpdatedHandler);

    unregister = registerPageActions({

        'services.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Services/serviceForm.html',
                controller: '../pages/Services/serviceForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'services.edit': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = servicesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Services/serviceForm.html',
                controller: '../pages/Services/serviceForm.modal.js',
                data: { service_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'services.deactivate': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = servicesDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Deactivate service',
                message: `Deactivate "${name}"? It will no longer appear in active listings.`
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/services/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to deactivate service' });
                } else {
                    await fetchServices();
                }
            } catch (err) {
                console.error('Deactivate service failed:', err);
            }
        },

        'services.manage-categories': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Services/serviceCategories.html',
                controller: '../pages/Services/serviceCategories.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'services.reactivate': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = servicesDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Reactivate service',
                message: `Reactivate "${name}"? It will appear in active listings again.`
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/services/${id}`, {
                    method: 'PUT',
                    body: JSON.stringify({ is_active: 1 })
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to reactivate service' });
                } else {
                    await fetchServices();
                }
            } catch (err) {
                console.error('Reactivate service failed:', err);
            }
        }
    });
}

export function unmount() {
    if (servicesUpdatedHandler) {
        document.removeEventListener('services.updated', servicesUpdatedHandler);
        servicesUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    categoryFilterSelect?.destroy();
    categoryFilterSelect = null;
    servicesDataTable?.destroy();
    servicesDataTable = null;
    servicesCache = [];
    categoriesCache = [];
    closeModalsInContainer(services_container);
}

export async function render(container) {
    services_container = container;
    const html = await fetch('html/Services/services.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    const permMap = {
        'services.create':            'services.create',
        'services.manage-categories': 'services.create',
    };
    container.querySelectorAll('[data-action]').forEach(el => {
        const action = el.dataset.action;
        const perm = permMap[action];
        if (perm && !hasPermission(perm)) { el.remove(); }
    });
}

async function loadCategories() {
    try {
        const res  = await apiFetch('/api/services/categories');
        const data = await res.json();
        categoriesCache = data.success ? (data.data.categories || data.data.service_categories || []) : [];
    } catch (err) {
        console.error('loadCategories failed:', err);
    }
}

async function fetchServices() {
    const res  = await apiFetch('/api/services');
    const data = await res.json();
    if (!data.success) return;
    servicesCache = data.data.services || [];
    renderTable(servicesCache);
}

function renderTable(services) {
    if (!servicesDataTable) {
        servicesDataTable = createDataTable('#servicesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 6, className: 'text-center', orderable: false, searchable: false }
            ]
        });

        // Build category filter next to search
        buildCategoryFilter();
    }

    servicesDataTable.clear();

    const canEdit   = hasPermission('services.edit');
    const canDelete = hasPermission('services.delete');

    services.forEach(service => {
        let actions = '';

        if (canEdit) {
            actions += `<button data-action="services.edit" class="btn btn-info btn-circle btn-sm me-1" title="Edit">
                            <i class="fas fa-edit"></i></button>`;
        }
        if (canDelete && parseInt(service.is_active) === 1) {
            actions += `<button data-action="services.deactivate" class="btn btn-danger btn-circle btn-sm me-1" title="Deactivate">
                            <i class="fas fa-ban"></i></button>`;
        }
        if (canEdit && parseInt(service.is_active) === 0) {
            actions += `<button data-action="services.reactivate" class="btn btn-success btn-circle btn-sm me-1" title="Reactivate">
                            <i class="fas fa-redo"></i></button>`;
        }

        const statusBadge = parseInt(service.is_active) === 1
            ? '<span class="badge bg-success text-white">Active</span>'
            : '<span class="badge bg-secondary text-white">Inactive</span>';

        servicesDataTable.row.add([
            service.id,
            escHtml(service.name),
            service.category_name || '—',
            service.unit || '—',
            service.rate != null ? getFormatter().formatNumber(service.rate, 2) : '—',
            statusBadge,
            actions
        ]);
    });

    servicesDataTable.draw(false);
    servicesDataTable.columns.adjust();
}

function escHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function buildCategoryFilter() {
    if (categoriesCache.length === 0) return;

    const dtSearch = services_container.querySelector('.dt-search') || services_container.querySelector('.dataTables_filter');
    if (!dtSearch) return;

    const filterHost = document.createElement('div');
    filterHost.style.minWidth = '220px';
    const searchParent = dtSearch.parentElement;
    searchParent.style.display = 'flex';
    searchParent.style.alignItems = 'flex-start';
    searchParent.style.gap = '1rem';
    searchParent.style.flexWrap = 'wrap';
    searchParent.insertBefore(filterHost, dtSearch);

    categoryFilterSelect = createMultiSelect({
        container: filterHost,
        placeholder: 'Filter by Category',
        icon: 'fas fa-folder',
        options: categoriesCache.map(c => ({ value: String(c.id), label: c.name })),
        onChange: (selectedValues) => {
            if (selectedValues.length === 0) {
                servicesDataTable.column(2).search('').draw();
            } else {
                const selectedNames = selectedValues.map(v => {
                    const c = categoriesCache.find(cat => String(cat.id) === v);
                    return c ? c.name : v;
                });
                const regex = selectedNames.map(s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
                servicesDataTable.column(2).search(regex, true, false).draw();
            }
        }
    });
}
