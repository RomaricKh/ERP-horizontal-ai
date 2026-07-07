// assets/js/pages/Finance/accounts.js

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

let accounts_container;
let unregister;
let accountsUpdatedHandler;
let accountsDataTable = null;

export async function mount() {
    await fetchAccounts();

    accountsUpdatedHandler = async () => { await fetchAccounts(); };
    document.addEventListener('accounts.updated', accountsUpdatedHandler);

    unregister = registerPageActions({

        'accounts.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/accountForm.html',
                controller: '../pages/Finance/accountForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'accounts.transfer': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/accountTransferForm.modal.html',
                controller: '../pages/Finance/accountTransferForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'accounts.edit': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = accountsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/accountForm.html',
                controller: '../pages/Finance/accountForm.modal.js',
                data: { account_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'accounts.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = accountsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete account',
                message: 'Delete this account? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/accounts/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete account' });
                } else {
                    await fetchAccounts();
                }
            } catch (err) {
                console.error('Delete account failed:', err);
            }
        }
    });
}

export function unmount() {
    if (accountsUpdatedHandler) {
        document.removeEventListener('accounts.updated', accountsUpdatedHandler);
        accountsUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    accountsDataTable?.destroy();
    accountsDataTable = null;
    closeModalsInContainer(accounts_container);
}

export async function render(container) {
    accounts_container = container;
    const html = await fetch('html/Finance/accounts.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'accounts.create' && !hasPermission('accounts.manage')) {
            el.remove();
        }
        if (el.dataset.action === 'accounts.transfer' && !hasPermission('accounts.transfer')) {
            el.remove();
        }
    });
}

async function fetchAccounts() {
    const res  = await apiFetch('/api/accounts');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.accounts ?? []);
}

function renderTable(accounts) {
    if (!accountsDataTable) {
        accountsDataTable = createDataTable('#accountsTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 6, className: 'text-center', orderable: false, searchable: false }
            ],
            order: [[2, 'asc'], [1, 'asc']],
            drawCallback: function (settings) {
                const api = this.api();
                const rows = api.rows({ page: 'current' }).nodes();
                let lastCurrency = null;

                api.column(2, { page: 'current' }).data().each(function (currency, i) {
                    if (currency !== lastCurrency) {
                        $(rows).eq(i).before(
                            `<tr class="table-secondary currency-group-header">
                                <td colspan="6" class="fw-bold">${escHtml(currency)}</td>
                            </tr>`
                        );
                        lastCurrency = currency;
                    }
                });
            }
        });
    }

    accountsDataTable.clear();

    const canManage = hasPermission('accounts.manage');
    let totalBc = 0;

    accounts.forEach(account => {
        let actions = '';
        if (canManage) {
            actions += `<button data-action="accounts.edit" class="btn btn-light btn-circle btn-sm me-1" title="Edit">
                            <i class="fas fa-edit text-info"></i></button>`;
        }
        if (canManage) {
            actions += `<button data-action="accounts.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash text-danger"></i></button>`;
        }

        const balanceFc = account.balance_fc != null ? getFormatter().formatNumber(account.balance_fc, 2) : '0.00';
        const balanceBc = account.balance != null ? getFormatter().formatNumber(account.balance, 2) : '0.00';
        totalBc += account.balance != null ? parseFloat(account.balance) : 0;

        const typeHtml = escHtml(account.account_type ?? 'CASH');
        const badge = account.is_local_currency_account == 1 
            ? ' <span class="badge bg-info text-white ms-2" title="Local Currency Spillover Account"><i class="fas fa-star text-warning"></i> Local</span>' 
            : '';

        accountsDataTable.row.add([
            account.id,
            escHtml(account.name ?? '') + badge,
            escHtml(account.currency ?? ''),
            typeHtml,
            balanceFc,
            balanceBc,
            actions
        ]);
    });

    accountsDataTable.draw(false);
    accountsDataTable.columns.adjust();

    // Update footer total
    const totalEl = document.getElementById('totalBalanceBc');
    if (totalEl) {
        totalEl.textContent = getFormatter().formatNumber(totalBc, 2);
    }
}
