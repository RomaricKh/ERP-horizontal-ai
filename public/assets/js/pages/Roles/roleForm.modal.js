// assets/js/pages/Roles/roleForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let currentRoleId = null;
let allPermissionsFlat = [];

const MODULE_LABELS = {
    po:            'Purchase Orders',
    expenses:      'Expenses',
    invoices:      'Invoices',
    payments:      'Payments',
    collections:   'Collections',
    items:         'Items',
    users:         'Users',
    roles:         'Roles',
    accounts:      'Accounts',
    settings:      'Settings',
    salaries:      'Salaries',
    manufacturing: 'Manufacturing',
    businesses:    'Businesses',
    dashboard:     'Dashboard',
    people:        'People'
};

const MODULE_ICONS = {
    po:            'fa-shopping-cart',
    expenses:      'fa-receipt',
    invoices:      'fa-file-invoice',
    payments:      'fa-money-bill',
    collections:   'fa-hand-holding-usd',
    items:         'fa-boxes',
    users:         'fa-users',
    roles:         'fa-user-shield',
    accounts:      'fa-university',
    settings:      'fa-cogs',
    salaries:      'fa-user-tie',
    manufacturing: 'fa-industry',
    businesses:    'fa-briefcase',
    dashboard:     'fa-tachometer-alt',
    people:        'fa-address-book'
};

const F = {
    title:       () => document.getElementById('roleModalTitle'),
    name:        () => document.getElementById('roleName'),
    description: () => document.getElementById('roleDescription'),
    permissions: () => document.getElementById('permissionsContainer'),
    saveBtn:     () => document.getElementById('saveRoleBtn'),
    search:      () => document.getElementById('permissionSearch'),
    countBadge:  () => document.getElementById('permissionCountBadge')
};

export async function mount({ modalRoot: root, data }) {
    modalRoot     = root;
    currentRoleId = data?.role_id ?? null;

    F.title().textContent = currentRoleId ? 'Edit Role' : 'Create Role';

    // Load permissions and render grouped checkboxes
    allPermissionsFlat = await fetchPermissions();
    const grouped = groupPermissions(allPermissionsFlat);
    renderPermissionGroups(grouped);

    // If editing, fetch role and populate form
    if (currentRoleId) {
        await fetchAndFill(currentRoleId);
    }

    updateCountBadge();

    // Wire search filter
    F.search().addEventListener('input', handleSearch);

    unregister = registerPageActions({
        'roles.save': handleSave
    });

    // Wire save button
    F.saveBtn().addEventListener('click', handleSave);
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentRoleId = null;
    allPermissionsFlat = [];
}

async function fetchPermissions() {
    try {
        const res  = await apiFetch('/api/roles/permissions', { method: 'GET' });
        const json = await res.json();
        if (!res.ok || !json.success) return [];
        return json.data?.permissions ?? [];
    } catch (err) {
        console.error('fetchPermissions failed:', err);
        return [];
    }
}

function groupPermissions(permissions) {
    const groups = {};
    permissions.forEach(perm => {
        const dotIndex = perm.code.indexOf('.');
        const prefix = dotIndex > -1 ? perm.code.substring(0, dotIndex) : perm.code;
        if (!groups[prefix]) {
            groups[prefix] = [];
        }
        groups[prefix].push(perm);
    });
    return groups;
}

function getModuleLabel(prefix) {
    return MODULE_LABELS[prefix] || prefix.charAt(0).toUpperCase() + prefix.slice(1);
}

function getModuleIcon(prefix) {
    return MODULE_ICONS[prefix] || 'fa-lock';
}

function renderPermissionGroups(grouped) {
    const container = F.permissions();
    container.innerHTML = '';

    const prefixes = Object.keys(grouped).sort();

    if (prefixes.length === 0) {
        container.innerHTML = '<span class="text-muted small">No permissions available.</span>';
        return;
    }

    prefixes.forEach(prefix => {
        const perms = grouped[prefix];
        const label = getModuleLabel(prefix);
        const icon  = getModuleIcon(prefix);

        const card = document.createElement('div');
        card.className = 'card mb-2 permission-group';
        card.dataset.group = prefix;

        const header = document.createElement('div');
        header.className = 'card-header py-2 d-flex align-items-center justify-content-between';
        header.style.cssText = 'background: #f8f9fc; padding-left: 1.5rem; padding-right: 1rem;';

        const headerLeft = document.createElement('div');
        headerLeft.className = 'd-flex align-items-center';

        // Select-all checkbox
        const selectAllId = `selectAll_${prefix}`;
        const selectAllCb = document.createElement('input');
        selectAllCb.type      = 'checkbox';
        selectAllCb.className = 'form-check-input me-2';
        selectAllCb.id        = selectAllId;

        const iconEl = document.createElement('i');
        iconEl.className = `fas ${icon} text-primary me-2`;
        iconEl.style.width = '16px';

        const selectAllLabel = document.createElement('label');
        selectAllLabel.className = 'form-check-label fw-bold mb-0';
        selectAllLabel.htmlFor   = selectAllId;
        selectAllLabel.textContent = label;

        headerLeft.appendChild(selectAllCb);
        headerLeft.appendChild(iconEl);
        headerLeft.appendChild(selectAllLabel);

        // Count badge per group
        const groupBadge = document.createElement('span');
        groupBadge.className = 'badge bg-light text-dark group-count-badge';
        groupBadge.id = `groupCount_${prefix}`;
        groupBadge.textContent = `0/${perms.length}`;

        header.appendChild(headerLeft);
        header.appendChild(groupBadge);

        const body = document.createElement('div');
        body.className = 'card-body py-2 px-3';

        const row = document.createElement('div');
        row.className = 'row g-1';

        perms.forEach(perm => {
            const col = document.createElement('div');
            col.className = 'col-sm-6 col-md-4 perm-item';
            col.dataset.code = perm.code.toLowerCase();
            col.dataset.name = (perm.name || '').toLowerCase();

            const wrapper = document.createElement('div');
            wrapper.className = 'form-check py-1';

            const cb = document.createElement('input');
            cb.type      = 'checkbox';
            cb.className = 'form-check-input perm-checkbox';
            cb.id        = `perm_${perm.id}`;
            cb.value     = perm.id;
            cb.dataset.group = prefix;

            const cbLabel = document.createElement('label');
            cbLabel.className = 'form-check-label text-capitalize';
            cbLabel.htmlFor   = `perm_${perm.id}`;
            // Show the action part (after the dot) as the label
            const actionPart = perm.code.indexOf('.') > -1
                ? perm.code.substring(perm.code.indexOf('.') + 1)
                : perm.code;
            cbLabel.textContent = actionPart.replace(/[._-]/g, ' ');

            wrapper.appendChild(cb);
            wrapper.appendChild(cbLabel);
            col.appendChild(wrapper);
            row.appendChild(col);

            // Individual checkbox change → update states
            cb.addEventListener('change', () => {
                updateSelectAllState(prefix, selectAllCb);
                updateGroupCount(prefix, perms.length);
                updateCountBadge();
            });
        });

        body.appendChild(row);
        card.appendChild(header);
        card.appendChild(body);
        container.appendChild(card);

        // Select-all toggle behavior
        selectAllCb.addEventListener('change', () => {
            const checkboxes = card.querySelectorAll('.perm-checkbox');
            checkboxes.forEach(cb => { cb.checked = selectAllCb.checked; });
            updateGroupCount(prefix, perms.length);
            updateCountBadge();
        });
    });
}

