// assets/js/pages/Items/productionOrders.js

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

const STATUS_BADGE = {
    COMPLETED: 'success',
    REVERSED:  'secondary',
};

let container;
let unregister;
let updatedHandler;
let dataTable = null;

export async function mount() {
    await fetchOrders();

    updatedHandler = async () => { await fetchOrders(); };
    document.addEventListener('items.updated', updatedHandler);

    unregister = registerPageActions({

        'production.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Items/productionOrderForm.html',
                controller: '../pages/Items/productionOrderForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'production.reverse': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Reverse Production Order',
                message: 'Reverse this production order? Stock will be restored to pre-production levels.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/manufacturing/orders/${id}/reverse`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to reverse production order' });
                } else {
                    await fetchOrders();
                }
            } catch (err) {
                console.error('Reverse production order failed:', err);
            }
        },
    });
}

export function unmount() {
    if (updatedHandler) {
        document.removeEventListener('items.updated', updatedHandler);
        updatedHandler = null;
    }
    unregister?.();
    unregister = null;
    dataTable?.destroy();
    dataTable = null;
    closeModalsInContainer(container);
}

export async function render(cont) {
    container = cont;
    const html = await fetch('html/Items/productionOrders.html').then(r => r.text());
    container.innerHTML = html;

    // Hide create button if no permission
    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'production.create' && !hasPermission('manufacturing.create')) {
            el.remove();
        }
    });
}

async function fetchOrders() {
    const res  = await apiFetch('/api/manufacturing/orders');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.orders ?? []);
}

function renderTable(orders) {
    if (!dataTable) {
        dataTable = createDataTable('#productionOrdersTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 7, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    dataTable.clear();

    const canReverse = hasPermission('manufacturing.create');

    orders.forEach(order => {
        const badgeClass = STATUS_BADGE[order.status] || 'secondary';
        const statusBadge = `<span class="badge bg-${escHtml(badgeClass)}">${escHtml(order.status)}</span>`;

        const totalCost = order.manufacturing_cost != null ? getFormatter().formatNumber(order.manufacturing_cost, 2) : '0.00';
        const unitCost = order.unit_manufacturing_cost != null ? getFormatter().formatNumber(order.unit_manufacturing_cost, 2) : '0.00';
        const qty = order.production_quantity != null ? getFormatter().formatNumber(order.production_quantity, 2) : '0';

        let actions = '';

        if (canReverse && order.status === 'COMPLETED') {
            actions += `<button data-action="production.reverse" class="btn btn-warning btn-circle btn-sm me-1" title="Reverse">
                            <i class="fas fa-undo"></i></button>`;
        }

        const prodDate = order.production_date ? getFormatter().formatDate(order.production_date) : '—';

        dataTable.row.add([
            order.id,
            prodDate,
            escHtml(order.manufactured_item_name ?? '—'),
            qty,
            totalCost,
            unitCost,
            statusBadge,
            actions
        ]);
    });

    dataTable.draw(false);
    dataTable.columns.adjust();
}
