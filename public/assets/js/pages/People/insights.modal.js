// assets/js/pages/People/insights.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let insightsTable = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const { payee_id, insight_type, person_name, payee_types } = data;

    // Set title with appropriate icon
    const titleEl = modalRoot.querySelector('#insightsModalTitle');
    const typeConfig = {
        supplier: { label: 'Purchase Insights', icon: 'fa-shopping-cart' },
        customer: { label: 'Sales Insights', icon: 'fa-chart-line' },
        partner:  { label: 'Partner Insights', icon: 'fa-handshake' },
        general:  { label: 'Usage Insights', icon: 'fa-chart-pie' }
    };
    const cfg = typeConfig[insight_type] || typeConfig.general;
    if (titleEl) titleEl.innerHTML = `<i class="fas ${cfg.icon} me-2"></i>${cfg.label} — ${escHtml(person_name || '')}`;

    await loadInsights(payee_id, insight_type, payee_types);
}

export function unmount() {
    insightsTable?.destroy();
    insightsTable = null;
    modalRoot = null;
}

async function loadInsights(payeeId, type, payeeTypes) {
    const loading = modalRoot.querySelector('#insightsLoading');
    const content = modalRoot.querySelector('#insightsContent');
    const empty = modalRoot.querySelector('#insightsEmpty');
    const error = modalRoot.querySelector('#insightsError');

    try {
        const res = await apiFetch(`/api/people/${payeeId}/insights/${type}`);
        const json = await res.json();

        loading.classList.add('d-none');

        if (!json.success) {
            error.classList.remove('d-none');
            const errMsg = modalRoot.querySelector('#insightsErrorMessage');
            if (errMsg) errMsg.textContent = json.message || 'Failed to load insights.';
            return;
        }

        if (type === 'general') {
            renderGeneralInsights(json.data, content, empty, payeeTypes);
        } else if (type === 'partner') {
            renderPartnerInsights(json.data, content, empty);
        } else {
            const items = json.data?.items || [];
            if (items.length === 0) {
                empty.classList.remove('d-none');
                const emptyMsg = modalRoot.querySelector('#insightsEmptyMessage');
                if (emptyMsg) emptyMsg.textContent = type === 'supplier'
                    ? 'No purchase records found for this supplier.'
                    : 'No sales records found for this customer.';
            } else {
                content.classList.remove('d-none');
                // Show summary row
                renderSummaryRow(items, type);
                renderItemsTable(items, type);
            }
        }
    } catch (err) {
        console.error('loadInsights failed:', err);
        loading.classList.add('d-none');
        error.classList.remove('d-none');
        const errMsg = modalRoot.querySelector('#insightsErrorMessage');
        if (errMsg) errMsg.textContent = err.message || 'Failed to load insights.';
    }
}

function renderSummaryRow(items, type) {
    const summaryRow = modalRoot.querySelector('#insightsSummaryRow');
    if (!summaryRow) return;

    const fmt = getFormatter();
    const totalQty = items.reduce((sum, i) => sum + parseFloat(i.total_quantity || 0), 0);
    const totalDocs = items.reduce((sum, i) => sum + parseInt(i.document_count || 0), 0);
    const isSupplier = type === 'supplier';

    summaryRow.classList.remove('d-none');
    summaryRow.innerHTML = `
        <div class="d-flex flex-wrap gap-4">
            <div class="text-center">
                <div class="h5 mb-0 fw-bold text-primary">${items.length}</div>
                <small class="text-muted">Unique Items</small>
            </div>
            <div class="text-center">
                <div class="h5 mb-0 fw-bold text-info">${fmt.formatNumber(totalQty, 2)}</div>
                <small class="text-muted">Total Qty ${isSupplier ? 'Purchased' : 'Sold'}</small>
            </div>
            <div class="text-center">
                <div class="h5 mb-0 fw-bold text-success">${totalDocs}</div>
                <small class="text-muted">${isSupplier ? 'Purchase Orders' : 'Invoices'}</small>
            </div>
        </div>
    `;
}

