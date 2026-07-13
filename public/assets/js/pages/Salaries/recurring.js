// assets/js/pages/Salaries/recurring.js

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

let container;
let unregister;
let instancesUpdatedHandler;
let recurringDataTable = null;
let currentFilter = 'all';
let allInstances = [];

export async function mount() {
    await fetchInstances();

    instancesUpdatedHandler = async () => { await fetchInstances(); };
    document.addEventListener('recurring-salaries.updated', instancesUpdatedHandler);
    document.addEventListener('payments.updated', instancesUpdatedHandler);

    unregister = registerPageActions({

        'recurring-salaries.mark-paid': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = recurringDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const instance = allInstances.find(i => String(i.id) === String(id));
            if (!instance) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Salaries/salaryPaymentForm.html',
                controller: '../pages/Salaries/salaryPayment.modal.js',
                data: { instance },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'recurring-salaries.skip': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = recurringDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Skip Salary Instance',
                message: 'Skip this recurring salary instance? No payment will be recorded for this period.'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/recurring-instances/${id}/status`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ status: 'skipped' })
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to skip instance' });
                } else {
                    await fetchInstances();
                }
            } catch (err) {
                console.error('Skip salary instance failed:', err);
            }
        },

        'recurring-salaries.pay-early': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = recurringDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const instance = allInstances.find(i => String(i.id) === String(id));
            if (!instance) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Salaries/salaryPaymentForm.html',
                controller: '../pages/Salaries/salaryPayment.modal.js',
                data: { instance },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'recurring-salaries.bulk-advance': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Expenses/bulkAdvanceModal.html',
                controller: '../pages/Expenses/bulkAdvance.modal.js',
                data: { entity_type: 'SALARY' },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        }
    });
}

export function unmount() {
    if (instancesUpdatedHandler) {
        document.removeEventListener('recurring-salaries.updated', instancesUpdatedHandler);
        document.removeEventListener('payments.updated', instancesUpdatedHandler);
        instancesUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    recurringDataTable?.destroy();
    recurringDataTable = null;
    allInstances = [];
    closeModalsInContainer(container);
}

export async function render(pageContainer) {
    container = pageContainer;
    const html = await fetch('html/Salaries/recurring.html').then(r => r.text());
    container.innerHTML = html;

    // Hide bulk advance button if no permission
    container.querySelectorAll('[data-action]').forEach(el => {
        const action = el.dataset.action;
        const permMap = {
            'recurring-salaries.bulk-advance': 'salaries.edit',
        };
        const perm = permMap[action];
        if (perm && !hasPermission(perm)) el.remove();
    });

    // Bind filter buttons
    container.querySelectorAll('[data-filter]').forEach(btn => {
        btn.addEventListener('click', () => {
            container.querySelectorAll('[data-filter]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            renderTable(filterInstances(allInstances));
        });
    });
}

async function fetchInstances() {
    try {
        const businessId = localStorage.getItem('business_id');
        if (!businessId || businessId === 'null') {
            showEmptyMessage('No business selected. Please log out and log back in to set your active business.');
            return;
        }

        const [schedulesRes, instancesRes] = await Promise.all([
            apiFetch('/api/recurrences?entity_type=SALARY'),
            apiFetch('/api/recurring-instances?entity_type=SALARY')
        ]);

        const schedulesData = await schedulesRes.json();
        const instancesData = await instancesRes.json();

        const schedules = schedulesData.success ? (schedulesData.data?.schedules ?? []) : [];
        const instances = instancesData.success ? (instancesData.data?.instances ?? []) : [];

        // Build a map of schedules that have instances
        const schedulesWithInstances = new Set(instances.map(i => i.recurrence_schedule_id));

        // Create placeholders for schedules that don't have any instances yet
        const placeholders = schedules
            .filter(s => !schedulesWithInstances.has(s.id))
            .map(s => ({
                id: `schedule_${s.id}`,
                entity_name: s.entity_name || `Schedule #${s.id}`,
                due_date: s.start_date,
                scheduled_amount: s.default_amount,
                currency: s.default_currency,
                status: 'pending',
                days_overdue: 0,
                is_schedule: true,
                frequency: s.frequency,
                payee_id: s.entity_id || null,
                exchange_rate: s.exchange_rate || 1.0,
                metadata: s.metadata || {}
            }));

        allInstances = [...instances, ...placeholders];

        renderOverdueSection(allInstances);
        renderTable(filterInstances(allInstances));
    } catch (err) {
        console.error('Failed to fetch recurring salary instances:', err);
    }
}

function showEmptyMessage(message) {
    const card = container?.querySelector('.card-body');
    if (card) {
        card.innerHTML = `
            <div class="text-center py-5 text-muted">
                <i class="fas fa-info-circle fa-2x mb-3"></i>
                <p>${message}</p>
            </div>`;
    }
}

function filterInstances(instances) {
    if (currentFilter === 'all') return instances;
    return instances.filter(i => i.status === currentFilter);
}

