// assets/js/pages/Finance/profitLoss.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let plContainer;

export async function mount() {
    initDateDefaults();
    bindEvents();
}

export function unmount() {
    // No cleanup needed
}

export async function render(container) {
    plContainer = container;
    const html = await fetch('html/Finance/profitLoss.html').then(r => r.text());
    container.innerHTML = html;
}

function initDateDefaults() {
    const now = new Date();
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    document.getElementById('plStartDate').value = formatDate(startOfMonth);
    document.getElementById('plEndDate').value = formatDate(now);
}

function bindEvents() {
    const form = document.getElementById('plFilterForm');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = e.submitter || form.querySelector('button[type="submit"]');
        await fetchReport(btn);
    });
}

async function fetchReport(btn = null) {
    const start = document.getElementById('plStartDate').value;
    const end = document.getElementById('plEndDate').value;

    if (!start || !end) return;

    const originalContent = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';
    }

    try {
        const res = await apiFetch(`/api/reports/profit-loss?start=${start}&end=${end}`);
        const json = await res.json();
        if (!json.success) {
            console.error('P&L report error:', json.message);
            return;
        }
        renderReport(json.data?.report);
    } catch (err) {
        console.error('Failed to fetch P&L report:', err);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    }
}

function renderReport(report) {
    if (!report) return;

    const output = document.getElementById('plReportOutput');
    output.style.display = 'block';

    const fmt = getFormatter();

    document.getElementById('plRevenue').textContent = fmt.formatNumber(report.revenue ?? 0, 2);
    document.getElementById('plCogs').textContent = fmt.formatNumber(report.cogs ?? 0, 2);
    document.getElementById('plGrossProfit').textContent = fmt.formatNumber(report.gross_profit ?? 0, 2);
    document.getElementById('plNetProfit').textContent = fmt.formatNumber(report.net_profit ?? 0, 2);
    document.getElementById('plTotalExpenses').textContent = fmt.formatNumber(report.total_expenses ?? 0, 2);

    // Render expenses by category
    const tbody = document.querySelector('#plExpensesTable tbody');
    tbody.innerHTML = '';

    const expenses = report.expenses_by_category ?? [];
    expenses.forEach(row => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${escHtml(row.category)}</td>
            <td class="text-end">${fmt.formatNumber(row.total ?? 0, 2)}</td>
        `;
        tbody.appendChild(tr);
    });

    if (expenses.length === 0) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="2" class="text-center text-muted">No expenses in this period</td>';
        tbody.appendChild(tr);
    }
}

function formatDate(d) {
    return d.toISOString().split('T')[0];
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
