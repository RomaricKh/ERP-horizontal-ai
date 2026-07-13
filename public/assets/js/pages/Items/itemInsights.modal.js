// assets/js/pages/Items/itemInsights.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter, t } from '../../i18n/translationClient.js';

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
        
        if (insights.is_manufactured) {
            modalRoot.querySelector('#insightsPurchaseTitle').textContent = t('items.production_summary') !== 'items.production_summary' ? t('items.production_summary') : 'Production Summary';
            modalRoot.querySelector('#insightsTotalBoughtTitle').textContent = t('items.total_produced') !== 'items.total_produced' ? t('items.total_produced') : 'Total Produced';
            modalRoot.querySelector('#insightsAvgBuyingPriceTitle').textContent = t('items.avg_production_cost') !== 'items.avg_production_cost' ? t('items.avg_production_cost') : 'Avg. Production Cost';
            modalRoot.querySelector('#insightsLastBoughtDateTitle').textContent = t('items.last_produced_date') !== 'items.last_produced_date' ? t('items.last_produced_date') : 'Last Produced Date';

            F.totalBought().textContent = f.formatNumber(insights.total_produced, 3);
            F.totalCost().textContent = f.formatNumber(insights.total_production_cost, 2);
            F.avgBuyingPrice().textContent = f.formatNumber(insights.average_production_cost, 2);
            F.lastBoughtDate().textContent = insights.last_produced_date ? f.formatDate(insights.last_produced_date) : '--';
            
            const icon = modalRoot.querySelector('#insightsPurchaseIcon');
            if (icon) {
                icon.className = 'fas fa-industry me-1';
            }
        } else {
            modalRoot.querySelector('#insightsPurchaseTitle').textContent = t('items.purchase_summary') !== 'items.purchase_summary' ? t('items.purchase_summary') : 'Purchase Summary';
            modalRoot.querySelector('#insightsTotalBoughtTitle').textContent = t('items.total_bought') !== 'items.total_bought' ? t('items.total_bought') : 'Total Bought';
            modalRoot.querySelector('#insightsAvgBuyingPriceTitle').textContent = t('items.avg_buying_price') !== 'items.avg_buying_price' ? t('items.avg_buying_price') : 'Avg. Buying Price';
            modalRoot.querySelector('#insightsLastBoughtDateTitle').textContent = t('items.last_bought_date') !== 'items.last_bought_date' ? t('items.last_bought_date') : 'Last Bought Date';

            F.totalBought().textContent = f.formatNumber(insights.total_bought, 3);
            F.totalCost().textContent = f.formatNumber(insights.total_cost, 2);
            F.avgBuyingPrice().textContent = f.formatNumber(insights.average_buying_price, 2);
            F.lastBoughtDate().textContent = insights.last_bought_date ? f.formatDate(insights.last_bought_date) : '--';
            
            const icon = modalRoot.querySelector('#insightsPurchaseIcon');
            if (icon) {
                icon.className = 'fas fa-shopping-cart me-1';
            }
        }

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