function renderItemsTable(items, type) {
    const thead = modalRoot.querySelector('#insightsTableHead');
    const isSupplier = type === 'supplier';

    thead.innerHTML = `<tr>
        <th>Item / Service</th>
        <th class="text-end">Total Qty</th>
        <th class="text-end">Avg Price</th>
        <th class="text-end">Last Price</th>
        <th>Last Date</th>
        <th class="text-end"># ${isSupplier ? 'POs' : 'Invoices'}</th>
    </tr>`;

    if (!insightsTable) {
        insightsTable = createDataTable('#insightsTable', {
            columnDefs: [
                { targets: [1, 2, 3, 5], className: 'text-end' },
                { targets: '_all', className: 'text-nowrap' }
            ],
            order: [[1, 'desc']]
        });
    }

    insightsTable.clear();
    const fmt = getFormatter();

    items.forEach(item => {
        insightsTable.row.add([
            escHtml(item.item_name || '—'),
            fmt.formatNumber(item.total_quantity || 0, 2),
            fmt.formatNumber(item.avg_unit_price || 0, 2),
            fmt.formatNumber(item.last_price || 0, 2),
            item.last_date ? fmt.formatDate(item.last_date) : '—',
            String(item.document_count || 0)
        ]);
    });

    insightsTable.draw(false);
    insightsTable.columns.adjust();
}

