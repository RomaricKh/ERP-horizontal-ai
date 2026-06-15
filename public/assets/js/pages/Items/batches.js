// assets/js/pages/Items/batches.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let batchesTable = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const nameEl = modalRoot.querySelector('#batchesItemName');
    if (nameEl) nameEl.textContent = data.item_name || '—';

    await loadBatches(data.item_id);
}

export function unmount() {
    batchesTable?.destroy();
    batchesTable = null;
    modalRoot = null;
}

async function loadBatches(itemId) {
    try {
        const res = await apiFetch(`/api/batches/${itemId}`);
        const json = await res.json();
        if (!json.success) return;
        const records = json.data?.history || json.data || [];
        renderTable(records);
    } catch (err) {
        console.error('loadBatches failed:', err);
    }
}

function renderTable(records) {
    if (!batchesTable) {
        batchesTable = createDataTable('#batchesTable', {
            columnDefs: [
                { targets: '_all', className: 'text-nowrap' },
            ],
            order: [[4, 'desc']]
        });
    }

    batchesTable.clear();

    const fmt = getFormatter();

    records.forEach(record => {
        const statusBadge = record.type === 'IN'
            ? '<span class="badge bg-success">IN</span>'
            : '<span class="badge bg-danger">OUT</span>';

        batchesTable.row.add([
            record.batch_number || record.reference_type || '—',
            record.quantity != null ? fmt.formatNumber(record.quantity, 3) : '—',
            record.expiry_date ? fmt.formatDate(record.expiry_date) : '—',
            statusBadge,
            record.created_at ? fmt.formatDate(record.created_at) : '—'
        ]);
    });

    batchesTable.draw(false);
    batchesTable.columns.adjust();
}
