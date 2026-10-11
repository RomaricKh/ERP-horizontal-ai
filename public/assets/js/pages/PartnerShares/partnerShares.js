// assets/js/pages/PartnerShares/partnerShares.js

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

let shares_container;
let unregister;
let dataTable = null;
let updatedHandler = null;

export async function render(pageContainer) {
    shares_container = pageContainer;
    const html = await fetch('html/PartnerShares/partnerShares.html').then(r => r.text());
    pageContainer.innerHTML = html;

    // Hide buttons the user has no permission for
    pageContainer.querySelectorAll('[data-action]').forEach(el => {
        const action = el.dataset.action;
        const permMap = { 'partnerShares.create': 'partners.manage' };
        const perm = permMap[action];
        if (perm && !hasPermission(perm)) el.remove();
    });
}

export async function mount() {
    await fetchShares();

    updatedHandler = async () => { await fetchShares(); };
    document.addEventListener('partnerShares.updated', updatedHandler);
    document.addEventListener('partnerEquity.updated', updatedHandler);

    unregister = registerPageActions({
        'partnerShares.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/PartnerShares/shareForm.html',
                controller: '../pages/PartnerShares/shareForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'partnerShares.edit': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/PartnerShares/shareForm.html',
                controller: '../pages/PartnerShares/shareForm.modal.js',
                data: { share_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'partnerShares.delete': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const id = rowData[0];
            const name = rowData[1];
            if (!id) return;

            const confirmed = await confirm({
                title: 'Delete Partner Share',
                message: `Remove share for "${name}"? This action cannot be undone.`
            });
            if (!confirmed) return;

            try {
                const res = await apiFetch(`/api/partner-shares/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete partner share.' });
                } else {
                    await fetchShares();
                }
            } catch (err) {
                console.error('Delete partner share failed:', err);
            }
        },

        'partnerShares.contribute': async ({ el }) => {
            const partnerId = el.dataset.partnerId;
            if (!partnerId) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/PartnerEquity/equityForm.html',
                controller: '../pages/PartnerEquity/equityForm.modal.js',
                data: { equity_type: 'CONTRIBUTION', partner_id: partnerId },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'partnerShares.withdraw': async ({ el }) => {
            const partnerId = el.dataset.partnerId;
            if (!partnerId) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/PartnerEquity/equityForm.html',
                controller: '../pages/PartnerEquity/equityForm.modal.js',
                data: { equity_type: 'WITHDRAWAL', partner_id: partnerId },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'partnerShares.equity-history': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = dataTable.row(tr).data();
            const partnerId = rowData[0];
            if (!partnerId) return;
            window.location.hash = `#/partner-equity?partner_id=${partnerId}`;
        }
    });
}

export function unmount() {
    if (updatedHandler) {
        document.removeEventListener('partnerShares.updated', updatedHandler);
        document.removeEventListener('partnerEquity.updated', updatedHandler);
        updatedHandler = null;
    }
    unregister?.();
    unregister = null;
    dataTable?.destroy();
    dataTable = null;
    closeModalsInContainer(shares_container);
}

// ─── Data Fetching ───────────────────────────────────────────────────────────

async function fetchShares() {
    try {
        const res = await apiFetch('/api/partner-shares');
        const data = await res.json();
        if (!data.success) return;
        const shares = data.data.shares ?? data.data.partner_shares ?? data.data ?? [];
        renderTable(shares);
        updateSummaryCards(shares);
    } catch (err) {
        console.error('Fetch partner shares failed:', err);
    }
}

// ─── Table Rendering ─────────────────────────────────────────────────────────

function renderTable(shares) {
    if (!dataTable) {
        dataTable = createDataTable('#partnerSharesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 4, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    dataTable.clear();

    const canManage = hasPermission('partners.manage');
    const fmt = getFormatter();

    shares.forEach(share => {
        let actions = '';
        if (canManage) {
            actions += `<button data-action="partnerShares.contribute" data-partner-id="${share.partner_id}" class="btn btn-light btn-circle btn-sm me-1" title="Contribute">
                            <i class="fas fa-plus-circle text-success"></i></button>`;
            actions += `<button data-action="partnerShares.withdraw" data-partner-id="${share.partner_id}" class="btn btn-light btn-circle btn-sm me-1" title="Withdraw">
                            <i class="fas fa-minus-circle text-warning"></i></button>`;
            actions += `<button data-action="partnerShares.equity-history" class="btn btn-light btn-circle btn-sm me-1" title="Equity History">
                            <i class="fas fa-history text-secondary"></i></button>`;
            actions += `<button data-action="partnerShares.edit" class="btn btn-light btn-circle btn-sm me-1" title="Edit">
                            <i class="fas fa-edit text-info"></i></button>`;
            actions += `<button data-action="partnerShares.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash text-danger"></i></button>`;
        }

        dataTable.row.add([
            share.id,
            escHtml(share.partner_name ?? '—'),
            fmt.formatNumber(share.share_percent, 2) + '%',
            fmt.formatNumber(share.invested_capital, 2),
            actions
        ]);
    });

    dataTable.draw(false);
    dataTable.columns.adjust();
}

function updateSummaryCards(shares) {
    const fmt = getFormatter();
    const totalPercent = shares.reduce((sum, s) => sum + parseFloat(s.share_percent || 0), 0);
    const remainingPercent = Math.max(0, 100 - totalPercent);
    const totalCapital = shares.reduce((sum, s) => sum + parseFloat(s.invested_capital || 0), 0);

    const totalEl = document.getElementById('totalSharePercent');
    if (totalEl) {
        totalEl.textContent = fmt.formatNumber(totalPercent, 2) + '%';
        totalEl.classList.toggle('text-danger', totalPercent > 100);
    }

    const remainEl = document.getElementById('remainingPercent');
    if (remainEl) {
        remainEl.textContent = fmt.formatNumber(remainingPercent, 2) + '%';
    }

    const capitalEl = document.getElementById('totalInvestedCapital');
    if (capitalEl) {
        capitalEl.textContent = fmt.formatNumber(totalCapital, 2);
    }
}
