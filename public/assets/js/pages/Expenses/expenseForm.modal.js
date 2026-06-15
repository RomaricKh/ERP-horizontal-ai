// assets/js/pages/Expenses/expenseForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { calcBc, formatAmount } from '../../utils/currency.js';
import { populatePayeeSelect } from '../../utils/payeeSelect.js';
import { attachRateAutoFetch } from '../../utils/exchangeRateHelper.js';

let modalRoot;
let unregister;
let cleanupRateAutoFetch;
let currentExpenseId = null;
let currentAttachmentPath = null;

const F = {
    title:        () => document.getElementById('expenseModalTitle'),
    payee:        () => document.getElementById('inputExpensePayee'),
    category:     () => document.getElementById('inputExpenseCategory'),
    date:         () => document.getElementById('inputExpenseDate'),
    currency:     () => document.getElementById('inputExpenseCurrency'),
    exchangeRate: () => document.getElementById('inputExpenseExchangeRate'),
    amountFc:     () => document.getElementById('inputExpenseAmountFc'),
    amountBc:     () => document.getElementById('inputExpenseAmountBc'),
    notes:        () => document.getElementById('inputExpenseNotes'),
    referenceNumber: () => document.getElementById('inputExpenseReferenceNumber'),
    referenceDate:   () => document.getElementById('inputExpenseReferenceDate'),
    attachment:      () => document.getElementById('inputExpenseAttachment'),
    currentAttachment: () => document.getElementById('currentAttachment'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot        = root;
    currentExpenseId = data?.expense_id ?? null;

    F.title().textContent = currentExpenseId ? 'Edit Expense' : 'Add Expense';

    // Populate payee select
    await populatePayeeSelect(F.payee());

    // Populate category select
    await loadCategories();

    if (currentExpenseId) {
        await fetchAndFill(currentExpenseId);
    }

    // Auto-calculate amount_bc when amount_fc or exchange_rate changes
    F.amountFc().addEventListener('input', recalcBc);
    F.exchangeRate().addEventListener('input', recalcBc);

    // Sync recurrence start_date with expense_date when user changes expense_date
    F.date().addEventListener('change', () => {
        const recStartDate = document.getElementById('inputExpRecurrenceStartDate');
        const recurrenceToggle = document.getElementById('inputExpenseRecurrenceToggle');
        // Only auto-sync if recurrence fields aren't already populated (not editing existing schedule)
        if (recStartDate && !recurrenceToggle?.checked) {
            recStartDate.value = F.date().value || new Date().toISOString().split('T')[0];
        }
    });

    // Auto-fetch exchange rate when currency changes
    cleanupRateAutoFetch = attachRateAutoFetch(F.currency(), F.exchangeRate());

    // Recurrence toggle handler
    const recurrenceToggle = document.getElementById('inputExpenseRecurrenceToggle');
    const recurrenceFields = document.getElementById('expenseRecurrenceFields');
    if (recurrenceToggle) {
        recurrenceToggle.addEventListener('change', async () => {
            if (recurrenceToggle.checked) {
                recurrenceFields.style.display = '';
            } else {
                // If editing an existing expense that had recurrence, prompt and DELETE
                if (currentExpenseId) {
                    const confirmed = confirm('Disable recurring schedule for this expense?');
                    if (confirmed) {
                        try {
                            await apiFetch(`/api/expenses/${currentExpenseId}/recurrence`, { method: 'DELETE' });
                        } catch (err) {
                            console.warn('Failed to delete recurrence:', err);
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

    // Default recurrence start date to expense_date (fallback to today)
    const recStartDate = document.getElementById('inputExpRecurrenceStartDate');
    if (recStartDate && !recStartDate.value) {
        const expenseDate = F.date().value;
        recStartDate.value = expenseDate || new Date().toISOString().split('T')[0];
    }

    // If editing, load existing recurrence for this expense
    if (currentExpenseId) {
        await loadExistingRecurrence(currentExpenseId);
    }

    unregister = registerPageActions({
        'expenses.save': async () => {
            if (!validateForm()) return;

            // Handle file upload first if a file is selected
            let attachmentPath = currentAttachmentPath;
            const fileInput = F.attachment();
            if (fileInput && fileInput.files.length > 0) {
                try {
                    attachmentPath = await uploadAttachment(fileInput.files[0]);
                } catch (err) {
                    showFormError(err.message || 'File upload failed');
                    return;
                }
            }

            const payload = {
                payee_id:             F.payee().value        || null,
                expense_category_id:  F.category().value     || null,
                expense_date:         F.date().value,
                currency:             F.currency().value,
                exchange_rate:        parseFloat(F.exchangeRate().value),
                amount_fc:            parseFloat(F.amountFc().value),
                notes:                F.notes().value.trim() || null,
                reference_number:     F.referenceNumber().value.trim() || null,
                reference_date:       F.referenceDate().value || null,
                attachment_path:      attachmentPath || null,
            };

            const url    = currentExpenseId ? `/api/expenses/${currentExpenseId}` : '/api/expenses';
            const method = currentExpenseId ? 'PUT' : 'POST';

            try {
                const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Operation failed');

                // Handle recurrence save/delete after expense is saved
                const savedExpenseId = json.data?.id || currentExpenseId;
                if (savedExpenseId) {
                    await handleRecurrenceSave(savedExpenseId);
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('expenses.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save expense');
            }
        }
    });
}

export function unmount() {
    cleanupRateAutoFetch?.();
    cleanupRateAutoFetch = null;
    unregister?.();
    unregister = null;
    currentExpenseId = null;
    currentAttachmentPath = null;
}

async function loadCategories() {
    try {
        const res  = await apiFetch('/api/expenses/categories');
        const json = await res.json();
        const cats = json.success ? (json.data?.categories ?? []) : [];

        const select = F.category();
        // Keep the blank option, remove any previously loaded options
        select.innerHTML = '<option value="">— Select category —</option>';

        cats.forEach(cat => {
            const opt = document.createElement('option');
            opt.value       = cat.id;
            opt.textContent = cat.name;
            select.appendChild(opt);
        });
    } catch (err) {
        console.error('loadCategories failed:', err);
    }
}

async function fetchAndFill(expenseId) {
    try {
        const res  = await apiFetch(`/api/expenses/${expenseId}`);
        const data = await res.json();
        if (!data.success) return;

        const record = data.data?.expense ?? data.data;
        if (!record) return;

        F.payee().value        = record.payee_id             ?? '';
        F.category().value     = record.expense_category_id  ?? '';
        F.date().value         = record.expense_date         ?? '';
        F.currency().value     = record.currency             ?? '';
        F.exchangeRate().value = record.exchange_rate        ?? '';
        F.amountFc().value     = record.amount_fc            ?? '';
        F.amountBc().value     = formatAmount(record.amount_bc);
        F.notes().value        = record.notes                ?? '';
        F.referenceNumber().value = record.reference_number  ?? '';
        F.referenceDate().value   = record.reference_date    ?? '';

        // Show current attachment if exists
        currentAttachmentPath = record.attachment_path || null;
        if (currentAttachmentPath) {
            F.currentAttachment().innerHTML = `<a href="${currentAttachmentPath}" target="_blank"><i class="fas fa-file"></i> View current attachment</a>`;
        }
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

async function uploadAttachment(file) {
    const formData = new FormData();
    formData.append('file', file);

    const res = await apiFetch('/api/uploads/expenses', {
        method: 'POST',
        body: formData,
        headers: {} // Let browser set Content-Type with boundary
    });
    const json = await res.json();
    if (!res.ok || !json.success) {
        throw new Error(json.message || 'Upload failed');
    }
    return json.data.path;
}

function recalcBc() {
    const fc   = parseFloat(F.amountFc().value)     || 0;
    const rate = parseFloat(F.exchangeRate().value) || 0;
    F.amountBc().value = formatAmount(calcBc(fc, rate));
}

async function loadExistingRecurrence(expenseId) {
    try {
        const res = await apiFetch(`/api/expenses/${expenseId}/recurrence`);
        if (!res.ok) return;
        const json = await res.json();
        if (json.success && json.data) {
            const recurrence = json.data;
            const toggle = document.getElementById('inputExpenseRecurrenceToggle');
            const fields = document.getElementById('expenseRecurrenceFields');
            if (toggle) toggle.checked = true;
            if (fields) fields.style.display = '';

            const freqEl = document.getElementById('inputExpRecurrenceFrequency');
            const startEl = document.getElementById('inputExpRecurrenceStartDate');
            const endEl = document.getElementById('inputExpRecurrenceEndDate');
            if (freqEl) freqEl.value = recurrence.frequency || '';
            if (startEl) startEl.value = recurrence.start_date || '';
            if (endEl) endEl.value = recurrence.end_date || '';
        }
    } catch (err) {
        // Silently ignore — recurrence may not exist
    }
}

async function handleRecurrenceSave(expenseId) {
    const toggle = document.getElementById('inputExpenseRecurrenceToggle');
    if (!toggle) return;

    if (toggle.checked) {
        const frequency = document.getElementById('inputExpRecurrenceFrequency')?.value;
        const startDate = document.getElementById('inputExpRecurrenceStartDate')?.value;
        const endDate = document.getElementById('inputExpRecurrenceEndDate')?.value;

        if (!frequency || !startDate) return; // Skip if not filled

        const recPayload = {
            frequency,
            start_date: startDate,
            end_date: endDate || null,
            default_amount: parseFloat(F.amountFc().value) || 0,
            default_currency: F.currency().value,
            exchange_rate: parseFloat(F.exchangeRate().value) || 1,
        };

        try {
            const res = await apiFetch(`/api/expenses/${expenseId}/recurrence`, {
                method: 'POST',
                body: JSON.stringify(recPayload)
            });
            const json = await res.json();
            if (!res.ok || !json.success) {
                console.warn('Recurrence save failed:', json.message);
            }
        } catch (err) {
            console.warn('Recurrence save error:', err);
        }
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.date().value) {
        markInvalid(F.date(), 'Expense date is required');
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
    if (!F.amountFc().value) {
        markInvalid(F.amountFc(), 'Amount is required');
        valid = false;
    }

    // Recurrence date validation: end_date >= start_date
    const recurrenceToggle = document.getElementById('inputExpenseRecurrenceToggle');
    if (recurrenceToggle && recurrenceToggle.checked) {
        const startDate = document.getElementById('inputExpRecurrenceStartDate')?.value;
        const endDate = document.getElementById('inputExpRecurrenceEndDate')?.value;
        if (endDate && startDate && endDate < startDate) {
            markInvalid(document.getElementById('inputExpRecurrenceEndDate'), 'End date must be on or after start date');
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
