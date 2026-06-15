// assets/js/pages/Tenant/userForm.modal.js
import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let existingUserId = null;
let assignments = []; // [{business_id, business_name, role_id, role_name}]
let businessesCache = []; // [{id, name}]
let rolesCache = {}; // { businessId: [{id, name}] }

const FIELDS = {
    email:           () => document.getElementById('tenantInputEmail'),
    username:        () => document.getElementById('tenantInputUsername'),
    firstName:       () => document.getElementById('tenantInputFirstName'),
    lastName:        () => document.getElementById('tenantInputLastName'),
    password:        () => document.getElementById('tenantInputPassword'),
    confirmPassword: () => document.getElementById('tenantInputConfirmPass'),
    tenantOwner:     () => document.getElementById('tenantOwnerCheck'),
    businessSection: () => document.getElementById('businessAccessSection'),
    business:        () => document.getElementById('tenantBusinessSelect'),
    role:            () => document.getElementById('tenantRoleSelect'),
    addBtn:          () => document.getElementById('addAssignmentBtn'),
    assignmentsList: () => document.getElementById('assignmentsList'),
    noMsg:           () => document.getElementById('noAssignmentsMsg'),
    passwordSection: () => document.getElementById('tenantPasswordSection'),
    saveBtn:         () => document.getElementById('tenantUserSaveBtn')
};

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    existingUserId = null;
    assignments = [];
    rolesCache = {};

    await fetchBusinesses();
    setupListeners();

    unregister = registerPageActions({
        'tenant.users.save': handleSave
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    existingUserId = null;
    assignments = [];
    rolesCache = {};
    businessesCache = [];
}

// ─── Save ────────────────────────────────────────────────────────────────────

async function handleSave() {
    if (!validateForm()) return;

    const isTenantOwner = FIELDS.tenantOwner().checked;

    const payload = {
        email: FIELDS.email().value.trim(),
        username: FIELDS.username().value.trim(),
        first_name: FIELDS.firstName().value.trim(),
        last_name: FIELDS.lastName().value.trim(),
        make_tenant_owner: isTenantOwner,
        assignments: assignments.map(a => ({
            business_id: a.business_id,
            role_id: a.role_id
        }))
    };

    // Only include password for new users
    if (!existingUserId) {
        payload.password = FIELDS.password().value;
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
        showFormError(err.message || 'Unable to save user');
    }
}

// ─── Data fetching ───────────────────────────────────────────────────────────

async function fetchBusinesses() {
    try {
        const res = await apiFetch('/api/tenant/businesses');
        const data = await res.json();

        if (!res.ok || !data.success) return;

        const select = FIELDS.business();
        if (!select) return;

        businessesCache = data.data?.data || data.data?.businesses || [];

        while (select.options.length > 1) select.remove(1);

        businessesCache.forEach(biz => {
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
    // Use cache if available
    if (rolesCache[businessId]) {
        populateRoleSelect(rolesCache[businessId]);
        return;
    }

    const roleSelect = FIELDS.role();
    if (!roleSelect) return;

    roleSelect.innerHTML = '<option value="">Loading…</option>';
    roleSelect.disabled = true;

    try {
        const res = await apiFetch(`/api/tenant/businesses/${businessId}/roles`);
        const data = await res.json();

        if (!res.ok || !data.success) {
            roleSelect.innerHTML = '<option value="">— No roles —</option>';
            return;
        }

        const roles = data.data?.roles || {};
        const allRoles = [
            ...(roles.business_roles || []).map(r => ({ ...r, template: false })),
            ...(roles.template_roles || []).map(r => ({ ...r, template: true }))
        ];

        rolesCache[businessId] = allRoles;
        populateRoleSelect(allRoles);
    } catch (err) {
        console.error('fetchRolesForBusiness failed:', err);
        const roleSelect = FIELDS.role();
        if (roleSelect) roleSelect.innerHTML = '<option value="">— Failed —</option>';
    }
}

function populateRoleSelect(roles) {
    const roleSelect = FIELDS.role();
    if (!roleSelect) return;

    roleSelect.innerHTML = '<option value="">— Select a role —</option>';

    roles.forEach(role => {
        const option = document.createElement('option');
        option.value = role.id;
        option.textContent = role.template ? `${role.name} (template)` : role.name;
        roleSelect.appendChild(option);
    });

    roleSelect.disabled = false;
    updateAddBtnState();
}

// ─── Event listeners ─────────────────────────────────────────────────────────

function setupListeners() {
    FIELDS.business()?.addEventListener('change', onBusinessChange);
    FIELDS.role()?.addEventListener('change', updateAddBtnState);
    FIELDS.addBtn()?.addEventListener('click', onAddAssignment);
    FIELDS.tenantOwner()?.addEventListener('change', onTenantOwnerToggle);

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

function onBusinessChange() {
    const businessId = FIELDS.business().value;
    const roleSelect = FIELDS.role();

    if (!businessId) {
        roleSelect.innerHTML = '<option value="">— Select business first —</option>';
        roleSelect.disabled = true;
        updateAddBtnState();
        return;
    }

    fetchRolesForBusiness(businessId);
}

function updateAddBtnState() {
    const btn = FIELDS.addBtn();
    if (!btn) return;
    btn.disabled = !FIELDS.business()?.value || !FIELDS.role()?.value;
}

function onAddAssignment() {
    const bizSelect = FIELDS.business();
    const roleSelect = FIELDS.role();
    const businessId = parseInt(bizSelect.value, 10);
    const roleId = parseInt(roleSelect.value, 10);

    if (!businessId || !roleId) return;

    // Prevent duplicate business
    if (assignments.some(a => a.business_id === businessId)) {
        showFormError('This business is already assigned.');
        return;
    }

    const businessName = bizSelect.options[bizSelect.selectedIndex].text;
    const roleName = roleSelect.options[roleSelect.selectedIndex].text;

    assignments.push({ business_id: businessId, business_name: businessName, role_id: roleId, role_name: roleName });
    renderAssignments();

    // Reset selectors
    bizSelect.value = '';
    roleSelect.innerHTML = '<option value="">— Select business first —</option>';
    roleSelect.disabled = true;
    updateAddBtnState();
}

function onRemoveAssignment(index) {
    assignments.splice(index, 1);
    renderAssignments();
}

function renderAssignments() {
    const container = FIELDS.assignmentsList();
    const noMsg = FIELDS.noMsg();
    if (!container) return;

    // Clear existing assignment badges (keep the noMsg element)
    container.querySelectorAll('.assignment-row').forEach(el => el.remove());

    if (assignments.length === 0) {
        if (noMsg) noMsg.style.display = '';
        return;
    }

    if (noMsg) noMsg.style.display = 'none';

    assignments.forEach((a, idx) => {
        const row = document.createElement('div');
        row.className = 'assignment-row d-flex align-items-center justify-content-between border rounded p-2 mb-2 bg-white';

        row.innerHTML = `
            <div>
                <span class="fw-semibold small">${escHtml(a.business_name)}</span>
                <span class="text-muted small ms-1">→</span>
                <span class="badge bg-light text-dark border ms-1">${escHtml(a.role_name)}</span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" title="Remove">
                <i class="fas fa-times"></i>
            </button>
        `;

        row.querySelector('button').addEventListener('click', () => onRemoveAssignment(idx));
        container.appendChild(row);
    });
}

function onTenantOwnerToggle() {
    const checked = FIELDS.tenantOwner().checked;
    const section = FIELDS.businessSection();
    if (section) {
        section.style.opacity = checked ? '0.5' : '1';
        section.style.pointerEvents = checked ? 'none' : '';
    }
}

// ─── User lookup ─────────────────────────────────────────────────────────────

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

            setDisabled([FIELDS.firstName(), FIELDS.lastName()], true);
            // Hide password section for existing users
            const pwSection = FIELDS.passwordSection();
            if (pwSection) pwSection.style.display = 'none';

            setLookupFeedback('Existing user found. Assign them to businesses.', 'success');

            // Check if user is already a tenant owner
            await checkTenantOwnerStatus(user.user_id);
        } else {
            existingUserId = null;
            setDisabled([FIELDS.firstName(), FIELDS.lastName()], false);
            const pwSection = FIELDS.passwordSection();
            if (pwSection) pwSection.style.display = '';
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

        const user = data.data?.user || data.data;
        const isTenantOwner = (user.is_tenant_owner === true) ||
            (user.assignments || []).some(a => a.role_type === 'TENANT_OWNER');

        if (isTenantOwner) {
            showBusinessSection(false);
            FIELDS.tenantOwner().checked = true;
            FIELDS.tenantOwner().disabled = true;
        } else {
            showBusinessSection(true);
        }
    } catch (err) {
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
    const isTenantOwner = FIELDS.tenantOwner().checked;

    if (!email.value.trim()) {
        markInvalid(email, 'Email is required');
        valid = false;
    }
    if (!username.value.trim()) {
        markInvalid(username, 'Username is required');
        valid = false;
    }

    // At least one assignment OR tenant owner required
    if (!isTenantOwner && assignments.length === 0) {
        showFormError('Add at least one business assignment or make the user a Tenant Owner.');
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

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
