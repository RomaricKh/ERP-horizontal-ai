// assets/js/pages/Finance/prepaid-assets.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

let pageContainer;
let unregister;
let prepaidAssetsDataTable = null;
let currentFilter = 'all';
let allAssets = [];
let detailModal = null;

export async function mount() {
    await fetchPrepaidAssets();

    unregister = registerPageActions({

        'prepaid-assets.view-detail': async ({ el }) => {
            const tr = el.closest('tr');
            const rowData = prepaidAssetsDataTable.row(tr).data();
            const id = rowData[0];
            if (!id) return;
            await showAssetDetail(id);
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    prepaidAssetsDataTable?.destroy();
    prepaidAssetsDataTable = null;
    allAssets = [];
    if (detailModal) {
        detailModal.hide();
        detailModal = null;
    }
}

export async function render(container) {
    pageContainer = container;
    const html = await fetch('html/Finance/prepaid-assets.html').then(r => r.text());
    container.innerHTML = html;

    // Bind filter buttons
    container.querySelectorAll('[data-filter]').forEach(btn => {
        btn.addEventListener('click', () => {
            container.querySelectorAll('[data-filter]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            renderTable(filterAssets(allAssets));
        });
    });
}

async function fetchPrepaidAssets() {
    try {
        const res = await apiFetch('/api/prepaid-assets');
        const data = await res.json();
        if (!data.success) return;
        allAssets = data.data?.prepaid_assets ?? data.data ?? [];
        renderTable(filterAssets(allAssets));
    } catch (err) {
        console.error('Failed to fetch prepaid assets:', err);
    }
}

function filterAssets(assets) {
    if (currentFilter === 'all') return assets;
    return assets.filter(a => a.status === currentFilter);
}

function renderTable(assets) {
    if (!prepaidAssetsDataTable) {
        prepaidAssetsDataTable = createDataTable('#prepaidAssetsTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 6, className: 'text-center', orderable: false, searchable: false }
            ],
            order: [[5, 'desc']]
        });
    }

    prepaidAssetsDataTable.clear();

    assets.forEach(asset => {
        const actions = buildActions(asset);
        const statusBadge = buildStatusBadge(asset);
        const totalAmount = asset.total_amount != null
            ? getFormatter().formatNumber(asset.total_amount, 2)
            : '0.00';
        const periodsDisplay = `${asset.periods_recognized ?? 0} / ${asset.periods_total ?? 0}`;

        prepaidAssetsDataTable.row.add([
            asset.id,
            totalAmount,
            escHtml(asset.currency ?? ''),
            periodsDisplay,
            statusBadge,
            getFormatter().formatDate(asset.created_at ?? ''),
            actions
        ]);
    });

    prepaidAssetsDataTable.draw(false);
    prepaidAssetsDataTable.columns.adjust();
}

function buildStatusBadge(asset) {
    const statusMap = {
        active:           { class: 'bg-primary', label: 'Active' },
        fully_amortized:  { class: 'bg-success', label: 'Fully Amortized' },
        reversed:         { class: 'bg-danger', label: 'Reversed' }
    };
    const info = statusMap[asset.status] ?? { class: 'bg-light text-dark', label: asset.status };
    return `<span class="badge ${info.class}">${info.label}</span>`;
}

function buildActions(asset) {
    return `<button data-action="prepaid-assets.view-detail" class="btn btn-info btn-circle btn-sm" title="View Detail">
                <i class="fas fa-eye"></i></button>`;
}

async function showAssetDetail(id) {
    try {
        const res = await apiFetch(`/api/prepaid-assets/${id}`);
        const data = await res.json();
        if (!data.success) return;

        const asset = data.data?.prepaid_asset ?? data.data;
        renderDetailModal(asset);
    } catch (err) {
        console.error('Failed to fetch prepaid asset detail:', err);
    }
}

function renderDetailModal(asset) {
    const formatter = getFormatter();

    // Populate summary cards
    const totalAmountEl = pageContainer.querySelector('#detailTotalAmount');
    const perPeriodEl = pageContainer.querySelector('#detailPerPeriod');
    const progressEl = pageContainer.querySelector('#detailProgress');
    const progressPercentEl = pageContainer.querySelector('#detailProgressPercent');
    const progressBarEl = pageContainer.querySelector('#detailProgressBar');
    const scheduleBody = pageContainer.querySelector('#amortizationScheduleBody');

    if (totalAmountEl) {
        totalAmountEl.textContent = `${formatter.formatNumber(asset.total_amount, 2)} ${asset.currency ?? ''}`;
    }
    if (perPeriodEl) {
        perPeriodEl.textContent = `${formatter.formatNumber(asset.per_period_amount, 2)} ${asset.currency ?? ''}`;
    }
    if (progressEl) {
        progressEl.textContent = `${asset.periods_recognized ?? 0} / ${asset.periods_total ?? 0} periods`;
    }

    const percent = asset.periods_total > 0
        ? Math.round((asset.periods_recognized / asset.periods_total) * 100)
        : 0;

    if (progressPercentEl) {
        progressPercentEl.textContent = `${percent}%`;
    }
    if (progressBarEl) {
        progressBarEl.style.width = `${percent}%`;
        progressBarEl.setAttribute('aria-valuenow', percent);
    }

    // Render amortization schedule
    if (scheduleBody) {
        const schedule = asset.amortization_schedule ?? [];
        if (schedule.length === 0) {
            scheduleBody.innerHTML = `<tr><td colspan="5" class="text-center text-muted">No amortization schedule available</td></tr>`;
        } else {
            scheduleBody.innerHTML = schedule.map((period, index) => {
                const statusBadge = period.recognized
                    ? '<span class="badge bg-success">Recognized</span>'
                    : '<span class="badge bg-warning text-dark">Pending</span>';
                const recognizedDate = period.recognized_date
                    ? formatter.formatDate(period.recognized_date)
                    : '—';
                const amount = period.amount != null
                    ? formatter.formatNumber(period.amount, 2)
                    : formatter.formatNumber(asset.per_period_amount, 2);

                return `<tr>
                    <td>${index + 1}</td>
                    <td>${formatter.formatDate(period.due_date ?? '')}</td>
                    <td>${amount} ${escHtml(asset.currency ?? '')}</td>
                    <td>${statusBadge}</td>
                    <td>${recognizedDate}</td>
                </tr>`;
            }).join('');
        }
    }

    // Show the modal
    const modalEl = pageContainer.querySelector('#prepaidAssetDetailModal');
    if (modalEl) {
        // eslint-disable-next-line no-undef
        detailModal = new bootstrap.Modal(modalEl);
        detailModal.show();
    }
}
