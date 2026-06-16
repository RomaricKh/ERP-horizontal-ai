// assets/js/pages/Salaries/salaries.js

import { hasPermission, getToken } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';
import { createMultiSelect } from '../../components/multiSelect.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

let salaries_container;
let unregister;
let salariesUpdatedHandler;
let paymentsUpdatedHandler;
let salariesDataTable = null;
let employeeFilter = null;
let allSalaries = [];
let selectedEmployees = [];

export async function mount() {
    await fetchSalaries();

    salariesUpdatedHandler = async () => { await fetchSalaries(); };
    paymentsUpdatedHandler = async () => { await fetchSalaries(); };
    document.addEventListener('salaries.updated', salariesUpdatedHandler);
    document.addEventListener('payments.updated', paymentsUpdatedHandler);

    unregister = registerPageActions({

        'salaries.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Salaries/salaryForm.html',
                controller: '../pages/Salaries/salaryForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'salaries.edit': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = salariesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Salaries/salaryForm.html',
                controller: '../pages/Salaries/salaryForm.modal.js',
                data: { salary_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'salaries.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = salariesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete salary record',
                message: 'Delete this salary record? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/salaries/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete salary record' });
                } else {
                    await fetchSalaries();
                }
            } catch (err) {
                console.error('Delete salary failed:', err);
            }
        },

        'salaries.pay': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = salariesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            try {
                const res = await apiFetch(`/api/salaries/${id}`);
                const json = await res.json();
                if (!json.success) return;

                const salary = json.data?.salary;
                if (!salary) return;

                // Calculate remaining to pay
                const paidBc = parseFloat(salary.paid_amount_bc) || 0;
                const totalBc = parseFloat(salary.net_amount_bc) || 0;
                const remainingFc = totalBc > 0
                    ? ((totalBc - paidBc) / (parseFloat(salary.exchange_rate) || 1))
                    : parseFloat(salary.net_amount_fc) || 0;

                await openModal({
                    scope: MODAL_SCOPE.PAGE,
                    template: 'html/Finance/paymentForm.html',
                    controller: '../pages/Finance/paymentForm.modal.js',
                    data: {
                        prefill: {
                            payee_id: salary.payee_id,
                            currency: salary.currency,
                            exchange_rate: salary.exchange_rate,
                            amount_fc: remainingFc.toFixed(2),
                            reference_type: 'SALARY',
                            reference_id: String(salary.id),
                        }
                    },
                    options: { backdrop: false, keyboard: true, focus: true }
                });
            } catch (err) {
                console.error('Failed to load salary for payment:', err);
            }
        },

        'salaries.payslip': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = salariesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            // Open payslip PDF in a new tab (pass token for auth since window.open doesn't send headers)
            const token = getToken();
            window.open(`/api/salaries/${id}/payslip?token=${encodeURIComponent(token)}`, '_blank');
        },

        'salaries.generate-payroll': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Salaries/payrollForm.html',
                controller: '../pages/Salaries/payrollForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        }
    });
}

export function unmount() {
    if (salariesUpdatedHandler) {
        document.removeEventListener('salaries.updated', salariesUpdatedHandler);
        salariesUpdatedHandler = null;
    }
    if (paymentsUpdatedHandler) {
        document.removeEventListener('payments.updated', paymentsUpdatedHandler);
        paymentsUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    salariesDataTable?.destroy();
    salariesDataTable = null;
    employeeFilter = null;
    allSalaries = [];
    selectedEmployees = [];
    closeModalsInContainer(salaries_container);
}

export async function render(container) {
    salaries_container = container;
    const html = await fetch('html/Salaries/salaries.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    container.querySelectorAll('[data-action]').forEach(el => {
        const action = el.dataset.action;
        const permMap = {
            'salaries.create': 'salaries.create',
            'salaries.generate-payroll': 'salaries.create',
        };
        const perm = permMap[action];
        if (perm && !hasPermission(perm)) el.remove();
    });
}

async function fetchSalaries() {
    const res  = await apiFetch('/api/salaries');
    const data = await res.json();
    if (!data.success) return;
    allSalaries = data.data.salaries ?? [];
    buildEmployeeFilter();
    renderTable(getFilteredSalaries());
}

