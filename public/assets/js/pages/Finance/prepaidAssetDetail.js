// assets/js/pages/Finance/prepaidAssetDetail.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

let container;
let assetId = null;

export async function render(pageContainer) {
    container = pageContainer;
    const html = await fetch('html/Finance/prepaid-asset-detail.html').then(r => r.text());
    container.innerHTML = html;

    // Extract id from hash query string: #/prepaid-assets/detail?id=123
    assetId = getAssetIdFromHash();
}

export async function mount() {
    if (!assetId) {
        showError('No prepaid asset ID specified.');
        return;
    }
    await loadAssetDetail();
    await loadAccountingEntries();
}

export function unmount() {
    container = null;
    assetId = null;
}

/**
 * Parse the asset ID from the current hash query string.
 */
function getAssetIdFromHash() {
    const hash = window.location.hash || '';
    const qIndex = hash.indexOf('?');
    if (qIndex === -1) return null;
    const params = new URLSearchParams(hash.substring(qIndex + 1));
    const id = params.get('id');
    return id ? parseInt(id, 10) : null;
}

/**
 * Load the prepaid asset detail from the API and render all sections.
 */
async function loadAssetDetail() {
    try {
        const res = await apiFetch(`/api/prepaid-assets/${assetId}`);
        const data = await res.json();

        if (!res.ok || !data.success) {
            showError(data.message || 'Failed to load prepaid asset.');
            return;
        }

        const asset = data.data?.prepaid_asset ?? data.data;
        renderSummary(asset);
        renderProgressBar(asset);
        renderAmortizationSchedule(asset);
        renderAdvancePaymentLink(asset);
    } catch (err) {
        console.error('Failed to fetch prepaid asset detail:', err);
        showError('An error occurred while loading the prepaid asset.');
    }
}

/**
 * Load accounting entries related to this prepaid asset.
 */
async function loadAccountingEntries() {
    try {
        const res = await apiFetch(`/api/accounting-entries?reference_type=prepaid_asset&reference_id=${assetId}`);
        const data = await res.json();

        if (!res.ok || !data.success) {
            renderAccountingEntriesEmpty('No accounting entries found.');
            return;
        }

        const entries = data.data?.entries ?? data.data?.accounting_entries ?? [];
        renderAccountingEntries(entries);
    } catch (err) {
        console.error('Failed to fetch accounting entries:', err);
        renderAccountingEntriesEmpty('Failed to load accounting entries.');
    }
}

/**
 * Render the summary cards with asset info.
 */
function renderSummary(asset) {
    const fmt = getFormatter();

    const subtitle = container.querySelector('#paDetailSubtitle');
    if (subtitle) {
        subtitle.textContent = `Prepaid Asset #${asset.id} — ${escHtml(asset.currency ?? '')}`;
    }

    const totalEl = container.querySelector('#paSummaryTotal');
    if (totalEl) {
        totalEl.textContent = `${fmt.formatNumber(asset.total_amount, 2)} ${asset.currency ?? ''}`;
    }

    const perPeriodEl = container.querySelector('#paSummaryPerPeriod');
    if (perPeriodEl) {
        perPeriodEl.textContent = `${fmt.formatNumber(asset.per_period_amount, 2)} ${asset.currency ?? ''}`;
    }

    const periodsEl = container.querySelector('#paSummaryPeriods');
    if (periodsEl) {
        periodsEl.textContent = `${asset.periods_recognized ?? 0} / ${asset.periods_total ?? 0}`;
    }

    const statusEl = container.querySelector('#paSummaryStatus');
    if (statusEl) {
        const statusMap = {
            active: { class: 'text-primary', label: 'Active' },
            fully_amortized: { class: 'text-success', label: 'Fully Amortized' },
            reversed: { class: 'text-danger', label: 'Reversed' }
        };
        const info = statusMap[asset.status] ?? { class: 'text-dark', label: asset.status };
        statusEl.innerHTML = `<span class="${info.class}">${info.label}</span>`;
    }
}

/**
 * Render the amortization progress bar.
 */
function renderProgressBar(asset) {
    const percent = asset.periods_total > 0
        ? Math.round((asset.periods_recognized / asset.periods_total) * 100)
        : 0;

    const percentEl = container.querySelector('#paProgressPercent');
    if (percentEl) percentEl.textContent = `${percent}%`;

    const barEl = container.querySelector('#paProgressBar');
    if (barEl) {
        barEl.style.width = `${percent}%`;
        barEl.setAttribute('aria-valuenow', percent);
    }
}

