// assets/js/pages/Expenses/recurring.js

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
    await fetchAdvancePaidSummary();

    instancesUpdatedHandler = async () => {
        await fetchInstances();
        await fetchAdvancePaidSummary();
    };
    document.addEventListener('recurring-expenses.updated', instancesUpdatedHandler);
    document.addEventListener('payments.updated', instancesUpdatedHandler);

    unregister = registerPageActions({

        'recurring-expenses.view-advance-group': async ({ el }) => {
            const bapId = el.dataset.bapId;
            if (!bapId) return;
            await showAdvancePaymentGroup(bapId);
        },

        'recurring-expenses.mark-paid': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = recurringDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            // Find the instance data to prefill the payment form
            const instance = allInstances.find(i => String(i.id) === String(id));
            if (!instance) return;

            // Open the standard Payment form with prefilled data
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/paymentForm.html',
                controller: '../pages/Finance/paymentForm.modal.js',
                data: {
                    prefill: {
                        payee_id: instance.payee_id,
                        currency: instance.currency,
                        exchange_rate: instance.exchange_rate,
                        amount_fc: instance.scheduled_amount,
                    },
                    recurring_instance_id: instance.id
                },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'recurring-expenses.skip': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = recurringDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Skip Instance',
                message: 'Skip this recurring expense instance? No payment will be recorded for this period.'
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
                console.error('Skip instance failed:', err);
            }
        },

        'recurring-expenses.pay-early': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = recurringDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            // Same as mark-paid — open Payment form with prefill
            const instance = allInstances.find(i => String(i.id) === String(id));
            if (!instance) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/paymentForm.html',
                controller: '../pages/Finance/paymentForm.modal.js',
                data: {
                    prefill: {
                        payee_id: instance.payee_id,
                        currency: instance.currency,
                        exchange_rate: instance.exchange_rate,
                        amount_fc: instance.scheduled_amount,
                    },
                    recurring_instance_id: instance.id
                },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'recurring-expenses.bulk-advance': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Expenses/bulkAdvanceModal.html',
                controller: '../pages/Expenses/bulkAdvance.modal.js',
                data: { entity_type: 'EXPENSE' },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'recurring-expenses.view-history': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = recurringDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const instance = allInstances.find(i => String(i.id) === String(id));
            if (!instance) return;

            // Determine entity_type and entity_id for the audit log query
            const isSchedule = instance.is_schedule === true;
            const entityType = isSchedule ? 'schedule' : 'instance';
            const entityId = isSchedule ? String(id).replace('schedule_', '') : id;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Expenses/auditHistoryModal.html',
                controller: '../pages/Expenses/auditHistory.modal.js',
                data: {
                    entity_type: entityType,
                    entity_id: entityId,
                    entity_name: instance.entity_name ?? ''
                },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        }
    });
}

