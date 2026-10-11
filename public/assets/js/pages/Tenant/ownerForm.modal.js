// assets/js/pages/Tenant/ownerForm.modal.js
import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let existingUserId = null;

const FIELDS = {
    email: () => document.getElementById('ownerInputEmail'),
    username: () => document.getElementById('ownerInputUsername'),
    firstName: () => document.getElementById('ownerInputFirstName'),
    lastName: () => document.getElementById('ownerInputLastName'),
    password: () => document.getElementById('ownerInputPassword'),
    confirmPassword: () => document.getElementById('ownerInputConfirmPass'),
    saveBtn: () => document.getElementById('ownerSaveBtn'),
    existingAlert: () => document.getElementById('ownerExistingUserAlert')
};

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    existingUserId = null;
    setupListeners();

    unregister = registerPageActions({
        'tenant-owners.save': async () => {
            if (!validateForm()) return;

            const payload = {
                email: FIELDS.email().value.trim(),
                username: FIELDS.username().value.trim(),
                first_name: FIELDS.firstName().value.trim(),
                last_name: FIELDS.lastName().value.trim()
            };

            if (!existingUserId) {
                payload.password = FIELDS.password().value;
            }

            try {
                const res = await apiFetch('/api/tenant/owners', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    throw new Error(json.message || 'Failed to designate owner');
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('tenant-owners.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to designate owner');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    existingUserId = null;
}

// ─── Event listeners ─────────────────────────────────────────────────────────

function setupListeners() {
    const email = FIELDS.email();
    const username = FIELDS.username();
    if (email) email.addEventListener('blur', handleLookup);
    if (username) username.addEventListener('blur', handleLookup);

    modalRoot.addEventListener('input', e => {
        if (e.target.classList.contains('is-invalid')) {
            e.target.classList.remove('is-invalid');
            const feedback = e.target.parentElement.querySelector('.invalid-feedback');
            if (feedback) feedback.remove();
        }
    });
}

async function handleLookup() {
    const email = FIELDS.email().value.trim();
    const username = FIELDS.username().value.trim();

    if (!email && !username) return;

    const params = new URLSearchParams();
    if (email) params.append('email', email);
    if (username) params.append('username', username);

    try {
        const res = await apiFetch(`/api/users/lookup?${params}`);
        if (!res.ok) return;

        const data = await res.json();
        if (!data.success) return;

        if (data.data.exists) {
            const user = data.data.user;
            existingUserId = user.user_id;
            FIELDS.firstName().value = user.first_name || '';
            FIELDS.lastName().value = user.last_name || '';
            FIELDS.email().value = user.email || '';
            FIELDS.username().value = user.username || '';

            // Disable identity + password fields for existing user
            setDisabled([FIELDS.firstName(), FIELDS.lastName(), FIELDS.password(), FIELDS.confirmPassword()], true);
            FIELDS.existingAlert()?.classList.remove('d-none');
            setLookupFeedback('Existing user found. They will be promoted to Tenant Owner.', 'success');
        } else {
            existingUserId = null;
            setDisabled([FIELDS.firstName(), FIELDS.lastName(), FIELDS.password(), FIELDS.confirmPassword()], false);
            FIELDS.existingAlert()?.classList.add('d-none');
            setLookupFeedback('No existing user found. A new Tenant Owner account will be created.', 'info');
        }
    } catch (err) {
        console.error('Lookup failed:', err);
    }
}

// ─── Validation ──────────────────────────────────────────────────────────────

function validateForm() {
    clearValidation();
    let valid = true;

    const email = FIELDS.email();
    const username = FIELDS.username();
    const firstName = FIELDS.firstName();
    const lastName = FIELDS.lastName();
    const password = FIELDS.password();
    const confirmPassword = FIELDS.confirmPassword();

    if (!email.value.trim()) {
        markInvalid(email, 'Email is required');
        valid = false;
    } else {
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(email.value.trim())) {
            markInvalid(email, 'Please enter a valid email address');
            valid = false;
        }
    }
    if (!username.value.trim()) {
        markInvalid(username, 'Username is required');
        valid = false;
    }

    // New user fields
    if (!existingUserId) {
        if (!firstName.value.trim()) {
            markInvalid(firstName, 'First name is required');
            valid = false;
        }
        if (!lastName.value.trim()) {
            markInvalid(lastName, 'Last name is required');
            valid = false;
        }
        if (!password.value) {
            markInvalid(password, 'Password is required');
            valid = false;
        }
        if (!confirmPassword.value) {
            markInvalid(confirmPassword, 'Please confirm the password');
            valid = false;
        }
        if (password.value && confirmPassword.value && password.value !== confirmPassword.value) {
            markInvalid(confirmPassword, 'Passwords do not match');
            valid = false;
        }
    }

    return valid;
}

// ─── UI helpers ──────────────────────────────────────────────────────────────

function setDisabled(elements, disabled) {
    elements.forEach(el => { if (el) el.disabled = disabled; });
}

function setLookupFeedback(message, type = 'info') {
    const feedback = document.getElementById('ownerLookupFeedback');
    if (!feedback) return;
    feedback.className = 'form-text';
    feedback.textContent = message;
    if (type === 'success') feedback.classList.add('text-success');
    else if (type === 'error') feedback.classList.add('text-danger');
    else feedback.classList.add('text-muted');
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
