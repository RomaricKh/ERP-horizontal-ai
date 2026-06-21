// assets/js/pages/Salaries/salaryForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { formatAmount } from '../../utils/currency.js';
import { populatePayeeSelect } from '../../utils/payeeSelect.js';
import { attachRateAutoFetch } from '../../utils/exchangeRateHelper.js';
import { openModal, MODAL_SCOPE } from '../../components/modals.js';

let modalRoot;
let unregister;
let cleanupRateAutoFetch;
let currentSalaryId = null;
let currentPayeeId = null;
let hasExistingRecurrence = false;

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

// Recurrence field accessors
const RF = {
    section:      () => document.getElementById('salaryRecurrenceSection'),
    toggle:       () => document.getElementById('inputSalaryRecurrenceToggle'),
    fields:       () => document.getElementById('salaryRecurrenceFields'),
    frequency:    () => document.getElementById('inputRecurrenceFrequency'),
    startDate:    () => document.getElementById('inputRecurrenceStartDate'),
    endDate:      () => document.getElementById('inputRecurrenceEndDate'),
    grossAmountFc:() => document.getElementById('inputRecurrenceGrossAmountFc'),
    taxFc:        () => document.getElementById('inputRecurrenceTaxFc'),
    currency:     () => document.getElementById('inputRecurrenceCurrency'),
    exchangeRate: () => document.getElementById('inputRecurrenceExchangeRate'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot       = root;
    currentSalaryId = data?.salary_id ?? null;
    currentPayeeId  = data?.payee_id ?? null;
    hasExistingRecurrence = false;

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

    // --- Recurrence Configuration ---
    // Show recurrence section (it's hidden by default in HTML until JS activates it)
    const recSection = RF.section();
    if (recSection) recSection.style.display = '';

    // Recurrence toggle handler
    const recurrenceToggle = RF.toggle();
    const recurrenceFields = RF.fields();
    if (recurrenceToggle) {
        recurrenceToggle.addEventListener('change', async () => {
            if (recurrenceToggle.checked) {
                recurrenceFields.style.display = '';
                // Pre-populate recurrence fields from current salary values if not already set
                prepopulateRecurrenceFields();
            } else {
                // If editing and has an existing recurrence, prompt and DELETE
                if (hasExistingRecurrence && currentPayeeId) {
                    const confirmed = confirm('Disable recurring salary schedule for this employee? All future instances will be cancelled.');
                    if (confirmed) {
                        try {
                            await apiFetch(`/api/salaries/employees/${currentPayeeId}/recurrence`, { method: 'DELETE' });
                            hasExistingRecurrence = false;
                        } catch (err) {
                            console.warn('Failed to delete salary recurrence:', err);
                        }
                    } else {
                        // User cancelled — re-check the toggle
                        recurrenceToggle.checked = true;
                        return;
                    }
                }
                recurrenceFields.style.display = 'none';
            }
        });
    }

    // If editing, track payee_id from the loaded record and load existing recurrence
    if (currentSalaryId && currentPayeeId) {
        await loadExistingRecurrence(currentPayeeId);
    }

    // Track employee select changes to update currentPayeeId
    F.employee().addEventListener('change', () => {
        currentPayeeId = F.employee().value || null;
    });

    unregister = registerPageActions({
        'salaries.create-employee': async () => {
            await openModal({
                scope: MODAL_SCOPE.STACKED,
                template: 'html/People/personForm.html',
                controller: '../pages/People/personForm.modal.js',
                data: {
                    onComplete: async (newPerson) => {
                        await populatePayeeSelect(F.employee(), { typeFilter: 'Employee' });
                        F.employee().value = newPerson.id;
                        F.employee().dispatchEvent(new Event('change'));
                    }
                }
            });
        },

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

                // Handle recurrence save after salary is saved
                const payeeId = payload.payee_id || currentPayeeId;
                if (payeeId) {
                    await handleRecurrenceSave(payeeId);
                }

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
    currentPayeeId = null;
    hasExistingRecurrence = false;
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

        // Track payee_id from the loaded record
        currentPayeeId = record.payee_id || null;

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

    // Recurrence date validation: end_date >= start_date
    const recurrenceToggle = RF.toggle();
    if (recurrenceToggle && recurrenceToggle.checked) {
        const startDate = RF.startDate()?.value;
        const endDate = RF.endDate()?.value;
        if (endDate && startDate && endDate < startDate) {
            markInvalid(RF.endDate(), 'End date must be on or after start date');
            valid = false;
        }
        if (!RF.frequency()?.value) {
            markInvalid(RF.frequency(), 'Frequency is required');
            valid = false;
        }
        if (!startDate) {
            markInvalid(RF.startDate(), 'Start date is required');
            valid = false;
        }
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

/**
 * Pre-populate recurrence fields from the current salary form values.
 * Requirement 9.7: pre-populate with current salary record's values.
 * Requirement 9.5: default start_date to today if not provided.
 */
function prepopulateRecurrenceFields() {
    const grossEl = RF.grossAmountFc();
    const taxEl = RF.taxFc();
    const currEl = RF.currency();
    const rateEl = RF.exchangeRate();
    const startEl = RF.startDate();

    // Only pre-populate if the field is currently empty (avoid overwriting existing schedule data)
    if (grossEl && !grossEl.value) {
        grossEl.value = F.grossAmountFc().value || '';
    }
    if (taxEl && !taxEl.value) {
        taxEl.value = F.taxFc().value || '';
    }
    if (currEl && !currEl.value) {
        currEl.value = F.currency().value || '';
    }
    if (rateEl && !rateEl.value) {
        rateEl.value = F.exchangeRate().value || '';
    }
    // Requirement 9.5: default start_date to today
    if (startEl && !startEl.value) {
        startEl.value = new Date().toISOString().split('T')[0];
    }
}

/**
 * Load existing recurrence schedule for the given payee_id.
 * On edit, pre-populate the toggle as checked and fill in schedule data.
 */
async function loadExistingRecurrence(payeeId) {
    try {
        const res = await apiFetch(`/api/salaries/employees/${payeeId}/recurrence`);
        if (!res.ok) return;
        const json = await res.json();
        if (json.success && json.data) {
            const recurrence = json.data;
            hasExistingRecurrence = true;

            // Enable the toggle and show fields
            const toggle = RF.toggle();
            const fields = RF.fields();
            if (toggle) toggle.checked = true;
            if (fields) fields.style.display = '';

            // Populate recurrence fields from the schedule
            if (RF.frequency()) RF.frequency().value = recurrence.frequency || '';
            if (RF.startDate()) RF.startDate().value = recurrence.start_date || '';
            if (RF.endDate()) RF.endDate().value = recurrence.end_date || '';
            if (RF.grossAmountFc()) RF.grossAmountFc().value = recurrence.default_amount || '';
            if (RF.currency()) RF.currency().value = recurrence.default_currency || '';
            if (RF.exchangeRate()) RF.exchangeRate().value = recurrence.exchange_rate || '';
            // Tax field is stored in metadata or default to current salary tax
            if (RF.taxFc()) RF.taxFc().value = recurrence.tax_fc || recurrence.metadata?.tax_fc || '';
        }
    } catch (err) {
        // Silently ignore — recurrence may not exist for this employee
    }
}

/**
 * Save or update the recurrence schedule for the employee after salary save.
 * POST /api/salaries/employees/{payee_id}/recurrence
 */
async function handleRecurrenceSave(payeeId) {
    const toggle = RF.toggle();
    if (!toggle) return;

    if (toggle.checked) {
        const frequency = RF.frequency()?.value;
        const startDate = RF.startDate()?.value;
        const endDate = RF.endDate()?.value;

        if (!frequency || !startDate) return; // Skip if required fields not filled

        // default_amount should be NET (gross - tax) since SettlementEngine targets net_amount_bc
        const grossAmount = parseFloat(RF.grossAmountFc()?.value) || parseFloat(F.grossAmountFc().value) || 0;
        const taxAmount = parseFloat(RF.taxFc()?.value) || parseFloat(F.taxFc().value) || 0;
        const netAmount = grossAmount - taxAmount;

        const recPayload = {
            frequency,
            start_date: startDate,
            end_date: endDate || null,
            default_amount: netAmount,
            default_currency: RF.currency()?.value || F.currency().value,
            exchange_rate: parseFloat(RF.exchangeRate()?.value) || parseFloat(F.exchangeRate().value) || 1,
            gross_amount_fc: grossAmount,
            tax_fc: taxAmount,
        };

        try {
            const res = await apiFetch(`/api/salaries/employees/${payeeId}/recurrence`, {
                method: 'POST',
                body: JSON.stringify(recPayload)
            });
            const json = await res.json();
            if (!res.ok || !json.success) {
                console.warn('Salary recurrence save failed:', json.message);
            }
        } catch (err) {
            console.warn('Salary recurrence save error:', err);
        }
    }
}
