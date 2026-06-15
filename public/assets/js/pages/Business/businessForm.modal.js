// JavaScript Document
import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let currentBusinessId = null;

const FIELDS = {
    modalTitle:     () => document.getElementById('businessModalTitle'),
    name:           () => document.getElementById('inputBusinessName'),
    legalName:      () => document.getElementById('inputLegalName'),
    registrationNo: () => document.getElementById('inputRegistrationNo'),
    address:        () => document.getElementById('inputAddress'),
    phone:          () => document.getElementById('inputPhone'),
    email:          () => document.getElementById('inputBusinessEmail'),
    currency:       () => document.getElementById('currencySelect'),
    saveBtn:        () => document.getElementById('businessSaveBtn')
};

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    currentBusinessId = data?.business_id ?? null;

    if (currentBusinessId) {
        FIELDS.modalTitle().textContent = 'Edit Business';
        await fetchAndFillBusiness(currentBusinessId);
    } else {
        FIELDS.modalTitle().textContent = 'Create Business';
    }

    unregister = registerPageActions({
        'businesses.save': async () => {
            if (!validateForm()) return;

            const payload = {
                name:            FIELDS.name().value.trim(),
                legal_name:      FIELDS.legalName().value.trim() || null,
                registration_no: FIELDS.registrationNo().value.trim() || null,
                address:         FIELDS.address().value.trim() || null,
                phone:           FIELDS.phone().value.trim() || null,
                email:           FIELDS.email().value.trim() || null,
                currency:        FIELDS.currency().value
            };

            const url    = currentBusinessId ? `/api/businesses/${currentBusinessId}` : '/api/businesses';
            const method = currentBusinessId ? 'PUT' : 'POST';

            try {
                const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    throw new Error(json.message || 'Operation failed');
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('businesses.updated'));

            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save business');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentBusinessId = null;
}

async function fetchAndFillBusiness(businessId) {
    try {
        const res = await apiFetch(`/api/businesses/${businessId}`, { method: 'GET' });

        if (!res.ok) throw new Error(`HTTP error ${res.status}`);

        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Failed to fetch business');

        fillForm(data.data.business);
    } catch (err) {
        console.error('fetchAndFillBusiness failed:', err);
        showFormError('Failed to load business data');
    }
}

function fillForm(business) {
    FIELDS.name().value           = business.name           ?? '';
    FIELDS.legalName().value      = business.legal_name     ?? '';
    FIELDS.registrationNo().value = business.registration_no ?? '';
    FIELDS.address().value        = business.address        ?? '';
    FIELDS.phone().value          = business.phone          ?? '';
    FIELDS.email().value          = business.email          ?? '';
    FIELDS.currency().value       = business.currency       ?? '';
}

function validateForm() {
    clearValidation();
    let valid = true;

    const name     = FIELDS.name();
    const currency = FIELDS.currency();

    if (!name.value.trim()) {
        markInvalid(name, 'Business name is required');
        valid = false;
    }

    if (!currency.value) {
        markInvalid(currency, 'Currency is required');
        valid = false;
    }

    return valid;
}

function clearValidation() {
    modalRoot.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
    const footer = modalRoot.querySelector('.modal-footer');
    footer?.querySelector('.form-error')?.remove();
}

function markInvalid(input, message) {
    if (!input) return;
    input.classList.add('is-invalid');

    let feedback = input.parentElement.querySelector('.invalid-feedback');
    if (!feedback) {
        feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        input.parentElement.appendChild(feedback);
    }
    feedback.textContent = message;
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
