// assets/js/pages/BusinessProfit/index.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

let profit_container;
let unregister;
let profitUpdatedHandler;
let profitDataTable = null;

export async function mount() {
    await fetchProfitRecords();

    profitUpdatedHandler = async () => { await fetchProfitRecords(); };
    document.addEventListener('profit.updated', profitUpdatedHandler);

    unregister = registerPageActions({

        'profit.create': async () => {
            resetForm();
            const modal = new bootstrap.Modal(document.getElementById('addProfitModal'));
            modal.show();
        },

        'profit.edit': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = profitDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            try {
                const res = await apiFetch(`/api/business-profit/${id}`);
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to load profit record' });
                    return;
                }
                populateForm(data.data);
                const modal = new bootstrap.Modal(document.getElementById('addProfitModal'));
                modal.show();
            } catch (err) {
                console.error('Load profit record failed:', err);
            }
        },

        'profit.delete': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = profitDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Delete Profit Record',
                message: 'Delete this profit record? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/business-profit/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete profit record' });
                } else {
                    await fetchProfitRecords();
                }
            } catch (err) {
                console.error('Delete profit record failed:', err);
            }
        },

        'profit.save': async () => {
            await saveProfit();
        },

        'profit.finalize': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = profitDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Finalize Profit Record',
                message: 'Finalize this profit record? Once finalized, the P&L values cannot be edited.'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/business-profit/${id}/finalize`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to finalize profit record' });
                } else {
                    await fetchProfitRecords();
                }
            } catch (err) {
                console.error('Finalize profit record failed:', err);
            }
        },

        'profit.distribute': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = profitDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Distribute Profit',
                message: 'Distribute this profit to partners based on their ownership percentages? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/business-profit/${id}/distribute`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to distribute profit' });
                } else {
                    await fetchProfitRecords();
                }
            } catch (err) {
                console.error('Distribute profit failed:', err);
            }
        },

        'profit.view-details': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = profitDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            try {
                const res = await apiFetch(`/api/business-profit/${id}`);
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to load profit details' });
                    return;
                }
                showDetailModal(data.data);
            } catch (err) {
                console.error('Load profit details failed:', err);
            }
        }
    });
}

export function unmount() {
    if (profitUpdatedHandler) {
        document.removeEventListener('profit.updated', profitUpdatedHandler);
        profitUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    profitDataTable?.destroy();
    profitDataTable = null;
    closeModalsInContainer(profit_container);
}

export async function render(container) {
    profit_container = container;
    const html = await fetch('html/BusinessProfit/index.html').then(r => r.text());
    container.innerHTML = html;

    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'profit.create' && !hasPermission('profit.manage')) {
            el.remove();
        }
    });
}

// ─── Data Fetching ───────────────────────────────────────────────────────────

async function fetchProfitRecords() {
    const res = await apiFetch('/api/business-profit');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.profits ?? data.data ?? []);
}

// ─── Table Rendering ─────────────────────────────────────────────────────────

