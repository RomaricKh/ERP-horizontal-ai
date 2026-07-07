// assets/js/pages/SalesInvoices/salesInvoices.js

import { hasPermission, getToken } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';
import { createMultiSelect } from '../../components/multiSelect.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

const STATUS_BADGE = {
    DRAFT:     'secondary',
    DELIVERED: 'primary',
    PAID:      'success',
    CANCELLED: 'danger',
};

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
let allInvoices = [];
let statusFilterSelect = null;
let customerFilterSelect = null;
let selectedStatuses = [];
let selectedCustomers = [];

export async function mount() {
    await fetchInvoices();

    invUpdatedHandler = async () => { await fetchInvoices(); };
    document.addEventListener('module.updated', invUpdatedHandler);

    collectionsUpdatedHandler = async () => { await fetchInvoices(); };
    document.addEventListener('collections.updated', collectionsUpdatedHandler);

    unregister = registerPageActions({

        'invoice.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/SalesInvoices/invoiceForm.html',
                controller: '../pages/SalesInvoices/invoiceForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'invoice.view': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/SalesInvoices/invoiceForm.html',
                controller: '../pages/SalesInvoices/invoiceForm.modal.js',
                data: { invoice_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'invoice.delete': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Delete Sales Invoice',
                message: 'Delete this sales invoice? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/sales-invoices/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete' });
                } else {
                    await fetchInvoices();
                }
            } catch (err) {
                console.error('Delete invoice failed:', err);
            }
        },

        'invoice.edit-number': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id = rowData[0];
            const currentNumber = rowData[1];
            if (!id) return;

            const newNumber = window.prompt('Enter new invoice ID number:', currentNumber);
            if (newNumber !== null && newNumber.trim() !== '' && newNumber.trim() !== currentNumber) {
                try {
                    const res = await apiFetch(`/api/sales-invoices/${id}/number`, {
                        method: 'PUT',
                        body: JSON.stringify({ invoice_number: newNumber.trim() })
                    });
                    const data = await res.json();
                    if (!res.ok || !data.success) {
                        await alert({ title: 'Error', alertText: data.message || 'Failed to update ID number' });
                    } else {
                        await fetchInvoices();
                    }
                } catch (err) {
                    console.error('Update ID failed:', err);
                }
            }
        },

        'invoice.deliver': async ({ el }) => {
            await changeStatus(el, 'DELIVERED', 'Mark this invoice as delivered? Stock will be deducted.');
        },

        'invoice.cancel': async ({ el }) => {
            await changeStatus(el, 'CANCELLED', 'Cancel this sales invoice?');
        },

        'invoice.collect': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            try {
                const res = await apiFetch(`/api/sales-invoices/${id}`);
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
                            amount_fc: inv.remaining_amount_fc || inv.balance_fc,
                        }
                    },
                    options: { backdrop: false, keyboard: true, focus: true }
                });
            } catch (err) {
                console.error('Collect failed:', err);
            }
        },

        'invoice.download-pdf': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;
            const token = getToken();
            const businessId = localStorage.getItem('business_id');
            const bizParam = businessId ? `&business_id=${businessId}` : '';
            window.open(`/api/sales-invoices/${id}/pdf?token=${encodeURIComponent(token)}${bizParam}`, '_blank');
        },

        'invoice.credit-note': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;
            window.location.hash = `#/invoices/credit-notes?invoice_id=${id}`;
        },

        'invoice.duplicate': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = invDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Duplicate Invoice',
                message: 'Create a new DRAFT invoice with the same line items?'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/sales-invoices/${id}/duplicate`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to duplicate' });
                } else {
                    await fetchInvoices();
                }
            } catch (err) {
                console.error('Duplicate failed:', err);
            }
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
    statusFilterSelect?.destroy?.();
    customerFilterSelect?.destroy?.();
    statusFilterSelect = null;
    customerFilterSelect = null;
    invDataTable?.destroy();
    invDataTable = null;
    allInvoices = [];
    selectedStatuses = [];
    selectedCustomers = [];
    closeModalsInContainer(inv_container);
}

export async function render(container) {
    inv_container = container;
    const html = await fetch('html/SalesInvoices/salesInvoices.html').then(r => r.text());
    container.innerHTML = html;

    if (!hasPermission('invoices.create')) {
        container.querySelectorAll('[data-action="invoice.create"]').forEach(el => el.remove());
    }

    // Handle Quick Action param
    const urlParams = new URLSearchParams(window.location.hash.split('?')[1]);
    if (urlParams.get('action') === 'new' && hasPermission('invoices.create')) {
        setTimeout(() => {
            const btn = container.querySelector('[data-action="invoice.create"]');
            if (btn) btn.click();
            // Clear URL param without reloading to prevent opening modal on refresh
            window.history.replaceState(null, '', window.location.pathname + '#/invoices');
        }, 100);
    }
}

async function fetchInvoices() {
    const res = await apiFetch('/api/sales-invoices');
    const data = await res.json();
    if (!data.success) return;
    allInvoices = data.data.sales_invoices ?? [];
    renderTable(allInvoices);
}

function buildFilters() {
    if (statusFilterSelect) return; // Already built

    const dtSearch = inv_container.querySelector('.dt-search') || inv_container.querySelector('.dataTables_filter');
    if (!dtSearch) return;

    const searchParent = dtSearch.parentElement;
    searchParent.style.display = 'flex';
    searchParent.style.alignItems = 'flex-start';
    searchParent.style.gap = '1rem';
    searchParent.style.flexWrap = 'wrap';

    // Status filter
    const statusHost = document.createElement('div');
    statusHost.style.minWidth = '200px';
    searchParent.insertBefore(statusHost, dtSearch);

    statusFilterSelect = createMultiSelect({
        container: statusHost,
        options: [
            { value: 'DRAFT', label: 'Draft' },
            { value: 'DELIVERED', label: 'Delivered' },
            { value: 'CANCELLED', label: 'Cancelled' },
            { value: 'UNPAID', label: 'Unpaid' },
            { value: 'PARTIALLY PAID', label: 'Partially Paid' },
            { value: 'SETTLED', label: 'Paid' },
        ],
        placeholder: 'Filter status...',
        onChange: (selected) => {
            if (!invDataTable) return;
            if (selected.length === 0) {
                invDataTable.column(4).search('').draw();
            } else {
                const regex = selected.join('|');
                invDataTable.column(4).search(regex, true, false).draw();
            }
        }
    });

    // Customer filter
    const customerMap = new Map();
    allInvoices.forEach(inv => {
        if (inv.customer_id && inv.customer_name) {
            customerMap.set(String(inv.customer_id), inv.customer_name);
        }
    });
    const customerOptions = Array.from(customerMap.entries()).map(([value, label]) => ({ value, label }));

    if (customerOptions.length > 1) {
        const customerHost = document.createElement('div');
        customerHost.style.minWidth = '200px';
        searchParent.insertBefore(customerHost, dtSearch);

        customerFilterSelect = createMultiSelect({
            container: customerHost,
            options: customerOptions,
            placeholder: 'Filter customer...',
            onChange: (selected) => {
                if (!invDataTable) return;
                if (selected.length === 0) {
                    invDataTable.column(2).search('').draw();
                } else {
                    const selectedNames = selected.map(v => customerMap.get(v) || v);
                    const regex = selectedNames.map(s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
                    invDataTable.column(2).search(regex, true, false).draw();
                }
            }
        });
    }
}

function getFilteredInvoices() {
    let filtered = allInvoices;

    if (selectedStatuses.length > 0) {
        const docStatuses = selectedStatuses.filter(s => ['DRAFT', 'DELIVERED', 'CANCELLED'].includes(s));
        const payStatuses = selectedStatuses.filter(s => ['UNPAID', 'PARTIALLY_PAID', 'PAID'].includes(s));

        filtered = filtered.filter(inv => {
            const matchDoc = docStatuses.length === 0 || docStatuses.includes(inv.status);
            const matchPay = payStatuses.length === 0 || payStatuses.includes(inv.payment_status);
            if (docStatuses.length > 0 && payStatuses.length > 0) return matchDoc && matchPay;
            return matchDoc || matchPay;
        });
    }

    if (selectedCustomers.length > 0) {
        filtered = filtered.filter(inv => selectedCustomers.includes(String(inv.customer_id)));
    }

    return filtered;
}

async function changeStatus(el, newStatus, confirmMessage) {
    const tr = el.closest('tr');
    const rowData = invDataTable.row(tr).data();
    const id = rowData[0];
    if (!id) return;

    const confirmed = await confirm({ title: 'Confirm', message: confirmMessage });
    if (!confirmed) return;

    try {
        const res = await apiFetch(`/api/sales-invoices/${id}/status`, {
            method: 'POST',
            body: JSON.stringify({ status: newStatus })
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
            order: [[1, 'desc'], [3, 'desc']],
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { 
                    targets: 3, 
                    render: function(data, type) {
                        return (type === 'sort' || type === 'type') ? data : getFormatter().formatDate(data);
                    }
                },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 9, className: 'text-center', orderable: false, searchable: false }
            ]
        });

        // Build filters next to the search box (after DataTable DOM is ready)
        buildFilters();
    }

    invDataTable.clear();

    const canView    = hasPermission('invoices.view');
    const canDelete  = hasPermission('invoices.delete');
    const canDeliver = hasPermission('invoices.approve');
    const canCollect = hasPermission('collections.create');
    const canCreate  = hasPermission('invoices.create');

    invoices.forEach(inv => {
        const badgeClass = STATUS_BADGE[inv.status] || 'secondary';
        const statusBadge = `<span class="badge bg-${escHtml(badgeClass)}">${escHtml(inv.status)}</span>`;

        let paymentBadge = '';
        if (inv.status === 'DELIVERED' && inv.payment_status) {
            const pBadgeClass = PAYMENT_STATUS_BADGE[inv.payment_status] || 'secondary';
            const pLabel = inv.payment_status === 'PARTIALLY_PAID' ? 'PARTIALLY PAID'
                         : inv.payment_status === 'PAID' ? 'SETTLED'
                         : inv.payment_status;
            paymentBadge = ` <span class="badge bg-${escHtml(pBadgeClass)}">${escHtml(pLabel)}</span>`;
        }

        const totalFc = inv.total_amount_fc != null ? getFormatter().formatNumber(inv.total_amount_fc, 2) : '0.00';
        const returnsFc = inv.returns_fc != null && inv.returns_fc > 0 ? getFormatter().formatNumber(inv.returns_fc, 2) : '—';
        const collectedFc = inv.amount_collected_fc != null ? getFormatter().formatNumber(inv.amount_collected_fc, 2) : '0.00';
        const balanceFc = inv.balance_fc != null ? getFormatter().formatNumber(inv.balance_fc, 2) : '0.00';

        // Build actions with overflow
        const actionItems = buildActionItems(inv, { canView, canDelete, canDeliver, canCollect, canCreate });
        const actions = renderActions(actionItems);

        invDataTable.row.add([
            inv.id,
            escHtml(inv.invoice_number || ''),
            escHtml(inv.customer_name || '—'),
            inv.invoice_date || '',
            statusBadge + paymentBadge,
            `${escHtml(inv.currency || '')} ${totalFc}`,
            returnsFc === '—' ? '—' : `${escHtml(inv.currency || '')} ${returnsFc}`,
            `${escHtml(inv.currency || '')} ${collectedFc}`,
            `${escHtml(inv.currency || '')} ${balanceFc}`,
            actions
        ]);
    });

    invDataTable.draw(false);
    invDataTable.columns.adjust();
}

function buildActionItems(inv, perms) {
    const items = [];

    if (perms.canView) {
        items.push({ action: 'invoice.view', icon: 'fa-eye text-info', label: 'View', primary: true });
    }
    if (perms.canDeliver && inv.status === 'DRAFT') {
        items.push({ action: 'invoice.deliver', icon: 'fa-shipping-fast text-primary', label: 'Deliver', primary: true });
    }
    if (perms.canCollect && inv.status === 'DELIVERED' && inv.payment_status !== 'PAID') {
        items.push({ action: 'invoice.collect', icon: 'fa-receipt text-success', label: 'Collect', primary: true });
    }

    // Secondary
    if (hasPermission('invoices.edit')) {
        items.push({ action: 'invoice.edit-number', icon: 'fa-edit text-info', label: 'Edit ID Number' });
    }
    if (perms.canDeliver && (inv.status === 'DRAFT' || inv.status === 'DELIVERED')) {
        items.push({ action: 'invoice.cancel', icon: 'fa-ban text-warning', label: 'Cancel' });
    }
    if (perms.canView) {
        items.push({ action: 'invoice.download-pdf', icon: 'fa-file-pdf text-danger', label: 'Download PDF' });
    }
    if (perms.canView && (inv.status === 'DELIVERED' || inv.status === 'PAID')) {
        items.push({ action: 'invoice.credit-note', icon: 'fa-undo text-warning', label: 'Credit Notes' });
    }
    if (perms.canCreate) {
        items.push({ action: 'invoice.duplicate', icon: 'fa-copy text-secondary', label: 'Duplicate' });
    }
    if (perms.canDelete && inv.status === 'DRAFT') {
        items.push({ action: 'invoice.delete', icon: 'fa-trash text-danger', label: 'Delete', divider: true });
    }

    return items;
}

function renderActions(items) {
    if (items.length === 0) return '';

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