function buildEmployeeFilter() {
    const filterContainer = salaries_container?.querySelector('#employeeFilterContainer');
    if (!filterContainer) return;

    // Extract unique employees
    const employeeMap = new Map();
    allSalaries.forEach(s => {
        if (s.payee_id && s.employee_name) {
            employeeMap.set(String(s.payee_id), s.employee_name);
        }
    });

    const options = Array.from(employeeMap.entries()).map(([value, label]) => ({ value, label }));

    if (options.length <= 1) {
        filterContainer.innerHTML = '';
        return;
    }

    filterContainer.innerHTML = '';
    employeeFilter = createMultiSelect({
        container: filterContainer,
        options,
        placeholder: 'Filter by employee...',
        onChange: (selected) => {
            selectedEmployees = selected;
            renderTable(getFilteredSalaries());
        }
    });
}

function getFilteredSalaries() {
    if (selectedEmployees.length === 0) return allSalaries;
    return allSalaries.filter(s => selectedEmployees.includes(String(s.payee_id)));
}

function renderTable(salaries) {
    if (!salariesDataTable) {
        salariesDataTable = createDataTable('#salariesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 7, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    salariesDataTable.clear();

    const canEdit   = hasPermission('salaries.edit');
    const canDelete = hasPermission('salaries.delete');
    const canPay    = hasPermission('payments.create');

    salaries.forEach(salary => {
        // Calculate payment status
        const paidBc = parseFloat(salary.paid_amount_bc) || 0;
        const totalBc = parseFloat(salary.net_amount_bc) || 0;
        const isFullyPaid = totalBc > 0 && paidBc >= totalBc - 0.01;
        const isPartiallyPaid = paidBc > 0 && !isFullyPaid;

        let statusBadge = '';
        if (isFullyPaid) {
            statusBadge = '<span class="badge bg-success">Paid</span>';
        } else if (isPartiallyPaid) {
            const remaining = getFormatter().formatNumber(totalBc - paidBc, 2);
            statusBadge = `<span class="badge bg-warning text-dark">Partial (${remaining})</span>`;
        } else {
            statusBadge = '<span class="badge bg-secondary">Unpaid</span>';
        }

        // Build actions
        let actions = '';

        // Quick-pay button (only if not fully paid)
        if (canPay && !isFullyPaid) {
            actions += `<button data-action="salaries.pay" class="btn btn-success btn-circle btn-sm me-1" title="Pay">
                            <i class="fas fa-money-bill"></i></button>`;
        }

        // Payslip PDF download
        actions += `<button data-action="salaries.payslip" class="btn btn-outline-primary btn-circle btn-sm me-1" title="Download Payslip">
                        <i class="fas fa-file-pdf"></i></button>`;

        if (canEdit) {
            actions += `<button data-action="salaries.edit" class="btn btn-info btn-circle btn-sm me-1" title="Edit">
                            <i class="fas fa-edit"></i></button>`;
        }
        if (canDelete && !isFullyPaid && !isPartiallyPaid) {
            actions += `<button data-action="salaries.delete" class="btn btn-danger btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash"></i></button>`;
        }

        const netFc = salary.net_amount_fc != null ? getFormatter().formatNumber(salary.net_amount_fc, 2) : '0.00';
        const netBc = salary.net_amount_bc != null ? getFormatter().formatNumber(salary.net_amount_bc, 2) : '0.00';

        const fmtDate = getFormatter().formatDate;
        const startStr = salary.period_start || '';
        const endStr = salary.period_end || '';
        const period = escHtml(
            (fmtDate(startStr) || startStr) +
            ' → ' +
            (fmtDate(endStr) || endStr)
        );

        salariesDataTable.row.add([
            salary.id,
            escHtml(salary.employee_name ?? '—'),
            period,
            escHtml(salary.currency ?? ''),
            netFc,
            netBc,
            statusBadge,
            actions
        ]);
    });

    salariesDataTable.draw(false);
    salariesDataTable.columns.adjust();
}
