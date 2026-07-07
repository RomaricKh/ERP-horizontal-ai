// assets/js/pages/Finance/accountForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let currentAccountId = null;

const F = {
    title:    () => document.getElementById('accountModalTitle'),
    name:     () => document.getElementById('inputAccountName'),
    currency: () => document.getElementById('inputAccountCurrency'),
    type:     () => document.getElementById('inputAccountType'),
    isLocal:  () => document.getElementById('inputLocalCurrency'),
    denom:    () => document.getElementById('inputCashDenomination')
};

export async function mount({ modalRoot: root, data }) {
    modalRoot        = root;
    currentAccountId = data?.account_id ?? null;

    F.title().textContent = currentAccountId ? 'Edit Account' : 'Add Account';

    if (currentAccountId) {
        await fetchAndFill(currentAccountId);
        // Currency cannot be changed after creation
        F.currency().disabled = true;
    }

    unregister = registerPageActions({
        'accounts.save': async () => {
            if (!validateForm()) return;

            const denomVal = F.denom().value.trim();
            const payload = currentAccountId
                ? { 
                    name: F.name().value.trim(),
                    account_type: F.type().value,
                    is_local_currency_account: F.isLocal().checked,
                    cash_denomination: denomVal !== '' ? parseFloat(denomVal) : null
                  }
                : { 
                    name: F.name().value.trim(), 
                    currency: F.currency().value,
                    account_type: F.type().value,
                    is_local_currency_account: F.isLocal().checked,
                    cash_denomination: denomVal !== '' ? parseFloat(denomVal) : null
                  };

            const url    = currentAccountId ? `/api/accounts/${currentAccountId}` : '/api/accounts';
            const method = currentAccountId ? 'PUT' : 'POST';

            try {
                const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Operation failed');
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('accounts.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save account');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentAccountId = null;
}

async function fetchAndFill(accountId) {
    try {
        const res  = await apiFetch(`/api/accounts/${accountId}`);
        const data = await res.json();
        if (!data.success) return;

        const record = data.data?.account ?? data.data;
        if (!record) return;

        F.name().value       = record.name     ?? '';
        F.currency().value   = record.currency ?? '';
        F.type().value       = record.account_type ?? 'CASH';
        F.isLocal().checked  = record.is_local_currency_account == 1;
        F.denom().value      = record.cash_denomination ?? '';
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.name().value.trim()) {
        markInvalid(F.name(), 'Name is required');
        valid = false;
    }
    if (!currentAccountId && !F.currency().value) {
        markInvalid(F.currency(), 'Currency is required');
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