function updateSelectAllState(prefix, selectAllCb) {
    const container = F.permissions();
    const checkboxes = container.querySelectorAll(`.perm-checkbox[data-group="${prefix}"]`);
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    selectAllCb.checked = allChecked;
}

function updateGroupCount(prefix, total) {
    const container = F.permissions();
    const checked = container.querySelectorAll(`.perm-checkbox[data-group="${prefix}"]:checked`).length;
    const badge = document.getElementById(`groupCount_${prefix}`);
    if (badge) {
        badge.textContent = `${checked}/${total}`;
        badge.className = checked > 0
            ? 'badge bg-primary group-count-badge'
            : 'badge bg-light text-dark group-count-badge';
    }
}

function updateCountBadge() {
    const container = F.permissions();
    const total   = container.querySelectorAll('.perm-checkbox').length;
    const checked = container.querySelectorAll('.perm-checkbox:checked').length;
    const badge   = F.countBadge();
    if (badge) {
        badge.textContent = `${checked} / ${total} selected`;
    }
}

function handleSearch() {
    const query = (F.search().value || '').toLowerCase().trim();
    const container = F.permissions();

    // Show/hide individual permission items
    container.querySelectorAll('.perm-item').forEach(item => {
        const code = item.dataset.code || '';
        const name = item.dataset.name || '';
        const match = !query || code.includes(query) || name.includes(query);
        item.style.display = match ? '' : 'none';
    });

    // Show/hide entire groups if no children match
    container.querySelectorAll('.permission-group').forEach(group => {
        const visibleItems = group.querySelectorAll('.perm-item:not([style*="display: none"])');
        group.style.display = visibleItems.length > 0 ? '' : 'none';
    });
}

async function fetchAndFill(roleId) {
    try {
        const res  = await apiFetch(`/api/roles/${roleId}`, { method: 'GET' });
        const json = await res.json();
        if (!res.ok || !json.success) return;

        const role = json.data?.role;
        if (!role) return;

        F.name().value        = role.name ?? '';
        F.description().value = role.description ?? '';

        // Check the permission checkboxes that belong to this role
        const permIds = (role.permissions ?? []).map(p => String(p.id));
        const container = F.permissions();
        container.querySelectorAll('.perm-checkbox').forEach(cb => {
            cb.checked = permIds.includes(cb.value);
        });

        // Update all select-all states and group counts
        const selectAlls = container.querySelectorAll('[id^="selectAll_"]');
        selectAlls.forEach(sa => {
            const prefix = sa.id.replace('selectAll_', '');
            updateSelectAllState(prefix, sa);
            const groupPerms = container.querySelectorAll(`.perm-checkbox[data-group="${prefix}"]`);
            updateGroupCount(prefix, groupPerms.length);
        });

        updateCountBadge();
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

async function handleSave() {
    if (!validateForm()) return;

    const container = F.permissions();
    const checkedBoxes = container.querySelectorAll('.perm-checkbox:checked');
    const permissionIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value, 10));

    const payload = {
        name:           F.name().value.trim(),
        description:    F.description().value.trim() || null,
        permission_ids: permissionIds
    };

    const url    = currentRoleId ? `/api/roles/${currentRoleId}` : '/api/roles';
    const method = currentRoleId ? 'PUT' : 'POST';

    try {
        const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
        const json = await res.json();

        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Operation failed');
        }

        bootstrap.Modal.getInstance(modalRoot).hide();
        document.dispatchEvent(new Event('roles.updated'));
    } catch (err) {
        console.error(err);
        showFormError(err.message || 'Unable to save role');
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    const name = F.name().value.trim();
    if (!name) {
        markInvalid(F.name(), 'Role name is required');
        valid = false;
    } else if (name.length < 2 || name.length > 50) {
        markInvalid(F.name(), 'Role name must be between 2 and 50 characters');
        valid = false;
    }

    const desc = F.description().value;
    if (desc && desc.length > 255) {
        markInvalid(F.description(), 'Description must not exceed 255 characters');
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
