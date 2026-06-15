// assets/js/pages/Tenant/tenantForm.modal.js
import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let tenantId = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    tenantId = data?.tenant_id ?? null;

    const titleEl = modalRoot.querySelector('#tenantModalTitle');
    if (titleEl) {
        titleEl.innerHTML = tenantId
            ? '<i class="fas fa-building me-2"></i>Edit Tenant'
            : '<i class="fas fa-building me-2"></i>Create Tenant';
    }

    // If editing, fetch and fill
    if (tenantId) {
        await fetchAndFill(tenantId);
    }

    unregister = registerPageActions({
        'tenants.save': handleSave
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    tenantId = null;
}

async function fetchAndFill(id) {
    try {
        const res = await apiFetch('/api/tenant/tenants');
        const json = await res.json();
        if (!res.ok || !json.success) return;

        const tenant = (json.data?.tenants || []).find(t => t.id === id);
        if (!tenant) return;

        const nameInput = modalRoot.querySelector('#inputTenantName');
        const descInput = modalRoot.querySelector('#inputTenantDescription');
        if (nameInput) nameInput.value = tenant.name || '';
        if (descInput) descInput.value = tenant.description || '';
    } catch (err) {
        console.error('Failed to fetch tenant:', err);
    }
}

async function handleSave() {
    const nameInput = modalRoot.querySelector('#inputTenantName');
    const descInput = modalRoot.querySelector('#inputTenantDescription');

    const name = nameInput?.value?.trim();
    const description = descInput?.value?.trim() || null;

    if (!name) {
        markInvalid(nameInput, 'Name is required');
        return;
    }

    if (name.length < 2 || name.length > 100) {
        markInvalid(nameInput, 'Name must be between 2 and 100 characters');
        return;
    }

    const url = tenantId ? `/api/tenant/tenants/${tenantId}` : '/api/tenant/tenants';
    const method = tenantId ? 'PUT' : 'POST';

    try {
        const res = await apiFetch(url, {
            method,
            body: JSON.stringify({ name, description })
        });
        const json = await res.json();

        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Failed to save tenant');
        }

        bootstrap.Modal.getInstance(modalRoot).hide();
        document.dispatchEvent(new CustomEvent('tenants.updated'));
    } catch (err) {
        console.error(err);
        showFormError(err.message || 'Unable to save tenant');
    }
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
