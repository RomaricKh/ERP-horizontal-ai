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
    Expense:    'pill-expense',
    Payment:    'pill-neutral',
    Collection: 'pill-income',
    'Credit Note': 'pill-neutral',
    // legacy uppercase keys kept for safety
    EXPENSE:    'bg-danger',
    PAYMENT:    'bg-warning text-dark',
    COLLECTION: 'bg-success',
};

let myAreaChart = null;
let myPieChart = null;

function loadChartJs() {
    return new Promise((resolve, reject) => {
        if (window.Chart) {
            resolve();
            return;
        }
        const script = document.createElement('script');
        script.src = 'assets/vendor/chart.js/chart.umd.js';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load Chart.js'));
        document.head.appendChild(script);
    });
}

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

        try {
            await loadChartJs();
            renderCharts(data);
        } catch (e) {
            console.error(e);
        }

    } catch (err) {
        console.error('Dashboard mount error:', err);
    }
}

export function unmount() {
    if (myAreaChart) { myAreaChart.destroy(); myAreaChart = null; }
    if (myPieChart) { myPieChart.destroy(); myPieChart = null; }
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
        tbody.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-3">No accounts found.</td></tr>';
        return;
    }

    tbody.innerHTML = accounts.map(function(acc) {
        return '<tr>' +
            '<td class="py-3 fw-medium text-gray-800"><i class="fas fa-university text-primary me-2 opacity-50"></i>' + escHtml(acc.name) + '</td>' +
            '<td class="text-end py-3 fw-bold">' + formatAmount(acc.balance) + '</td>' +
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
        var badgeClass = TYPE_BADGE[tx.type] || 'pill-neutral';
        var typeBadge  = '<span class="status-pill ' + badgeClass + '">' + escHtml(tx.type) + '</span>';
        return '<tr>' +
            '<td class="py-3">' + typeBadge + '</td>' +
            '<td class="py-3 text-muted">' + escHtml(getFormatter().formatDate(tx.date || '')) + '</td>' +
            '<td class="py-3 fw-medium text-gray-800">' + escHtml(tx.name || tx.description || '—') + '</td>' +
            '<td class="text-end py-3 fw-bold">' + formatAmount(tx.amount || tx.amount_bc) + '</td>' +
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
        tbody.innerHTML = '<li class="list-group-item text-center text-muted py-3 border-0">All items are sufficiently stocked.</li>';
        return;
    }

    tbody.innerHTML = items.map(function(item) {
        return '<li class="list-group-item d-flex justify-content-between align-items-center py-3 border-bottom-0 border-top">' +
               '<div><i class="fas fa-exclamation-circle text-warning me-2"></i><strong>' + escHtml(item.name) + '</strong><br>' +
               '<small class="text-muted">SKU: ' + escHtml(item.sku || '—') + '</small></div>' +
               '<span class="badge bg-danger rounded-pill">' + formatAmount(item.quantity) + ' QTY</span>' +
               '</li>';
    }).join('');
}

function renderCharts(data) {
    // 1. Area Chart (Earnings Overview)
    const ctxArea = document.getElementById("myAreaChart");
    if (ctxArea && data.earnings_overview) {
        if (myAreaChart) myAreaChart.destroy();
        
        const labels = MONTH_NAMES.map(m => m.substring(0, 3));
        const revenues = data.earnings_overview.map(d => d.revenue);

        myAreaChart = new Chart(ctxArea, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: "Earnings",
                    tension: 0.3,
                    backgroundColor: "rgba(78, 115, 223, 0.05)",
                    borderColor: "rgba(78, 115, 223, 1)",
                    pointRadius: 3,
                    pointBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointBorderColor: "rgba(78, 115, 223, 1)",
                    pointHoverRadius: 3,
                    pointHoverBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointHoverBorderColor: "rgba(78, 115, 223, 1)",
                    pointHitRadius: 10,
                    pointBorderWidth: 2,
                    data: revenues,
                    fill: true
                }],
            },
            options: {
                maintainAspectRatio: false,
                layout: { padding: { left: 10, right: 25, top: 25, bottom: 0 } },
                scales: {
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: { maxTicksLimit: 7 }
                    },
                    y: {
                        ticks: {
                            maxTicksLimit: 5,
                            padding: 10,
                            callback: function(value) { return formatAmount(value); }
                        },
                        grid: { color: "rgb(234, 236, 244)", drawBorder: false, borderDash: [2] },
                        border: { dash: [2] }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: "rgb(255,255,255)",
                        bodyColor: "#858796",
                        titleMarginBottom: 10,
                        titleColor: '#6e707e',
                        titleFont: { size: 14 },
                        borderColor: '#dddfeb',
                        borderWidth: 1,
                        padding: 15,
                        displayColors: false,
                        intersect: false,
                        mode: 'index',
                        caretPadding: 10,
                        callbacks: {
                            label: function(context) { return 'Earnings: ' + formatAmount(context.raw); }
                        }
                    }
                }
            }
        });
    }

    // 2. Donut Chart (Expense Sources)
    const ctxPie = document.getElementById("myPieChart");
    if (ctxPie && data.expense_breakdown) {
        if (myPieChart) myPieChart.destroy();
        
        let labels = [];
        let values = [];
        const bgColors = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b'];
        const hoverBgColors = ['#2e59d9', '#17a673', '#2c9faf', '#dda20a', '#be2617'];

        data.expense_breakdown.forEach(item => {
            labels.push(item.category);
            values.push(item.total);
        });

        if (values.length === 0) {
            labels = ["No Expenses"];
            values = [1];
        }

        myPieChart = new Chart(ctxPie, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: bgColors.slice(0, values.length),
                    hoverBackgroundColor: hoverBgColors.slice(0, values.length),
                    hoverBorderColor: "rgba(234, 236, 244, 1)",
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '80%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: "rgb(255,255,255)",
                        bodyColor: "#858796",
                        borderColor: '#dddfeb',
                        borderWidth: 1,
                        padding: 15,
                        displayColors: false,
                        caretPadding: 10,
                        callbacks: {
                            label: function(context) { return context.label + ': ' + formatAmount(context.raw); }
                        }
                    }
                }
            }
        });

        // Build Custom Legend
        const legendContainer = document.getElementById('pieChartLegend');
        if (legendContainer && data.expense_breakdown.length > 0) {
            legendContainer.innerHTML = data.expense_breakdown.map((item, index) => {
                const color = bgColors[index % bgColors.length];
                return '<span class="me-2">' +
                    '<i class="fas fa-circle" style="color: ' + color + ';"></i> ' + escHtml(item.category) +
                '</span>';
            }).join('');
        }
    }
}
