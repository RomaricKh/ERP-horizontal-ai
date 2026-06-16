// assets/js/pages/PurchaseOrders/debitNoteForm.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { alert, confirm } from '../../components/modals.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot = null;
let poData = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const poId = data?.po_id;
    const debitNoteId = data?.debit_note_id;

    if (debitNoteId) {
        // View/issue a specific debit note
        await loadDebitNote(debitNoteId);
    } else if (poId) {
        // Check for existing draft debit notes for this PO
        const hasDrafts = await showExistingNotes(poId);
        if (!hasDrafts) {
            // No drafts — go straight to creation form
            await loadPOForDebitNote(poId);
        }
    }

    // Wire up buttons
    const btnSave = modalRoot.querySelector('#btnSaveDebitNote');
    if (btnSave) btnSave.addEventListener('click', handleSave);

    const btnIssue = modalRoot.querySelector('#btnIssueDebitNote');
    if (btnIssue) btnIssue.addEventListener('click', handleIssue);

    const btnNewNote = modalRoot.querySelector('#btnNewDebitNote');
    if (btnNewNote) {
        btnNewNote.addEventListener('click', async () => {
            // Hide the existing notes section, show creation form
            const existingSection = modalRoot.querySelector('#dnExistingSection');
            if (existingSection) existingSection.classList.add('d-none');
            const headerForm = modalRoot.querySelector('#debitNoteHeaderForm');
            if (headerForm) headerForm.classList.remove('d-none');
            const linesTable = modalRoot.querySelector('#dnLinesTable');
            if (linesTable) linesTable.classList.remove('d-none');
            const saveBtn = modalRoot.querySelector('#btnSaveDebitNote');
            if (saveBtn) saveBtn.classList.remove('d-none');
            btnNewNote.classList.add('d-none');

            await loadPOForDebitNote(btnNewNote.dataset.poId);
        });
    }
}

export function unmount() {
    modalRoot = null;
    poData = null;
}

/**
 * Check for existing debit notes (DRAFT or ISSUED) for this PO.
 * If found, show them in a list with actions. Returns true if drafts exist.
 */
async function showExistingNotes(poId) {
    try {
        const res = await apiFetch('/api/debit-notes');
        const json = await res.json();
        if (!json.success) return false;

        const allNotes = json.data?.debit_notes || [];
        const poNotes = allNotes.filter(n => String(n.purchase_order_id) === String(poId));

        if (poNotes.length === 0) return false;

        const fmt = getFormatter();

        // Hide the creation form and show existing notes
        const headerForm = modalRoot.querySelector('#debitNoteHeaderForm');
        if (headerForm) headerForm.classList.add('d-none');
        const linesTable = modalRoot.querySelector('#dnLinesTable');
        if (linesTable) linesTable.classList.add('d-none');
        const saveBtn = modalRoot.querySelector('#btnSaveDebitNote');
        if (saveBtn) saveBtn.classList.add('d-none');

        // Show existing notes section
        const existingSection = modalRoot.querySelector('#dnExistingSection');
        existingSection.classList.remove('d-none');

        const listContainer = modalRoot.querySelector('#dnExistingList');
        listContainer.innerHTML = poNotes.map(note => {
            const statusBadge = note.status === 'ISSUED'
                ? '<span class="badge bg-success">ISSUED</span>'
                : '<span class="badge bg-secondary">DRAFT</span>';
            const total = fmt.formatNumber(note.total_amount_bc, 2);
            const date = fmt.formatDate(note.debit_note_date) || note.debit_note_date;

            let actionBtn = '';
            if (note.status === 'DRAFT') {
                actionBtn = `<button class="btn btn-success btn-sm dn-issue-btn" data-dn-id="${note.id}">
                    <i class="fas fa-check-circle me-1"></i>Issue</button>`;
            }

            return `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${escHtml(note.debit_note_number)}</strong>
                        <span class="ms-2">${statusBadge}</span>
                        <span class="text-muted ms-2">${date}</span>
                        <span class="ms-2">${escHtml(note.currency || '')} ${total}</span>
                    </div>
                    <div>${actionBtn}</div>
                </div>
            `;
        }).join('');

        // Bind issue buttons on existing drafts
        listContainer.querySelectorAll('.dn-issue-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const dnId = btn.dataset.dnId;
                const ok = await confirm({ title: 'Issue Debit Note', message: 'Issue this debit note? Stock will be adjusted and supplier balance reduced.' });
                if (!ok) return;

                try {
                    const issueRes = await apiFetch(`/api/debit-notes/${dnId}/issue`, { method: 'POST' });
                    const issueJson = await issueRes.json();
                    if (!issueRes.ok || !issueJson.success) {
                        await alert({ title: 'Error', alertText: issueJson.message || 'Failed to issue' });
                        return;
                    }
                    document.dispatchEvent(new CustomEvent('module.updated'));
                    // Refresh the list
                    await showExistingNotes(poId);
                } catch (e) {
                    console.error('Issue debit note failed:', e);
                }
            });
        });

        // Show the "Create New" button
        const btnNew = modalRoot.querySelector('#btnNewDebitNote');
        if (btnNew) {
            btnNew.classList.remove('d-none');
            btnNew.dataset.poId = poId;
        }

        // Update title
        const title = modalRoot.querySelector('#debitNoteModalTitle span');
        if (title) title.textContent = 'Debit Notes';

        return true;
    } catch (err) {
        console.error('Failed to check existing debit notes:', err);
        return false;
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
        modalRoot.querySelector('#debitNoteHeaderForm')?.classList.add('d-none');
        modalRoot.querySelector('#dnLinesTable')?.classList.add('d-none');
        modalRoot.querySelector('#btnSaveDebitNote')?.classList.add('d-none');

        const viewSection = modalRoot.querySelector('#dnViewSection');
        viewSection.classList.remove('d-none');

        modalRoot.querySelector('#debitNoteModalTitle span').textContent = `Debit Note: ${dn.debit_note_number}`;
        modalRoot.querySelector('#viewDNNumber').textContent = dn.debit_note_number;
        modalRoot.querySelector('#viewDNStatus').innerHTML = `<span class="badge bg-${dn.status === 'ISSUED' ? 'success' : 'secondary'}">${dn.status}</span>`;
        modalRoot.querySelector('#viewDNTotal').textContent = parseFloat(dn.total_amount_bc).toFixed(2);

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

        // Ask if they want to issue immediately
        const issueNow = await confirm({
            title: 'Debit Note Created',
            message: 'Draft debit note created. Issue it now? (Stock will be adjusted)'
        });

        if (issueNow) {
            const issueRes = await apiFetch(`/api/debit-notes/${json.data.id}/issue`, { method: 'POST' });
            const issueJson = await issueRes.json();
            if (!issueRes.ok || !issueJson.success) {
                await alert({ title: 'Warning', alertText: 'Draft created but issue failed: ' + (issueJson.message || '') });
            }
        }

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
