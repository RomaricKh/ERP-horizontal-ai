// assets/js/pages/Finance/paymentAllocations.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const paymentId = data?.payment_id;
    const payment = data?.payment;
    const fmt = getFormatter();

    // Fill payment header info
    if (payment) {
        const numEl = modalRoot.querySelector('#allocPaymentNumber');
        const dateEl = modalRoot.querySelector('#allocPaymentDate');
        const payeeEl = modalRoot.querySelector('#allocPayeeName');
        const amountEl = modalRoot.querySelector('#allocPaymentAmount');

        if (numEl) numEl.textContent = payment.payment_number || '—';
        if (dateEl) dateEl.textContent = payment.payment_date || '—';
        if (payeeEl) payeeEl.textContent = payment.payee_name || '—';
        if (amountEl) amountEl.textContent = payment.amount_fc || '—';
    }

    // Fetch allocations
    if (!paymentId) return;

    try {
        const res = await apiFetch(`/api/payments/${paymentId}/allocations`);
        const json = await res.json();
        if (!json.success) return;

        const allocations = json.data?.allocations ?? [];
        const tbody = modalRoot.querySelector('#allocTableBody');

        if (allocations.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3">
                <i class="fas fa-info-circle me-1"></i>No allocations recorded (unallocated payment)
            </td></tr>`;
            return;
        }

        tbody.innerHTML = '';
        allocations.forEach(alloc => {
            const tr = document.createElement('tr');
            const refLabel = formatRefType(alloc.reference_type);
            const docNum = alloc.doc_number || `#${alloc.reference_id}`;
            const statusHtml = alloc.doc_status
                ? `<span class="badge bg-${getStatusColor(alloc.doc_status)}">${alloc.doc_status}</span>`
                : '—';

            tr.innerHTML = `
                <td>${escHtml(docNum)}</td>
                <td><span class="badge bg-light text-dark border">${refLabel}</span></td>
                <td class="text-end">${fmt.formatNumber(alloc.amount_fc, 2)}</td>
                <td class="text-end">${fmt.formatNumber(alloc.amount_bc, 2)}</td>
                <td>${statusHtml}</td>
            `;
            tbody.appendChild(tr);
        });
    } catch (err) {
        console.error('Failed to load allocations:', err);
    }
}

export function unmount() {
    modalRoot = null;
}

function formatRefType(type) {
    const map = {
        'PURCHASE_ORDER': 'Purchase Order',
        'EXPENSE': 'Expense',
        'SALARY': 'Salary',
        'PARTNER_PROFIT': 'Profit Dist.',
        'RECURRING_EXPENSE': 'Recurring Exp.',
        'RECURRING_SALARY': 'Recurring Sal.',
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

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
