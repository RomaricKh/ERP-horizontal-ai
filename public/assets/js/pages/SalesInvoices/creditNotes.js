// assets/js/pages/SalesInvoices/creditNotes.js

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

let cn_container;
let unregister;
let cnUpdatedHandler;
let cnDataTable = null;
let currentInvoiceId = null;

export async function mount() {
    // Extract invoice_id from URL query string
    const params = new URLSearchParams(window.location.hash.split('?')[1] || '');
    currentInvoiceId = params.get('invoice_id') || null;

    await fetchCreditNotes();

    cnUpdatedHandler = async () => { await fetchCreditNotes(); };
    document.addEventListener('module.updated', cnUpdatedHandler);

    unregister = registerPageActions({

        'cn.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/SalesInvoices/creditNoteForm.html',
                controller: '../pages/SalesInvoices/creditNoteForm.modal.js',
                data: { invoice_id: currentInvoiceId ? parseInt(currentInvoiceId) : null },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'cn.view': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = cnDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            // Simple view via alert with details
            try {
                const res = await apiFetch(`/api/credit-notes/${id}`);
                const json = await res.json();
                if (!json.success) return;
                const cn = json.data.credit_note;
                const fmt = getFormatter();
                const lines = (cn.lines || []).map(l =>
                    `• ${l.item_name || l.service_name || '—'}: qty ${fmt.formatNumber(l.quantity, 3)} × ${fmt.formatNumber(l.unit_price_fc, 2)}`
                ).join('\n');
                await alert({
                    title: `Credit Note: ${cn.credit_note_number}`,
                    alertText: `Status: ${cn.status}\nDate: ${cn.credit_note_date}\nTotal: ${cn.currency} ${fmt.formatNumber(cn.total_amount_bc, 2)}\n\nLines:\n${lines || '(none)'}`
                });
            } catch (e) { console.error(e); }
        },

        'cn.issue': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = cnDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const ok = await confirm({
                title: 'Issue Credit Note',
                message: 'Issue this credit note? Stock will be adjusted for physical items and customer balance reduced. This cannot be undone.'
            });
            if (!ok) return;

            try {
                const res = await apiFetch(`/api/credit-notes/${id}/issue`, { method: 'POST' });
                const json = await res.json();
                if (!res.ok || !json.success) {
                    await alert({ title: 'Error', alertText: json.message || 'Failed to issue' });
                } else {
                    await fetchCreditNotes();
                }
            } catch (err) {
                console.error('Issue credit note failed:', err);
            }
        },

        'cn.delete': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = cnDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const ok = await confirm({
                title: 'Delete Draft',
                message: 'Delete this draft credit note?'
            });
            if (!ok) return;

            try {
                const res = await apiFetch(`/api/credit-notes/${id}`, { method: 'DELETE' });
                const json = await res.json();
                if (!res.ok || !json.success) {
                    await alert({ title: 'Error', alertText: json.message || 'Failed to delete' });
                } else {
                    await fetchCreditNotes();
                }
            } catch (err) {
                console.error('Delete credit note failed:', err);
            }
        },
    });
}

export function unmount() {
    if (cnUpdatedHandler) {
        document.removeEventListener('module.updated', cnUpdatedHandler);
        cnUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    cnDataTable?.destroy();
    cnDataTable = null;
    currentInvoiceId = null;
    closeModalsInContainer(cn_container);
}

export async function render(container) {
    cn_container = container;
    const html = await fetch('html/SalesInvoices/creditNotes.html').then(r => r.text());
    container.innerHTML = html;

    if (!hasPermission('invoices.edit')) {
        container.querySelectorAll('[data-action="cn.create"]').forEach(el => el.remove());
    }

    // Show invoice context in the subtitle if filtered
    if (currentInvoiceId) {
        const subtitle = container.querySelector('#cnSubtitle');
        if (subtitle) {
            try {
                const res = await apiFetch(`/api/sales-invoices/${currentInvoiceId}`);
                const json = await res.json();
                if (json.success) {
                    const inv = json.data.sales_invoice;
                    subtitle.innerHTML = `Showing returns for <strong>${inv.invoice_number || 'Invoice #' + currentInvoiceId}</strong> — ${escHtml(inv.customer_name || '')}
                        <a href="#/invoices" class="ms-2"><i class="fas fa-arrow-left me-1"></i>Back to Invoices</a>`;
                }
            } catch (e) { /* ignore */ }
        }
    }
}

async function fetchCreditNotes() {
    const res = await apiFetch('/api/credit-notes');
    const json = await res.json();
    if (!json.success) return;

    let notes = json.data?.credit_notes ?? [];

    if (currentInvoiceId) {
        notes = notes.filter(n => String(n.sales_invoice_id) === String(currentInvoiceId));
    }

    renderTable(notes);
}

function renderTable(notes) {
    if (!cnDataTable) {
        cnDataTable = createDataTable('#creditNotesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 7, className: 'text-center', orderable: false, searchable: false }
            ],
            order: [[3, 'desc']]
        });
    }

    cnDataTable.clear();

    const canEdit = hasPermission('invoices.edit');
    const fmt = getFormatter();

    notes.forEach(note => {
        const statusBadge = note.status === 'ISSUED'
            ? '<span class="badge bg-success">Issued</span>'
            : '<span class="badge bg-secondary">Draft</span>';

        const total = fmt.formatNumber(note.total_amount_bc, 2);

        let actions = '';
        actions += `<button data-action="cn.view" class="btn btn-light btn-circle btn-sm me-1" title="View Details">
                        <i class="fas fa-eye text-info"></i></button>`;

        if (canEdit && note.status === 'DRAFT') {
            actions += `<button data-action="cn.issue" class="btn btn-light btn-circle btn-sm me-1" title="Issue">
                            <i class="fas fa-check-circle text-success"></i></button>`;
            actions += `<button data-action="cn.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete Draft">
                            <i class="fas fa-trash text-danger"></i></button>`;
        }

        cnDataTable.row.add([
            note.id,
            escHtml(note.credit_note_number || ''),
            escHtml(note.invoice_number || '—'),
            fmt.formatDate(note.credit_note_date || ''),
            escHtml(note.currency || ''),
            total,
            statusBadge,
            actions
        ]);
    });

    cnDataTable.draw(false);
    cnDataTable.columns.adjust();
}
