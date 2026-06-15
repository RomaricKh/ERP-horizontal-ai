// assets/js/pages/Items/priceHistory.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let priceHistoryTable = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const nameEl = modalRoot.querySelector('#priceHistoryItemName');
    if (nameEl) nameEl.textContent = data.item_name || '—';

    await loadPriceHistory(data.item_id);
}

export function unmount() {
    priceHistoryTable?.destroy();
    priceHistoryTable = null;
    modalRoot = null;
}

async function loadPriceHistory(itemId) {
    try {
        const res = await apiFetch(`/api/items/${itemId}/price-history`);
        const json = await res.json();
        if (!json.success) return;
        const records = json.data || [];
        renderTable(records);
    } catch (err) {
        console.error('loadPriceHistory failed:', err);
    }
}

function renderTable(records) {
    if (!priceHistoryTable) {
        priceHistoryTable = createDataTable('#priceHistoryTable', {
            columnDefs: [
                { targets: [2, 3, 4, 5], className: 'text-end' },
                { targets: '_all', className: 'text-nowrap' },
            ],
            order: [[0, 'desc']]
        });
    }

    priceHistoryTable.clear();

    records.forEach(record => {
        const date = record.effective_date || '—';
        const priceType = record.price_type || '—';

        // Show old/new cost and sale price in the appropriate columns
        let oldCost = '—';
        let newCost = '—';
        let oldSale = '—';
        let newSale = '—';

        if (priceType === 'COST') {
            oldCost = record.old_price != null ? getFormatter().formatNumber(parseFloat(record.old_price), 4) : '—';
            newCost = record.new_price != null ? getFormatter().formatNumber(parseFloat(record.new_price), 4) : '—';
        } else if (priceType === 'SALE') {
            oldSale = record.old_price != null ? getFormatter().formatNumber(parseFloat(record.old_price), 4) : '—';
            newSale = record.new_price != null ? getFormatter().formatNumber(parseFloat(record.new_price), 4) : '—';
        }

        // Source type badge
        const sourceLabel = formatSourceType(record.source_type, record.source_id);

        // Changed by user name
        const changedBy = record.changed_by_name || '—';

        priceHistoryTable.row.add([
            date,
            formatPriceTypeBadge(priceType),
            oldCost,
            newCost,
            oldSale,
            newSale,
            sourceLabel,
            escHtml(changedBy)
        ]);
    });

    priceHistoryTable.draw(false);
    priceHistoryTable.columns.adjust();
}

function formatPriceTypeBadge(type) {
    if (type === 'COST') {
        return '<span class="badge bg-primary">Cost</span>';
    } else if (type === 'SALE') {
        return '<span class="badge bg-success">Sale</span>';
    }
    return escHtml(type);
}

function formatSourceType(sourceType, sourceId) {
    const labels = {
        'INITIAL': 'Initial',
        'PURCHASE_ORDER': 'Purchase Order',
        'MANUAL_EDIT': 'Manual Edit',
        'MANUFACTURING': 'Manufacturing'
    };
    let label = labels[sourceType] || sourceType || '—';
    if (sourceType === 'PURCHASE_ORDER' && sourceId) {
        label += ` #${sourceId}`;
    }
    return escHtml(label);
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
