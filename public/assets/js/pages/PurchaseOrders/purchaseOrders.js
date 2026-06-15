// assets/js/pages/PurchaseOrders/purchaseOrders.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';
import { receivePOWithBatchInfo } from './poReceive.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

/** Map status → Bootstrap badge class */
const STATUS_BADGE = {
    DRAFT:     'secondary',
    APPROVED:  'primary',
    RECEIVED:  'success',
    CANCELLED: 'danger',
};

/** Map Payment_Status → Bootstrap badge class */
const PAYMENT_STATUS_BADGE = {
    UNPAID:         'warning',
    PARTIALLY_PAID: 'info',
    PAID:           'success',
};

let po_container;
let unregister;
let poUpdatedHandler;
let paymentsUpdatedHandler;
let poDataTable = null;

export async function mount() {
    await fetchPurchaseOrders();

    poUpdatedHandler = async () => { await fetchPurchaseOrders(); };
    document.addEventListener('module.updated', poUpdatedHandler);

    paymentsUpdatedHandler = async () => { await fetchPurchaseOrders(); };
    document.addEventListener('payments.updated', paymentsUpdatedHandler);

    unregister = registerPageActions({

        'po.create': async () => {
            await openModal({
                scope:      MODAL_SCOPE.PAGE,
                template:   'html/PurchaseOrders/poForm.html',
                controller: '../pages/PurchaseOrders/poForm.modal.js',
                data:       {},
                options:    { backdrop: false, keyboard: true, focus: true }
            });
        },

        'po.view': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope:      MODAL_SCOPE.PAGE,
                template:   'html/PurchaseOrders/poForm.html',
                controller: '../pages/PurchaseOrders/poForm.modal.js',
                data:       { po_id: id },
                options:    { backdrop: false, keyboard: true, focus: true }
            });
        },

        'po.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete Purchase Order',
                message: 'Delete this purchase order? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/purchase-orders/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete purchase order' });
                } else {
                    await fetchPurchaseOrders();
                }
            } catch (err) {
                console.error('Delete PO failed:', err);
            }
        },

        'po.approve': async ({ el }) => {
            await changeStatus(el, 'APPROVED', 'Approve this purchase order?');
        },

        'po.receive': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            await receivePOWithBatchInfo(id, async () => {
                await fetchPurchaseOrders();
            });
        },

        'po.cancel': async ({ el }) => {
            await changeStatus(el, 'CANCELLED', 'Cancel this purchase order?');
        },

        'po.record-payment': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            try {
                const res  = await apiFetch(`/api/purchase-orders/${id}/payment-shortcut`);
                const json = await res.json();
                if (!json.success) {
                    await alert({ title: 'Error', alertText: json.message || 'Unable to load payment shortcut data' });
                    return;
                }
                const shortcut = json.data.payment_shortcut;

                await openModal({
                    scope:      MODAL_SCOPE.PAGE,
                    template:   'html/Finance/paymentForm.html',
                    controller: '../pages/Finance/paymentForm.modal.js',
                    data:       {
                        prefill: {
                            payee_id:       shortcut.supplier_id,
                            reference_type: shortcut.reference_type,
                            reference_id:   shortcut.reference_id,
                            currency:       shortcut.currency,
                            exchange_rate:  shortcut.exchange_rate,
                            amount_fc:      shortcut.amount_fc,
                        }
                    },
                    options:    { backdrop: false, keyboard: true, focus: true }
                });
            } catch (err) {
                console.error('Payment shortcut failed:', err);
            }
        },

        'po.download-pdf': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            // Open PDF download in a new tab/window
            const token = localStorage.getItem('token') || sessionStorage.getItem('token');
            window.open(`/api/purchase-orders/${id}/pdf?token=${encodeURIComponent(token)}`, '_blank');
        },

        'po.debit-note': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            await openModal({
                scope:      MODAL_SCOPE.PAGE,
                template:   'html/PurchaseOrders/debitNoteForm.html',
                controller: '../pages/PurchaseOrders/debitNoteForm.modal.js',
                data:       { po_id: id },
                options:    { backdrop: false, keyboard: true, focus: true }
            });
        },
    });
}

export function unmount() {
    if (poUpdatedHandler) {
        document.removeEventListener('module.updated', poUpdatedHandler);
        poUpdatedHandler = null;
    }
    if (paymentsUpdatedHandler) {
        document.removeEventListener('payments.updated', paymentsUpdatedHandler);
        paymentsUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    poDataTable?.destroy();
    poDataTable = null;
    closeModalsInContainer(po_container);
}

export async function render(container) {
    po_container = container;
    const html = await fetch('html/PurchaseOrders/purchaseOrders.html').then(r => r.text());
    container.innerHTML = html;

    // Hide create button if no permission
    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'po.create' && !hasPermission('po.create')) {
            el.remove();
        }
    });
}

