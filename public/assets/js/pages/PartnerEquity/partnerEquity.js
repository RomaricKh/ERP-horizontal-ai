// assets/js/pages/PartnerEquity/partnerEquity.js

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

let equity_container;
let unregister;
let equityDataTable = null;
let updatedHandler = null;

export async function render(container) {
    equity_container = container;
    const html = await fetch('html/PartnerEquity/partnerEquity.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons if user lacks permission
    if (!hasPermission('partners.manage')) {
        container.querySelectorAll('[data-action]').forEach(el => {
            if (el.dataset.action.startsWith('partnerEquity.')) el.remove();
        });
    }
}

export async function mount() {
    await loadFilterDropdown();
    await fetchEquityEntries();

    const filterSelect = document.getElementById('filterPartner');
    filterSelect?.addEventListener('change', onFilterChange);

    updatedHandler = async () => { await fetchEquityEntries(); };
    document.addEventListener('partnerEquity.updated', updatedHandler);

    unregister = registerPageActions({
        'partnerEquity.contribute': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/PartnerEquity/equityForm.html',
                controller: '../pages/PartnerEquity/equityForm.modal.js',
                data: { equity_type: 'CONTRIBUTION' },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },
        'partnerEquity.withdraw': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/PartnerEquity/equityForm.html',
                controller: '../pages/PartnerEquity/equityForm.modal.js',
                data: { equity_type: 'WITHDRAWAL' },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },
        'partnerEquity.view-slip': async ({ el }) => {
            const slipId = el.dataset.slipId;
            const slipType = el.dataset.slipType;

            if (!slipId) return;

            if (slipType === 'COLLECTION_SLIP') {
                await openModal({
                    scope: MODAL_SCOPE.PAGE,
                    template: 'html/Finance/collectionForm.html',
                    controller: '../pages/Finance/collectionForm.modal.js',
                    data: { collection_id: slipId },
                    options: { backdrop: false, keyboard: true, focus: true }
                });
            } else if (slipType === 'PAYMENT_SLIP') {
                await openModal({
                    scope: MODAL_SCOPE.PAGE,
                    template: 'html/Finance/paymentForm.html',
                    controller: '../pages/Finance/paymentForm.modal.js',
                    data: { payment_id: slipId },
                    options: { backdrop: false, keyboard: true, focus: true }
                });
            }
        },
        'partnerEquity.reverse': async ({ el }) => {
            const id = el.dataset.id || el.closest('[data-id]')?.dataset.id;
            if (!id) return;

            const confirmed = await confirm({
                title: 'Reverse Entry',
                message: `Are you sure you want to reverse equity entry #${id}? This will create an opposite entry for the same amount.`
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/partner-equity/${id}/reverse`, {
                    method: 'POST',
                    body: JSON.stringify({})
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to reverse entry' });
                } else {
                    await fetchEquityEntries();
                }
            } catch (err) {
                console.error('Reverse equity entry failed:', err);
            }
        }
    });
}

export function unmount() {
    if (updatedHandler) {
        document.removeEventListener('partnerEquity.updated', updatedHandler);
        updatedHandler = null;
    }

    const filterSelect = document.getElementById('filterPartner');
    filterSelect?.removeEventListener('change', onFilterChange);

    unregister?.();
    unregister = null;
    equityDataTable?.destroy();
    equityDataTable = null;
    closeModalsInContainer(equity_container);
}

/* ─── Filter Dropdown ─── */

async function loadFilterDropdown() {
    try {
        const res = await apiFetch('/api/partner-shares');
        const json = await res.json();
        const shares = json.data?.shares ?? json.data?.partner_shares ?? json.data ?? [];

        const filterSelect = document.getElementById('filterPartner');
        if (filterSelect) {
            const allOption = filterSelect.querySelector('option[value=""]');
            filterSelect.innerHTML = '';
            if (allOption) filterSelect.appendChild(allOption);

            for (const share of shares) {
                const option = document.createElement('option');
                option.value = share.partner_id;
                option.textContent = share.partner_name;
                filterSelect.appendChild(option);
            }
        }
    } catch (err) {
        console.error('Failed to load partners:', err);
    }
}

function onFilterChange() {
    fetchEquityEntries();
}

/* ─── Fetch & Render ─── */

async function fetchEquityEntries() {
    const filterSelect = document.getElementById('filterPartner');
    const partnerId = filterSelect?.value || '';

    let url = '/api/partner-equity';
    if (partnerId) {
        url += `?partner_id=${encodeURIComponent(partnerId)}`;
    }

    try {
        const res = await apiFetch(url);
        const data = await res.json();
        if (!data.success) return;
        renderTable(data.data?.entries ?? data.data?.equity ?? data.data?.partner_equity ?? []);
    } catch (err) {
        console.error('Failed to fetch equity entries:', err);
    }
}

function renderTable(entries) {
    if (!equityDataTable) {
        equityDataTable = createDataTable('#partnerEquityTable', {
            columnDefs: [
                { targets: '_all', className: 'text-nowrap' },
                { targets: 7, className: 'text-center', orderable: false, searchable: false }
            ],
            order: [[6, 'desc']]
        });
    }

    equityDataTable.clear();
    const fmt = getFormatter();

    entries.forEach(entry => {
        const amountFc = fmt.formatNumber(entry.amount ?? 0, 2);
        const amountBc = fmt.formatNumber(entry.amount_bc ?? 0, 2);
        const currency = entry.currency ?? 'USD';

        const typeBadge = entry.equity_type === 'CONTRIBUTION'
            ? '<span class="badge bg-success">Contribution</span>'
            : '<span class="badge bg-danger">Withdrawal</span>';

        const formattedFc = entry.equity_type === 'CONTRIBUTION'
            ? `<span class="text-success">+${amountFc} ${currency}</span>`
            : `<span class="text-danger">-${amountFc} ${currency}</span>`;

        const formattedBc = entry.equity_type === 'CONTRIBUTION'
            ? `<span class="text-success">+${amountBc}</span>`
            : `<span class="text-danger">-${amountBc}</span>`;

        // Slip reference link
        let slipRef = '—';
        if (entry.slip_number && entry.slip_id) {
            slipRef = `<a href="javascript:void(0);" data-action="partnerEquity.view-slip" data-slip-id="${entry.slip_id}" data-slip-type="${entry.slip_type}">${escHtml(entry.slip_number)}</a>`;
        } else if (entry.slip_number) {
            slipRef = escHtml(entry.slip_number);
        }

        const date = fmt.formatDate(entry.created_at ?? '') || (entry.created_at ?? '');

        const canManage = hasPermission('partners.manage');
        let actions = '';
        if (canManage) {
            actions = `<button data-action="partnerEquity.reverse" data-id="${entry.id}" class="btn btn-light btn-circle btn-sm" title="Reverse"><i class="fas fa-undo text-warning"></i></button>`;
        }

        equityDataTable.row.add([
            escHtml(entry.partner_name ?? '—'),
            typeBadge,
            formattedFc,
            formattedBc,
            slipRef,
            escHtml(entry.notes ?? ''),
            date,
            actions
        ]);
    });

    equityDataTable.draw(false);
    equityDataTable.columns.adjust();
}
