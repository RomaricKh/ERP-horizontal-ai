// assets/js/pages/PurchaseOrders/debitNoteView.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const debitNoteId = data?.debit_note_id;
    if (!debitNoteId) return;

    try {
        const res = await apiFetch(`/api/debit-notes/${debitNoteId}`);
        const json = await res.json();
        if (!json.success) return;

        const dn = json.data.debit_note;
        const fmt = getFormatter();

        modalRoot.querySelector('#dnViewModalTitle span').textContent = `Debit Note: ${dn.debit_note_number}`;
        modalRoot.querySelector('#dnViewNumber').textContent = dn.debit_note_number || '—';
        modalRoot.querySelector('#dnViewStatus').innerHTML = dn.status === 'ISSUED'
            ? '<span class="badge bg-success">Issued</span>'
            : '<span class="badge bg-secondary">Draft</span>';
        modalRoot.querySelector('#dnViewDate').textContent = fmt.formatDate(dn.debit_note_date) || dn.debit_note_date || '—';
        modalRoot.querySelector('#dnViewPO').textContent = dn.po_number || '—';
        modalRoot.querySelector('#dnViewNotes').textContent = dn.notes || '—';
        modalRoot.querySelector('#dnViewCurrency').textContent = dn.currency || '—';
        modalRoot.querySelector('#dnViewTotal').textContent = fmt.formatNumber(dn.total_amount_bc, 2);

        // Render lines
        const tbody = modalRoot.querySelector('#dnViewLinesBody');
        const lines = dn.lines || [];
        tbody.innerHTML = lines.map(line => `
            <tr>
                <td>${escHtml(line.item_name || '—')}</td>
                <td class="text-end">${fmt.formatNumber(line.quantity, 3)}</td>
                <td class="text-end">${fmt.formatNumber(line.unit_price_fc, 2)}</td>
                <td class="text-end">${fmt.formatNumber(line.unit_price_bc, 2)}</td>
                <td class="text-end fw-semibold">${fmt.formatNumber(line.total_price_bc, 2)}</td>
            </tr>
        `).join('');
    } catch (err) {
        console.error('Failed to load debit note:', err);
    }
}

export function unmount() {
    modalRoot = null;
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
