// assets/js/pages/People/statement.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { getFormatter } from '../../i18n/translationClient.js';

let statementContainer;
let statementTable = null;
let payeeId = null;

export async function render(container) {
    statementContainer = container;
    const html = await fetch('html/People/statement.html').then(r => r.text());
    container.innerHTML = html;

    // Extract payee_id from hash query string: #/people/statement?id=123
    payeeId = getPayeeIdFromHash();

    // Set default date range: first day of current year to today
    const today = new Date();
    const firstOfYear = new Date(today.getFullYear(), 0, 1);

    const startInput = container.querySelector('#statementStartDate');
    const endInput = container.querySelector('#statementEndDate');

    startInput.value = formatDateForInput(firstOfYear);
    endInput.value = formatDateForInput(today);
}

export async function mount() {
    if (!payeeId) {
        renderError('No payee ID found. Please navigate to this page from the People list.');
        return;
    }

    // Load button handler
    const loadBtn = statementContainer.querySelector('#statementLoadBtn');
    loadBtn?.addEventListener('click', handleLoad);

    // Export button handler
    const exportBtn = statementContainer.querySelector('#statementExportBtn');
    exportBtn?.addEventListener('click', handleExport);

    // Initial load
    await loadStatement();
}

export function unmount() {
    statementTable?.destroy();
    statementTable = null;
    payeeId = null;
    statementContainer = null;
}

/**
 * Parse the payee_id from the current hash.
 * Supports format: #/people/statement?id=123
 */
function getPayeeIdFromHash() {
    const hash = window.location.hash || '';
    const qIndex = hash.indexOf('?');
    if (qIndex === -1) return null;

    const queryString = hash.substring(qIndex + 1);
    const params = new URLSearchParams(queryString);
    const id = params.get('id');
    return id ? parseInt(id, 10) : null;
}

/**
 * Format a Date object as YYYY-MM-DD for input[type=date].
 */
