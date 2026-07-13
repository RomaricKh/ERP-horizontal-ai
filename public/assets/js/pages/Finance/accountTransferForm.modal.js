// assets/js/pages/Finance/accountTransferForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let accountsData = [];

const F = {
    date:         () => document.getElementById('inputTransferDate'),
    fromAccount:  () => document.getElementById('selectFromAccount'),
    toAccount:    () => document.getElementById('selectToAccount'),
    amountFrom:   () => document.getElementById('inputAmountFrom'),
    amountTo:     () => document.getElementById('inputAmountTo'),
    rateFrom:     () => document.getElementById('inputRateFrom'),
    rateTo:       () => document.getElementById('inputRateTo'),
    amountBc:     () => document.getElementById('inputAmountBc'),
    amountBcDest: () => document.getElementById('inputAmountBcDest'),
    reference:    () => document.getElementById('inputReference'),
    notes:        () => document.getElementById('inputNotes'),
};

export async function mount({ modalRoot: root }) {
    modalRoot = root;
    F.date().valueAsDate = new Date();

    await loadAccounts();

    // Event Listeners
    F.amountFrom().addEventListener('input', () => calculateEquivalents(false));
    F.amountTo().addEventListener('input', () => calculateEquivalents(true)); // user manually changed received
    F.rateFrom().addEventListener('input', () => calculateEquivalents(false));
    F.rateTo().addEventListener('input', () => calculateEquivalents(false));
    F.fromAccount().addEventListener('change', () => fetchExchangeRate(true));
    F.toAccount().addEventListener('change', () => fetchExchangeRate(false));

    // Force an initial fetch for whatever might be pre-selected or empty
    fetchExchangeRate(true);
    fetchExchangeRate(false);

    unregister = registerPageActions({
        'accounts.submitTransfer': async () => {
            if (!validateForm()) return;

            // Notice we still only send "exchange_rate" to backend which represents the rate used to convert amount_from -> amount_bc
            // Because the DB only expects one exchange_rate to calculate amount_bc from amount_from.
            // The destination exchange_rate is implicitly amount_bc / amount_to.
            const payload = {
                transfer_date:   F.date().value,
                from_account_id: F.fromAccount().value,
                to_account_id:   F.toAccount().value,
                amount_from:     parseFloat(F.amountFrom().value) || 0,
                amount_to:       parseFloat(F.amountTo().value) || 0,
                exchange_rate:   parseFloat(F.rateFrom().value) || 1,
                amount_bc:       parseFloat(F.amountBc().value) || 0,
                reference:       F.reference().value.trim(),
                notes:           F.notes().value.trim()
            };

            try {
                const res = await apiFetch('/api/account-transfers', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Operation failed');
                
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('accounts.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save transfer');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    accountsData = [];
}

async function loadAccounts() {
    try {
        const res = await apiFetch('/api/accounts');
        const json = await res.json();
        if (!json.success) return;
        accountsData = json.data?.accounts || [];

        let options = '<option value="">-- Select Account --</option>';
        accountsData.forEach(acct => {
            options += `<option value="${acct.id}" data-currency="${acct.currency}">${acct.name} (${acct.currency})</option>`;
        });

        F.fromAccount().innerHTML = options;
        F.toAccount().innerHTML = options;
    } catch (err) {
        console.error('Failed to load accounts', err);
    }
}

async function fetchExchangeRate(isSource) {
    const acctId = isSource ? F.fromAccount().value : F.toAccount().value;
    const targetInput = isSource ? F.rateFrom() : F.rateTo();

    if (!acctId) {
        targetInput.value = '1.000000';
        calculateEquivalents();
        return;
    }

    const acct = accountsData.find(a => a.id == acctId);
    if (!acct) return;

    try {
        const res = await apiFetch(`/api/exchange-rates/latest?currency=${encodeURIComponent(acct.currency)}`);
        const json = await res.json();
        if (json.success && json.data?.rate !== undefined && json.data.rate !== null) {
            targetInput.value = json.data.rate;
        } else {
            targetInput.value = '1.000000';
        }
    } catch (err) {
        targetInput.value = '1.000000';
    }
    
    calculateEquivalents();
}

function calculateEquivalents(reverse = false) {
    const amtFrom = parseFloat(F.amountFrom().value) || 0;
    const amtToManual = parseFloat(F.amountTo().value) || 0;
    
    const rateFrom = parseFloat(F.rateFrom().value) || 1;
    const rateTo = parseFloat(F.rateTo().value) || 1;
    
    if (reverse) {
        // If the user modified the "Amount Received" manually, we should ideally adjust Amount Sent.
        // Amount BC = Amount Received * Rate To
        const bc = amtToManual * rateTo;
        F.amountBc().value = bc.toFixed(2);
        F.amountBcDest().value = bc.toFixed(2);
        
        const calculatedFrom = bc / rateFrom;
        if (isFinite(calculatedFrom)) {
            F.amountFrom().value = calculatedFrom.toFixed(2);
        }
    } else {
        // Standard flow: Amount BC = Amount Sent * Rate From
        const bc = amtFrom * rateFrom;
        F.amountBc().value = bc.toFixed(2);
        F.amountBcDest().value = bc.toFixed(2);

        // Amount To = Amount BC / Rate To
        const calculatedTo = bc / rateTo;
        if (isFinite(calculatedTo)) {
            F.amountTo().value = calculatedTo.toFixed(2);
        }
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.date().value) { markInvalid(F.date(), 'Date required'); valid = false; }
    if (!F.fromAccount().value) { markInvalid(F.fromAccount(), 'Required'); valid = false; }
    if (!F.toAccount().value) { markInvalid(F.toAccount(), 'Required'); valid = false; }
    if (F.fromAccount().value === F.toAccount().value && F.fromAccount().value !== '') {
        markInvalid(F.toAccount(), 'Cannot transfer to same account');
        valid = false;
    }

    const amtFrom = parseFloat(F.amountFrom().value);
    const amtTo = parseFloat(F.amountTo().value);

    if (isNaN(amtFrom) || amtFrom <= 0) { markInvalid(F.amountFrom(), 'Must be > 0'); valid = false; }
    if (isNaN(amtTo) || amtTo <= 0) { markInvalid(F.amountTo(), 'Must be > 0'); valid = false; }
    if (!F.rateFrom().value || parseFloat(F.rateFrom().value) <= 0) {
        markInvalid(F.rateFrom(), 'Must be > 0'); valid = false;
    }
    if (!F.rateTo().value || parseFloat(F.rateTo().value) <= 0) {
        markInvalid(F.rateTo(), 'Must be > 0'); valid = false;
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

function showFormError(msg) {
    let err = modalRoot.querySelector('.modal-footer .form-error');
    if (!err) {
        err = document.createElement('div');
        err.className = 'form-error text-danger me-auto fw-bold';
        modalRoot.querySelector('.modal-footer').prepend(err);
    }
    err.textContent = msg;
}
