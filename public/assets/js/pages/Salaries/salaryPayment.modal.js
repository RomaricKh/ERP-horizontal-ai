// assets/js/pages/Salaries/salaryPayment.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { formatAmount, calcBc } from '../../utils/currency.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let instanceData = null;

const F = {
    employeeName:   () => document.getElementById('instanceEmployeeName'),
    dueDate:        () => document.getElementById('instanceDueDate'),
    currency:       () => document.getElementById('instanceCurrency'),
    scheduledGross: () => document.getElementById('instanceScheduledGross'),
    scheduledTax:   () => document.getElementById('instanceScheduledTax'),
    scheduledNet:   () => document.getElementById('instanceScheduledNet'),
    grossAmount:    () => document.getElementById('inputSalPayGrossAmount'),
    taxAmount:      () => document.getElementById('inputSalPayTaxAmount'),
    netAmount:      () => document.getElementById('inputSalPayNetAmount'),
    exchangeRate:   () => document.getElementById('inputSalPayExchangeRate'),
    amountBc:       () => document.getElementById('inputSalPayAmountBc'),
    account:        () => document.getElementById('inputSalPayAccount'),
    method:         () => document.getElementById('inputSalPayMethod'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    instanceData = data?.instance ?? null;

    if (!instanceData) {
        console.error('salaryPayment.modal: No instance data provided');
        return;
    }

    const fmt = getFormatter();

    // Fill read-only summary
    F.employeeName().textContent = instanceData.entity_name || instanceData.employee_name || '—';
    F.dueDate().textContent = fmt.formatDate(instanceData.due_date) || instanceData.due_date || '—';
    F.currency().textContent = instanceData.currency || '—';

    const scheduledGross = parseFloat(instanceData.scheduled_amount) || 0;
    const scheduledTax = parseFloat(instanceData.tax_amount ?? instanceData.metadata?.tax_fc ?? 0);
    const scheduledNet = scheduledGross - scheduledTax;

    F.scheduledGross().textContent = fmt.formatNumber(scheduledGross, 2);
    F.scheduledTax().textContent = fmt.formatNumber(scheduledTax, 2);
    F.scheduledNet().textContent = fmt.formatNumber(scheduledNet, 2);

    // Pre-fill editable fields (Requirement 12.4: allow editing before marking paid)
    F.grossAmount().value = scheduledGross.toFixed(2);
    F.taxAmount().value = scheduledTax.toFixed(2);
    F.exchangeRate().value = parseFloat(instanceData.exchange_rate) || parseFloat(instanceData.metadata?.exchange_rate) || 1;

    recalcNet();

    // Load accounts filtered by currency
    await loadAccounts(instanceData.currency);

    // Bind recalculation listeners
    F.grossAmount().addEventListener('input', recalcNet);
    F.taxAmount().addEventListener('input', recalcNet);
    F.exchangeRate().addEventListener('input', recalcNet);

    unregister = registerPageActions({
        'salary-payment.confirm': async () => {
            if (!validateForm()) return;

            const grossAmount = parseFloat(F.grossAmount().value);
            const taxAmount = parseFloat(F.taxAmount().value) || 0;
            const netAmount = grossAmount - taxAmount;
            const exchangeRate = parseFloat(F.exchangeRate().value) || 1;
            const accountId = F.account().value;
            const method = F.method().value;

            try {
                const res = await apiFetch(`/api/recurring-instances/${instanceData.id}/status`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        status: 'paid',
                        account_id: parseInt(accountId, 10),
                        actual_amount: netAmount,
                        gross_amount: grossAmount,
                        tax_amount: taxAmount,
                        exchange_rate: exchangeRate,
                        method: method
                    })
                });
                const json = await res.json();
                if (!res.ok || !json.success) {
                    showFormError(json.message || 'Failed to mark salary as paid');
                    return;
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('recurring-salaries.updated'));
            } catch (err) {
                console.error('Salary payment confirm failed:', err);
                showFormError('An error occurred while processing payment');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    instanceData = null;
}

function recalcNet() {
    const gross = parseFloat(F.grossAmount().value) || 0;
    const tax = parseFloat(F.taxAmount().value) || 0;
    const net = gross - tax;
    const rate = parseFloat(F.exchangeRate().value) || 1;
    const netBc = calcBc(net, rate);

    F.netAmount().value = formatAmount(net);
    if (F.amountBc()) {
        F.amountBc().value = formatAmount(netBc);
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
        select.innerHTML = '';

        if (accounts.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.disabled = true;
            opt.selected = true;
            opt.textContent = currency
                ? `No accounts for ${currency}`
                : 'No accounts available';
            select.appendChild(opt);
        } else {
            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = '— Select account —';
            select.appendChild(defaultOpt);

            accounts.forEach(acc => {
                const opt = document.createElement('option');
                opt.value = acc.id;
                opt.textContent = `${acc.name} (${acc.currency})`;
                opt.dataset.currency = acc.currency;
                select.appendChild(opt);
            });
        }
    } catch (err) {
        console.error('loadAccounts failed:', err);
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.grossAmount().value || parseFloat(F.grossAmount().value) <= 0) {
        markInvalid(F.grossAmount(), 'Gross amount is required and must be > 0');
        valid = false;
    }
    if (!F.exchangeRate().value || parseFloat(F.exchangeRate().value) <= 0) {
        markInvalid(F.exchangeRate(), 'Exchange rate is required and must be > 0');
        valid = false;
    }
    if (!F.account().value) {
        markInvalid(F.account(), 'Source account is required');
        valid = false;
    }
    if (!F.method().value) {
        markInvalid(F.method(), 'Payment method is required');
        valid = false;
    }

    return valid;
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
