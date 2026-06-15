// assets/js/pages/Items/stockView.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal } from '../../components/modals.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { hasPermission } from '../../Auth/auth.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let stockTable = null;
let stockUpdatedHandler;

export async function mount({ modalRoot: root }) {
    modalRoot = root;
    await loadStock();

    stockUpdatedHandler = async () => { await loadStock(); };
    document.addEventListener('items.updated', stockUpdatedHandler);

    unregister = registerPageActions({
        'stock.adjust-from-view': async ({ el }) => {
            const itemId   = parseInt(el.dataset.itemId, 10);
            const itemName = el.dataset.itemName;
            const qty      = el.dataset.qty;

            await openModal({
                scope:      MODAL_SCOPE.GLOBAL,
                template:   'html/Items/stockAdjust.html',
                controller: '../pages/Items/stockAdjust.modal.js',
                data:       { item_id: itemId, item_name: itemName, current_qty: qty },
                options:    { backdrop: false, keyboard: true, focus: true }
            });
        }
    });
}

export function unmount() {
    if (stockUpdatedHandler) {
        document.removeEventListener('items.updated', stockUpdatedHandler);
        stockUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    stockTable?.destroy();
    stockTable = null;
}

async function loadStock() {
    try {
        const res  = await apiFetch('/api/items/stock');
        const data = await res.json();
        if (!data.success) return;
        renderTable(data.data.stock);
    } catch (err) {
        console.error('loadStock failed:', err);
    }
}

function renderTable(stock) {
    if (!stockTable) {
        stockTable = createDataTable('#stockTable', {
            columnDefs: [
                { targets: '_all', className: 'text-nowrap' },
                { targets: 6, className: 'text-center', orderable: false, searchable: false }
            ],
            scrollY: '45vh'
        });
    }

    stockTable.clear();

    const canAdjust = hasPermission('stock.adjust');

    stock.forEach(s => {
        const qty = parseFloat(s.quantity ?? 0);
        const qtyBadge = qty <= 0
            ? `<span class="badge badge-danger text-white">${getFormatter().formatNumber(qty, 3)}</span>`
            : `<span class="badge badge-success text-white">${getFormatter().formatNumber(qty, 3)}</span>`;

        let actions = '';
        if (canAdjust) {
            actions = `<button class="btn btn-success btn-circle btn-sm"
                               data-action="stock.adjust-from-view"
                               data-item-id="${s.item_id}"
                               data-item-name="${escHtml(s.item_name)}"
                               data-qty="${qty}"
                               title="Adjust stock">
                           <i class="fas fa-boxes"></i>
                       </button>`;
        }

        stockTable.row.add([
            s.item_name,
            s.sku  || '—',
            s.type_name,
            s.unit || '—',
            qtyBadge,
            s.updated_at ? s.updated_at.substring(0, 16) : '—',
            actions
        ]);
    });

    stockTable.draw(false);
    stockTable.columns.adjust();
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
