// assets/js/pages/Services/serviceForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let currentServiceId = null;

const F = {
    title:          () => document.getElementById('serviceModalTitle'),
    name:           () => document.getElementById('inputServiceName'),
    description:    () => document.getElementById('inputServiceDescription'),
    categorySelect: () => document.getElementById('serviceCategorySelect'),
    unit:           () => document.getElementById('inputServiceUnit'),
    rate:           () => document.getElementById('inputServiceRate'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot        = root;
    currentServiceId = data?.service_id ?? null;

    F.title().textContent = currentServiceId ? 'Edit Service' : 'Add Service';

    await loadCategories();

    if (currentServiceId) {
        await fetchAndFill(currentServiceId);
    }

    unregister = registerPageActions({
        'services.save': async () => {
            if (!validateForm()) return;

            const payload = {
                name:        F.name().value.trim(),
                description: F.description().value.trim() || null,
                category_id: F.categorySelect().value ? parseInt(F.categorySelect().value, 10) : null,
                unit:        F.unit().value.trim(),
                rate:        parseFloat(F.rate().value),
            };

            const url    = currentServiceId ? `/api/services/${currentServiceId}` : '/api/services';
            const method = currentServiceId ? 'PUT' : 'POST';

            try {
                const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Operation failed');
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('services.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save service');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentServiceId = null;
}

async function loadCategories() {
    try {
        const res  = await apiFetch('/api/services/categories');
        const data = await res.json();
        if (!data.success) return;

        const sel = F.categorySelect();
        while (sel.options.length > 1) sel.remove(1);

        (data.data.service_categories || []).forEach(cat => {
            const opt = document.createElement('option');
            opt.value       = cat.id;
            opt.textContent = cat.name;
            sel.appendChild(opt);
        });
    } catch (err) {
        console.error('loadCategories failed:', err);
    }
}

async function fetchAndFill(serviceId) {
    try {
        const res  = await apiFetch(`/api/services/${serviceId}`);
        const data = await res.json();
        if (!data.success) return;

        const service = data.data.service;
        F.name().value           = service.name        ?? '';
        F.description().value    = service.description ?? '';
        F.categorySelect().value = service.category_id ?? '';
        F.unit().value           = service.unit        ?? '';
        F.rate().value           = service.rate        ?? '';
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.name().value.trim()) {
        markInvalid(F.name(), 'Service name is required');
        valid = false;
    }
    if (!F.unit().value.trim()) {
        markInvalid(F.unit(), 'Service unit is required');
        valid = false;
    }
    const rateVal = F.rate().value;
    if (rateVal === '' || isNaN(parseFloat(rateVal)) || parseFloat(rateVal) < 0) {
        markInvalid(F.rate(), 'Rate must be a non-negative number');
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
