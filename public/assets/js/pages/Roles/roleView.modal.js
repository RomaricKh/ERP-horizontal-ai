// assets/js/pages/Roles/roleView.modal.js
// Read-only role details view

import { hasPermission } from '../../Auth/auth.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { MODAL_SCOPE, openModal } from '../../components/modals.js';

let modalRoot;
let roleId = null;

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

function getModuleLabel(prefix) {
    return MODULE_LABELS[prefix] || prefix.charAt(0).toUpperCase() + prefix.slice(1);
}

function getModuleIcon(prefix) {
    return MODULE_ICONS[prefix] || 'fa-lock';
}

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    roleId    = data?.role_id ?? null;

    if (!roleId) return;

    // Show edit button if user has permission and role is not system
    const editBtn = document.getElementById('roleViewEditBtn');

    await fetchAndRender(roleId, editBtn);
}

export function unmount() {
    modalRoot = null;
    roleId = null;
}

async function fetchAndRender(id, editBtn) {
    try {
        const res  = await apiFetch(`/api/roles/${id}`, { method: 'GET' });
        const json = await res.json();
        if (!res.ok || !json.success) return;

        const role = json.data?.role;
        if (!role) return;

        const isSystem = (role.id === 1);

        // Fill header info
        const nameEl = document.getElementById('roleViewName');
        nameEl.textContent = role.name;
        if (isSystem) {
            const badge = document.createElement('span');
            badge.className = 'badge bg-warning text-dark ms-2';
            badge.textContent = 'System';
            nameEl.appendChild(badge);
        }

        const descEl = document.getElementById('roleViewDescription');
        descEl.textContent = role.description || 'No description provided';

        // Permission count
        const permissions = role.permissions || [];
        const countEl = document.getElementById('roleViewPermCount');
        countEl.textContent = `${permissions.length} permission${permissions.length !== 1 ? 's' : ''}`;

        // Group and render permissions (read-only)
        renderPermissionsReadOnly(permissions);

        // Show edit button if allowed
        if (editBtn && hasPermission('roles.edit') && !isSystem) {
            editBtn.style.display = '';
            editBtn.addEventListener('click', async () => {
                // Close this modal and open edit modal
                bootstrap.Modal.getInstance(modalRoot).hide();
                setTimeout(async () => {
                    await openModal({
                        scope: MODAL_SCOPE.PAGE,
                        template: 'html/Roles/roleForm.html',
                        controller: '../pages/Roles/roleForm.modal.js',
                        data: { role_id: id },
                        options: { backdrop: false, keyboard: true, focus: true }
                    });
                }, 300);
            });
        }
    } catch (err) {
        console.error('fetchAndRender failed:', err);
    }
}

function renderPermissionsReadOnly(permissions) {
    const container = document.getElementById('roleViewPermissions');
    container.innerHTML = '';

    if (permissions.length === 0) {
        container.innerHTML = '<p class="text-muted">No permissions assigned to this role.</p>';
        return;
    }

    // Group by prefix
    const groups = {};
    permissions.forEach(perm => {
        const dotIndex = perm.code.indexOf('.');
        const prefix = dotIndex > -1 ? perm.code.substring(0, dotIndex) : perm.code;
        if (!groups[prefix]) groups[prefix] = [];
        groups[prefix].push(perm);
    });

    const prefixes = Object.keys(groups).sort();

    prefixes.forEach(prefix => {
        const perms = groups[prefix];
        const label = getModuleLabel(prefix);
        const icon  = getModuleIcon(prefix);

        const card = document.createElement('div');
        card.className = 'card mb-2';

        const header = document.createElement('div');
        header.className = 'card-header py-2 d-flex align-items-center justify-content-between';
        header.style.cssText = 'background: #f8f9fc; padding-left: 1.5rem; padding-right: 1rem;';

        const headerLeft = document.createElement('div');
        headerLeft.className = 'd-flex align-items-center';

        const iconEl = document.createElement('i');
        iconEl.className = `fas ${icon} text-primary me-2`;
        iconEl.style.width = '16px';

        const labelEl = document.createElement('span');
        labelEl.className = 'fw-bold';
        labelEl.textContent = label;

        headerLeft.appendChild(iconEl);
        headerLeft.appendChild(labelEl);

        const groupBadge = document.createElement('span');
        groupBadge.className = 'badge bg-primary';
        groupBadge.textContent = `${perms.length}`;

        header.appendChild(headerLeft);
        header.appendChild(groupBadge);

        const body = document.createElement('div');
        body.className = 'card-body py-2 px-3';

        const row = document.createElement('div');
        row.className = 'row g-1';

        perms.forEach(perm => {
            const col = document.createElement('div');
            col.className = 'col-sm-6 col-md-4';

            const wrapper = document.createElement('div');
            wrapper.className = 'py-1 d-flex align-items-center';

            const checkIcon = document.createElement('i');
            checkIcon.className = 'fas fa-check-circle text-success me-2';
            checkIcon.style.fontSize = '0.85rem';

            const actionPart = perm.code.indexOf('.') > -1
                ? perm.code.substring(perm.code.indexOf('.') + 1)
                : perm.code;

            const text = document.createElement('span');
            text.className = 'text-capitalize small';
            text.textContent = actionPart.replace(/[._-]/g, ' ');

            wrapper.appendChild(checkIcon);
            wrapper.appendChild(text);
            col.appendChild(wrapper);
            row.appendChild(col);
        });

        body.appendChild(row);
        card.appendChild(header);
        card.appendChild(body);
        container.appendChild(card);
    });
}
