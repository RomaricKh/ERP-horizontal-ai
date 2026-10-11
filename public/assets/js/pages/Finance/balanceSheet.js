// assets/js/pages/Finance/balanceSheet.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let bsContainer;

export async function mount() {
    initDateDefaults();
    bindEvents();
}

export function unmount() {
    // No cleanup needed
}

export async function render(container) {
    bsContainer = container;
    const html = await fetch('html/Finance/balanceSheet.html').then(r => r.text());
    container.innerHTML = html;
}

function initDateDefaults() {
    const now = new Date();
    document.getElementById('bsAsOfDate').value = formatDate(now);
}

function bindEvents() {
    const form = document.getElementById('bsFilterForm');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = e.submitter || form.querySelector('button[type="submit"]');
        await fetchReport(btn);
    });
}

async function fetchReport(btn = null) {
    const asOf = document.getElementById('bsAsOfDate').value;

    if (!asOf) return;

    const originalContent = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';
    }

    try {
        const res = await apiFetch(`/api/reports/balance-sheet?as_of=${asOf}`);
        const json = await res.json();
        if (!json.success) {
            console.error('Balance Sheet report error:', json.message);
            return;
        }
        renderReport(json.data?.report);
    } catch (err) {
        console.error('Failed to fetch Balance Sheet report:', err);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    }
}

function renderReport(report) {
    if (!report) return;

    const output = document.getElementById('bsReportOutput');
    output.style.display = 'block';

    const fmt = getFormatter();

    const assets = report.assets ?? {};
    const liabilities = report.liabilities ?? {};
    const equity = report.equity ?? {};

    document.getElementById('bsAccounts').textContent = fmt.formatNumber(assets.cash_bank ?? 0, 2);
    document.getElementById('bsAccountsReceivable').textContent = fmt.formatNumber(assets.accounts_receivable ?? 0, 2);
    document.getElementById('bsInventory').textContent = fmt.formatNumber(assets.inventory ?? 0, 2);
    document.getElementById('bsFixedAssets').textContent = fmt.formatNumber(assets.fixed_assets ?? 0, 2);
    document.getElementById('bsTotalAssets').textContent = fmt.formatNumber(assets.total ?? 0, 2);

    document.getElementById('bsPayables').textContent = fmt.formatNumber(liabilities.accounts_payable ?? 0, 2);
    document.getElementById('bsUnpaidExpenses').textContent = fmt.formatNumber(liabilities.unpaid_expenses ?? 0, 2);
    document.getElementById('bsUnpaidSalaries').textContent = fmt.formatNumber(liabilities.unpaid_salaries ?? 0, 2);
    document.getElementById('bsTotalLiabilities').textContent = fmt.formatNumber(liabilities.total ?? 0, 2);

    document.getElementById('bsPartnerShares').textContent = fmt.formatNumber(equity.partner_capital ?? 0, 2);
    document.getElementById('bsRetainedEarnings').textContent = fmt.formatNumber(equity.retained_earnings ?? 0, 2);
    document.getElementById('bsTotalEquity').textContent = fmt.formatNumber(equity.total ?? 0, 2);
}

function formatDate(d) {
    return d.toISOString().split('T')[0];
}
