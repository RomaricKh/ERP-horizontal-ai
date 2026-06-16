// assets/js/components/supplierPricesPanel.js
// Renders supplier/purchase price history for an item inside a given container element.

import { apiFetch } from '../Auth/apiFetch.js';
import { getFormatter } from '../i18n/translationClient.js';

/**
 * Render supplier prices for an item inside the specified container.
 * Shows a table of historical cost prices from the price history table
 * (populated when POs are received and price changes).
 *
 * @param {string} containerId - The DOM element ID to render into
 * @param {number|string} itemId - The item ID to fetch supplier prices for
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
        const res = await apiFetch(`/api/items/${itemId}/price-history`);
        const json = await res.json();

        if (!json.success) {
            container.innerHTML = `<p class="text-muted small p-3 mb-0">No price history available.</p>`;
            return;
        }

        // Response shape: { success: true, data: [ {price_type, old_price, new_price, effective_date, source_type, source_id, ...} ] }
        const records = Array.isArray(json.data) ? json.data : (json.data?.records || json.data?.history || []);

        if (!Array.isArray(records) || records.length === 0) {
            container.innerHTML = `<p class="text-muted small p-3 mb-0">No price history found for this item.</p>`;
            return;
        }

        renderTable(container, records);
    } catch (err) {
        console.error('renderSupplierPricesPanel failed:', err);
        container.innerHTML = `<p class="text-danger small p-3 mb-0">Failed to load price history.</p>`;
    }
}

function renderTable(container, records) {
    const fmt = getFormatter();

    // Filter to COST price type changes and show most recent first (up to 10)
    const costRecords = records
        .filter(r => r.price_type === 'COST')
        .slice(0, 10);

    if (costRecords.length === 0) {
        container.innerHTML = `<p class="text-muted small p-3 mb-0">No cost price changes recorded.</p>`;
        return;
    }

    const rows = costRecords.map(r => {
        const oldPrice = r.old_price != null ? fmt.formatNumber(r.old_price, 4) : '—';
        const newPrice = r.new_price != null ? fmt.formatNumber(r.new_price, 4) : '—';
        const date = r.effective_date ? fmt.formatDate(r.effective_date) : '—';
        const source = r.source_type || '—';
        const sourceLabel = source === 'PURCHASE_ORDER' ? `PO (${r.source_id || '?'})`
            : source === 'MANUAL_EDIT' ? 'Manual'
            : source === 'MANUFACTURING' ? 'Manufacturing'
            : source === 'INITIAL' ? 'Initial'
            : source;

        return `
            <tr>
                <td><span class="badge bg-light text-dark">${escHtml(sourceLabel)}</span></td>
                <td class="text-end text-muted"><s>${oldPrice}</s></td>
                <td class="text-end fw-semibold">${newPrice}</td>
                <td>${date}</td>
            </tr>
        `;
    }).join('');

    container.innerHTML = `
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Source</th>
                        <th class="text-end">Old Cost</th>
                        <th class="text-end">New Cost</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
    `;
}

function escHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
