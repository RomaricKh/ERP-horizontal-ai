// assets/js/pages/Expenses/bulkAdvance.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { formatAmount } from '../../utils/currency.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let schedules = [];
let entityType = 'expense_category';
let preselectedScheduleId = null;

const F = {
    schedule:      () => document.getElementById('inputBulkSchedule'),
    periods:       () => document.getElementById('inputBulkPeriods'),
    account:       () => document.getElementById('inputBulkAccount'),
    paymentMethod: () => document.getElementById('inputBulkPaymentMethod'),
    configSection: () => document.getElementById('bulkAdvanceConfig'),
    summarySection:() => document.getElementById('bulkAdvanceSummary'),
    confirmBtn:    () => document.getElementById('btnConfirmBulkAdvance'),
    // Summary fields
    summaryScheduleName: () => document.getElementById('summaryScheduleName'),
    summaryPeriods:      () => document.getElementById('summaryPeriods'),
    summaryPerPeriod:    () => document.getElementById('summaryPerPeriod'),
    summaryTotal:        () => document.getElementById('summaryTotal'),
    summaryDateRange:    () => document.getElementById('summaryDateRange'),
    summaryAccount:      () => document.getElementById('summaryAccount'),
    summaryMethod:       () => document.getElementById('summaryMethod'),
    summaryNote:         () => document.getElementById('summaryNote'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    entityType = data?.entity_type ?? 'expense_category';
    preselectedScheduleId = data?.schedule_id ?? null;

    // Update title based on entity type
    const title = modalRoot.querySelector('#bulkAdvanceModalTitle');
    if (title && entityType === 'salary') {
        title.innerHTML = '<i class="fas fa-forward me-2"></i>Pay Multiple Salary Periods in Advance';
    }

    await loadSchedules();
    await loadAccounts();

    // If a schedule was preselected, set it
    if (preselectedScheduleId) {
        F.schedule().value = String(preselectedScheduleId);
        // Filter accounts by schedule currency
        const schedule = schedules.find(s => String(s.id) === String(preselectedScheduleId));
        if (schedule) {
            await loadAccounts(schedule.default_currency);
        }
    }

    // When schedule changes, filter accounts by currency
    F.schedule().addEventListener('change', async () => {
        const scheduleId = F.schedule().value;
        const schedule = schedules.find(s => String(s.id) === String(scheduleId));
        if (schedule) {
            await loadAccounts(schedule.default_currency);
        } else {
            await loadAccounts();
        }
        hideSummary();
    });

    // When periods change, hide summary (needs recalculation)
    F.periods().addEventListener('input', hideSummary);

    unregister = registerPageActions({
        'bulk-advance.calculate': async () => {
            if (!validateConfig()) return;
            showSummary();
        },

        'bulk-advance.confirm': async () => {
            await executeBulkAdvance();
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    schedules = [];
    preselectedScheduleId = null;
}

async function loadSchedules() {
    try {
        const res = await apiFetch(`/api/recurrences?entity_type=${encodeURIComponent(entityType)}`);
        const json = await res.json();
        if (!json.success) return;

        schedules = (json.data?.schedules ?? []).filter(s => s.is_active == 1 || s.is_active === true);

        const select = F.schedule();
        select.innerHTML = '<option value="">— Select a schedule —</option>';

        schedules.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = `${s.entity_name || 'Schedule #' + s.id} — ${s.frequency} (${formatAmount(s.default_amount)} ${s.default_currency})`;
            opt.dataset.amount = s.default_amount;
            opt.dataset.currency = s.default_currency;
            opt.dataset.frequency = s.frequency;
            opt.dataset.startDate = s.start_date;
            opt.dataset.endDate = s.end_date || '';
            select.appendChild(opt);
        });
    } catch (err) {
        console.error('loadSchedules failed:', err);
    }
}

async function loadAccounts(currency) {
    try {
        const url = currency
            ? `/api/accounts?currency=${encodeURIComponent(currency)}`
            : '/api/accounts';
        const res = await apiFetch(url);
        const json = await res.json();
        const accounts = json.success ? (json.data?.accounts ?? []) : [];

        const select = F.account();
        const currentValue = select.value;
        select.innerHTML = '';

        if (accounts.length === 0 && currency) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.disabled = true;
            opt.selected = true;
            opt.textContent = `No accounts for ${currency}`;
            select.appendChild(opt);
        } else {
            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = '— Select account —';
            select.appendChild(defaultOpt);

            accounts.forEach(acc => {
                const opt = document.createElement('option');
                opt.value = acc.id;
                opt.textContent = `${acc.name} (${acc.currency}) — Balance: ${formatAmount(acc.balance_fc)}`;
                opt.dataset.currency = acc.currency;
                select.appendChild(opt);
            });
        }

        // Restore previous selection if still available
        if (currentValue) {
            const stillExists = select.querySelector(`option[value="${currentValue}"]`);
            if (stillExists) select.value = currentValue;
        }
    } catch (err) {
        console.error('loadAccounts failed:', err);
    }
}

function validateConfig() {
    clearValidation();
    let valid = true;

    if (!F.schedule().value) {
        markInvalid(F.schedule(), 'Please select a recurring schedule');
        valid = false;
    }

    const periods = parseInt(F.periods().value, 10);
    if (!periods || periods < 2 || periods > 12) {
        markInvalid(F.periods(), 'Number of periods must be between 2 and 12');
        valid = false;
    }

    if (!F.account().value) {
        markInvalid(F.account(), 'Please select a source account');
        valid = false;
    }

    if (!F.paymentMethod().value) {
        markInvalid(F.paymentMethod(), 'Please select a payment method');
        valid = false;
    }

    return valid;
}

function showSummary() {
    const scheduleId = F.schedule().value;
    const schedule = schedules.find(s => String(s.id) === String(scheduleId));
    if (!schedule) return;

    const periods = parseInt(F.periods().value, 10);
    const perPeriodAmount = parseFloat(schedule.default_amount);
    const totalAmount = perPeriodAmount * periods;
    const currency = schedule.default_currency;
    const fmt = getFormatter();

    // Calculate date range
    const dateRange = calculateDateRange(schedule, periods);

    // Populate summary
    F.summaryScheduleName().textContent = schedule.entity_name || '—';
    F.summaryPeriods().textContent = `${periods} periods (${schedule.frequency})`;
    F.summaryPerPeriod().textContent = `${fmt.formatNumber(perPeriodAmount, 2)} ${currency}`;
    F.summaryTotal().textContent = `${fmt.formatNumber(totalAmount, 2)} ${currency}`;
    F.summaryDateRange().textContent = dateRange;

    // Account and method
    const accountSelect = F.account();
    const selectedAccountOpt = accountSelect.options[accountSelect.selectedIndex];
    F.summaryAccount().textContent = selectedAccountOpt ? selectedAccountOpt.textContent : '—';

    const methodSelect = F.paymentMethod();
    const selectedMethodOpt = methodSelect.options[methodSelect.selectedIndex];
    F.summaryMethod().textContent = selectedMethodOpt ? selectedMethodOpt.textContent : '—';

    // Check if end_date limits the periods
    if (schedule.end_date) {
        const endDate = new Date(schedule.end_date);
        const lastPeriodDate = getLastPeriodDate(schedule, periods);
        if (lastPeriodDate > endDate) {
            F.summaryNote().textContent =
                `Note: The schedule ends on ${fmt.formatDate(schedule.end_date)}. ` +
                `Only periods up to the end date will be paid.`;
        }
    }

    // Show summary section and confirm button
    F.summarySection().classList.remove('d-none');
    F.confirmBtn().classList.remove('d-none');
}

function hideSummary() {
    F.summarySection().classList.add('d-none');
    F.confirmBtn().classList.add('d-none');
}

function calculateDateRange(schedule, periods) {
    const fmt = getFormatter();
    const today = new Date();
    const frequency = schedule.frequency;

    // First period starts from next due date (approximately today or next period)
    let startDate = new Date(today);
    let endDate = new Date(today);

    // Calculate end date based on frequency and periods
    switch (frequency) {
        case 'weekly':
            endDate.setDate(endDate.getDate() + (7 * periods));
            break;
        case 'bi-weekly':
            endDate.setDate(endDate.getDate() + (14 * periods));
            break;
        case 'monthly':
            endDate.setMonth(endDate.getMonth() + periods);
            break;
        case 'quarterly':
            endDate.setMonth(endDate.getMonth() + (3 * periods));
            break;
        case 'yearly':
            endDate.setFullYear(endDate.getFullYear() + periods);
            break;
        default:
            endDate.setMonth(endDate.getMonth() + periods);
    }

    const startStr = fmt.formatDate(startDate.toISOString().split('T')[0]) || startDate.toISOString().split('T')[0];
    const endStr = fmt.formatDate(endDate.toISOString().split('T')[0]) || endDate.toISOString().split('T')[0];

    return `${startStr} → ${endStr}`;
}

function getLastPeriodDate(schedule, periods) {
    const today = new Date();
    let lastDate = new Date(today);

    switch (schedule.frequency) {
        case 'weekly':
            lastDate.setDate(lastDate.getDate() + (7 * periods));
            break;
        case 'bi-weekly':
            lastDate.setDate(lastDate.getDate() + (14 * periods));
            break;
        case 'monthly':
            lastDate.setMonth(lastDate.getMonth() + periods);
            break;
        case 'quarterly':
            lastDate.setMonth(lastDate.getMonth() + (3 * periods));
            break;
        case 'yearly':
            lastDate.setFullYear(lastDate.getFullYear() + periods);
            break;
    }

    return lastDate;
}

async function executeBulkAdvance() {
    const scheduleId = F.schedule().value;
    const periods = parseInt(F.periods().value, 10);
    const accountId = F.account().value;
    const paymentMethod = F.paymentMethod().value;

    // Disable confirm button to prevent double-click
    const confirmBtn = F.confirmBtn();
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';

    try {
        const res = await apiFetch('/api/recurring-instances/bulk-advance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                schedule_id: parseInt(scheduleId, 10),
                periods: periods,
                account_id: parseInt(accountId, 10),
                payment_method: paymentMethod
            })
        });

        const json = await res.json();

        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Bulk advance payment failed');
        }

        // Close modal and dispatch update event
        bootstrap.Modal.getInstance(modalRoot).hide();

        // Dispatch event based on entity type
        const eventName = entityType === 'salary'
            ? 'recurring-salaries.updated'
            : 'recurring-expenses.updated';
        document.dispatchEvent(new CustomEvent(eventName));

    } catch (err) {
        console.error('Bulk advance payment failed:', err);
        showFormError(err.message || 'Unable to process bulk advance payment');
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Payment';
    }
}

function clearValidation() {
    modalRoot.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
    modalRoot.querySelector('.modal-footer .form-error')?.remove();
}

function markInvalid(input, message) {
    if (!input) return;
    input.classList.add('is-invalid');
    let fb = input.parentElement.querySelector('.invalid-feedback');
    if (!fb) {
        fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        input.parentElement.appendChild(fb);
    }
    fb.textContent = message;
}

function showFormError(message) {
    const footer = modalRoot.querySelector('.modal-footer');
    let el = footer?.querySelector('.form-error');
    if (!el) {
        el = document.createElement('div');
        el.className = 'form-error text-danger me-auto';
        footer?.prepend(el);
    }
    el.textContent = message;
}
