// assets/js/pages/Finance/exchangeRateForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let currentRateId = null;

const F = {
    title:    () => document.getElementById('exchangeRateModalTitle'),
    currency: () => document.getElementById('inputErCurrency'),
    rateDate: () => document.getElementById('inputErRateDate'),
    rate:     () => document.getElementById('inputErRate'),
    source:   () => document.getElementById('inputErSource'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot     = root;
    currentRateId = data?.rate_id ?? null;

    F.title().textContent = currentRateId ? 'Edit Exchange Rate' : 'Add Exchange Rate';

    if (currentRateId) {
        await fetchAndFill(currentRateId);
    }

    unregister = registerPageActions({
        'exchange-rates.save': async () => {
            if (!validateForm()) return;

            const payload = {
                currency:  F.currency().value,
                rate_date: F.rateDate().value,
                rate:      parseFloat(F.rate().value),
                source:    F.source().value.trim() || null,
            };

            const url    = currentRateId ? `/api/exchange-rates/${currentRateId}` : '/api/exchange-rates';
            const method = currentRateId ? 'PUT' : 'POST';

            try {
                const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Operation failed');
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('exchange-rates.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save exchange rate');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentRateId = null;
}

async function fetchAndFill(rateId) {
    try {
        const res  = await apiFetch(`/api/exchange-rates/${rateId}`);
        const data = await res.json();
        if (!data.success) return;

        const record = data.data?.exchange_rate;
        if (!record) return;

        F.currency().value = record.currency  ?? '';
        F.rateDate().value = record.rate_date ?? '';
        F.rate().value     = record.rate      ?? '';
        F.source().value   = record.source    ?? '';
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.currency().value) {
        markInvalid(F.currency(), 'Currency is required');
        valid = false;
    }
    if (!F.rateDate().value) {
        markInvalid(F.rateDate(), 'Rate date is required');
        valid = false;
    }
    if (!F.rate().value) {
        markInvalid(F.rate(), 'Rate is required');
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
