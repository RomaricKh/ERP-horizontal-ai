// assets/js/pages/SalesInvoices/creditNoteForm.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { alert } from '../../components/modals.js';

let modalRoot = null;
let invoiceData = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const invoiceId = data?.invoice_id;
    const creditNoteId = data?.credit_note_id;

    if (creditNoteId) {
        // View existing credit note
        await loadCreditNote(creditNoteId);
    } else if (invoiceId) {
        // Create new credit note for this invoice
        await loadInvoiceForCreditNote(invoiceId);
    }

    // Wire up save button
    const btnSave = modalRoot.querySelector('#btnSaveCreditNote');
    if (btnSave) {
        btnSave.addEventListener('click', handleSave);
    }

    // Wire up issue button
    const btnIssue = modalRoot.querySelector('#btnIssueCreditNote');
    if (btnIssue) {
        btnIssue.addEventListener('click', handleIssue);
    }
}

export function unmount() {
    modalRoot = null;
    invoiceData = null;
}

async function loadInvoiceForCreditNote(invoiceId) {
    try {
        const res = await apiFetch(`/api/sales-invoices/${invoiceId}`);
        const json = await res.json();
        if (!json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Failed to load invoice' });
            return;
        }
        invoiceData = json.data.sales_invoice;

        // Populate header fields
        modalRoot.querySelector('#inputCNInvoice').value = invoiceData.invoice_number || `Invoice #${invoiceId}`;
        modalRoot.querySelector('#inputCNInvoiceId').value = invoiceId;
        modalRoot.querySelector('#inputCNCurrency').value = invoiceData.currency || '';
        modalRoot.querySelector('#inputCNDate').value = new Date().toISOString().slice(0, 10);

        // Populate line items from invoice
        const tbody = modalRoot.querySelector('#cnLinesBody');
        tbody.innerHTML = '';

        const lines = invoiceData.lines || [];
        lines.forEach((line, idx) => {
            const name = line.item_name || line.service_name || '—';
            const maxQty = parseFloat(line.quantity) || 0;
            const unitFc = parseFloat(line.unit_price_fc) || 0;
            const unitBc = parseFloat(line.unit_price_bc) || 0;

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escHtml(name)}</td>
                <td>${maxQty}</td>
                <td><input type="number" class="form-control form-control-sm cn-qty" 
                     data-idx="${idx}" min="0" max="${maxQty}" step="0.001" value="0"></td>
                <td>${unitFc.toFixed(2)}</td>
                <td>${unitBc.toFixed(2)}</td>
                <td class="cn-line-total">0.00</td>
            `;
            tr.dataset.itemId = line.item_id || '';
            tr.dataset.serviceId = line.service_id || '';
            tr.dataset.unitPriceFc = unitFc;
            tr.dataset.unitPriceBc = unitBc;
            tbody.appendChild(tr);
        });

        // Attach qty change listeners
        tbody.querySelectorAll('.cn-qty').forEach(input => {
            input.addEventListener('input', recalculateTotal);
        });
    } catch (err) {
        console.error('Failed to load invoice for credit note:', err);
    }
}

async function loadCreditNote(creditNoteId) {
    try {
        const res = await apiFetch(`/api/credit-notes/${creditNoteId}`);
        const json = await res.json();
        if (!json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Failed to load credit note' });
            return;
        }
        const cn = json.data.credit_note;

        // Show view section, hide edit form
        modalRoot.querySelector('#creditNoteHeaderForm').classList.add('d-none');
        modalRoot.querySelector('#cnLinesTable').classList.add('d-none');
        modalRoot.querySelector('#cnViewSection').classList.remove('d-none');
        modalRoot.querySelector('#btnSaveCreditNote').classList.add('d-none');

        modalRoot.querySelector('#creditNoteModalTitle').textContent = `Credit Note: ${cn.credit_note_number}`;
        modalRoot.querySelector('#viewCNNumber').textContent = cn.credit_note_number;
        modalRoot.querySelector('#viewCNStatus').innerHTML = `<span class="badge bg-${cn.status === 'ISSUED' ? 'success' : 'secondary'}">${cn.status}</span>`;
        modalRoot.querySelector('#viewCNTotal').textContent = parseFloat(cn.total_amount_bc).toFixed(2);

        // Show issue button if DRAFT
        if (cn.status === 'DRAFT') {
            const btnIssue = modalRoot.querySelector('#btnIssueCreditNote');
            btnIssue.classList.remove('d-none');
            btnIssue.dataset.creditNoteId = creditNoteId;
        }
    } catch (err) {
        console.error('Failed to load credit note:', err);
    }
}

function recalculateTotal() {
    const tbody = modalRoot.querySelector('#cnLinesBody');
    let totalBc = 0;

    tbody.querySelectorAll('tr').forEach(tr => {
        const qtyInput = tr.querySelector('.cn-qty');
        const qty = parseFloat(qtyInput?.value) || 0;
        const unitBc = parseFloat(tr.dataset.unitPriceBc) || 0;
        const lineTotalBc = qty * unitBc;
        tr.querySelector('.cn-line-total').textContent = lineTotalBc.toFixed(2);
        totalBc += lineTotalBc;
    });

    modalRoot.querySelector('#cnTotalBc').textContent = totalBc.toFixed(2);
}

async function handleSave() {
    const invoiceId = modalRoot.querySelector('#inputCNInvoiceId').value;
    const date = modalRoot.querySelector('#inputCNDate').value;
    const notes = modalRoot.querySelector('#inputCNNotes').value;

    if (!date) {
        await alert({ title: 'Validation', alertText: 'Credit note date is required.' });
        return;
    }

    // Collect lines with qty > 0
    const lines = [];
    const tbody = modalRoot.querySelector('#cnLinesBody');
    tbody.querySelectorAll('tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.cn-qty')?.value) || 0;
        if (qty <= 0) return;

        const line = {
            quantity: qty,
            unit_price_fc: parseFloat(tr.dataset.unitPriceFc) || 0,
            unit_price_bc: parseFloat(tr.dataset.unitPriceBc) || 0,
        };
        if (tr.dataset.itemId) line.item_id = parseInt(tr.dataset.itemId);
        if (tr.dataset.serviceId) line.service_id = parseInt(tr.dataset.serviceId);
        lines.push(line);
    });

    if (lines.length === 0) {
        await alert({ title: 'Validation', alertText: 'At least one line with a return quantity is required.' });
        return;
    }

    try {
        const res = await apiFetch('/api/credit-notes', {
            method: 'POST',
            body: JSON.stringify({
                sales_invoice_id: parseInt(invoiceId),
                credit_note_date: date,
                notes: notes,
                lines: lines,
            })
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Failed to create credit note' });
            return;
        }

        // Dispatch event and close
        document.dispatchEvent(new CustomEvent('module.updated'));
        const modal = bootstrap.Modal.getInstance(modalRoot.closest('.modal'));
        modal?.hide();
    } catch (err) {
        console.error('Save credit note failed:', err);
    }
}

async function handleIssue() {
    const btn = modalRoot.querySelector('#btnIssueCreditNote');
    const creditNoteId = btn.dataset.creditNoteId;
    if (!creditNoteId) return;

    try {
        const res = await apiFetch(`/api/credit-notes/${creditNoteId}/issue`, { method: 'POST' });
        const json = await res.json();
        if (!res.ok || !json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Failed to issue credit note' });
            return;
        }

        document.dispatchEvent(new CustomEvent('module.updated'));
        const modal = bootstrap.Modal.getInstance(modalRoot.closest('.modal'));
        modal?.hide();
    } catch (err) {
        console.error('Issue credit note failed:', err);
    }
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
