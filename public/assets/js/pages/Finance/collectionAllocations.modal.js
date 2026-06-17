// assets/js/pages/Finance/collectionAllocations.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const collectionId = data?.collection_id;
    const collection = data?.collection;
    const fmt = getFormatter();

    // Fill collection header info
    if (collection) {
        const numEl = modalRoot.querySelector('#cAllocSlipNumber');
        const dateEl = modalRoot.querySelector('#cAllocSlipDate');
        const payeeEl = modalRoot.querySelector('#cAllocPayeeName');
        const amountEl = modalRoot.querySelector('#cAllocAmount');

        if (numEl) numEl.textContent = collection.slip_number || '—';
        if (dateEl) dateEl.textContent = collection.slip_date || '—';
        if (payeeEl) payeeEl.textContent = collection.payee_name || '—';
        if (amountEl) amountEl.textContent = collection.amount_fc || '—';
    }

    // Fetch allocations
    if (!collectionId) return;

    try {
        const res = await apiFetch(`/api/collections/${collectionId}/allocations`);
        const json = await res.json();
        if (!json.success) return;

        const allocations = json.data?.allocations ?? [];
        const tbody = modalRoot.querySelector('#cAllocTableBody');

        if (allocations.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">
                <i class="fas fa-info-circle me-1"></i>No allocations recorded (unallocated collection)
            </td></tr>`;
            return;
        }

        tbody.innerHTML = '';
        allocations.forEach(alloc => {
            const tr = document.createElement('tr');
            const docNum = alloc.doc_number || `Invoice #${alloc.reference_id}`;
            const statusHtml = alloc.doc_status
                ? `<span class="badge bg-${getStatusColor(alloc.doc_status)}">${alloc.doc_status}</span>`
                : '—';

            tr.innerHTML = `
                <td>${escHtml(docNum)}</td>
                <td class="text-end">${fmt.formatNumber(alloc.amount_fc, 2)}</td>
                <td class="text-end">${fmt.formatNumber(alloc.amount_bc, 2)}</td>
                <td>${statusHtml}</td>
            `;
            tbody.appendChild(tr);
        });
    } catch (err) {
        console.error('Failed to load collection allocations:', err);
    }
}

export function unmount() {
    modalRoot = null;
}

function getStatusColor(status) {
    const map = {
        'PAID': 'success',
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
