// assets/js/pages/PurchaseOrders/purchaseOrders.js

import { hasPermission, getToken } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';
import { receivePOWithBatchInfo } from './poReceive.js';
import { createMultiSelect } from '../../components/multiSelect.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

const STATUS_BADGE = {
    DRAFT:     'secondary',
    APPROVED:  'primary',
    RECEIVED:  'success',
    CANCELLED: 'danger',
};

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
let allPOs = [];
let statusFilterSelect = null;
let supplierFilterSelect = null;
let selectedStatuses = [];
let selectedSuppliers = [];

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
            const tr = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id = rowData[0];
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
            const tr = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Delete Purchase Order',
                message: 'Delete this purchase order? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/purchase-orders/${id}`, { method: 'DELETE' });
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
            const tr = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;
            await receivePOWithBatchInfo(id, async () => { await fetchPurchaseOrders(); });
        },

        'po.cancel': async ({ el }) => {
            await changeStatus(el, 'CANCELLED', 'Cancel this purchase order?');
        },

        'po.record-payment': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            try {
                const res = await apiFetch(`/api/purchase-orders/${id}/payment-shortcut`);
                const json = await res.json();
                if (!json.success) {
                    await alert({ title: 'Error', alertText: json.message || 'Unable to load payment data' });
                    return;
                }
                const shortcut = json.data.payment_shortcut;
                await openModal({
                    scope:      MODAL_SCOPE.PAGE,
                    template:   'html/Finance/paymentForm.html',
                    controller: '../pages/Finance/paymentForm.modal.js',
                    data:       { prefill: {
                        payee_id: shortcut.supplier_id,
                        reference_type: shortcut.reference_type,
                        reference_id: shortcut.reference_id,
                        currency: shortcut.currency,
                        exchange_rate: shortcut.exchange_rate,
                        amount_fc: shortcut.amount_fc,
                    }},
                    options: { backdrop: false, keyboard: true, focus: true }
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
            const token = getToken();
            window.open(`/api/purchase-orders/${id}/pdf?token=${encodeURIComponent(token)}`, '_blank');
        },

        'po.debit-note': async ({ el }) => {
            // Navigate to the debit notes page filtered by this PO
            const tr = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;
            window.location.hash = `#/purchase-orders/debit-notes?po_id=${id}`;
        },

        'po.duplicate': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = poDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Duplicate Purchase Order',
                message: 'Create a new DRAFT PO with the same line items?'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/purchase-orders/${id}/duplicate`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to duplicate PO' });
                } else {
                    await fetchPurchaseOrders();
                }
            } catch (err) {
                console.error('Duplicate PO failed:', err);
            }
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
    statusFilterSelect?.destroy?.();
    supplierFilterSelect?.destroy?.();
    statusFilterSelect = null;
    supplierFilterSelect = null;
    poDataTable?.destroy();
    poDataTable = null;
    allPOs = [];
    selectedStatuses = [];
    selectedSuppliers = [];
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
    const res = await apiFetch('/api/purchase-orders');
    const data = await res.json();
    if (!data.success) return;
    allPOs = data.data.purchase_orders ?? [];
    buildFilters();
    renderTable(getFilteredPOs());
}

function buildFilters() {
    // Build status filter
    if (!statusFilterSelect) {
        const statusHost = po_container.querySelector('#poStatusFilterHost');
        if (!statusHost) return;

        const statusOptions = [
            { value: 'DRAFT', label: 'Draft' },
            { value: 'APPROVED', label: 'Approved' },
            { value: 'RECEIVED', label: 'Received' },
            { value: 'CANCELLED', label: 'Cancelled' },
            { value: 'UNPAID', label: 'Unpaid' },
            { value: 'PARTIALLY_PAID', label: 'Partial' },
            { value: 'PAID', label: 'Paid' },
        ];

        statusFilterSelect = createMultiSelect({
            container: statusHost,
            options: statusOptions,
            placeholder: 'Filter status...',
            onChange: (selected) => {
                selectedStatuses = selected;
                renderTable(getFilteredPOs());
            }
        });
    }

    // Build supplier filter
    if (!supplierFilterSelect) {
        const supplierMap = new Map();
        allPOs.forEach(po => {
            if (po.supplier_id && po.supplier_name) {
                supplierMap.set(String(po.supplier_id), po.supplier_name);
            }
        });
        const supplierOptions = Array.from(supplierMap.entries()).map(([value, label]) => ({ value, label }));

        if (supplierOptions.length > 1) {
            const supplierHost = po_container.querySelector('#poSupplierFilterHost');
            if (supplierHost) {
                supplierFilterSelect = createMultiSelect({
                    container: supplierHost,
                    options: supplierOptions,
                    placeholder: 'Filter supplier...',
                    onChange: (selected) => {
                        selectedSuppliers = selected;
                        renderTable(getFilteredPOs());
                    }
                });
            }
        }
    }
}

function getFilteredPOs() {
    let filtered = allPOs;

    if (selectedStatuses.length > 0) {
        // Split into document statuses and payment statuses
        const docStatuses = selectedStatuses.filter(s => ['DRAFT', 'APPROVED', 'RECEIVED', 'CANCELLED'].includes(s));
        const payStatuses = selectedStatuses.filter(s => ['UNPAID', 'PARTIALLY_PAID', 'PAID'].includes(s));

        filtered = filtered.filter(po => {
            const matchDoc = docStatuses.length === 0 || docStatuses.includes(po.status);
            const matchPay = payStatuses.length === 0 || payStatuses.includes(po.payment_status);
            // If user selected both types, require both to match
            if (docStatuses.length > 0 && payStatuses.length > 0) {
                return matchDoc && matchPay;
            }
            return matchDoc || matchPay;
        });
    }

    if (selectedSuppliers.length > 0) {
        filtered = filtered.filter(po => selectedSuppliers.includes(String(po.supplier_id)));
    }

    return filtered;
}

async function changeStatus(el, newStatus, confirmMessage) {
    const tr = el.closest('tr');
    const rowData = poDataTable.row(tr).data();
    const id = rowData[0];
    if (!id) return;

    const confirmed = await confirm({ title: 'Confirm', message: confirmMessage });
    if (!confirmed) return;

    try {
        const res = await apiFetch(`/api/purchase-orders/${id}/status`, {
            method: 'POST',
            body: JSON.stringify({ status: newStatus })
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
                { targets: 9, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    poDataTable.clear();

    const canView    = hasPermission('po.view');
    const canDelete  = hasPermission('po.delete');
    const canApprove = hasPermission('po.approve');
    const canReceive = hasPermission('po.receive');
    const canPay     = hasPermission('payments.create');
    const canCreate  = hasPermission('po.create');
    const canEdit    = hasPermission('po.edit');

    pos.forEach(po => {
        const badgeClass = STATUS_BADGE[po.status] || 'secondary';
        const statusBadge = `<span class="badge bg-${escHtml(badgeClass)}">${escHtml(po.status)}</span>`;

        let paymentBadge = '';
        if (po.status === 'RECEIVED' && po.payment_status) {
            const pBadgeClass = PAYMENT_STATUS_BADGE[po.payment_status] || 'secondary';
            paymentBadge = ` <span class="badge bg-${escHtml(pBadgeClass)}">${escHtml(po.payment_status.replace('_', ' '))}</span>`;
        }

        const totalFc   = po.total_amount_fc != null ? getFormatter().formatNumber(po.total_amount_fc, 2) : '0.00';
        const returnsFc = po.returns_fc != null && po.returns_fc > 0 ? getFormatter().formatNumber(po.returns_fc, 2) : '—';
        const paidFc    = po.amount_paid_fc != null ? getFormatter().formatNumber(po.amount_paid_fc, 2) : '0.00';
        const balanceFc = po.balance_fc != null ? getFormatter().formatNumber(po.balance_fc, 2) : '0.00';

        // Build actions: primary inline + overflow dropdown
        const actionItems = buildActionItems(po, { canView, canDelete, canApprove, canReceive, canPay, canCreate, canEdit });
        const actions = renderActions(actionItems);

        poDataTable.row.add([
            po.id,
            escHtml(po.po_number || ''),
            escHtml(po.supplier_name || '—'),
            getFormatter().formatDate(po.po_date || ''),
            statusBadge + paymentBadge,
            `${escHtml(po.currency || '')} ${totalFc}`,
            returnsFc === '—' ? '—' : `${escHtml(po.currency || '')} ${returnsFc}`,
            `${escHtml(po.currency || '')} ${paidFc}`,
            `${escHtml(po.currency || '')} ${balanceFc}`,
            actions
        ]);
    });

    poDataTable.draw(false);
    poDataTable.columns.adjust();
}

function buildActionItems(po, perms) {
    const items = [];

    // Primary: View (always first)
    if (perms.canView) {
        items.push({ action: 'po.view', icon: 'fa-eye text-info', label: 'View', primary: true });
    }

    // Workflow actions based on status
    if (perms.canApprove && po.status === 'DRAFT') {
        items.push({ action: 'po.approve', icon: 'fa-check text-primary', label: 'Approve', primary: true });
    }
    if (perms.canReceive && po.status === 'APPROVED') {
        items.push({ action: 'po.receive', icon: 'fa-truck text-success', label: 'Receive', primary: true });
    }
    if (perms.canPay && po.status === 'RECEIVED' && po.payment_status !== 'PAID') {
        items.push({ action: 'po.record-payment', icon: 'fa-money-bill-wave text-dark', label: 'Pay', primary: true });
    }

    // Secondary actions (overflow)
    if (perms.canApprove && (po.status === 'APPROVED' || po.status === 'RECEIVED')) {
        items.push({ action: 'po.cancel', icon: 'fa-ban text-warning', label: 'Cancel' });
    }
    if (perms.canView) {
        items.push({ action: 'po.download-pdf', icon: 'fa-file-pdf text-danger', label: 'Download PDF' });
    }
    if (perms.canView && po.status === 'RECEIVED') {
        items.push({ action: 'po.debit-note', icon: 'fa-undo text-warning', label: 'Debit Notes' });
    }
    if (perms.canCreate) {
        items.push({ action: 'po.duplicate', icon: 'fa-copy text-secondary', label: 'Duplicate' });
    }
    if (perms.canDelete && po.status === 'DRAFT') {
        items.push({ action: 'po.delete', icon: 'fa-trash text-danger', label: 'Delete', divider: true });
    }

    return items;
}

function renderActions(items) {
    if (items.length === 0) return '';

    // Split into primary (max 3) and overflow
    const primary = items.filter(i => i.primary).slice(0, 3);
    const overflow = items.filter(i => !i.primary || items.filter(x => x.primary).indexOf(i) >= 3);

    let html = primary.map(item =>
        `<button data-action="${item.action}" class="btn btn-light btn-circle btn-sm me-1" title="${escHtml(item.label)}">
            <i class="fas ${item.icon}"></i></button>`
    ).join('');

    if (overflow.length > 0) {
        html += `<div class="dropdown d-inline-block">
            <button class="btn btn-light btn-circle btn-sm" data-bs-toggle="dropdown" data-bs-auto-close="true" data-bs-display="dynamic" title="More">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                ${overflow.map(item => {
                    const divider = item.divider ? '<li><hr class="dropdown-divider"></li>' : '';
                    return `${divider}<li><button class="dropdown-item small" data-action="${item.action}">
                        <i class="fas ${item.icon} me-2"></i>${escHtml(item.label)}</button></li>`;
                }).join('')}
            </ul>
        </div>`;
    }

    return html;
}
