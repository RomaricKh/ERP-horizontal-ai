// assets/js/components/supplierPricesPanel.js
// Renders supplier price data for an item inside a given container element.

import { apiFetch } from '../Auth/apiFetch.js';
import { getFormatter } from '../i18n/translationClient.js';

/**
 * Render supplier prices for an item inside the specified container.
 * Shows a table of supplier prices sourced from purchase order history.
 *
 * @param {string} containerId - The DOM element ID to render into
 * @param {number} itemId - The item ID to fetch supplier prices for
 */
export async function renderSupplierPricesPanel(containerId, itemId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `;

    try {
        const res = await apiFetch(`/api/items/${itemId}/stock/history`);
        const json = await res.json();

        if (!json.success) {
            container.innerHTML = `<p class="text-muted small">No supplier price data available.</p>`;
            return;
        }

        const history = json.data?.history || json.data || [];

        // Extract unique supplier prices from stock history (PO receipts)
        const supplierPrices = extractSupplierPrices(history);

        if (supplierPrices.length === 0) {
            container.innerHTML = `<p class="text-muted small">No supplier price history found for this item.</p>`;
            return;
        }

        renderTable(container, supplierPrices);
    } catch (err) {
        console.error('renderSupplierPricesPanel failed:', err);
        container.innerHTML = `<p class="text-danger small">Failed to load supplier prices.</p>`;
    }
}

function extractSupplierPrices(history) {
    // Filter to stock-in transactions from purchase orders
    const poRecords = history.filter(h =>
        h.type === 'IN' && h.reference_type === 'PURCHASE_ORDER'
    );

    // Group by supplier (reference_id) and find latest price per supplier
    const supplierMap = new Map();
    for (const record of poRecords) {
        const key = record.reference_id;
        if (!supplierMap.has(key) || record.created_at > supplierMap.get(key).created_at) {
            supplierMap.set(key, record);
        }
    }

    return Array.from(supplierMap.values());
}

function renderTable(container, prices) {
    const fmt = getFormatter();

    const rows = prices.map(p => `
        <tr>
            <td>${escHtml(p.supplier_name || `PO #${p.reference_id}`)}</td>
            <td class="text-end">${p.unit_cost != null ? fmt.formatNumber(p.unit_cost, 4) : '—'}</td>
            <td>${p.created_at ? fmt.formatDate(p.created_at) : '—'}</td>
            <td class="text-end">${p.quantity != null ? fmt.formatNumber(p.quantity, 3) : '—'}</td>
        </tr>
    `).join('');

    container.innerHTML = `
        <h6 class="text-primary fw-bold mb-2">
            <i class="fas fa-truck me-1"></i> Supplier Prices
        </h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Supplier / Source</th>
                        <th class="text-end">Unit Cost</th>
                        <th>Date</th>
                        <th class="text-end">Qty</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
    `;
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
