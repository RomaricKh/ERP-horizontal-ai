// assets/js/pages/People/insights.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let insightsTable = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const { payee_id, insight_type, person_name } = data;

    // Set title
    const titleEl = modalRoot.querySelector('#insightsModalTitle');
    const typeLabels = { supplier: 'Purchase Insights', customer: 'Sales Insights', general: 'Usage Insights' };
    if (titleEl) titleEl.textContent = `${typeLabels[insight_type] || 'Insights'} — ${person_name || ''}`;

    await loadInsights(payee_id, insight_type);
}

export function unmount() {
    insightsTable?.destroy();
    insightsTable = null;
    modalRoot = null;
}

async function loadInsights(payeeId, type) {
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
            renderGeneralInsights(json.data, content, empty);
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
                renderItemsTable(items, type);
            }
        }
    } catch (err) {
        console.error('loadInsights failed:', err);
        loading.classList.add('d-none');
        error.classList.remove('d-none');
    }
}

function renderItemsTable(items, type) {
    const thead = modalRoot.querySelector('#insightsTableHead');
    const isSupplier = type === 'supplier';

    thead.innerHTML = `<tr>
        <th>Item Name</th>
        <th class="text-end">Total Qty ${isSupplier ? 'Purchased' : 'Sold'}</th>
        <th class="text-end">Avg Unit Price</th>
        <th class="text-end">Last Price</th>
        <th>Last Date</th>
        <th class="text-end"># of ${isSupplier ? 'POs' : 'Invoices'}</th>
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

function renderGeneralInsights(data, content, empty) {
    const generalContainer = modalRoot.querySelector('#insightsGeneralContainer');
    const tableContainer = modalRoot.querySelector('#insightsTableContainer');

    const expenses = data?.expenses || { count: 0, total: 0, categories: [] };
    const salaries = data?.salaries || { count: 0, gross_total: 0, net_total: 0 };
    const payments = data?.payments || { count: 0, total: 0 };
    const collections = data?.collections || { count: 0, total: 0 };

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
            <div class="col-md-6">
                <div class="card border-left-info h-100">
                    <div class="card-body">
                        <h6 class="text-info fw-bold"><i class="fas fa-user-tie me-1"></i>Salaries</h6>
                        <p class="mb-1">Count: <strong>${salaries.count}</strong></p>
                        <p class="mb-1">Gross Total: <strong>${fmt.formatNumber(salaries.gross_total, 2)}</strong></p>
                        <p class="mb-0">Net Total: <strong>${fmt.formatNumber(salaries.net_total, 2)}</strong></p>
                    </div>
                </div>
            </div>
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

function escHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