function formatDateForInput(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/**
 * Load statement data from the API and render it.
 */
async function loadStatement() {
    const startDate = statementContainer.querySelector('#statementStartDate').value;
    const endDate = statementContainer.querySelector('#statementEndDate').value;

    if (!startDate || !endDate || !payeeId) {
        renderEmpty('Please select a date range.');
        return;
    }

    const params = new URLSearchParams();
    params.set('start_date', startDate);
    params.set('end_date', endDate);

    try {
        const res = await apiFetch(`/api/people/${payeeId}/statement?${params.toString()}`);
        const json = await res.json();

        if (!res.ok || !json.success) {
            renderEmpty(json.message || 'Failed to load statement');
            return;
        }

        const data = json.data;
        renderBalances(data.opening_balance, data.closing_balance, data.transactions || []);
        renderTransactions(data.transactions || []);

        // Display payee name if available
        if (data.payee_name) {
            const nameEl = statementContainer.querySelector('#statementPayeeName');
            if (nameEl) nameEl.textContent = `— ${data.payee_name}`;
        }
    } catch (err) {
        console.error('Failed to load statement:', err);
        renderEmpty('An error occurred while loading the statement.');
    }
}

/**
 * Render balance summary cards.
 */
function renderBalances(openingBalance, closingBalance, transactions) {
    const fmt = getFormatter();
    const openingEl = statementContainer.querySelector('#statementOpeningBalance');
    const closingEl = statementContainer.querySelector('#statementClosingBalance');
    const debitsEl = statementContainer.querySelector('#statementTotalDebits');

    if (openingEl) {
        const val = parseFloat(openingBalance) || 0;
        openingEl.textContent = fmt.formatNumber(val, 2);
        openingEl.className = `h5 mb-0 font-weight-bold ${val < 0 ? 'text-danger' : 'text-gray-800'}`;
    }

    if (closingEl) {
        const val = parseFloat(closingBalance) || 0;
        closingEl.textContent = fmt.formatNumber(val, 2);
        closingEl.className = `h5 mb-0 font-weight-bold ${val < 0 ? 'text-danger' : 'text-gray-800'}`;
    }

    // Calculate total debits from transactions
    if (debitsEl) {
        let totalDebits = 0;
        transactions.forEach(txn => {
            totalDebits += parseFloat(txn.debit_amount) || 0;
        });
        debitsEl.textContent = fmt.formatNumber(totalDebits, 2);
    }
}

/**
 * Render the transactions table using DataTables.
 */
function renderTransactions(transactions) {
    const fmt = getFormatter();

    if (!statementTable) {
        statementTable = createDataTable('#statementTable', {
            columnDefs: [
                { targets: '_all', className: 'text-nowrap' },
                { targets: [4, 5, 6], className: 'text-end' },
                { targets: 7, className: 'text-center' }
            ],
            ordering: false,
            paging: false,
            searching: false,
            info: false,
            lengthChange: false
        });
    }

    statementTable.clear();

    transactions.forEach(txn => {
        const debit = parseFloat(txn.debit_amount) || 0;
        const credit = parseFloat(txn.credit_amount) || 0;
        const balance = parseFloat(txn.running_balance) || 0;

        const statusBadge = getStatusBadge(txn.status, txn.type);

        statementTable.row.add([
            fmt.formatDate(txn.date),
            formatTransactionType(txn.type),
            txn.reference_number || '—',
            txn.description || '—',
            debit > 0 ? fmt.formatNumber(debit, 2) : '—',
            credit > 0 ? fmt.formatNumber(credit, 2) : '—',
            `<span class="${balance < 0 ? 'text-danger fw-bold' : ''}">${fmt.formatNumber(balance, 2)}</span>`,
            statusBadge
        ]);
    });

    statementTable.draw(false);
    statementTable.columns.adjust();
}

/**
 * Format transaction type for display.
 */
function formatTransactionType(type) {
    const typeMap = {
        'expense': '<i class="fas fa-receipt text-warning me-1"></i>Expense',
        'purchase_order': '<i class="fas fa-shopping-cart text-primary me-1"></i>Purchase Order',
        'sales_invoice': '<i class="fas fa-file-invoice text-info me-1"></i>Sales Invoice',
        'payment_slip': '<i class="fas fa-money-bill-wave text-danger me-1"></i>Payment',
        'collection_slip': '<i class="fas fa-hand-holding-usd text-success me-1"></i>Collection',
        'salary': '<i class="fas fa-user-tie text-secondary me-1"></i>Salary',
    };
    return typeMap[type] || type || '—';
}

/**
 * Get a status badge based on document status.
 */
function getStatusBadge(status, type) {
    if (!status) return '—';

    const colorMap = {
        'CLOSED': 'success',
        'PAID': 'success',
        'DELIVERED': 'info',
        'RECEIVED': 'info',
        'DRAFT': 'secondary',
        'PARTIALLY_PAID': 'warning',
        'UNPAID': 'danger',
        'CANCELLED': 'dark',
    };

    const color = colorMap[status] || 'secondary';
    return `<span class="badge bg-${color}">${status}</span>`;
}

/**
 * Show an empty/error state.
 */
function renderEmpty(message) {
    renderBalances(0, 0, []);
    if (statementTable) {
        statementTable.clear().draw();
    }
    // Show error/empty message in the table area
    const tableBody = statementContainer.querySelector('#statementTable tbody');
    if (tableBody && message) {
        tableBody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-3">${message}</td></tr>`;
    }
}

/**
 * Show a persistent error message on the page.
 */
function renderError(message) {
    const tableCard = statementContainer.querySelector('#statementTable')?.closest('.card');
    if (tableCard) {
        tableCard.querySelector('.card-body').innerHTML = `
            <div class="text-center text-danger py-4">
                <i class="fas fa-exclamation-circle fa-2x mb-2"></i>
                <p>${message}</p>
            </div>`;
    }
}

/**
 * Handle the Load button click.
 */
async function handleLoad() {
    await loadStatement();
}

/**
 * Handle the Export button click - triggers file download.
 */
async function handleExport() {
    const startDate = statementContainer.querySelector('#statementStartDate').value;
    const endDate = statementContainer.querySelector('#statementEndDate').value;

    if (!startDate || !endDate || !payeeId) return;

    const params = new URLSearchParams();
    params.set('start_date', startDate);
    params.set('end_date', endDate);

    try {
        const res = await apiFetch(`/api/people/${payeeId}/statement/export?${params.toString()}`);

        if (!res.ok) {
            const json = await res.json();
            console.error('Export failed:', json.message);
            return;
        }

        // Trigger file download from the response blob
        const blob = await res.blob();
        const contentDisposition = res.headers.get('Content-Disposition');
        let filename = `statement_${payeeId}_${startDate}_${endDate}.csv`;

        if (contentDisposition) {
            const match = contentDisposition.match(/filename="?([^";\n]+)"?/);
            if (match) filename = match[1];
        }

        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch (err) {
        console.error('Export failed:', err);
    }
}
