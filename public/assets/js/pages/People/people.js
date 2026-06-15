// assets/js/pages/People/people.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let people_container;
let unregister;
let peopleUpdatedHandler;
let peopleDataTable = null;

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

export async function mount() {
    await fetchPeople();

    peopleUpdatedHandler = async () => { await fetchPeople(); };
    document.addEventListener('people.updated', peopleUpdatedHandler);

    unregister = registerPageActions({

        'people.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/People/personForm.html',
                controller: '../pages/People/personForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'people.edit': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = peopleDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/People/personForm.html',
                controller: '../pages/People/personForm.modal.js',
                data: { person_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'people.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = peopleDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Delete person',
                message: `Remove "${name}" from your contacts?`
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/people/${id}`, { method: 'DELETE' });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    await alert({ title: 'Delete person', alertText: data.message || 'Failed to delete person' });
                } else {
                    await fetchPeople();
                    await alert({ title: 'Delete person', alertText: `"${name}" was removed successfully.` });
                }
            } catch (err) {
                console.error('Delete person failed:', err);
            }
        },

        'people.manage-types': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/People/payeeTypes.html',
                controller: '../pages/People/payeeTypes.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'people.insights': async ({ el }) => {
            const payeeId = el.dataset.payeeId;
            const insightType = el.dataset.insightType;
            const name = el.dataset.name;
            const payeeTypes = el.dataset.types || '';
            if (!payeeId || !insightType) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/People/insightsModal.html',
                controller: '../pages/People/insights.modal.js',
                data: { payee_id: payeeId, insight_type: insightType, person_name: name, payee_types: payeeTypes },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'people.customer-items': async ({ el }) => {
            const payeeId = el.dataset.payeeId;
            const name = el.dataset.name;
            if (!payeeId) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/People/customerItems.html',
                controller: '../pages/People/customerItems.modal.js',
                data: { payee_id: payeeId, person_name: name },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        }
    });
}

export function unmount() {
    if (peopleUpdatedHandler) {
        document.removeEventListener('people.updated', peopleUpdatedHandler);
        peopleUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    peopleDataTable?.destroy();
    peopleDataTable = null;
    closeModalsInContainer(people_container);
}

export async function render(container) {
    people_container = container;
    const html = await fetch('html/People/people.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    container.querySelectorAll('[data-action]').forEach(el => {
        const action = el.dataset.action;
        // map button actions to permission codes
        const permMap = {
            'people.create':       'people.create',
            'people.manage-types': 'people.create',
        };
        const perm = permMap[action];
        if (perm && !hasPermission(perm)) el.remove();
    });
}

async function fetchPeople() {
    const res  = await apiFetch('/api/people');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.people);
}

function renderTable(people) {
    if (!peopleDataTable) {
        peopleDataTable = createDataTable('#peopleTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 6, className: 'text-center', orderable: false, searchable: false }
            ]
        });

        // Build type filter dropdown next to the DataTables search box
        buildTypeFilterDropdown();
    }

    peopleDataTable.clear();

    const canEdit   = hasPermission('people.edit');
    const canDelete = hasPermission('people.delete');
    const canViewStatement = hasPermission('people.view');

    people.forEach(p => {
        let actions = '';

        if (canEdit) {
            actions += `<button data-action="people.edit" class="btn btn-info btn-circle btn-sm me-1" title="Edit">
                            <i class="fas fa-edit"></i></button>`;
        }
        // Show statement button only for people who have a payee (financial identity)
        if (canViewStatement && p.payee_id) {
            actions += `<a href="#/people/statement?id=${p.payee_id}" class="btn btn-outline-primary btn-circle btn-sm me-1" title="View Statement">
                            <i class="fas fa-file-invoice-dollar"></i></a>`;
        }
        // Insight buttons based on payee type
        if (p.payee_id) {
            const types = (p.payee_types || '').toLowerCase();
            if (types.includes('supplier')) {
                actions += `<button data-action="people.insights" data-insight-type="supplier" data-payee-id="${p.payee_id}" data-name="${escHtml(p.name)}" data-types="${escHtml(p.payee_types || '')}" class="btn btn-outline-success btn-circle btn-sm me-1" title="Purchase Insights">
                                <i class="fas fa-shopping-cart"></i></button>`;
            }
            if (types.includes('customer')) {
                actions += `<button data-action="people.insights" data-insight-type="customer" data-payee-id="${p.payee_id}" data-name="${escHtml(p.name)}" data-types="${escHtml(p.payee_types || '')}" class="btn btn-outline-info btn-circle btn-sm me-1" title="Sales Insights">
                                <i class="fas fa-chart-line"></i></button>`;
                // Customer items stock button (for customers with specific items)
                if (p.has_customer_items) {
                    actions += `<button data-action="people.customer-items" data-payee-id="${p.payee_id}" data-name="${escHtml(p.name)}" class="btn btn-outline-warning btn-circle btn-sm me-1" title="Customer Items & Stock">
                                    <i class="fas fa-boxes"></i></button>`;
                }
            }
            if (types.includes('partner')) {
                actions += `<button data-action="people.insights" data-insight-type="partner" data-payee-id="${p.payee_id}" data-name="${escHtml(p.name)}" data-types="${escHtml(p.payee_types || '')}" class="btn btn-outline-primary btn-circle btn-sm me-1" title="Partner Insights">
                                <i class="fas fa-handshake"></i></button>`;
            }
            if (!types.includes('supplier') && !types.includes('customer') && !types.includes('partner')) {
                actions += `<button data-action="people.insights" data-insight-type="general" data-payee-id="${p.payee_id}" data-name="${escHtml(p.name)}" data-types="${escHtml(p.payee_types || '')}" class="btn btn-outline-secondary btn-circle btn-sm me-1" title="Usage Insights">
                                <i class="fas fa-chart-pie"></i></button>`;
            }
        }
        if (canDelete) {
            actions += `<button data-action="people.delete" class="btn btn-danger btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash"></i></button>`;
        }

        const balance = p.payee_id
            ? `<span class="${parseFloat(p.balance) < 0 ? 'text-danger' : 'text-success'}">${getFormatter().formatNumber(p.balance, 2)}</span>`
            : '<span class="text-muted">—</span>';

        peopleDataTable.row.add([
            p.id,
            p.name,
            p.phone ? getFormatter().formatPhone(p.phone) : '—',
            p.email  || '—',
            p.payee_types || '—',
            balance,
            actions
        ]);
    });

    peopleDataTable.draw(false);
    peopleDataTable.columns.adjust();
}

async function buildTypeFilterDropdown() {
    // Fetch payee types from the API
    let types = [];
    try {
        const res = await apiFetch('/api/people/payee-types');
        const json = await res.json();
        if (json.success) {
            types = json.data?.payee_types || [];
        }
    } catch (err) {
        console.error('Failed to load payee types for filter:', err);
    }

    if (types.length === 0) return;

    // Find the DataTables search container (DT2 uses .dt-search, legacy uses .dataTables_filter)
    const dtSearch = people_container.querySelector('.dt-search') || people_container.querySelector('.dataTables_filter');
    if (!dtSearch) return;

    // Build the custom multi-select dropdown
    const wrapper = document.createElement('div');
    wrapper.className = 'dropdown d-inline-block';
    wrapper.style.verticalAlign = 'middle';

    const toggleBtn = document.createElement('button');
    toggleBtn.type = 'button';
    toggleBtn.className = 'btn btn-outline-secondary btn-sm';
    toggleBtn.setAttribute('data-bs-toggle', 'dropdown');
    toggleBtn.setAttribute('data-bs-auto-close', 'outside');
    toggleBtn.innerHTML = '<i class="fas fa-filter me-1"></i>Filter by Type';

    const menu = document.createElement('div');
    menu.className = 'dropdown-menu p-2 shadow';
    menu.style.minWidth = '200px';

    // Build checkbox list
    types.forEach(type => {
        const item = document.createElement('div');
        item.className = 'form-check py-1';
        item.innerHTML = `
            <input class="form-check-input type-filter-cb" type="checkbox" value="${escHtml(type.name)}" id="typeFilter_${type.id}">
            <label class="form-check-label" for="typeFilter_${type.id}">${escHtml(type.name)}</label>
        `;
        menu.appendChild(item);
    });

    // Divider + clear button
    const divider = document.createElement('div');
    divider.className = 'dropdown-divider';
    menu.appendChild(divider);

    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'btn btn-sm btn-link text-decoration-none w-100 text-start';
    clearBtn.innerHTML = '<i class="fas fa-times me-1"></i>Clear all';
    clearBtn.addEventListener('click', () => {
        menu.querySelectorAll('.type-filter-cb').forEach(cb => { cb.checked = false; });
        applyTypeFilter();
    });
    menu.appendChild(clearBtn);

    wrapper.appendChild(toggleBtn);
    wrapper.appendChild(menu);

    // Insert next to the search input
    const searchParent = dtSearch.parentElement;
    searchParent.style.display = 'flex';
    searchParent.style.alignItems = 'center';
    searchParent.style.gap = '0.75rem';
    searchParent.insertBefore(wrapper, dtSearch);

    // Wire checkbox change events
    menu.addEventListener('change', (e) => {
        if (e.target.classList.contains('type-filter-cb')) {
            applyTypeFilter();
        }
    });
}

function applyTypeFilter() {
    const checkboxes = people_container.querySelectorAll('.type-filter-cb:checked');
    const selected = [...checkboxes].map(cb => cb.value);

    if (selected.length === 0) {
        peopleDataTable.column(4).search('').draw();
        // Reset button label
        const btn = people_container.querySelector('.type-filter-wrapper .dropdown-toggle');
        if (btn) btn.innerHTML = '<i class="fas fa-filter me-1"></i>Filter by Type';
    } else {
        // Build regex: match any of the selected types (case-insensitive word match)
        const regex = selected.map(s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
        peopleDataTable.column(4).search(regex, true, false).draw();
        // Update button label to show count
        const btn = people_container.querySelector('.type-filter-wrapper .dropdown-toggle');
        if (btn) btn.innerHTML = `<i class="fas fa-filter me-1"></i>Types (${selected.length})`;
    }
}
