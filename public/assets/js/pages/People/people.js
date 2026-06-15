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

        // Wire type filter
        const typeFilter = document.getElementById('typeFilter');
        const clearBtn = document.getElementById('clearTypeFilter');
        if (typeFilter) {
            typeFilter.addEventListener('change', () => {
                const selected = [...typeFilter.selectedOptions].map(o => o.value);
                if (selected.length === 0) {
                    peopleDataTable.column(4).search('').draw();
                } else {
                    const regex = selected.join('|');
                    peopleDataTable.column(4).search(regex, true, false).draw();
                }
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (typeFilter) {
                    [...typeFilter.options].forEach(o => o.selected = false);
                }
                peopleDataTable.column(4).search('').draw();
            });
        }
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
