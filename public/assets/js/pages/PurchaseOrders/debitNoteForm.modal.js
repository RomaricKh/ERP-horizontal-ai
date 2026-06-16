// assets/js/pages/PurchaseOrders/debitNoteForm.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { alert } from '../../components/modals.js';

let modalRoot = null;
let poData = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const poId = data?.po_id;
    const debitNoteId = data?.debit_note_id;

    if (debitNoteId) {
        // View existing debit note
        await loadDebitNote(debitNoteId);
    } else if (poId) {
        // Create new debit note for this PO
        await loadPOForDebitNote(poId);
    }

    // Wire up save button
    const btnSave = modalRoot.querySelector('#btnSaveDebitNote');
    if (btnSave) {
        btnSave.addEventListener('click', handleSave);
    }

    // Wire up issue button
    const btnIssue = modalRoot.querySelector('#btnIssueDebitNote');
    if (btnIssue) {
        btnIssue.addEventListener('click', handleIssue);
    }
}

export function unmount() {
    modalRoot = null;
    poData = null;
}

async function loadPOForDebitNote(poId) {
    try {
        const res = await apiFetch(`/api/purchase-orders/${poId}`);
        const json = await res.json();
        if (!json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Failed to load purchase order' });
            return;
        }
        poData = json.data.purchase_order;

        // Fetch all items to get current stock levels (the listing includes stock_qty)
        const stockLevels = {};
        try {
            const itemsRes = await apiFetch('/api/items');
            const itemsJson = await itemsRes.json();
            const allItems = itemsJson.success ? (itemsJson.data?.items ?? []) : [];
            allItems.forEach(item => {
                stockLevels[item.id] = parseFloat(item.stock_qty) || 0;
            });
        } catch (e) {
            console.warn('Failed to load stock levels:', e);
        }

        // Populate header fields
        modalRoot.querySelector('#inputDNPurchaseOrder').value = poData.po_number || `PO #${poId}`;
        modalRoot.querySelector('#inputDNPurchaseOrderId').value = poId;
        modalRoot.querySelector('#inputDNCurrency').value = poData.currency || '';
        modalRoot.querySelector('#inputDNDate').value = new Date().toISOString().slice(0, 10);

        // Populate line items from PO
        const tbody = modalRoot.querySelector('#dnLinesBody');
        tbody.innerHTML = '';

        const lines = poData.lines || [];
        const returnedQtys = poData.returned_quantities || {};

        lines.forEach((line, idx) => {
            const name = line.item_name || '—';
            const orderedQty = parseFloat(line.quantity) || 0;
            const alreadyReturned = parseFloat(returnedQtys[line.item_id]) || 0;
            const remainingFromPO = Math.max(0, orderedQty - alreadyReturned);
            const currentStock = stockLevels[line.item_id] || 0;
            // Max returnable = min(what hasn't been returned yet, what's actually in stock)
            const maxQty = Math.min(remainingFromPO, currentStock);
            const unitFc = parseFloat(line.unit_price_fc) || 0;
            const unitBc = parseFloat(line.unit_price_bc) || 0;

            let infoText = '';
            if (alreadyReturned > 0) {
                infoText += `<small class="text-muted d-block">${alreadyReturned} returned</small>`;
            }
            if (currentStock < remainingFromPO) {
                infoText += `<small class="text-warning d-block">Stock: ${currentStock}</small>`;
            }

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escHtml(name)}</td>
                <td>${orderedQty}${infoText}</td>
                <td><input type="number" class="form-control form-control-sm dn-qty" 
                     data-idx="${idx}" min="0" max="${maxQty}" step="0.001" value="0"
                     ${maxQty <= 0 ? 'disabled' : ''}></td>
                <td class="text-end">${unitFc.toFixed(2)}</td>
                <td class="text-end">${unitBc.toFixed(2)}</td>
                <td class="text-end dn-line-total">0.00</td>
            `;
            tr.dataset.itemId = line.item_id || '';
            tr.dataset.unitPriceFc = unitFc;
            tr.dataset.unitPriceBc = unitBc;
            tbody.appendChild(tr);
        });

        // Attach qty change listeners with max enforcement
        tbody.querySelectorAll('.dn-qty').forEach(input => {
            input.addEventListener('input', () => {
                // Clamp to max
                const max = parseFloat(input.max) || 0;
                const val = parseFloat(input.value) || 0;
                if (val > max) {
                    input.value = max;
                }
                if (val < 0) {
                    input.value = 0;
                }
                recalculateTotal();
            });
        });
    } catch (err) {
        console.error('Failed to load PO for debit note:', err);
    }
}

async function loadDebitNote(debitNoteId) {
    try {
        const res = await apiFetch(`/api/debit-notes/${debitNoteId}`);
        const json = await res.json();
        if (!json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Failed to load debit note' });
            return;
        }
        const dn = json.data.debit_note;

        // Show view section, hide edit form
        modalRoot.querySelector('#debitNoteHeaderForm').classList.add('d-none');
        modalRoot.querySelector('#dnLinesTable').classList.add('d-none');
        modalRoot.querySelector('#dnViewSection').classList.remove('d-none');
        modalRoot.querySelector('#btnSaveDebitNote').classList.add('d-none');

        modalRoot.querySelector('#debitNoteModalTitle').textContent = `Debit Note: ${dn.debit_note_number}`;
        modalRoot.querySelector('#viewDNNumber').textContent = dn.debit_note_number;
        modalRoot.querySelector('#viewDNStatus').innerHTML = `<span class="badge bg-${dn.status === 'ISSUED' ? 'success' : 'secondary'}">${dn.status}</span>`;
        modalRoot.querySelector('#viewDNTotal').textContent = parseFloat(dn.total_amount_bc).toFixed(2);

        // Show issue button if DRAFT
        if (dn.status === 'DRAFT') {
            const btnIssue = modalRoot.querySelector('#btnIssueDebitNote');
            btnIssue.classList.remove('d-none');
            btnIssue.dataset.debitNoteId = debitNoteId;
        }
    } catch (err) {
        console.error('Failed to load debit note:', err);
    }
}

function recalculateTotal() {
    const tbody = modalRoot.querySelector('#dnLinesBody');
    let totalBc = 0;

    tbody.querySelectorAll('tr').forEach(tr => {
        const qtyInput = tr.querySelector('.dn-qty');
        const qty = parseFloat(qtyInput?.value) || 0;
        const unitBc = parseFloat(tr.dataset.unitPriceBc) || 0;
        const lineTotalBc = qty * unitBc;
        tr.querySelector('.dn-line-total').textContent = lineTotalBc.toFixed(2);
        totalBc += lineTotalBc;
    });

    modalRoot.querySelector('#dnTotalBc').textContent = totalBc.toFixed(2);
}

async function handleSave() {
    const poId = modalRoot.querySelector('#inputDNPurchaseOrderId').value;
    const date = modalRoot.querySelector('#inputDNDate').value;
    const notes = modalRoot.querySelector('#inputDNNotes').value;

    if (!date) {
        await alert({ title: 'Validation', alertText: 'Debit note date is required.' });
        return;
    }

    // Collect lines with qty > 0
    const lines = [];
    const tbody = modalRoot.querySelector('#dnLinesBody');
    tbody.querySelectorAll('tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.dn-qty')?.value) || 0;
        if (qty <= 0) return;

        lines.push({
            item_id: parseInt(tr.dataset.itemId) || null,
            quantity: qty,
            unit_price_fc: parseFloat(tr.dataset.unitPriceFc) || 0,
            unit_price_bc: parseFloat(tr.dataset.unitPriceBc) || 0,
        });
    });

    if (lines.length === 0) {
        await alert({ title: 'Validation', alertText: 'At least one line with a return quantity is required.' });
        return;
    }

    try {
        const res = await apiFetch('/api/debit-notes', {
            method: 'POST',
            body: JSON.stringify({
                purchase_order_id: parseInt(poId),
                debit_note_date: date,
                notes: notes,
                lines: lines,
            })
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Failed to create debit note' });
            return;
        }

        // Dispatch event and close
        document.dispatchEvent(new CustomEvent('module.updated'));
        const modal = bootstrap.Modal.getInstance(modalRoot.closest('.modal'));
        modal?.hide();
    } catch (err) {
        console.error('Save debit note failed:', err);
    }
}

async function handleIssue() {
    const btn = modalRoot.querySelector('#btnIssueDebitNote');
    const debitNoteId = btn.dataset.debitNoteId;
    if (!debitNoteId) return;

    try {
        const res = await apiFetch(`/api/debit-notes/${debitNoteId}/issue`, { method: 'POST' });
        const json = await res.json();
        if (!res.ok || !json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Failed to issue debit note' });
            return;
        }

        document.dispatchEvent(new CustomEvent('module.updated'));
        const modal = bootstrap.Modal.getInstance(modalRoot.closest('.modal'));
        modal?.hide();
    } catch (err) {
        console.error('Issue debit note failed:', err);
    }
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
