// assets/js/pages/SalesInvoices/salesInvoices.js

import { hasPermission, getToken } from '../../Auth/auth.js';
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

/** Map status → Bootstrap badge class */
const STATUS_BADGE = {
    DRAFT:     'secondary',
    DELIVERED: 'primary',
    CANCELLED: 'danger',
};

/** Map Payment_Status → Bootstrap badge class */
const PAYMENT_STATUS_BADGE = {
    UNPAID:         'warning',
    PARTIALLY_PAID: 'info',
    PAID:           'success',
};

let inv_container;
let unregister;
let invUpdatedHandler;
let collectionsUpdatedHandler;
let invDataTable = null;

export async function mount() {
    await fetchInvoices();

    invUpdatedHandler = async () => { await fetchInvoices(); };
    document.addEventListener('module.updated', invUpdatedHandler);

    collectionsUpdatedHandler = async () => { await fetchInvoices(); };
    document.addEventListener('collections.updated', collectionsUpdatedHandler);

    unregister = registerPageActions({

        'invoice.create': async () => {
            await openModal({
                scope:      MODAL_SCOPE.PAGE,
                template:   'html/SalesInvoices/invoiceForm.html',
                controller: '../pages/SalesInvoices/invoiceForm.modal.js',
                data:       {},
                options:    { backdrop: false, keyboard: true, focus: true }
            });
        },

        'invoice.view': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope:      MODAL_SCOPE.PAGE,
                template:   'html/SalesInvoices/invoiceForm.html',
                controller: '../pages/SalesInvoices/invoiceForm.modal.js',
                data:       { invoice_id: id },
                options:    { backdrop: false, keyboard: true, focus: true }
            });
        },

        'invoice.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete Sales Invoice',
                message: 'Delete this sales invoice? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/sales-invoices/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete sales invoice' });
                } else {
                    await fetchInvoices();
                }
            } catch (err) {
                console.error('Delete invoice failed:', err);
            }
        },

        'invoice.deliver': async ({ el }) => {
            await changeStatus(el, 'DELIVERED', 'Mark this invoice as delivered? This will deduct stock for each line item.');
        },

        'invoice.cancel': async ({ el }) => {
            await changeStatus(el, 'CANCELLED', 'Cancel this sales invoice?');
        },

        'invoice.collect': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const invoiceId = rowData[0];
            if (!invoiceId) return;

            const res = await apiFetch(`/api/sales-invoices/${invoiceId}`);
            const json = await res.json();
            if (!json.success) return;
            const inv = json.data.sales_invoice;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/collectionForm.html',
                controller: '../pages/Finance/collectionForm.modal.js',
                data: {
                    prefill: {
                        payee_id: inv.customer_payee_id,
                        reference_type: 'SALES_INVOICE',
                        reference_id: String(inv.id),
                        currency: inv.currency,
                        exchange_rate: inv.exchange_rate,
                        amount_fc: inv.remaining_amount_fc,
                    }
                },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'invoice.download-pdf': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            // Open PDF download in a new tab/window
            const token = getToken();
            window.open(`/api/sales-invoices/${id}/pdf?token=${encodeURIComponent(token)}`, '_blank');
        },

        'invoice.credit-note': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            await openModal({
                scope:      MODAL_SCOPE.PAGE,
                template:   'html/SalesInvoices/creditNoteForm.html',
                controller: '../pages/SalesInvoices/creditNoteForm.modal.js',
                data:       { invoice_id: id },
                options:    { backdrop: false, keyboard: true, focus: true }
            });
        },
    });
}

export function unmount() {
    if (invUpdatedHandler) {
        document.removeEventListener('module.updated', invUpdatedHandler);
        invUpdatedHandler = null;
    }
    if (collectionsUpdatedHandler) {
        document.removeEventListener('collections.updated', collectionsUpdatedHandler);
        collectionsUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    invDataTable?.destroy();
    invDataTable = null;
    closeModalsInContainer(inv_container);
}

export async function render(container) {
    inv_container = container;
    const html = await fetch('html/SalesInvoices/salesInvoices.html').then(r => r.text());
    container.innerHTML = html;

    // Hide create button if no permission
    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'invoice.create' && !hasPermission('invoices.create')) {
            el.remove();
        }
    });
}

async function fetchInvoices() {
    const res  = await apiFetch('/api/sales-invoices', {method: 'GET'});
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.sales_invoices ?? []);
}

