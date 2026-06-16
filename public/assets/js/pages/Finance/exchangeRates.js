// assets/js/pages/Finance/exchangeRates.js

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

let exchangeRates_container;
let unregister;
let exchangeRatesUpdatedHandler;
let exchangeRatesDataTable = null;

export async function mount() {
    await fetchExchangeRates();

    exchangeRatesUpdatedHandler = async () => { await fetchExchangeRates(); };
    document.addEventListener('exchange-rates.updated', exchangeRatesUpdatedHandler);

    unregister = registerPageActions({

        'exchange-rates.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/exchangeRateForm.html',
                controller: '../pages/Finance/exchangeRateForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'exchange-rates.edit': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = exchangeRatesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Finance/exchangeRateForm.html',
                controller: '../pages/Finance/exchangeRateForm.modal.js',
                data: { rate_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'exchange-rates.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = exchangeRatesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete exchange rate',
                message: 'Delete this exchange rate? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/exchange-rates/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete exchange rate' });
                } else {
                    await fetchExchangeRates();
                }
            } catch (err) {
                console.error('Delete exchange rate failed:', err);
            }
        }
    });
}

export function unmount() {
    if (exchangeRatesUpdatedHandler) {
        document.removeEventListener('exchange-rates.updated', exchangeRatesUpdatedHandler);
        exchangeRatesUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    exchangeRatesDataTable?.destroy();
    exchangeRatesDataTable = null;
    closeModalsInContainer(exchangeRates_container);
}

export async function render(container) {
    exchangeRates_container = container;
    const html = await fetch('html/Finance/exchangeRates.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'exchange-rates.create' && !hasPermission('exchange-rates.manage')) {
            el.remove();
        }
    });
}

async function fetchExchangeRates() {
    const res  = await apiFetch('/api/exchange-rates');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.exchange_rates ?? []);
}

function renderTable(rates) {
    if (!exchangeRatesDataTable) {
        exchangeRatesDataTable = createDataTable('#exchangeRatesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 5, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    exchangeRatesDataTable.clear();

    const canManage = hasPermission('exchange-rates.manage');

    rates.forEach(rate => {
        let actions = '';
        if (canManage) {
            actions += `<button data-action="exchange-rates.edit" class="btn btn-light btn-circle btn-sm me-1" title="Edit">
                            <i class="fas fa-edit text-info"></i></button>`;
        }
        if (canManage) {
            actions += `<button data-action="exchange-rates.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash text-danger"></i></button>`;
        }

        const rateValue = rate.rate != null ? getFormatter().formatNumber(rate.rate, 6) : '—';

        exchangeRatesDataTable.row.add([
            rate.id,
            escHtml(rate.currency_code ?? rate.currency ?? ''),
            getFormatter().formatDate(rate.rate_date ?? rate.date ?? ''),
            rateValue,
            escHtml(rate.source ?? ''),
            actions
        ]);
    });

    exchangeRatesDataTable.draw(false);
    exchangeRatesDataTable.columns.adjust();
}
