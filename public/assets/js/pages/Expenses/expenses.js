// assets/js/pages/Expenses/expenses.js

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

let expenses_container;
let unregister;
let expensesUpdatedHandler;
let expensesDataTable = null;

export async function mount() {
    await fetchExpenses();

    expensesUpdatedHandler = async () => { await fetchExpenses(); };
    document.addEventListener('expenses.updated', expensesUpdatedHandler);

    unregister = registerPageActions({

        'expenses.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Expenses/expenseForm.html',
                controller: '../pages/Expenses/expenseForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'expenses.edit': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = expensesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Expenses/expenseForm.html',
                controller: '../pages/Expenses/expenseForm.modal.js',
                data: { expense_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'expenses.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = expensesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete expense',
                message: 'Delete this expense? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/expenses/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete expense' });
                } else {
                    await fetchExpenses();
                }
            } catch (err) {
                console.error('Delete expense failed:', err);
            }
        },

        'expenses.manage-categories': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Expenses/expenseCategories.html',
                controller: '../pages/Expenses/expenseCategories.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'expenses.pay': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = expensesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            // Fetch expense details for prefill
            try {
                const res = await apiFetch(`/api/expenses/${id}`);
                const json = await res.json();
                if (!json.success) return;

                const expense = json.data?.expense;
                if (!expense) return;

                // Open Payment form prefilled with expense data + auto-allocate
                await openModal({
                    scope: MODAL_SCOPE.PAGE,
                    template: 'html/Finance/paymentForm.html',
                    controller: '../pages/Finance/paymentForm.modal.js',
                    data: {
                        prefill: {
                            payee_id: expense.payee_id,
                            currency: expense.currency,
                            exchange_rate: expense.exchange_rate,
                            amount_fc: expense.amount_fc,
                            reference_type: 'EXPENSE',
                            reference_id: String(expense.id),
                        }
                    },
                    options: { backdrop: false, keyboard: true, focus: true }
                });
            } catch (err) {
                console.error('Failed to load expense for payment:', err);
            }
        }
    });
}

export function unmount() {
    if (expensesUpdatedHandler) {
        document.removeEventListener('expenses.updated', expensesUpdatedHandler);
        expensesUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    expensesDataTable?.destroy();
    expensesDataTable = null;
    closeModalsInContainer(expenses_container);
}

export async function render(container) {
    expenses_container = container;
    const html = await fetch('html/Expenses/expenses.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    container.querySelectorAll('[data-action]').forEach(el => {
        const action = el.dataset.action;
        const permMap = {
            'expenses.create':            'expenses.create',
            'expenses.manage-categories': 'expenses.manage',
        };
        const perm = permMap[action];
        if (perm && !hasPermission(perm)) el.remove();
    });
}

async function fetchExpenses() {
    const res  = await apiFetch('/api/expenses');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.expenses ?? []);
}

function renderTable(expenses) {
    if (!expensesDataTable) {
        expensesDataTable = createDataTable('#expensesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: [8, 9], className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    expensesDataTable.clear();

    const canEdit   = hasPermission('expenses.edit');
    const canDelete = hasPermission('expenses.delete');
    const canPay    = hasPermission('payments.create');

    expenses.forEach(expense => {

        // Calculate payment status
        const paidBc = parseFloat(expense.paid_amount_bc) || 0;
        const totalBc = parseFloat(expense.amount_bc) || 0;
        const isFullyPaid = paidBc >= totalBc - 0.01;
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

        let actions = '';
        if (canPay && !isFullyPaid) {
            const payAmount = isPartiallyPaid ? (totalBc - paidBc) : expense.amount_fc;
            actions += `<button data-action="expenses.pay" class="btn btn-success btn-circle btn-sm me-1" title="Pay">
                            <i class="fas fa-money-bill"></i></button>`;
        }
        if (canEdit) {
            actions += `<button data-action="expenses.edit" class="btn btn-info btn-circle btn-sm me-1" title="Edit">
                            <i class="fas fa-edit"></i></button>`;
        }
        if (canDelete && !isFullyPaid && !isPartiallyPaid) {
            actions += `<button data-action="expenses.delete" class="btn btn-danger btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash"></i></button>`;
        }

        const amountFc = expense.amount_fc != null ? getFormatter().formatNumber(expense.amount_fc, 2) : '0.00';
        const amountBc = expense.amount_bc != null ? getFormatter().formatNumber(expense.amount_bc, 2) : '0.00';

        // Document icon: filled if attachment exists, empty outline if not
        let docIcon = '';
        if (expense.attachment_path) {
            docIcon = `<a href="${escHtml(expense.attachment_path)}" target="_blank" title="View attachment"><i class="fas fa-file-alt text-primary"></i></a>`;
        } else {
            docIcon = `<i class="far fa-file text-muted"></i>`;
        }

        expensesDataTable.row.add([
            expense.id,
            getFormatter().formatDate(expense.expense_date ?? ''),
            escHtml(expense.payee_name   ?? '—'),
            escHtml(expense.category_name ?? '—'),
            escHtml(expense.reference_number ?? '—'),
            escHtml(expense.currency     ?? ''),
            amountFc,
            statusBadge,
            docIcon,
            actions
        ]);
    });

    expensesDataTable.draw(false);
    expensesDataTable.columns.adjust();
}
