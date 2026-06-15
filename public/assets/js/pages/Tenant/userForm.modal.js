// assets/js/pages/Tenant/userForm.modal.js
import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;

const FIELDS = {
    email: () => document.getElementById('tenantInputEmail'),
    username: () => document.getElementById('tenantInputUsername'),
    firstName: () => document.getElementById('tenantInputFirstName'),
    lastName: () => document.getElementById('tenantInputLastName'),
    password: () => document.getElementById('tenantInputPassword'),
    confirmPassword: () => document.getElementById('tenantInputConfirmPass'),
    tenantOwner: () => document.getElementById('tenantOwnerCheck'),
    businessSection: () => document.getElementById('businessAccessSection'),
    business: () => document.getElementById('tenantBusinessSelect'),
    role: () => document.getElementById('tenantRoleSelect'),
    isActive: () => document.getElementById('tenantIsActiveCheck'),
    saveBtn: () => document.getElementById('tenantUserSaveBtn')
};

let existingUserId = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    existingUserId = null;

    await fetchBusinesses();
    setupListeners();

    unregister = registerPageActions({
        'tenant.users.save': async () => {
            if (!validateForm()) return;

            const isTenantOwner = FIELDS.tenantOwner().checked;

            const payload = {
                email: FIELDS.email().value.trim(),
                username: FIELDS.username().value.trim(),
                first_name: FIELDS.firstName().value.trim(),
                last_name: FIELDS.lastName().value.trim(),
                password: FIELDS.password().value,
                make_tenant_owner: isTenantOwner,
                assignments: []
            };

            // Include business assignment if a business is selected
            const bizVal = FIELDS.business().value;
            const roleVal = FIELDS.role().value;
            if (bizVal && roleVal) {
                payload.assignments.push({
                    business_id: parseInt(bizVal, 10),
                    role_id: parseInt(roleVal, 10)
                });
            }

            try {
                const res = await apiFetch('/api/tenant/users', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    throw new Error(json.message || 'Failed to create user');
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('tenant.users.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to create user');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    existingUserId = null;
}

// ─── Data fetching ───────────────────────────────────────────────────────────

async function fetchBusinesses() {
    try {
        const res = await apiFetch('/api/tenant/businesses');
        const data = await res.json();

        if (!res.ok || !data.success) return;

        const select = FIELDS.business();
        if (!select) return;

        while (select.options.length > 1) {
            select.remove(1);
        }

        const businesses = data.data?.data || data.data?.businesses || [];
        businesses.forEach(biz => {
            const option = document.createElement('option');
            option.value = biz.id;
            option.textContent = biz.name;
            select.appendChild(option);
        });
    } catch (err) {
        console.error('fetchBusinesses failed:', err);
    }
}

async function fetchRolesForBusiness(businessId) {
    const roleSelect = FIELDS.role();
    if (!roleSelect) return;

    roleSelect.innerHTML = '<option value="">Loading roles…</option>';
    roleSelect.disabled = true;

    try {
        const res = await apiFetch(`/api/tenant/businesses/${businessId}/roles`);
        const data = await res.json();

        if (!res.ok || !data.success) {
            roleSelect.innerHTML = '<option value="">— No roles available —</option>';
            return;
        }

        roleSelect.innerHTML = '<option value="">— Select a role —</option>';

        const roles = data.data?.roles || {};
        const businessRoles = roles.business_roles || [];
        const templateRoles = roles.template_roles || [];

        businessRoles.forEach(role => {
            const option = document.createElement('option');
            option.value = role.id;
            option.textContent = role.name;
            roleSelect.appendChild(option);
        });

        if (templateRoles.length > 0) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = 'Template Roles';
            templateRoles.forEach(role => {
                const option = document.createElement('option');
                option.value = role.id;
                option.textContent = role.name;
                optgroup.appendChild(option);
            });
            roleSelect.appendChild(optgroup);
        }

        roleSelect.disabled = false;
    } catch (err) {
        console.error('fetchRolesForBusiness failed:', err);
        roleSelect.innerHTML = '<option value="">— Failed to load roles —</option>';
    }
}

// ─── Event listeners ─────────────────────────────────────────────────────────

function setupListeners() {
    const businessSelect = FIELDS.business();
    if (businessSelect) {
        businessSelect.addEventListener('change', onBusinessChange);
    }

    const tenantOwnerCheck = FIELDS.tenantOwner();
    if (tenantOwnerCheck) {
        tenantOwnerCheck.addEventListener('change', onTenantOwnerToggle);
    }

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

function onTenantOwnerToggle() {
    const checked = FIELDS.tenantOwner().checked;
    const section = FIELDS.businessSection();
    if (section) {
        section.style.opacity = checked ? '0.5' : '1';
    }
    // When tenant owner is checked, business/role become optional
    // but still visible in case they want to also assign a business role
}

function onBusinessChange() {
    const businessId = FIELDS.business().value;
    const roleSelect = FIELDS.role();

    if (!businessId) {
        roleSelect.innerHTML = '<option value="">— Select a business first —</option>';
        roleSelect.disabled = true;
        return;
    }

    fetchRolesForBusiness(businessId);
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

            setDisabled([FIELDS.firstName(), FIELDS.lastName(), FIELDS.password(), FIELDS.confirmPassword()], true);
            setLookupFeedback('Existing user found. Assign them to a business.', 'success');

            // Check if user is a tenant owner — hide business role form if so
            await checkTenantOwnerStatus(user.user_id);
        } else {
            existingUserId = null;
            setDisabled([FIELDS.firstName(), FIELDS.lastName(), FIELDS.password(), FIELDS.confirmPassword()], false);
            setLookupFeedback('No existing user found. A new user will be created.', 'info');
            showBusinessSection(true);
        }
    } catch (err) {
        console.error('Lookup failed:', err);
    }
}

async function checkTenantOwnerStatus(userId) {
    try {
        const res = await apiFetch(`/api/tenant/users/${userId}`);
        if (!res.ok) return;

        const data = await res.json();
        if (!data.success) return;

        const user = data.data;
        const isTenantOwner = (user.is_tenant_owner === true) ||
            (user.assignments || []).some(a => a.role_type === 'TENANT_OWNER');

        if (isTenantOwner) {
            showBusinessSection(false);
        } else {
            showBusinessSection(true);
        }
    } catch (err) {
        // If we can't determine tenant owner status, keep the section visible
        showBusinessSection(true);
    }
}

function showBusinessSection(visible) {
    const section = FIELDS.businessSection();
    const alert = document.getElementById('tenantOwnerAlert');

    if (section) {
        section.style.display = visible ? '' : 'none';
    }
    if (alert) {
        alert.classList.toggle('d-none', visible);
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
    const business = FIELDS.business();
    const role = FIELDS.role();
    const isTenantOwner = FIELDS.tenantOwner().checked;

    if (!email.value.trim()) {
        markInvalid(email, 'Email is required');
        valid = false;
    }
    if (!username.value.trim()) {
        markInvalid(username, 'Username is required');
        valid = false;
    }

    // Business & role required only if NOT making tenant owner
    if (!isTenantOwner) {
        if (!business.value) {
            markInvalid(business, 'Business is required');
            valid = false;
        }
        if (!role.value) {
            markInvalid(role, 'Role is required');
            valid = false;
        }
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
    const feedback = document.getElementById('tenantLookupFeedback');
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
