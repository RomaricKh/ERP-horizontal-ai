// assets/js/pages/Others/dashboard.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

function formatAmount(value) {
    const { formatNumber } = getFormatter();
    return formatNumber(value, 2);
}

const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

const TYPE_BADGE = {
    Expense:    'bg-danger',
    Payment:    'bg-warning text-dark',
    Collection: 'bg-success',
    // legacy uppercase keys kept for safety
    EXPENSE:    'bg-danger',
    PAYMENT:    'bg-warning text-dark',
    COLLECTION: 'bg-success',
};

export async function render(container) {
    const html = await fetch('html/dashboard.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    try {
        const res  = await apiFetch('/api/dashboard/summary');
        const body = await res.json();

        if (!res.ok || !body.success) {
            console.error('Dashboard summary failed:', body.message);
            return;
        }

        const data = body.data;
        renderStatCards(data);
        renderAccounts(data.account_balances || []);
        renderTransactions(data.recent_transactions || []);
        renderLowStock(data.low_stock_items || []);
        renderPeriod(data.year, data.month);

    } catch (err) {
        console.error('Dashboard mount error:', err);
    }
}

export function unmount() {
    // nothing to clean up — read-only page, no event listeners registered
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function renderPeriod(year, month) {
    const el = document.getElementById('dashboard-period');
    if (!el) return;
    const name = MONTH_NAMES[(month - 1)] || '';
    el.textContent = name + ' ' + year;
}

function renderStatCards(data) {
    const revenue   = parseFloat(data.revenue   || 0);
    const expenses  = parseFloat(data.expenses  || 0);
    const netProfit = parseFloat(data.net_profit || 0);

    const revenueEl   = document.getElementById('stat-revenue');
    const expensesEl  = document.getElementById('stat-expenses');
    const netProfitEl = document.getElementById('stat-net-profit');

    if (revenueEl)   revenueEl.textContent   = formatAmount(revenue);
    if (expensesEl)  expensesEl.textContent  = formatAmount(expenses);

    if (netProfitEl) {
        netProfitEl.textContent = formatAmount(netProfit);
        // Colour the net profit value based on sign
        netProfitEl.classList.remove('text-success', 'text-danger');
        if (netProfit >= 0) {
            netProfitEl.classList.add('text-success');
        } else {
            netProfitEl.classList.add('text-danger');
        }
    }
}

function renderAccounts(accounts) {
    const tbody = document.getElementById('dashboard-accounts-body');
    if (!tbody) return;

    if (!accounts.length) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No accounts found.</td></tr>';
        return;
    }

    tbody.innerHTML = accounts.map(function(acc) {
        return '<tr>' +
            '<td>' + escHtml(acc.name) + '</td>' +
            '<td>' + escHtml(acc.currency) + '</td>' +
            '<td class="text-end">' + formatAmount(acc.balance) + '</td>' +
            '</tr>';
    }).join('');
}

function renderTransactions(transactions) {
    const tbody = document.getElementById('dashboard-transactions-body');
    if (!tbody) return;

    if (!transactions.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No recent transactions.</td></tr>';
        return;
    }

    tbody.innerHTML = transactions.map(function(tx) {
        var badgeClass = TYPE_BADGE[tx.type] || 'bg-secondary';
        var typeBadge  = '<span class="badge ' + badgeClass + '">' + escHtml(tx.type) + '</span>';
        return '<tr>' +
            '<td>' + typeBadge + '</td>' +
            '<td>' + escHtml(getFormatter().formatDate(tx.date || '')) + '</td>' +
            '<td>' + escHtml(tx.name || tx.description || '—') + '</td>' +
            '<td class="text-end">' + formatAmount(tx.amount || tx.amount_bc) + '</td>' +
            '</tr>';
    }).join('');
}

function renderLowStock(items) {
    const tbody     = document.getElementById('dashboard-lowstock-body');
    const countBadge = document.getElementById('low-stock-count');

    if (countBadge) {
        countBadge.textContent = items.length;
    }

    if (!tbody) return;

    if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">All items are sufficiently stocked.</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(function(item) {
        return '<tr class="table-warning">' +
            '<td>' +
                '<i class="fas fa-exclamation-triangle text-warning me-1"></i>' +
                escHtml(item.name) +
            '</td>' +
            '<td>' + escHtml(item.sku || '—') + '</td>' +
            '<td class="text-end">' + getFormatter().formatNumber(item.quantity || 0, 2) + '</td>' +
            '</tr>';
    }).join('');
}
