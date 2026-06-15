// assets/js/pages/People/customerItems.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let itemsTable = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    const { payee_id, person_name } = data;

    const titleEl = modalRoot.querySelector('#customerItemsTitle');
    if (titleEl) titleEl.innerHTML = `<i class="fas fa-boxes me-2"></i>Items & Stock — ${escHtml(person_name || '')}`;

    await loadCustomerItems(payee_id);
}

export function unmount() {
    itemsTable?.destroy();
    itemsTable = null;
    modalRoot = null;
}

async function loadCustomerItems(payeeId) {
    const loading = modalRoot.querySelector('#customerItemsLoading');
    const content = modalRoot.querySelector('#customerItemsContent');
    const empty = modalRoot.querySelector('#customerItemsEmpty');

    try {
        const res = await apiFetch(`/api/people/${payeeId}/customer-items`);
        const json = await res.json();

        loading.classList.add('d-none');

        if (!json.success || !json.data?.items?.length) {
            empty.classList.remove('d-none');
            return;
        }

        content.classList.remove('d-none');
        const items = json.data.items;

        // Render summary
        const summary = modalRoot.querySelector('#customerItemsSummary');
        if (summary) {
            const fmt = getFormatter();
            const totalStock = items.reduce((s, i) => s + parseFloat(i.stock_quantity || 0), 0);
            const totalBatches = items.reduce((s, i) => s + parseInt(i.batch_count || 0), 0);
            summary.innerHTML = `
                <div class="text-center">
                    <div class="h5 mb-0 fw-bold text-primary">${items.length}</div>
                    <small class="text-muted">Items</small>
                </div>
                <div class="text-center">
                    <div class="h5 mb-0 fw-bold text-info">${fmt.formatNumber(totalStock, 2)}</div>
                    <small class="text-muted">Total Stock</small>
                </div>
                <div class="text-center">
                    <div class="h5 mb-0 fw-bold text-warning">${totalBatches}</div>
                    <small class="text-muted">Active Batches</small>
                </div>
            `;
        }

        renderTable(items);
    } catch (err) {
        console.error('loadCustomerItems failed:', err);
        loading.classList.add('d-none');
        empty.classList.remove('d-none');
    }
}

function renderTable(items) {
    if (!itemsTable) {
        itemsTable = createDataTable('#customerItemsTable', {
            columnDefs: [
                { targets: [2, 3, 4, 5], className: 'text-end' },
                { targets: '_all', className: 'text-nowrap' }
            ],
            order: [[0, 'asc']]
        });
    }

    itemsTable.clear();
    const fmt = getFormatter();

    items.forEach(item => {
        const expiryDisplay = item.nearest_expiry
            ? fmt.formatDate(item.nearest_expiry)
            : '—';

        itemsTable.row.add([
            escHtml(item.name || '—'),
            escHtml(item.item_type || '—'),
            item.cost_price != null ? fmt.formatNumber(item.cost_price, 2) : '—',
            item.sale_price != null ? fmt.formatNumber(item.sale_price, 2) : '—',
            fmt.formatNumber(item.stock_quantity || 0, 2),
            String(item.batch_count || 0),
            expiryDisplay
        ]);
    });

    itemsTable.draw(false);
    itemsTable.columns.adjust();
}

function escHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