export function unmount() {
    if (instancesUpdatedHandler) {
        document.removeEventListener('recurring-expenses.updated', instancesUpdatedHandler);
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
    const html = await fetch('html/Expenses/recurring.html').then(r => r.text());
    container.innerHTML = html;

    // Hide bulk advance button if no permission
    container.querySelectorAll('[data-action]').forEach(el => {
        const action = el.dataset.action;
        const permMap = {
            'recurring-expenses.bulk-advance': 'expenses.manage',
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

    // Bind close button for advance group section
    const closeAdvanceGroupBtn = container.querySelector('#closeAdvanceGroup');
    if (closeAdvanceGroupBtn) {
        closeAdvanceGroupBtn.addEventListener('click', () => {
            const section = container.querySelector('#advanceGroupSection');
            if (section) section.classList.add('d-none');
        });
    }
}

async function fetchInstances() {
    try {
        // Check if business_id is set (tenant owners need a business context)
        const businessId = localStorage.getItem('business_id');
        if (!businessId || businessId === 'null') {
            showEmptyMessage('No business selected. Please log out and log back in to set your active business.');
            return;
        }

        // Fetch both schedules and instances for expense recurrences
        const [schedulesRes, instancesRes] = await Promise.all([
            apiFetch('/api/recurrences?entity_type=EXPENSE'),
            apiFetch('/api/recurring-instances?entity_type=EXPENSE')
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
                payee_id: s.payee_id || null,
                exchange_rate: s.exchange_rate || 1.0
            }));

        allInstances = [...instances, ...placeholders];

        renderOverdueSection(allInstances);
        renderTable(filterInstances(allInstances));
    } catch (err) {
        console.error('Failed to fetch recurring instances:', err);
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
                <i class="fas fa-sync fa-sm text-muted me-2" title="Recurring"></i>
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
        recurringDataTable = createDataTable('#recurringExpensesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 7, className: 'text-center', orderable: false, searchable: false }
            ],
            order: [[2, 'asc']]
        });
    }

    recurringDataTable.clear();

    const canEdit = hasPermission('expenses.edit');

    instances.forEach(instance => {
        const actions = buildActions(instance, canEdit);
        const statusBadge = buildStatusBadge(instance);
        const categoryDisplay = buildCategoryDisplay(instance);
        const amount = instance.scheduled_amount != null
            ? getFormatter().formatNumber(instance.scheduled_amount, 2)
            : '0.00';
        const daysOverdue = instance.status === 'overdue' ? (instance.days_overdue ?? 0) : '—';

        recurringDataTable.row.add([
            instance.id,
            categoryDisplay,
            getFormatter().formatDate(instance.due_date ?? ''),
            amount,
            escHtml(instance.currency ?? ''),
            statusBadge,
            daysOverdue,
            actions
        ]);
    });

    recurringDataTable.draw(false);
    recurringDataTable.columns.adjust();
}

function buildCategoryDisplay(instance) {
    let display = `<i class="fas fa-sync fa-sm text-primary me-1" title="Recurring"></i>`;
    display += escHtml(instance.entity_name ?? '—');
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

    // Show "Advance" badge for advance-paid instances
    if (instance.is_advance_payment) {
        badge += ` <span class="badge bg-info">Advance</span>`;
    }

    return badge;
}

function buildActions(instance, canEdit) {
    let actions = '';

    if (canEdit) {
        if (instance.status === 'pending') {
            actions += `<button data-action="recurring-expenses.mark-paid" class="btn btn-light btn-circle btn-sm me-1" title="Mark Paid">
                            <i class="fas fa-check text-success"></i></button>`;
            actions += `<button data-action="recurring-expenses.skip" class="btn btn-light btn-circle btn-sm me-1" title="Skip">
                            <i class="fas fa-forward text-secondary"></i></button>`;

            // Show "Pay Early" for future pending instances
            const today = new Date().toISOString().split('T')[0];
            if (instance.due_date > today) {
                actions += `<button data-action="recurring-expenses.pay-early" class="btn btn-light btn-circle btn-sm me-1" title="Pay Early">
                                <i class="fas fa-clock text-info"></i></button>`;
            }
        }

        if (instance.status === 'overdue') {
            actions += `<button data-action="recurring-expenses.mark-paid" class="btn btn-light btn-circle btn-sm me-1" title="Mark Paid">
                            <i class="fas fa-check text-success"></i></button>`;
            actions += `<button data-action="recurring-expenses.skip" class="btn btn-light btn-circle btn-sm me-1" title="Skip">
                            <i class="fas fa-forward text-secondary"></i></button>`;
        }

        // Show "View Group" button for advance-paid instances that belong to a bulk advance payment
        if (instance.is_advance_payment && instance.bulk_advance_payment_id) {
            actions += `<button data-action="recurring-expenses.view-advance-group" data-bap-id="${instance.bulk_advance_payment_id}" class="btn btn-light btn-circle btn-sm me-1" title="View Advance Group">
                            <i class="fas fa-layer-group text-info"></i></button>`;
        }
    }

    // Audit history button — available to anyone with view permission
    actions += `<button data-action="recurring-expenses.view-history" class="btn btn-light btn-circle btn-sm me-1" title="View Audit History">
                    <i class="fas fa-history text-secondary"></i></button>`;

    return actions;
}


// ─── Advance Payment Features ──────────────────────────────────────────────────

/**
 * Fetch and render the "Advance-Paid Periods Remaining" summary section.
 * Shows the count of future advance-paid instances per active schedule.
 * Requirement 17.5
 */
async function fetchAdvancePaidSummary() {
    const summarySection = container?.querySelector('#advancePaidSummary');
    const summaryList = container?.querySelector('#advancePaidList');
    if (!summarySection || !summaryList) return;

    try {
        const res = await apiFetch('/api/recurring-instances/advance-payments?entity_type=EXPENSE');
        const data = await res.json();

        if (!res.ok || !data.success) {
            summarySection.classList.add('d-none');
            return;
        }

        const advancePayments = data.data?.advance_payments ?? [];
        if (advancePayments.length === 0) {
            summarySection.classList.add('d-none');
            return;
        }

        // Count advance-paid periods remaining (future instances already paid) per schedule
        const today = new Date().toISOString().split('T')[0];
        const futureAdvancePaid = allInstances.filter(i =>
            i.is_advance_payment && i.status === 'paid' && i.due_date > today
        );

        // Group by recurrence_schedule_id
        const scheduleMap = {};
        futureAdvancePaid.forEach(instance => {
            const schedId = instance.recurrence_schedule_id;
            if (!scheduleMap[schedId]) {
                scheduleMap[schedId] = {
                    name: instance.entity_name || `Schedule #${schedId}`,
                    currency: instance.currency,
                    count: 0,
                    amount_per_period: instance.scheduled_amount
                };
            }
            scheduleMap[schedId].count++;
        });

        const entries = Object.entries(scheduleMap);
        if (entries.length === 0) {
            summarySection.classList.add('d-none');
            return;
        }

        summarySection.classList.remove('d-none');
        summaryList.innerHTML = entries.map(([schedId, info]) => `
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-fast-forward fa-sm text-info me-2"></i>
                    <strong>${escHtml(info.name)}</strong>
                    <span class="text-muted ms-2">${getFormatter().formatNumber(info.amount_per_period, 2)} ${escHtml(info.currency)} / period</span>
                </div>
                <span class="badge bg-info rounded-pill" title="Advance-paid periods remaining">
                    ${info.count} period${info.count !== 1 ? 's' : ''} remaining
                </span>
            </div>
        `).join('');
    } catch (err) {
        console.error('Failed to fetch advance-paid summary:', err);
        summarySection.classList.add('d-none');
    }
}

/**
 * Fetch and display all instances linked to a bulk advance payment group.
 * Requirement 17.2
 */
async function showAdvancePaymentGroup(bapId) {
    const groupSection = container?.querySelector('#advanceGroupSection');
    const groupTitle = container?.querySelector('#advanceGroupTitle');
    const groupList = container?.querySelector('#advanceGroupList');
    if (!groupSection || !groupList) return;

    try {
        const res = await apiFetch(`/api/recurring-instances/advance-payments/${bapId}`);
        const data = await res.json();

        if (!res.ok || !data.success) {
            await alert({ title: 'Error', alertText: data.message || 'Failed to load advance payment group' });
            return;
        }

        const payment = data.data?.advance_payment;
        if (!payment) {
            await alert({ title: 'Error', alertText: 'Advance payment not found' });
            return;
        }

        const instances = payment.instances ?? [];

        // Update title
        if (groupTitle) {
            groupTitle.textContent = `Advance Payment Group — ${getFormatter().formatNumber(payment.total_amount, 2)} ${escHtml(payment.currency)} (${payment.periods_count} periods)`;
        }

        // Render linked instances
        groupList.innerHTML = instances.map(instance => {
            const statusBadge = buildStatusBadge(instance);
            const amount = instance.scheduled_amount != null
                ? getFormatter().formatNumber(instance.scheduled_amount, 2)
                : '0.00';
            return `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-calendar-day fa-sm text-info me-2"></i>
                        <strong>${escHtml(instance.entity_name ?? 'Instance')}</strong>
                        <span class="text-muted ms-2">${getFormatter().formatDate(instance.due_date)}</span>
                        <span class="ms-2">${amount} ${escHtml(instance.currency ?? '')}</span>
                    </div>
                    <div>
                        ${statusBadge}
                    </div>
                </div>
            `;
        }).join('');

        // Add summary footer
        groupList.innerHTML += `
            <div class="list-group-item bg-light text-muted small">
                <i class="fas fa-info-circle me-1"></i>
                Paid on ${getFormatter().formatDate(payment.payment_date)} — Total: ${getFormatter().formatNumber(payment.total_amount, 2)} ${escHtml(payment.currency)}
            </div>
        `;

        // Show the section
        groupSection.classList.remove('d-none');

        // Scroll to the section
        groupSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (err) {
        console.error('Failed to load advance payment group:', err);
        await alert({ title: 'Error', alertText: 'Failed to load advance payment group details.' });
    }
}
