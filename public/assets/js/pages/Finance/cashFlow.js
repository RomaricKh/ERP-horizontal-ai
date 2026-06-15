// assets/js/pages/Finance/cashFlow.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let cfContainer;

export async function mount() {
    initDateDefaults();
    bindEvents();
}

export function unmount() {
    // No cleanup needed
}

export async function render(container) {
    cfContainer = container;
    const html = await fetch('html/Finance/cashFlow.html').then(r => r.text());
    container.innerHTML = html;
}

function initDateDefaults() {
    const now = new Date();
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    document.getElementById('cfStartDate').value = formatDate(startOfMonth);
    document.getElementById('cfEndDate').value = formatDate(now);
}

function bindEvents() {
    const form = document.getElementById('cfFilterForm');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        await fetchReport();
    });
}

async function fetchReport() {
    const start = document.getElementById('cfStartDate').value;
    const end = document.getElementById('cfEndDate').value;

    if (!start || !end) return;

    try {
        const res = await apiFetch(`/api/reports/cash-flow?start=${start}&end=${end}`);
        const json = await res.json();
        if (!json.success) {
            console.error('Cash Flow report error:', json.message);
            return;
        }
        renderReport(json.data?.report);
    } catch (err) {
        console.error('Failed to fetch Cash Flow report:', err);
    }
}

function renderReport(report) {
    if (!report) return;

    const output = document.getElementById('cfReportOutput');
    output.style.display = 'block';

    const fmt = getFormatter();

    const inflows = report.inflows ?? {};
    const outflows = report.outflows ?? {};

    document.getElementById('cfTotalInflows').textContent = fmt.formatNumber(inflows.total ?? 0, 2);
    document.getElementById('cfTotalOutflows').textContent = fmt.formatNumber(outflows.total ?? 0, 2);
    document.getElementById('cfNetMovement').textContent = fmt.formatNumber(report.net_movement ?? 0, 2);

    document.getElementById('cfCollections').textContent = fmt.formatNumber(inflows.collections ?? 0, 2);
    document.getElementById('cfInflowsTotal').textContent = fmt.formatNumber(inflows.total ?? 0, 2);

    document.getElementById('cfPayments').textContent = fmt.formatNumber(outflows.payments ?? 0, 2);
    document.getElementById('cfSalaries').textContent = fmt.formatNumber(outflows.salaries ?? 0, 2);
    document.getElementById('cfExpenses').textContent = fmt.formatNumber(outflows.expenses ?? 0, 2);
    document.getElementById('cfOutflowsTotal').textContent = fmt.formatNumber(outflows.total ?? 0, 2);
}

function formatDate(d) {
    return d.toISOString().split('T')[0];
}