async function fetchPurchaseOrders() {
    const res  = await apiFetch('/api/purchase-orders');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.purchase_orders ?? []);
}

async function changeStatus(el, newStatus, confirmMessage) {
    const tr      = el.closest('tr');
    const rowData = poDataTable.row(tr).data();
    const id      = rowData[0];
    if (!id) return;

    const confirmed = await confirm({ title: 'Confirm', message: confirmMessage });
    if (!confirmed) return;

    try {
        const res  = await apiFetch(`/api/purchase-orders/${id}/status`, {
            method: 'POST',
            body:   JSON.stringify({ status: newStatus })
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            await alert({ title: 'Error', alertText: data.message || 'Failed to update status' });
        } else {
            await fetchPurchaseOrders();
        }
    } catch (err) {
        console.error('Status update failed:', err);
    }
}

function renderTable(pos) {
    if (!poDataTable) {
        poDataTable = createDataTable('#purchaseOrdersTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 8, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    poDataTable.clear();

    const canView    = hasPermission('po.view');
    const canDelete  = hasPermission('po.delete');
    const canApprove = hasPermission('po.approve');
    const canReceive = hasPermission('po.receive');
    const canPay     = hasPermission('payments.create');

    pos.forEach(po => {
        const badgeClass = STATUS_BADGE[po.status] || 'secondary';
        const statusBadge = `<span class="badge bg-${escHtml(badgeClass)}">${escHtml(po.status)}</span>`;

        // Display computed Payment_Status badge for RECEIVED POs
        let paymentBadge = '';
        if (po.status === 'RECEIVED' && po.payment_status) {
            const pBadgeClass = PAYMENT_STATUS_BADGE[po.payment_status] || 'secondary';
            paymentBadge = ` <span class="badge bg-${escHtml(pBadgeClass)}">${escHtml(po.payment_status)}</span>`;
        }

        const totalFc   = po.total_amount_fc != null ? getFormatter().formatNumber(po.total_amount_fc, 2) : '0.00';
        const paidFc    = po.amount_paid_fc != null ? getFormatter().formatNumber(po.amount_paid_fc, 2) : '0.00';
        const balanceFc = po.balance_fc != null ? getFormatter().formatNumber(po.balance_fc, 2) : '0.00';

        // Build workflow action buttons based on current status
        let actions = '';

        if (canView) {
            actions += `<button data-action="po.view" class="btn btn-info btn-circle btn-sm me-1" title="View / Edit">
                            <i class="fas fa-eye"></i></button>`;
        }

        if (canApprove && po.status === 'DRAFT') {
            actions += `<button data-action="po.approve" class="btn btn-primary btn-circle btn-sm me-1" title="Approve">
                            <i class="fas fa-check"></i></button>`;
        }

        if (canReceive && po.status === 'APPROVED') {
            actions += `<button data-action="po.receive" class="btn btn-success btn-circle btn-sm me-1" title="Mark Received">
                            <i class="fas fa-truck"></i></button>`;
        }

        if (canApprove && (po.status === 'APPROVED' || po.status === 'RECEIVED')) {
            actions += `<button data-action="po.cancel" class="btn btn-warning btn-circle btn-sm me-1" title="Cancel">
                            <i class="fas fa-ban"></i></button>`;
        }

        if (canPay && po.status === 'RECEIVED' && po.payment_status !== 'PAID') {
            actions += `<button data-action="po.record-payment" class="btn btn-dark btn-circle btn-sm me-1" title="Record Payment">
                            <i class="fas fa-money-bill-wave"></i></button>`;
        }

        if (canDelete && po.status === 'DRAFT') {
            actions += `<button data-action="po.delete" class="btn btn-danger btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash"></i></button>`;
        }

        if (canView) {
            actions += `<button data-action="po.download-pdf" class="btn btn-outline-danger btn-circle btn-sm me-1" title="Download PDF">
                            <i class="fas fa-file-pdf"></i></button>`;
        }

        if (canView && (po.status === 'RECEIVED' || po.status === 'CLOSED')) {
            actions += `<button data-action="po.debit-note" class="btn btn-outline-warning btn-circle btn-sm me-1" title="Issue Debit Note">
                            <i class="fas fa-undo"></i></button>`;
        }

        poDataTable.row.add([
            po.id,
            escHtml(po.po_number   ?? ''),
            escHtml(po.supplier_name ?? '—'),
            getFormatter().formatDate(po.po_date ?? ''),
            statusBadge + paymentBadge,
            totalFc,
            paidFc,
            balanceFc,
            actions
        ]);
    });

    poDataTable.draw(false);
    poDataTable.columns.adjust();
}