function renderOverdueSection(instances) {
    const overdueSection = container.querySelector('#overdueSection');
    const overdueList = container.querySelector('#overdueList');
    if (!overdueSection || !overdueList) return;

    const overdueInstances = instances
        .filter(i => i.status === 'overdue')
        .sort((a, b) => (b.days_overdue ?? 0) - (a.days_overdue ?? 0));

    if (overdueInstances.length === 0) {
        overdueSection.classList.add('d-none');
        return;
    }

    overdueSection.classList.remove('d-none');
    overdueList.innerHTML = overdueInstances.map(instance => `
        <div class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-user fa-sm text-muted me-2" title="Employee"></i>
                <strong>${escHtml(instance.entity_name)}</strong>
                <span class="text-muted ms-2">${getFormatter().formatDate(instance.due_date)}</span>
                <span class="ms-2">${getFormatter().formatNumber(instance.scheduled_amount, 2)} ${escHtml(instance.currency)}</span>
            </div>
            <span class="badge bg-danger rounded-pill">${instance.days_overdue ?? 0} days overdue</span>
        </div>
    `).join('');
}

function renderTable(instances) {
    if (!recurringDataTable) {
        recurringDataTable = createDataTable('#recurringSalariesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 8, className: 'text-center', orderable: false, searchable: false }
            ],
            order: [[2, 'asc']]
        });
    }

    recurringDataTable.clear();

    const canEdit = hasPermission('salaries.edit');

    instances.forEach(instance => {
        const actions = buildActions(instance, canEdit);
        const statusBadge = buildStatusBadge(instance);
        const grossAmount = instance.scheduled_amount != null
            ? getFormatter().formatNumber(instance.scheduled_amount, 2)
            : '0.00';
        const taxAmount = instance.tax_amount != null
            ? getFormatter().formatNumber(instance.tax_amount, 2)
            : (instance.metadata?.tax_fc != null
                ? getFormatter().formatNumber(instance.metadata.tax_fc, 2)
                : '0.00');
        const daysOverdue = instance.status === 'overdue' ? (instance.days_overdue ?? 0) : '—';

        recurringDataTable.row.add([
            instance.id,
            buildEmployeeDisplay(instance),
            getFormatter().formatDate(instance.due_date ?? ''),
            grossAmount,
            taxAmount,
            escHtml(instance.currency ?? ''),
            statusBadge,
            daysOverdue,
            actions
        ]);
    });

    recurringDataTable.draw(false);
    recurringDataTable.columns.adjust();
}

function buildEmployeeDisplay(instance) {
    let display = `<i class="fas fa-sync fa-sm text-primary me-1" title="Recurring"></i>`;
    display += escHtml(instance.entity_name ?? instance.employee_name ?? '—');
    if (instance.frequency) {
        display += ` <span class="badge bg-light text-dark ms-1">${escHtml(instance.frequency)}</span>`;
    }
    return display;
}

function buildStatusBadge(instance) {
    const statusMap = {
        pending: { class: 'bg-warning text-dark', label: 'Pending' },
        paid:    { class: 'bg-success', label: 'Paid' },
        skipped: { class: 'bg-secondary', label: 'Skipped' },
        overdue: { class: 'bg-danger', label: 'Overdue' }
    };
    const info = statusMap[instance.status] ?? { class: 'bg-light text-dark', label: instance.status };
    let badge = `<span class="badge ${info.class}">${info.label}</span>`;

    if (instance.is_advance_payment) {
        badge += ` <span class="badge bg-info">Advance</span>`;
    }

    return badge;
}

function buildActions(instance, canEdit) {
    if (!canEdit) return '';
    if (instance.is_schedule) return '';

    let actions = '';

    if (instance.status === 'pending') {
        actions += `<button data-action="recurring-salaries.mark-paid" class="btn btn-light btn-circle btn-sm me-1" title="Mark Paid">
                        <i class="fas fa-check text-success"></i></button>`;
        actions += `<button data-action="recurring-salaries.skip" class="btn btn-light btn-circle btn-sm me-1" title="Skip">
                        <i class="fas fa-forward text-secondary"></i></button>`;

        const today = new Date().toISOString().split('T')[0];
        if (instance.due_date > today) {
            actions += `<button data-action="recurring-salaries.pay-early" class="btn btn-light btn-circle btn-sm me-1" title="Pay Early">
                            <i class="fas fa-clock text-info"></i></button>`;
        }
    }

    if (instance.status === 'overdue') {
        actions += `<button data-action="recurring-salaries.mark-paid" class="btn btn-light btn-circle btn-sm me-1" title="Mark Paid">
                        <i class="fas fa-check text-success"></i></button>`;
        actions += `<button data-action="recurring-salaries.skip" class="btn btn-light btn-circle btn-sm me-1" title="Skip">
                        <i class="fas fa-forward text-secondary"></i></button>`;
    }

    return actions;
}
