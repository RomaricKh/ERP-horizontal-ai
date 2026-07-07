// assets/js/pages/BusinessProfit/index.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
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
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/BusinessProfit/profitForm.html',
                controller: '../pages/BusinessProfit/profitForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'profit.edit': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = profitDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/BusinessProfit/profitForm.html',
                controller: '../pages/BusinessProfit/profitForm.modal.js',
                data: { profit_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
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

            // Load accounts for selection
            let accounts = [];
            try {
                const accRes = await apiFetch('/api/accounts');
                const accJson = await accRes.json();
                accounts = accJson.success ? (accJson.data?.accounts ?? []) : [];
            } catch (e) { /* ignore */ }

            if (accounts.length === 0) {
                await alert({ title: 'Error', alertText: 'No business accounts found. Create an account first.' });
                return;
            }

            // Find the main currency account (not the local currency account)
            const selectedAccount = accounts.find(a => !a.is_local_currency_account) || accounts[0];
            const fmt = getFormatter();
            const balanceDisplay = fmt.formatNumber(selectedAccount.balance_fc ?? selectedAccount.balance ?? 0, 2);

            let confirmMessage;
            if (accounts.length === 1) {
                confirmMessage = `Distribute profit to partners from:\n\n` +
                    `Account: ${selectedAccount.name}\n` +
                    `Currency: ${selectedAccount.currency}\n` +
                    `Current Balance: ${balanceDisplay}\n\n` +
                    `This action cannot be undone.`;
            } else {
                confirmMessage = `Distribute profit to partners from:\n\n` +
                    `Account: ${selectedAccount.name}\n` +
                    `Currency: ${selectedAccount.currency}\n` +
                    `Current Balance: ${balanceDisplay}\n\n` +
                    `(${accounts.length} accounts available — using the primary account)\n\n` +
                    `This action cannot be undone.`;
            }

            const confirmed = await confirm({
                title: 'Distribute Profit',
                message: confirmMessage
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/business-profit/${id}/distribute`, {
                    method: 'POST',
                    body: JSON.stringify({ account_id: selectedAccount.id })
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Distribution failed' });
                } else {
                    await fetchProfitRecords();
                    
                    const distributions = data.data?.distributions || [];
                    if (distributions.length === 1) {
                        const dist = distributions[0];
                        
                        // We need the rate for the selected account to compute amount_fc
                        let rate = 1;
                        try {
                            const rRes = await apiFetch(`/api/exchange-rates/latest?currency=${encodeURIComponent(selectedAccount.currency)}`);
                            const rJson = await rRes.json();
                            if (rJson.success && rJson.data?.rate) {
                                rate = parseFloat(rJson.data.rate);
                            }
                        } catch(e) {}
                        
                        const amountFc = rate > 0 ? (dist.amount_bc / rate) : dist.amount_bc;
                        
                        await openModal({
                            scope: MODAL_SCOPE.PAGE,
                            template: 'html/Finance/paymentForm.html',
                            controller: '../pages/Finance/paymentForm.modal.js',
                            data: {
                                prefill: {
                                    payee_id: dist.partner_id,
                                    currency: selectedAccount.currency,
                                    exchange_rate: rate,
                                    amount_fc: amountFc,
                                    amount_bc: dist.amount_bc,
                                    account_id: selectedAccount.id,
                                    method: selectedAccount.account_type === 'CASH' ? 'CASH' : 'TRANSFER',
                                    reference_type: 'PARTNER_PROFIT',
                                    reference_id: id
                                }
                            },
                            options: { backdrop: false, keyboard: true, focus: true }
                        });
                    } else if (distributions.length > 1) {
                        await openModal({
                            scope: MODAL_SCOPE.PAGE,
                            template: 'html/BusinessProfit/profitDetail.html',
                            controller: '../pages/BusinessProfit/profitDetail.modal.js',
                            data: { profit_id: id },
                            options: { backdrop: false, keyboard: true, focus: true }
                        });
                    }
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

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/BusinessProfit/profitDetail.html',
                controller: '../pages/BusinessProfit/profitDetail.modal.js',
                data: { profit_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'profit.recalculate': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = profitDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;
            try {
                const res = await apiFetch(`/api/business-profit/${id}/recalculate`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to recalculate' });
                } else {
                    await fetchProfitRecords();
                }
            } catch (err) {
                console.error('Recalculate failed:', err);
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
        actions += `<button data-action="profit.recalculate" class="btn btn-light btn-circle btn-sm me-1" title="Recalculate">
                        <i class="fas fa-sync text-primary"></i></button>`;
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

