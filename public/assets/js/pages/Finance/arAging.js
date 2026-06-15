// assets/js/pages/Finance/arAging.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { getFormatter } from '../../i18n/translationClient.js';

let arAging_container;
let agingDataTable = null;

export async function mount() {
    await fetchAgingReport();
}

export function unmount() {
    agingDataTable?.destroy();
    agingDataTable = null;
}

export async function render(container) {
    arAging_container = container;
    const html = await fetch('html/Finance/arAging.html').then(r => r.text());
    container.innerHTML = html;
}

async function fetchAgingReport() {
    try {
        const res  = await apiFetch('/api/sales-invoices/aging');
        const json = await res.json();
        if (!json.success) return;

        const aging = json.data?.aging ?? {};
        renderBucketCards(aging.buckets ?? {});
        renderCustomerTable(aging.by_customer ?? []);
    } catch (err) {
        console.error('Failed to fetch aging report:', err);
    }
}

function renderBucketCards(buckets) {
    const fmt = getFormatter();
    document.getElementById('bucketCurrent').textContent = fmt.formatNumber(buckets.current?.total_bc ?? 0, 2);
    document.getElementById('bucket1_30').textContent    = fmt.formatNumber(buckets['1_30']?.total_bc ?? 0, 2);
    document.getElementById('bucket31_60').textContent   = fmt.formatNumber(buckets['31_60']?.total_bc ?? 0, 2);
    document.getElementById('bucket61_90').textContent   = fmt.formatNumber(buckets['61_90']?.total_bc ?? 0, 2);
    document.getElementById('bucket90_plus').textContent = fmt.formatNumber(buckets['90_plus']?.total_bc ?? 0, 2);
}

function renderCustomerTable(customers) {
    if (!agingDataTable) {
        agingDataTable = createDataTable('#arAgingTable', {
            columnDefs: [
                { targets: [1, 2, 3, 4, 5, 6], className: 'text-end' }
            ],
            order: [[6, 'desc']]
        });
    }

    agingDataTable.clear();
    const fmt = getFormatter();

    customers.forEach(cust => {
        agingDataTable.row.add([
            escHtml(cust.customer_name ?? 'Unknown'),
            fmt.formatNumber(cust.current ?? 0, 2),
            fmt.formatNumber(cust['1_30'] ?? 0, 2),
            fmt.formatNumber(cust['31_60'] ?? 0, 2),
            fmt.formatNumber(cust['61_90'] ?? 0, 2),
            fmt.formatNumber(cust['90_plus'] ?? 0, 2),
            fmt.formatNumber(cust.total ?? 0, 2)
        ]);
    });

    agingDataTable.draw(false);
    agingDataTable.columns.adjust();
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