function renderGeneralInsights(data, content, empty, payeeTypes) {
    const generalContainer = modalRoot.querySelector('#insightsGeneralContainer');
    const tableContainer = modalRoot.querySelector('#insightsTableContainer');

    const expenses = data?.expenses || { count: 0, total: 0, categories: [] };
    const salaries = data?.salaries || { count: 0, gross_total: 0, net_total: 0 };
    const payments = data?.payments || { count: 0, total: 0 };
    const collections = data?.collections || { count: 0, total: 0 };

    // Determine if person is an employee (show salary section only for employees)
    const types = (payeeTypes || '').toLowerCase();
    const isEmployee = types.includes('employee');

    const allZero = expenses.count === 0 && salaries.count === 0 && payments.count === 0 && collections.count === 0;
    if (allZero) {
        empty.classList.remove('d-none');
        const emptyMsg = modalRoot.querySelector('#insightsEmptyMessage');
        if (emptyMsg) emptyMsg.textContent = 'No transaction records found for this person.';
        return;
    }

    tableContainer.classList.add('d-none');
    generalContainer.classList.remove('d-none');
    content.classList.remove('d-none');

    const fmt = getFormatter();

    let categoriesHtml = '';
    if (expenses.categories && expenses.categories.length > 0) {
        categoriesHtml = `<table class="table table-sm table-bordered mt-2">
            <thead><tr><th>Category</th><th class="text-end">Count</th><th class="text-end">Total</th></tr></thead>
            <tbody>${expenses.categories.map(c => `<tr><td>${escHtml(c.name)}</td><td class="text-end">${c.count}</td><td class="text-end">${fmt.formatNumber(c.total, 2)}</td></tr>`).join('')}</tbody>
        </table>`;
    }

    // Build salary card only for employees
    const salaryCard = isEmployee ? `
        <div class="col-md-6">
            <div class="card border-left-info h-100">
                <div class="card-body">
                    <h6 class="text-info fw-bold"><i class="fas fa-user-tie me-1"></i>Salaries</h6>
                    <p class="mb-1">Count: <strong>${salaries.count}</strong></p>
                    <p class="mb-1">Gross Total: <strong>${fmt.formatNumber(salaries.gross_total, 2)}</strong></p>
                    <p class="mb-0">Net Total: <strong>${fmt.formatNumber(salaries.net_total, 2)}</strong></p>
                </div>
            </div>
        </div>` : '';

    generalContainer.innerHTML = `
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card border-left-warning h-100">
                    <div class="card-body">
                        <h6 class="text-warning fw-bold"><i class="fas fa-receipt me-1"></i>Expenses</h6>
                        <p class="mb-1">Count: <strong>${expenses.count}</strong></p>
                        <p class="mb-0">Total: <strong>${fmt.formatNumber(expenses.total, 2)}</strong></p>
                        ${categoriesHtml}
                    </div>
                </div>
            </div>
            ${salaryCard}
            <div class="col-md-6">
                <div class="card border-left-danger h-100">
                    <div class="card-body">
                        <h6 class="text-danger fw-bold"><i class="fas fa-money-bill-wave me-1"></i>Payments</h6>
                        <p class="mb-1">Count: <strong>${payments.count}</strong></p>
                        <p class="mb-0">Total: <strong>${fmt.formatNumber(payments.total, 2)}</strong></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-left-success h-100">
                    <div class="card-body">
                        <h6 class="text-success fw-bold"><i class="fas fa-hand-holding-usd me-1"></i>Collections</h6>
                        <p class="mb-1">Count: <strong>${collections.count}</strong></p>
                        <p class="mb-0">Total: <strong>${fmt.formatNumber(collections.total, 2)}</strong></p>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function renderPartnerInsights(data, content, empty) {
    const generalContainer = modalRoot.querySelector('#insightsGeneralContainer');
    const tableContainer = modalRoot.querySelector('#insightsTableContainer');

    const shares = data?.shares || { ownership_pct: 0, invested_capital: 0 };
    const equity = data?.equity || { contributions: 0, withdrawals: 0, net: 0 };
    const distributions = data?.distributions || { count: 0, total: 0 };
    const payments = data?.payments || { count: 0, total: 0 };
    const collections = data?.collections || { count: 0, total: 0 };

    const allZero = shares.ownership_pct === 0 && equity.contributions === 0 && distributions.count === 0;
    if (allZero) {
        empty.classList.remove('d-none');
        const emptyMsg = modalRoot.querySelector('#insightsEmptyMessage');
        if (emptyMsg) emptyMsg.textContent = 'No partner records found for this person.';
        return;
    }

    tableContainer.classList.add('d-none');
    generalContainer.classList.remove('d-none');
    content.classList.remove('d-none');

    const fmt = getFormatter();

    generalContainer.innerHTML = `
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card border-left-primary h-100">
                    <div class="card-body">
                        <h6 class="text-primary fw-bold"><i class="fas fa-chart-pie me-1"></i>Ownership</h6>
                        <p class="mb-1">Share: <strong>${fmt.formatNumber(shares.ownership_pct, 2)}%</strong></p>
                        <p class="mb-0">Invested Capital: <strong>${fmt.formatNumber(shares.invested_capital, 2)}</strong></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-left-info h-100">
                    <div class="card-body">
                        <h6 class="text-info fw-bold"><i class="fas fa-exchange-alt me-1"></i>Equity Movements</h6>
                        <p class="mb-1">Contributions: <strong>${fmt.formatNumber(equity.contributions, 2)}</strong></p>
                        <p class="mb-1">Withdrawals: <strong>${fmt.formatNumber(equity.withdrawals, 2)}</strong></p>
                        <p class="mb-0">Net Equity: <strong>${fmt.formatNumber(equity.net, 2)}</strong></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-left-success h-100">
                    <div class="card-body">
                        <h6 class="text-success fw-bold"><i class="fas fa-coins me-1"></i>Profit Distributions</h6>
                        <p class="mb-1">Count: <strong>${distributions.count}</strong></p>
                        <p class="mb-0">Total Received: <strong>${fmt.formatNumber(distributions.total, 2)}</strong></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-left-warning h-100">
                    <div class="card-body">
                        <h6 class="text-warning fw-bold"><i class="fas fa-money-bill-wave me-1"></i>Cash Movements</h6>
                        <p class="mb-1">Payments: <strong>${payments.count}</strong> (${fmt.formatNumber(payments.total, 2)})</p>
                        <p class="mb-0">Collections: <strong>${collections.count}</strong> (${fmt.formatNumber(collections.total, 2)})</p>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function escHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
