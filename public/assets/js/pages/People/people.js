// assets/js/pages/People/people.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';
import { createMultiSelect } from '../../components/multiSelect.js';

let people_container;
let unregister;
let peopleUpdatedHandler;
let peopleDataTable = null;
let typeFilterSelect = null;

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
    typeFilterSelect?.destroy();
    typeFilterSelect = null;
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
        const types = (p.payee_types || '').toLowerCase();
        let actionItems = [];

        if (canEdit) {
            actionItems.push({ action: 'people.edit', icon: 'fa-edit text-info', label: 'Edit' });
        }
        if (canViewStatement && p.payee_id) {
            actionItems.push({ href: `#/people/statement?id=${p.payee_id}`, icon: 'fa-file-invoice-dollar text-primary', label: 'Statement' });
        }
        if (p.payee_id && types.includes('supplier')) {
            actionItems.push({ action: 'people.insights', data: { insightType: 'supplier', payeeId: p.payee_id, name: p.name, types: p.payee_types || '' }, icon: 'fa-shopping-cart text-success', label: 'Purchase Insights' });
        }
        if (p.payee_id && types.includes('customer')) {
            actionItems.push({ action: 'people.insights', data: { insightType: 'customer', payeeId: p.payee_id, name: p.name, types: p.payee_types || '' }, icon: 'fa-chart-line text-info', label: 'Sales Insights' });
            if (p.has_customer_items) {
                actionItems.push({ action: 'people.customer-items', data: { payeeId: p.payee_id, name: p.name }, icon: 'fa-boxes text-warning', label: 'Customer Items' });
            }
        }
        if (p.payee_id && types.includes('partner')) {
            actionItems.push({ action: 'people.insights', data: { insightType: 'partner', payeeId: p.payee_id, name: p.name, types: p.payee_types || '' }, icon: 'fa-handshake text-primary', label: 'Partner Insights' });
        }
        if (p.payee_id && !types.includes('supplier') && !types.includes('customer') && !types.includes('partner')) {
            actionItems.push({ action: 'people.insights', data: { insightType: 'general', payeeId: p.payee_id, name: p.name, types: p.payee_types || '' }, icon: 'fa-chart-pie text-secondary', label: 'Usage Insights' });
        }
        if (canDelete) {
            actionItems.push({ action: 'people.delete', icon: 'fa-trash text-danger', label: 'Delete', divider: true });
        }

        // Render as dropdown if more than 3 actions, otherwise inline buttons
        let actions = '';
        if (actionItems.length <= 3) {
            actions = actionItems.map(item => renderInlineAction(item)).join('');
        } else {
            // First 2 actions inline, rest in dropdown
            const inline = actionItems.slice(0, 2);
            const overflow = actionItems.slice(2);
            actions = inline.map(item => renderInlineAction(item)).join('');
            actions += `<div class="dropdown d-inline-block">
                <button class="btn btn-light btn-circle btn-sm" data-bs-toggle="dropdown" data-bs-auto-close="true" title="More actions">
                    <i class="fas fa-ellipsis-v"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    ${overflow.map(item => renderDropdownItem(item)).join('')}
                </ul>
            </div>`;
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

    // Find the DataTables top layout row to place filter next to search
    const dtSearch = people_container.querySelector('.dt-search') || people_container.querySelector('.dataTables_filter');
    if (!dtSearch) return;

    // Create a wrapper container to sit beside the search
    const filterHost = document.createElement('div');
    filterHost.className = 'd-inline-block';
    dtSearch.parentElement.style.display = 'flex';
    dtSearch.parentElement.style.alignItems = 'flex-start';
    dtSearch.parentElement.style.gap = '0.75rem';
    dtSearch.parentElement.insertBefore(filterHost, dtSearch);

    // Use the custom multi-select component
    typeFilterSelect = createMultiSelect({
        container: filterHost,
        placeholder: 'Filter by Type',
        options: types.map(t => ({ id: t.id, label: t.name })),
        onChange: (selectedLabels) => {
            if (selectedLabels.length === 0) {
                peopleDataTable.column(4).search('').draw();
            } else {
                const regex = selectedLabels.map(s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
                peopleDataTable.column(4).search(regex, true, false).draw();
            }
        }
    });
}

function renderInlineAction(item) {
    if (item.href) {
        return `<a href="${item.href}" class="btn btn-light btn-circle btn-sm me-1" title="${escHtml(item.label)}">
                    <i class="fas ${item.icon}"></i></a>`;
    }
    const dataAttrs = buildDataAttrs(item);
    return `<button data-action="${item.action}" ${dataAttrs} class="btn btn-light btn-circle btn-sm me-1" title="${escHtml(item.label)}">
                <i class="fas ${item.icon}"></i></button>`;
}

function renderDropdownItem(item) {
    const divider = item.divider ? '<li><hr class="dropdown-divider"></li>' : '';
    if (item.href) {
        return `${divider}<li><a class="dropdown-item small" href="${item.href}">
                    <i class="fas ${item.icon} me-2"></i>${escHtml(item.label)}</a></li>`;
    }
    const dataAttrs = buildDataAttrs(item);
    return `${divider}<li><button class="dropdown-item small" data-action="${item.action}" ${dataAttrs}>
                <i class="fas ${item.icon} me-2"></i>${escHtml(item.label)}</button></li>`;
}

function buildDataAttrs(item) {
    if (!item.data) return '';
    let attrs = '';
    if (item.data.insightType) attrs += ` data-insight-type="${item.data.insightType}"`;
    if (item.data.payeeId) attrs += ` data-payee-id="${item.data.payeeId}"`;
    if (item.data.name) attrs += ` data-name="${escHtml(item.data.name)}"`;
    if (item.data.types) attrs += ` data-types="${escHtml(item.data.types)}"`;
    return attrs;
}
