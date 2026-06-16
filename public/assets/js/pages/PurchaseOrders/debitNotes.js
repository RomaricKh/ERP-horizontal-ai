// assets/js/pages/PurchaseOrders/debitNotes.js

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

let dn_container;
let unregister;
let dnUpdatedHandler;
let dnDataTable = null;
let currentPoId = null;

export async function mount() {
    // Extract po_id from URL query string
    const params = new URLSearchParams(window.location.hash.split('?')[1] || '');
    currentPoId = params.get('po_id') || null;

    await fetchDebitNotes();

    dnUpdatedHandler = async () => { await fetchDebitNotes(); };
    document.addEventListener('module.updated', dnUpdatedHandler);

    unregister = registerPageActions({

        'dn.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/PurchaseOrders/debitNoteForm.html',
                controller: '../pages/PurchaseOrders/debitNoteCreate.modal.js',
                data: { po_id: currentPoId ? parseInt(currentPoId) : null },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'dn.view': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dnDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/PurchaseOrders/debitNoteView.html',
                controller: '../pages/PurchaseOrders/debitNoteView.modal.js',
                data: { debit_note_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'dn.issue': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dnDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const ok = await confirm({
                title: 'Issue Debit Note',
                message: 'Issue this debit note? Stock will be adjusted and supplier balance reduced. This cannot be undone.'
            });
            if (!ok) return;

            try {
                const res = await apiFetch(`/api/debit-notes/${id}/issue`, { method: 'POST' });
                const json = await res.json();
                if (!res.ok || !json.success) {
                    await alert({ title: 'Error', alertText: json.message || 'Failed to issue debit note' });
                } else {
                    await fetchDebitNotes();
                }
            } catch (err) {
                console.error('Issue debit note failed:', err);
            }
        },

        'dn.delete': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dnDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const ok = await confirm({
                title: 'Delete Draft',
                message: 'Delete this draft debit note? This cannot be undone.'
            });
            if (!ok) return;

            try {
                const res = await apiFetch(`/api/debit-notes/${id}`, { method: 'DELETE' });
                const json = await res.json();
                if (!res.ok || !json.success) {
                    await alert({ title: 'Error', alertText: json.message || 'Failed to delete debit note' });
                } else {
                    await fetchDebitNotes();
                }
            } catch (err) {
                console.error('Delete debit note failed:', err);
            }
        },
    });
}

export function unmount() {
    if (dnUpdatedHandler) {
        document.removeEventListener('module.updated', dnUpdatedHandler);
        dnUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    dnDataTable?.destroy();
    dnDataTable = null;
    currentPoId = null;
    closeModalsInContainer(dn_container);
}

export async function render(container) {
    dn_container = container;
    const html = await fetch('html/PurchaseOrders/debitNotes.html').then(r => r.text());
    container.innerHTML = html;

    if (!hasPermission('po.edit')) {
        container.querySelectorAll('[data-action="dn.create"]').forEach(el => el.remove());
    }

    // Show PO context in the subtitle if filtered
    if (currentPoId) {
        const subtitle = container.querySelector('#dnSubtitle');
        if (subtitle) {
            try {
                const res = await apiFetch(`/api/purchase-orders/${currentPoId}`);
                const json = await res.json();
                if (json.success) {
                    const po = json.data.purchase_order;
                    subtitle.textContent = `Showing returns for ${po.po_number || 'PO #' + currentPoId} — ${po.supplier_name || ''}`;
                }
            } catch (e) { /* ignore */ }
        }
    }
}

async function fetchDebitNotes() {
    const res = await apiFetch('/api/debit-notes');
    const json = await res.json();
    if (!json.success) return;

    let notes = json.data?.debit_notes ?? [];

    // Filter by current PO if specified
    if (currentPoId) {
        notes = notes.filter(n => String(n.purchase_order_id) === String(currentPoId));
    }

    renderTable(notes);
}

function renderTable(notes) {
    if (!dnDataTable) {
        dnDataTable = createDataTable('#debitNotesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 7, className: 'text-center', orderable: false, searchable: false }
            ],
            order: [[3, 'desc']]
        });
    }

    dnDataTable.clear();

    const canEdit = hasPermission('po.edit');
    const fmt = getFormatter();

    notes.forEach(note => {
        const statusBadge = note.status === 'ISSUED'
            ? '<span class="badge bg-success">Issued</span>'
            : '<span class="badge bg-secondary">Draft</span>';

        const total = fmt.formatNumber(note.total_amount_bc, 2);

        let actions = '';
        actions += `<button data-action="dn.view" class="btn btn-light btn-circle btn-sm me-1" title="View Details">
                        <i class="fas fa-eye text-info"></i></button>`;

        if (canEdit && note.status === 'DRAFT') {
            actions += `<button data-action="dn.issue" class="btn btn-light btn-circle btn-sm me-1" title="Issue">
                            <i class="fas fa-check-circle text-success"></i></button>`;
            actions += `<button data-action="dn.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete Draft">
                            <i class="fas fa-trash text-danger"></i></button>`;
        }

        dnDataTable.row.add([
            note.id,
            escHtml(note.debit_note_number || ''),
            escHtml(note.po_number || '—'),
            fmt.formatDate(note.debit_note_date || ''),
            escHtml(note.currency || ''),
            total,
            statusBadge,
            actions
        ]);
    });

    dnDataTable.draw(false);
    dnDataTable.columns.adjust();
}
