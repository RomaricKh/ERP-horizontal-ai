// assets/js/pages/PurchaseOrders/debitNoteCreate.modal.js
// Modal for creating a new debit note — user selects PO, then fills return quantities.

import { apiFetch } from '../../Auth/apiFetch.js';
import { alert } from '../../components/modals.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot = null;
let poData = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const preselectedPoId = data?.po_id;

    // If a PO is preselected (from the PO page "Debit Note" button), load it directly
    if (preselectedPoId) {
        await loadPOForDebitNote(preselectedPoId);
    } else {
        // Show PO selector
        await showPOSelector();
    }

    // Wire up save button
    const btnSave = modalRoot.querySelector('#btnSaveDebitNote');
    if (btnSave) btnSave.addEventListener('click', handleSave);

    // Hide elements not needed for creation
    modalRoot.querySelector('#dnExistingSection')?.classList.add('d-none');
    modalRoot.querySelector('#dnViewSection')?.classList.add('d-none');
    modalRoot.querySelector('#btnIssueDebitNote')?.classList.add('d-none');
    modalRoot.querySelector('#btnNewDebitNote')?.classList.add('d-none');
}

export function unmount() {
    modalRoot = null;
    poData = null;
}

async function showPOSelector() {
    // Fetch received POs and let user pick one
    try {
        const res = await apiFetch('/api/purchase-orders');
        const json = await res.json();
        const pos = (json.success ? json.data?.purchase_orders : []) || [];
        const receivedPOs = pos.filter(po => po.status === 'RECEIVED');

        if (receivedPOs.length === 0) {
            const headerForm = modalRoot.querySelector('#debitNoteHeaderForm');
            if (headerForm) {
                headerForm.innerHTML = `
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <p>No received purchase orders available for return.</p>
                    </div>`;
            }
            modalRoot.querySelector('#dnLinesTable')?.classList.add('d-none');
            modalRoot.querySelector('#btnSaveDebitNote')?.classList.add('d-none');
            return;
        }

        // Replace the PO field with a select dropdown
        const poInput = modalRoot.querySelector('#inputDNPurchaseOrder');
        if (poInput) {
            const select = document.createElement('select');
            select.className = 'form-select';
            select.id = 'inputDNPurchaseOrderSelect';
            select.innerHTML = '<option value="">— Select Purchase Order —</option>';
            receivedPOs.forEach(po => {
                select.innerHTML += `<option value="${po.id}">${escHtml(po.po_number)} — ${escHtml(po.supplier_name || '?')} (${getFormatter().formatNumber(po.total_amount_fc, 2)} ${po.currency || ''})</option>`;
            });
            poInput.replaceWith(select);

            select.addEventListener('change', async () => {
                const poId = select.value;
                if (poId) {
                    await loadPOForDebitNote(parseInt(poId));
                }
            });
        }

        // Set today's date
        const dateInput = modalRoot.querySelector('#inputDNDate');
        if (dateInput) dateInput.value = new Date().toISOString().slice(0, 10);

        // Hide lines until PO is selected
        modalRoot.querySelector('#dnLinesTable')?.classList.add('d-none');

    } catch (err) {
        console.error('Failed to load POs:', err);
    }
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

        // Fetch all items to get current stock levels
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

        // Update header
        const poSelect = modalRoot.querySelector('#inputDNPurchaseOrderSelect');
        const poInput = modalRoot.querySelector('#inputDNPurchaseOrder');
        if (poSelect) poSelect.value = String(poId);
        if (poInput) poInput.value = poData.po_number || `PO #${poId}`;

        modalRoot.querySelector('#inputDNPurchaseOrderId').value = poId;
        modalRoot.querySelector('#inputDNCurrency').value = poData.currency || '';

        const dateInput = modalRoot.querySelector('#inputDNDate');
        if (dateInput && !dateInput.value) dateInput.value = new Date().toISOString().slice(0, 10);

        // Show lines table
        const linesSection = modalRoot.querySelector('#dnLinesTable');
        if (linesSection) linesSection.classList.remove('d-none');

        // Populate line items
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

        // Attach qty change listeners
        tbody.querySelectorAll('.dn-qty').forEach(input => {
            input.addEventListener('input', () => {
                const max = parseFloat(input.max) || 0;
                const val = parseFloat(input.value) || 0;
                if (val > max) input.value = max;
                if (val < 0) input.value = 0;
                recalculateTotal();
            });
        });
    } catch (err) {
        console.error('Failed to load PO for debit note:', err);
    }
}

function recalculateTotal() {
    const tbody = modalRoot.querySelector('#dnLinesBody');
    let totalBc = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.dn-qty')?.value) || 0;
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
    const notes = modalRoot.querySelector('#inputDNNotes')?.value || '';

    if (!poId) {
        await alert({ title: 'Validation', alertText: 'Please select a purchase order.' });
        return;
    }
    if (!date) {
        await alert({ title: 'Validation', alertText: 'Debit note date is required.' });
        return;
    }

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
        await alert({ title: 'Validation', alertText: 'At least one item with a return quantity is required.' });
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

        document.dispatchEvent(new CustomEvent('module.updated'));
        const modal = bootstrap.Modal.getInstance(modalRoot.closest('.modal'));
        modal?.hide();
    } catch (err) {
        console.error('Save debit note failed:', err);
    }
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