function renderTable(profits) {
    if (!profitDataTable) {
        profitDataTable = createDataTable('#profitTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 9, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    profitDataTable.clear();

    const canManage = hasPermission('profit.manage');
    const fmt = getFormatter();

    profits.forEach(record => {
        const actions = buildActionButtons(record, canManage);

        const periodStart = fmt.formatDate(record.period_start ?? '') || record.period_start || '';
        const periodEnd = fmt.formatDate(record.period_end ?? '') || record.period_end || '';
        const period = escHtml(`${periodStart} → ${periodEnd}`);

        const revenue = fmt.formatNumber(record.total_revenue ?? 0, 2);
        const cogs = fmt.formatNumber(record.total_cogs ?? 0, 2);
        const grossProfit = fmt.formatNumber(record.gross_profit ?? 0, 2);
        const expenses = fmt.formatNumber(record.total_expenses ?? 0, 2);
        const netProfit = fmt.formatNumber(record.net_profit ?? 0, 2);
        const distributable = fmt.formatNumber(record.distributable_profit ?? 0, 2);

        const statusBadge = getStatusBadge(record.status);

        profitDataTable.row.add([
            record.id,
            period,
            revenue,
            cogs,
            grossProfit,
            expenses,
            netProfit,
            distributable,
            statusBadge,
            actions
        ]);
    });

    profitDataTable.draw(false);
    profitDataTable.columns.adjust();
}

function buildActionButtons(record, canManage) {
    let actions = '';
    if (!canManage) return actions;

    const status = (record.status || '').toUpperCase();

    // View details — always visible
    actions += `<button data-action="profit.view-details" class="btn btn-light btn-circle btn-sm me-1" title="View Details">
                    <i class="fas fa-eye text-secondary"></i></button>`;

    if (status === 'DRAFT') {
        actions += `<button data-action="profit.edit" class="btn btn-light btn-circle btn-sm me-1" title="Edit">
                        <i class="fas fa-edit text-info"></i></button>`;
        actions += `<button data-action="profit.finalize" class="btn btn-light btn-circle btn-sm me-1" title="Finalize">
                        <i class="fas fa-lock text-warning"></i></button>`;
        actions += `<button data-action="profit.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete">
                        <i class="fas fa-trash text-danger"></i></button>`;
    }

    if (status === 'FINALIZED') {
        actions += `<button data-action="profit.distribute" class="btn btn-light btn-circle btn-sm me-1" title="Distribute">
                        <i class="fas fa-share-alt text-success"></i></button>`;
    }

    return actions;
}

function getStatusBadge(status) {
    const s = (status || '').toUpperCase();
    switch (s) {
        case 'DRAFT':
            return `<span class="badge bg-secondary">${escHtml(status)}</span>`;
        case 'FINALIZED':
            return `<span class="badge bg-warning text-dark">${escHtml(status)}</span>`;
        case 'DISTRIBUTED':
            return `<span class="badge bg-success">${escHtml(status)}</span>`;
        default:
            return `<span class="badge bg-light text-dark">${escHtml(status)}</span>`;
    }
}

// ─── Form Handling ───────────────────────────────────────────────────────────

function resetForm() {
    document.getElementById('inputProfitId').value = '';
    document.getElementById('inputPeriodStart').value = '';
    document.getElementById('inputPeriodEnd').value = '';
    document.getElementById('inputSafetyNetPercent').value = '';
    document.getElementById('profitForm').classList.remove('was-validated');
}

function populateForm(record) {
    document.getElementById('inputProfitId').value = record.id || '';
    document.getElementById('inputPeriodStart').value = record.period_start || '';
    document.getElementById('inputPeriodEnd').value = record.period_end || '';
    document.getElementById('inputSafetyNetPercent').value = record.safety_net_percent || '';
}

async function saveProfit() {
    const form = document.getElementById('profitForm');
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return;
    }

    const id = document.getElementById('inputProfitId').value;
    const payload = {
        period_start: document.getElementById('inputPeriodStart').value,
        period_end: document.getElementById('inputPeriodEnd').value,
        safety_net_percent: parseFloat(document.getElementById('inputSafetyNetPercent').value) || 0
    };

    try {
        let res;
        if (id) {
            res = await apiFetch(`/api/business-profit/${id}`, {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
        } else {
            res = await apiFetch('/api/business-profit', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
        }

        const data = await res.json();
        if (!res.ok || !data.success) {
            await alert({ title: 'Error', alertText: data.message || 'Failed to save profit record' });
            return;
        }

        const modalEl = document.getElementById('addProfitModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        modal?.hide();
        await fetchProfitRecords();
    } catch (err) {
        console.error('Save profit record failed:', err);
    }
}

// ─── Detail Modal ────────────────────────────────────────────────────────────

function showDetailModal(record) {
    const fmt = getFormatter();

    const periodStart = fmt.formatDate(record.period_start ?? '') || record.period_start || '';
    const periodEnd = fmt.formatDate(record.period_end ?? '') || record.period_end || '';

    document.getElementById('detailPeriod').textContent = `${periodStart} → ${periodEnd}`;
    document.getElementById('detailRevenue').textContent = fmt.formatNumber(record.total_revenue ?? 0, 2);
    document.getElementById('detailCogs').textContent = fmt.formatNumber(record.total_cogs ?? 0, 2);
    document.getElementById('detailGrossProfit').textContent = fmt.formatNumber(record.gross_profit ?? 0, 2);
    document.getElementById('detailExpenses').textContent = fmt.formatNumber(record.total_expenses ?? 0, 2);
    document.getElementById('detailSalaries').textContent = fmt.formatNumber(record.salary_expenses ?? 0, 2);
    document.getElementById('detailNetProfit').textContent = fmt.formatNumber(record.net_profit ?? 0, 2);
    document.getElementById('detailSafetyNetPct').textContent = fmt.formatNumber(record.safety_net_percent ?? 0, 2) + '%';
    document.getElementById('detailSafetyNetAmt').textContent = fmt.formatNumber(record.safety_net_amount ?? 0, 2);
    document.getElementById('detailDistributable').textContent = fmt.formatNumber(record.distributable_profit ?? 0, 2);
    document.getElementById('detailStatus').innerHTML = getStatusBadge(record.status);

    // Distribution section
    const distSection = document.getElementById('distributionSection');
    const tbody = document.querySelector('#distributionTable tbody');
    tbody.innerHTML = '';

    const distributions = record.distributions || [];
    if (distributions.length > 0) {
        distSection.style.display = '';
        distributions.forEach(dist => {
            const tr = document.createElement('tr');
            const slipLink = dist.payment_slip_number
                ? `<a href="#/finance/payments">${escHtml(dist.payment_slip_number)}</a>`
                : '—';
            tr.innerHTML = `
                <td>${escHtml(dist.partner_name || '—')}</td>
                <td>${fmt.formatNumber(dist.share_percent, 2)}%</td>
                <td>${fmt.formatNumber(dist.amount_bc, 2)}</td>
                <td>${slipLink}</td>
            `;
            tbody.appendChild(tr);
        });
    } else {
        distSection.style.display = 'none';
    }

    const modal = new bootstrap.Modal(document.getElementById('profitDetailModal'));
    modal.show();
}