async function changeStatus(el, newStatus, confirmMessage) {
    const tr      = el.closest('tr');
    const rowData = invDataTable.row(tr).data();
    const id      = rowData[0];
    if (!id) return;

    const confirmed = await confirm({ title: 'Confirm', message: confirmMessage });
    if (!confirmed) return;

    try {
        const res  = await apiFetch(`/api/sales-invoices/${id}/status`, {
            method: 'POST',
            body:   JSON.stringify({ status: newStatus })
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            await alert({ title: 'Error', alertText: data.message || 'Failed to update status' });
        } else {
            await fetchInvoices();
        }
    } catch (err) {
        console.error('Status update failed:', err);
    }
}

function renderTable(invoices) {
    if (!invDataTable) {
        invDataTable = createDataTable('#salesInvoicesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: 10, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 9, className: 'text-center', orderable: false, searchable: false }
            ],
            createdRow: function(row, data) {
                // data[10] is the raw due_date value
                const dueDateRaw = data[10];
                if (dueDateRaw) {
                    const today = new Date().toISOString().slice(0, 10);
                    if (dueDateRaw < today) {
                        const daysOverdue = Math.floor((new Date(today) - new Date(dueDateRaw)) / 86400000);
                        if (daysOverdue > 60) {
                            row.classList.add('table-danger');
                        } else if (daysOverdue > 0) {
                            row.classList.add('table-warning');
                        }
                    }
                }
            }
        });
    }

    invDataTable.clear();

    const canView    = hasPermission('invoices.view');
    const canDelete  = hasPermission('invoices.delete');
    const canDeliver = hasPermission('invoices.approve');
    const canCollect = hasPermission('collections.create');
    const today      = new Date().toISOString().slice(0, 10);

    invoices.forEach(inv => {
        const badgeClass  = STATUS_BADGE[inv.status] || 'secondary';
        const statusBadge = `<span class="badge bg-${escHtml(badgeClass)}">${escHtml(inv.status)}</span>`;

        // Display computed Payment_Status badge for DELIVERED invoices
        let paymentBadge = '';
        if (inv.status === 'DELIVERED' && inv.payment_status) {
            const pBadgeClass = PAYMENT_STATUS_BADGE[inv.payment_status] || 'secondary';
            paymentBadge = ` <span class="badge bg-${escHtml(pBadgeClass)}">${escHtml(inv.payment_status)}</span>`;
        }

        const totalFc  = inv.total_amount_fc != null ? getFormatter().formatNumber(inv.total_amount_fc, 2) : '0.00';
        const paidFc   = inv.amount_paid_fc != null ? getFormatter().formatNumber(inv.amount_paid_fc, 2) : '0.00';
        const balanceFc = inv.balance_fc != null ? getFormatter().formatNumber(inv.balance_fc, 2) : '0.00';

        // Due date display with overdue highlighting
        let dueDateDisplay = '—';
        let isOverdue = false;
        if (inv.due_date) {
            dueDateDisplay = getFormatter().formatDate(inv.due_date);
            if (inv.status === 'DELIVERED' && inv.due_date < today) {
                isOverdue = true;
                dueDateDisplay = `<span class="text-danger fw-bold">${dueDateDisplay}</span>`;
            }
        }

        // Build workflow action buttons based on current status
        let actions = '';

        if (canView) {
            actions += `<button data-action="invoice.view" class="btn btn-light btn-circle btn-sm me-1" title="View / Edit">
                            <i class="fas fa-eye text-info"></i></button>`;
        }

        if (canDeliver && inv.status === 'DRAFT') {
            actions += `<button data-action="invoice.deliver" class="btn btn-light btn-circle btn-sm me-1" title="Mark Delivered">
                            <i class="fas fa-shipping-fast text-primary"></i></button>`;
        }

        if ((inv.status === 'DRAFT' || inv.status === 'DELIVERED') && canDeliver) {
            actions += `<button data-action="invoice.cancel" class="btn btn-light btn-circle btn-sm me-1" title="Cancel">
                            <i class="fas fa-ban text-warning"></i></button>`;
        }

        if (canDelete && inv.status === 'DRAFT') {
            actions += `<button data-action="invoice.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash text-danger"></i></button>`;
        }

        if (canCollect && inv.status === 'DELIVERED' && inv.payment_status !== 'PAID') {
            actions += `<button data-action="invoice.collect" class="btn btn-light btn-circle btn-sm me-1" title="Create Collection Slip">
                            <i class="fas fa-receipt text-secondary"></i></button>`;
        }

        if (canView) {
            actions += `<button data-action="invoice.download-pdf" class="btn btn-outline-danger btn-circle btn-sm me-1" title="Download PDF">
                            <i class="fas fa-file-pdf"></i></button>`;
        }

        if (canView && (inv.status === 'DELIVERED' || inv.status === 'PAID')) {
            actions += `<button data-action="invoice.credit-note" class="btn btn-outline-warning btn-circle btn-sm me-1" title="Issue Credit Note">
                            <i class="fas fa-undo"></i></button>`;
        }

        const rowNode = invDataTable.row.add([
            inv.id,
            escHtml(inv.invoice_number ?? ''),
            escHtml(inv.customer_name  ?? '—'),
            getFormatter().formatDate(inv.invoice_date ?? ''),
            dueDateDisplay,
            statusBadge + paymentBadge,
            totalFc,
            paidFc,
            balanceFc,
            actions,
            inv.due_date || ''
        ]).node();
    });

    invDataTable.draw(false);
    invDataTable.columns.adjust();
}
