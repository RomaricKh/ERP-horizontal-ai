// assets/js/pages/Finance/payments.js

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

let payments_container;
let unregister;
let paymentsUpdatedHandler;
let paymentsDataTable = null;

export async function mount() {
    await fetchPayments();

    paymentsUpdatedHandler = async () => { await fetchPayments(); };
    document.addEventListener('payments.updated', paymentsUpdatedHandler);

    unregister = registerPageActions({

        'payments.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/paymentForm.html',
                controller: '../pages/Finance/paymentForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'payments.view': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = paymentsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/paymentForm.html',
                controller: '../pages/Finance/paymentForm.modal.js',
                data: { payment_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'payments.allocations': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = paymentsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await showAllocationDetail(id, rowData);
        },

        'payments.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = paymentsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete payment',
                message: 'Delete this payment? This will reverse the account balance deduction and cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/payments/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete payment' });
                } else {
                    await fetchPayments();
                }
            } catch (err) {
                console.error('Delete payment failed:', err);
            }
        }
    });
}

export function unmount() {
    if (paymentsUpdatedHandler) {
        document.removeEventListener('payments.updated', paymentsUpdatedHandler);
        paymentsUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    paymentsDataTable?.destroy();
    paymentsDataTable = null;
    closeModalsInContainer(payments_container);
}

export async function render(container) {
    payments_container = container;
    const html = await fetch('html/Finance/payments.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'payments.create' && !hasPermission('payments.create')) {
            el.remove();
        }
    });
}

async function fetchPayments() {
    const res  = await apiFetch('/api/payments');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.payments ?? []);
}

function renderTable(payments) {
    if (!paymentsDataTable) {
        paymentsDataTable = createDataTable('#paymentsTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 8, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    paymentsDataTable.clear();

    const canDelete = hasPermission('payments.delete');
    const canView   = hasPermission('payments.view');

    payments.forEach(payment => {
        let actions = '';
        if (canView) {
            actions += `<button data-action="payments.view" class="btn btn-light btn-circle btn-sm me-1" title="View">
                            <i class="fas fa-eye text-info"></i></button>`;
            actions += `<button data-action="payments.allocations" class="btn btn-light btn-circle btn-sm me-1" title="Allocations">
                            <i class="fas fa-layer-group text-secondary"></i></button>`;
        }
        if (canDelete) {
            actions += `<button data-action="payments.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash text-danger"></i></button>`;
        }

        const amountFc = payment.amount_fc != null ? getFormatter().formatNumber(payment.amount_fc, 2) : '0.00';
        const amountBc = payment.amount_bc != null ? getFormatter().formatNumber(payment.amount_bc, 2) : '0.00';

        paymentsDataTable.row.add([
            payment.id,
            escHtml(payment.payment_number ?? ''),
            getFormatter().formatDate(payment.payment_date ?? ''),
            escHtml(payment.payee_name     ?? '—'),
            escHtml(payment.account_name   ?? '—'),
            amountFc,
            amountBc,
            escHtml(payment.method         ?? ''),
            actions
        ]);
    });

    paymentsDataTable.draw(false);
    paymentsDataTable.columns.adjust();
}

async function showAllocationDetail(paymentId, rowData) {
    const fmt = getFormatter();

    try {
        const res = await apiFetch(`/api/payments/${paymentId}/allocations`);
        const json = await res.json();
        if (!json.success) return;

        const allocations = json.data?.allocations ?? [];

        // Populate header
        const headerEl = document.getElementById('paymentAllocDetailHeader');
        if (headerEl) {
            headerEl.innerHTML = `
                <div class="d-flex justify-content-between">
                    <span><strong>Payment:</strong> ${escHtml(rowData[1])}</span>
                    <span><strong>Amount:</strong> ${rowData[6]}</span>
                    <span><strong>Date:</strong> ${rowData[2]}</span>
                </div>
            `;
        }

        // Populate body
        const tbody = document.getElementById('paymentAllocDetailBody');
        if (tbody) {
            if (allocations.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3">No allocations recorded</td></tr>`;
            } else {
                tbody.innerHTML = '';
                allocations.forEach(alloc => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${escHtml(alloc.doc_number || `#${alloc.reference_id}`)}</td>
                        <td><span class="badge bg-secondary">${formatRefType(alloc.reference_type)}</span></td>
                        <td class="text-end">${fmt.formatNumber(alloc.amount_bc, 2)}</td>
                        <td class="text-end">${fmt.formatNumber(alloc.amount_fc, 2)}</td>
                        <td>${alloc.doc_status ? `<span class="badge bg-${getStatusColor(alloc.doc_status)}">${alloc.doc_status}</span>` : '—'}</td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        }

        // Show the modal
        const modalEl = document.getElementById('paymentAllocDetailModal');
        if (modalEl) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }
    } catch (err) {
        console.error('showAllocationDetail failed:', err);
    }
}

function formatRefType(type) {
    const map = {
        'PURCHASE_ORDER': 'PO',
        'EXPENSE': 'Expense',
        'SALARY': 'Salary',
        'PARTNER_PROFIT': 'Profit Dist.',
    };
    return map[type] || type;
}

function getStatusColor(status) {
    const map = {
        'CLOSED': 'success',
        'PAID': 'success',
        'RECEIVED': 'info',
        'DELIVERED': 'info',
        'PARTIALLY_PAID': 'warning',
    };
    return map[status] || 'secondary';
}
