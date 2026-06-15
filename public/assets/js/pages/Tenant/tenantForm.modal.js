// assets/js/pages/Tenant/tenantForm.modal.js
import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    unregister = registerPageActions({
        'tenants.save': async () => {
            const name = modalRoot.querySelector('#inputTenantName')?.value?.trim();
            const description = modalRoot.querySelector('#inputTenantDescription')?.value?.trim() || null;

            if (!name) {
                markInvalid(modalRoot.querySelector('#inputTenantName'), 'Name is required');
                return;
            }

            try {
                const res = await apiFetch('/api/tenant/tenants', {
                    method: 'POST',
                    body: JSON.stringify({ name, description })
                });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    throw new Error(json.message || 'Failed to create tenant');
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('tenants.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save tenant');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
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
