// assets/js/pages/BusinessProfit/profitDetail.modal.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const profitId = data?.profit_id;
    if (!profitId) return;

    try {
        const res = await apiFetch(`/api/business-profit/${profitId}`);
        const json = await res.json();
        if (!json.success) return;

        const record = json.data?.profit || json.data;
        const fmt = getFormatter();

        const periodStart = fmt.formatDate(record.period_start || '') || record.period_start || '';
        const periodEnd = fmt.formatDate(record.period_end || '') || record.period_end || '';

        modalRoot.querySelector('#detailPeriod').textContent = `${periodStart} → ${periodEnd}`;
        modalRoot.querySelector('#detailStatus').innerHTML = getStatusBadge(record.status);
        modalRoot.querySelector('#detailRevenue').textContent = fmt.formatNumber(record.total_revenue || 0, 2);
        modalRoot.querySelector('#detailCogs').textContent = fmt.formatNumber(record.total_cogs || 0, 2);
        modalRoot.querySelector('#detailGrossProfit').textContent = fmt.formatNumber(record.gross_profit || 0, 2);
        modalRoot.querySelector('#detailExpenses').textContent = fmt.formatNumber(record.total_expenses || 0, 2);
        modalRoot.querySelector('#detailSalaries').textContent = fmt.formatNumber(record.salary_expenses || 0, 2);
        modalRoot.querySelector('#detailNetProfit').textContent = fmt.formatNumber(record.net_profit || 0, 2);
        modalRoot.querySelector('#detailSafetyNetPct').textContent = fmt.formatNumber(record.safety_net_percent || 0, 2) + '%';
        modalRoot.querySelector('#detailSafetyNetAmt').textContent = fmt.formatNumber(record.safety_net_amount || 0, 2);
        modalRoot.querySelector('#detailDistributable').textContent = fmt.formatNumber(record.distributable_profit || 0, 2);

        // Distribution breakdown
        const distributions = record.distributions || [];
        const distSection = modalRoot.querySelector('#distributionSection');
        if (distributions.length > 0) {
            distSection.classList.remove('d-none');
            const tbody = modalRoot.querySelector('#distributionTable tbody');
            tbody.innerHTML = distributions.map(dist => {
                const slipLink = dist.payment_slip_number
                    ? `<a href="#/payments">${escHtml(dist.payment_slip_number)}</a>`
                    : '—';
                return `<tr>
                    <td>${escHtml(dist.partner_name || '—')}</td>
                    <td class="text-end">${fmt.formatNumber(dist.share_percent, 2)}%</td>
                    <td class="text-end">${fmt.formatNumber(dist.amount_bc, 2)}</td>
                    <td>${slipLink}</td>
                </tr>`;
            }).join('');
        }
    } catch (err) {
        console.error('Failed to load profit details:', err);
    }
}

export function unmount() {
    modalRoot = null;
}

function getStatusBadge(status) {
    const s = (status || '').toUpperCase();
    switch (s) {
        case 'DRAFT': return '<span class="badge bg-secondary">DRAFT</span>';
        case 'FINALIZED': return '<span class="badge bg-warning text-dark">FINALIZED</span>';
        case 'DISTRIBUTED': return '<span class="badge bg-success">DISTRIBUTED</span>';
        default: return `<span class="badge bg-light text-dark">${escHtml(status)}</span>`;
    }
}