/**
 * Render the amortization schedule table.
 */
function renderAmortizationSchedule(asset) {
    const fmt = getFormatter();
    const tbody = container.querySelector('#paAmortizationBody');
    if (!tbody) return;

    // The API returns instances within the prepaid asset or an amortization_schedule array
    const schedule = asset.amortization_schedule ?? asset.instances ?? [];

    if (schedule.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3">
            <i class="fas fa-info-circle me-1"></i>No amortization schedule available
        </td></tr>`;
        return;
    }

    tbody.innerHTML = schedule.map((period, index) => {
        const statusBadge = period.recognized
            ? '<span class="badge bg-success">Recognized</span>'
            : '<span class="badge bg-warning text-dark">Pending</span>';

        const recognizedDate = period.recognized_date
            ? fmt.formatDate(period.recognized_date)
            : '—';

        const amount = period.amount != null
            ? fmt.formatNumber(period.amount, 2)
            : fmt.formatNumber(asset.per_period_amount, 2);

        const dueDate = period.due_date
            ? fmt.formatDate(period.due_date)
            : '—';

        return `<tr>
            <td>${index + 1}</td>
            <td>${dueDate}</td>
            <td>${amount} ${escHtml(asset.currency ?? '')}</td>
            <td>${statusBadge}</td>
            <td>${recognizedDate}</td>
        </tr>`;
    }).join('');
}

/**
 * Render the advance payment link if the asset is linked to a bulk advance payment.
 */
function renderAdvancePaymentLink(asset) {
    const link = container.querySelector('#paViewAdvancePaymentLink');
    if (!link) return;

    if (asset.bulk_advance_payment_id) {
        link.classList.remove('d-none');
        link.href = `#/expenses/recurring`;
        link.title = `View Bulk Advance Payment #${asset.bulk_advance_payment_id}`;
    }
}

/**
 * Render the accounting entries table.
 */
function renderAccountingEntries(entries) {
    const fmt = getFormatter();
    const tbody = container.querySelector('#paAccountingEntriesBody');
    if (!tbody) return;

    if (entries.length === 0) {
        renderAccountingEntriesEmpty('No accounting entries found.');
        return;
    }

    tbody.innerHTML = entries.map(entry => {
        const entryTypeLabel = formatEntryType(entry.entry_type);
        const amountFc = entry.amount_fc != null
            ? fmt.formatNumber(entry.amount_fc, 2)
            : '—';
        const amountBc = entry.amount_bc != null
            ? fmt.formatNumber(entry.amount_bc, 2)
            : '—';

        return `<tr>
            <td>${fmt.formatDate(entry.entry_date ?? '')}</td>
            <td>${entryTypeLabel}</td>
            <td>${escHtml(entry.debit_account ?? '—')}</td>
            <td>${escHtml(entry.credit_account ?? '—')}</td>
            <td>${amountFc} ${escHtml(entry.currency ?? '')}</td>
            <td>${amountBc}</td>
            <td>${escHtml(entry.description ?? '—')}</td>
        </tr>`;
    }).join('');
}

/**
 * Show empty state for accounting entries.
 */
function renderAccountingEntriesEmpty(message) {
    const tbody = container.querySelector('#paAccountingEntriesBody');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-3">
        <i class="fas fa-info-circle me-1"></i>${escHtml(message)}
    </td></tr>`;
}

/**
 * Format entry_type enum value for display.
 */
function formatEntryType(entryType) {
    const typeMap = {
        prepaid_asset_creation: '<span class="badge bg-primary">Asset Creation</span>',
        expense_recognition: '<span class="badge bg-success">Expense Recognition</span>',
        prepaid_asset_reversal: '<span class="badge bg-danger">Reversal</span>'
    };
    return typeMap[entryType] ?? `<span class="badge bg-secondary">${escHtml(entryType ?? 'Unknown')}</span>`;
}

/**
 * Show an error message on the page.
 */
function showError(message) {
    const subtitle = container.querySelector('#paDetailSubtitle');
    if (subtitle) {
        subtitle.innerHTML = `<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>${escHtml(message)}</span>`;
    }
}
