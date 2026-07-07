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
        renderSummary(records);
        renderTable(records);
    } catch (err) {
        console.error('loadBatches failed:', err);
    }
}

function renderSummary(records) {
    const summary = modalRoot.querySelector('#batchesSummary');
    if (!summary) return;

    const fmt = getFormatter();
    const totalIn = records.filter(r => r.type === 'IN').reduce((s, r) => s + parseFloat(r.quantity || 0), 0);
    const totalOut = records.filter(r => r.type === 'OUT').reduce((s, r) => s + parseFloat(r.quantity || 0), 0);
    const activeCount = records.filter(r => r.type === 'IN').length;

    summary.innerHTML = `
        <div class="text-center">
            <div class="h5 mb-0 fw-bold text-success">${fmt.formatNumber(totalIn, 3)}</div>
            <small class="text-muted">Total IN</small>
        </div>
        <div class="text-center">
            <div class="h5 mb-0 fw-bold text-danger">${fmt.formatNumber(totalOut, 3)}</div>
            <small class="text-muted">Total OUT</small>
        </div>
        <div class="text-center">
            <div class="h5 mb-0 fw-bold text-primary">${activeCount}</div>
            <small class="text-muted">Transactions</small>
        </div>
    `;
}

function renderTable(records) {
    if (!batchesTable) {
        batchesTable = createDataTable('#batchesTable', {
            columnDefs: [
                { targets: 1, className: 'text-end' },
                {
                    targets: [2, 4],
                    render: function (data, type) {
                        if (type === 'sort' || type === 'type') {
                            return data ? data.sort : 0;
                        }
                        // For display, filter, etc.
                        return data ? data.display : '—';
                    }
                },
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
            : record.type === 'WRITE_OFF'
                ? '<span class="badge bg-dark">Write-Off</span>'
                : '<span class="badge bg-danger">OUT</span>';

        // Highlight expiry if within 30 days
        let expiryDisplay = '—';
        if (record.expiry_date) {
            const expiry = new Date(record.expiry_date);
            const now = new Date();
            const daysUntil = Math.floor((expiry - now) / (1000 * 60 * 60 * 24));
            if (daysUntil < 0) {
                expiryDisplay = `<span class="text-danger fw-bold">${fmt.formatDate(record.expiry_date)} (expired)</span>`;
            } else if (daysUntil <= 30) {
                expiryDisplay = `<span class="text-warning fw-bold">${fmt.formatDate(record.expiry_date)} (${daysUntil}d)</span>`;
            } else {
                expiryDisplay = fmt.formatDate(record.expiry_date);
            }
        }

        batchesTable.row.add([
            record.batch_number || record.reference_type || '—',
            record.quantity != null ? fmt.formatNumber(record.quantity, 3) : '—',
            {
                display: expiryDisplay,
                sort: record.expiry_date ? new Date(record.expiry_date).getTime() : 0
            },
            statusBadge,
            {
                display: record.created_at ? fmt.formatDate(record.created_at) : '—',
                sort: record.created_at ? new Date(record.created_at).getTime() : 0
            }
        ]);
    });

    batchesTable.draw(false);
    batchesTable.columns.adjust();
}
