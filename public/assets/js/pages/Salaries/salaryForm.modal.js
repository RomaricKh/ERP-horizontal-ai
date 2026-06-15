// assets/js/pages/Salaries/salaryForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { formatAmount } from '../../utils/currency.js';
import { populatePayeeSelect } from '../../utils/payeeSelect.js';
import { attachRateAutoFetch } from '../../utils/exchangeRateHelper.js';

let modalRoot;
let unregister;
let cleanupRateAutoFetch;
let currentSalaryId = null;

const F = {
    title:        () => document.getElementById('salaryModalTitle'),
    employee:     () => document.getElementById('inputSalaryEmployee'),
    periodStart:  () => document.getElementById('inputSalaryPeriodStart'),
    periodEnd:    () => document.getElementById('inputSalaryPeriodEnd'),
    currency:     () => document.getElementById('inputSalaryCurrency'),
    exchangeRate: () => document.getElementById('inputSalaryExchangeRate'),
    grossAmountFc:() => document.getElementById('inputSalaryGrossAmountFc'),
    taxFc:        () => document.getElementById('inputSalaryTaxFc'),
    netAmountFc:  () => document.getElementById('inputSalaryNetAmountFc'),
    notes:        () => document.getElementById('inputSalaryNotes'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot       = root;
    currentSalaryId = data?.salary_id ?? null;

    F.title().textContent = currentSalaryId ? 'Edit Salary' : 'Add Salary';

    // Populate employee (payee) select
    await populatePayeeSelect(F.employee(), { typeFilter: 'Employee' });

    if (currentSalaryId) {
        await fetchAndFill(currentSalaryId);
    }

    // Auto-calculate net_amount_fc when gross or tax changes
    F.grossAmountFc().addEventListener('input', recalcNet);
    F.taxFc().addEventListener('input', recalcNet);

    // Auto-fetch exchange rate when currency changes
    cleanupRateAutoFetch = attachRateAutoFetch(F.currency(), F.exchangeRate());

    unregister = registerPageActions({
        'salaries.save': async () => {
            if (!validateForm()) return;

            const payload = {
                payee_id:        F.employee().value         || null,
                period_start:    F.periodStart().value,
                period_end:      F.periodEnd().value,
                currency:        F.currency().value,
                exchange_rate:   parseFloat(F.exchangeRate().value),
                gross_amount_fc: parseFloat(F.grossAmountFc().value),
                tax_fc:          F.taxFc().value !== '' ? parseFloat(F.taxFc().value) : 0,
                notes:           F.notes().value.trim() || null,
            };

            const url    = currentSalaryId ? `/api/salaries/${currentSalaryId}` : '/api/salaries';
            const method = currentSalaryId ? 'PUT' : 'POST';

            try {
                const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Operation failed');
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('salaries.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save salary record');
            }
        }
    });
}

export function unmount() {
    cleanupRateAutoFetch?.();
    cleanupRateAutoFetch = null;
    unregister?.();
    unregister = null;
    currentSalaryId = null;
}

async function fetchAndFill(salaryId) {
    try {
        const res  = await apiFetch(`/api/salaries/${salaryId}`);
        const data = await res.json();
        if (!data.success || !data.data?.salary) return;

        const record = data.data.salary;

        F.employee().value     = record.payee_id        ?? '';
        F.periodStart().value  = record.period_start    ?? '';
        F.periodEnd().value    = record.period_end      ?? '';
        F.currency().value     = record.currency        ?? '';
        F.exchangeRate().value = record.exchange_rate   ?? '';
        F.grossAmountFc().value= record.gross_amount_fc ?? '';
        F.taxFc().value        = record.tax_fc          ?? '';
        F.notes().value        = record.notes           ?? '';

        recalcNet();
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

function recalcNet() {
    const gross = parseFloat(F.grossAmountFc().value) || 0;
    const tax   = parseFloat(F.taxFc().value)         || 0;
    F.netAmountFc().value = formatAmount(gross - tax);
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.periodStart().value) {
        markInvalid(F.periodStart(), 'Period start is required');
        valid = false;
    }
    if (!F.periodEnd().value) {
        markInvalid(F.periodEnd(), 'Period end is required');
        valid = false;
    }
    if (!F.currency().value) {
        markInvalid(F.currency(), 'Currency is required');
        valid = false;
    }
    if (!F.exchangeRate().value) {
        markInvalid(F.exchangeRate(), 'Exchange rate is required');
        valid = false;
    }
    if (!F.grossAmountFc().value) {
        markInvalid(F.grossAmountFc(), 'Gross amount is required');
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
