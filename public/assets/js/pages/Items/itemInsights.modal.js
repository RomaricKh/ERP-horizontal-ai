// assets/js/pages/Items/itemInsights.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let currentItemId;

const F = {
    itemName: () => modalRoot.querySelector('#insightsItemName'),
    itemSku: () => modalRoot.querySelector('#insightsItemSku'),
    
    startDate: () => modalRoot.querySelector('#insightsStartDate'),
    endDate: () => modalRoot.querySelector('#insightsEndDate'),
    btnFilter: () => modalRoot.querySelector('#btnInsightsFilter'),

    totalSold: () => modalRoot.querySelector('#insightsTotalSold'),
    totalRevenue: () => modalRoot.querySelector('#insightsTotalRevenue'),
    avgPrice: () => modalRoot.querySelector('#insightsAvgPrice'),
    
    totalBought: () => modalRoot.querySelector('#insightsTotalBought'),
    totalCost: () => modalRoot.querySelector('#insightsTotalCost'),
    avgBuyingPrice: () => modalRoot.querySelector('#insightsAvgBuyingPrice'),
    lastBoughtDate: () => modalRoot.querySelector('#insightsLastBoughtDate'),

    topCustomersTbody: () => modalRoot.querySelector('#insightsTopCustomersTbody'),
};

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    currentItemId = data.item_id;
    const { item_name, sku } = data;
    
    F.itemName().textContent = item_name || 'Unknown Item';
    F.itemSku().textContent = sku ? `SKU: ${sku}` : 'No SKU';

    // Reset date fields
    F.startDate().value = '';
    F.endDate().value = '';

    F.btnFilter().addEventListener('click', () => {
        fetchInsights();
    });

    await fetchInsights();
}

export function unmount() {
    modalRoot = null;
    currentItemId = null;
}

async function fetchInsights() {
    if (!currentItemId) return;

    try {
        let url = `/api/items/${currentItemId}/insights`;
        const params = new URLSearchParams();
        const start = F.startDate().value;
        const end = F.endDate().value;

        if (start) params.append('start_date', start);
        if (end) params.append('end_date', end);

        const qs = params.toString();
        if (qs) {
            url += '?' + qs;
        }

        const res = await apiFetch(url);
        const json = await res.json();
        
        if (!json.success || !json.data?.insights) {
            console.error('Failed to load insights:', json.message);
            return;
        }

        const insights = json.data.insights;
        const f = getFormatter();

        F.totalSold().textContent = f.formatNumber(insights.total_sold, 3);
        F.totalRevenue().textContent = f.formatNumber(insights.total_revenue, 2);
        F.avgPrice().textContent = f.formatNumber(insights.average_price, 2);
        
        F.totalBought().textContent = f.formatNumber(insights.total_bought, 3);
        F.totalCost().textContent = f.formatNumber(insights.total_cost, 2);
        F.avgBuyingPrice().textContent = f.formatNumber(insights.average_buying_price, 2);
        F.lastBoughtDate().textContent = insights.last_bought_date ? f.formatDate(insights.last_bought_date) : '--';

        const tbody = F.topCustomersTbody();
        tbody.innerHTML = '';

        if (insights.top_customers && insights.top_customers.length > 0) {
            insights.top_customers.forEach(cust => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="text-start text-truncate" style="max-width: 120px;" title="${escHtml(cust.customer_name)}">
                        ${escHtml(cust.customer_name)}
                    </td>
                    <td><span class="badge bg-secondary">${f.formatNumber(cust.quantity, 3)}</span></td>
                    <td class="text-end fw-semibold">${f.formatNumber(cust.revenue, 2)}</td>
                `;
                tbody.appendChild(tr);
            });
        } else {
            tbody.innerHTML = '<tr><td colspan="3" class="text-muted py-3 text-center">No sales data found</td></tr>';
        }

    } catch (err) {
        console.error('Fetch insights failed:', err);
    }
}
