// assets/js/pages/Services/services.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let services_container;
let unregister;
let servicesUpdatedHandler;
let servicesDataTable = null;
let servicesCache = [];
let categoriesCache = [];

export async function mount() {
    await loadCategories();
    await fetchServices();

    servicesUpdatedHandler = async () => {
        await loadCategories();
        await fetchServices();
    };
    document.addEventListener('services.updated', servicesUpdatedHandler);

    // Set up category filter
    const categoryFilter = document.getElementById('serviceCategoryFilter');
    if (categoryFilter) {
        categoryFilter.addEventListener('change', applyFilters);
    }

    // Set up name search
    const nameSearch = document.getElementById('serviceNameSearch');
    if (nameSearch) {
        nameSearch.addEventListener('input', applyFilters);
    }

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
        categoriesCache = data.success ? (data.data.categories || []) : [];
        populateCategoryFilter();
    } catch (err) {
        console.error('loadCategories failed:', err);
    }
}

function populateCategoryFilter() {
    const sel = document.getElementById('serviceCategoryFilter');
    if (!sel) return;

    // Keep the first "All Categories" option, remove the rest
    while (sel.options.length > 1) sel.remove(1);

    categoriesCache.forEach(cat => {
        const opt = document.createElement('option');
        opt.value       = cat.id;
        opt.textContent = cat.name;
        sel.appendChild(opt);
    });
}

async function fetchServices() {
    const res  = await apiFetch('/api/services');
    const data = await res.json();
    if (!data.success) return;
    servicesCache = data.data.services || [];
    applyFilters();
}

function applyFilters() {
    const categoryFilter = document.getElementById('serviceCategoryFilter');
    const nameSearch     = document.getElementById('serviceNameSearch');

    const selectedCategory = categoryFilter?.value || '';
    const searchText       = (nameSearch?.value || '').trim().toLowerCase();

    let filtered = servicesCache;

    if (selectedCategory) {
        filtered = filtered.filter(s => String(s.category_id) === selectedCategory);
    }

    if (searchText) {
        filtered = filtered.filter(s => s.name.toLowerCase().includes(searchText));
    }

    renderTable(filtered);
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
