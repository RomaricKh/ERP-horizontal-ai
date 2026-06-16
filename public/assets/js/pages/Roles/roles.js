// assets/js/pages/Roles/roles.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

let roles_container;
let unregister;
let rolesUpdatedHandler;
let rolesDataTable = null;
let templateDataTable = null;

export async function mount() {
    await fetchRoles();

    rolesUpdatedHandler = async () => { await fetchRoles(); };
    document.addEventListener('roles.updated', rolesUpdatedHandler);

    unregister = registerPageActions({

        'roles.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Roles/roleForm.html',
                controller: '../pages/Roles/roleForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'roles.view': async ({ el }) => {
            const tr      = el.closest('tr');
            const table   = el.closest('table');
            const dt      = table.id === 'templateRolesTable' ? templateDataTable : rolesDataTable;
            const rowData = dt.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Roles/roleView.html',
                controller: '../pages/Roles/roleView.modal.js',
                data: { role_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'roles.edit': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = rolesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Roles/roleForm.html',
                controller: '../pages/Roles/roleForm.modal.js',
                data: { role_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'roles.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = rolesDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1]; // hidden raw name column
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete role',
                message: `Delete the role "${name}"? This action cannot be undone.`
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/roles/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete role' });
                } else {
                    document.dispatchEvent(new CustomEvent('roles.updated'));
                }
            } catch (err) {
                console.error('Delete role failed:', err);
            }
        },

        'roles.clone': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = templateDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1];
            if (!id) return;

            // Clear any previous inline error on this row
            const existingError = tr.querySelector('.clone-error');
            if (existingError) existingError.remove();

            try {
                const res  = await apiFetch(`/api/roles/clone/${id}`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    // Display inline error without page refresh
                    const errorMsg = data.message || 'Failed to clone role';
                    const actionsCell = tr.querySelector('td:last-child');
                    if (actionsCell) {
                        const errorEl = document.createElement('div');
                        errorEl.className = 'clone-error text-danger small mt-1';
                        errorEl.textContent = errorMsg;
                        actionsCell.appendChild(errorEl);
                    } else {
                        await alert({ title: 'Error', alertText: errorMsg });
                    }
                } else {
                    document.dispatchEvent(new CustomEvent('roles.updated'));
                }
            } catch (err) {
                console.error('Clone role failed:', err);
            }
        }
    });
}

export function unmount() {
    if (rolesUpdatedHandler) {
        document.removeEventListener('roles.updated', rolesUpdatedHandler);
        rolesUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    rolesDataTable?.destroy();
    rolesDataTable = null;
    templateDataTable?.destroy();
    templateDataTable = null;
    closeModalsInContainer(roles_container);
}

export async function render(container) {
    roles_container = container;
    const html = await fetch('html/Roles/roles.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    container.querySelectorAll('[data-action]').forEach(el => {
        const action = el.dataset.action;
        const permMap = {
            'roles.create': 'roles.create',
        };
        const perm = permMap[action];
        if (perm && !hasPermission(perm)) el.remove();
    });
}

async function fetchRoles() {
    const res  = await apiFetch('/api/roles');
    const data = await res.json();
    if (!data.success) return;

    const rolesData = data.data.roles ?? {};
    const businessRoles = rolesData.business_roles ?? [];
    const templateRoles = rolesData.template_roles ?? [];

    renderBusinessRolesTable(businessRoles);
    renderTemplateRolesTable(templateRoles);
}

function renderBusinessRolesTable(roles) {
    if (!rolesDataTable) {
        rolesDataTable = createDataTable('#rolesTable', {
            columnDefs: [
                { targets: [0, 1], visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 4, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    rolesDataTable.clear();

    const canEdit   = hasPermission('roles.edit');
    const canDelete = hasPermission('roles.delete');

    roles.forEach(role => {
        const isProtected = role.type === 'SYSTEM_OWNER' || role.type === 'TENANT_OWNER';

        // Role name with badge for protected system roles — clickable to view details
        let nameHtml = `<button data-action="roles.view" class="btn btn-link p-0 text-primary fw-bold text-decoration-none">${escHtml(role.name)}</button>`;
        if (isProtected) {
            nameHtml += ' <span class="badge badge-warning text-dark ml-1"><i class="fas fa-lock mr-1"></i>System</span>';
        }

        // Actions: protected system roles cannot be edited or deleted — omit the buttons entirely
        let actions = '';
        if (isProtected) {
            actions = '<span class="text-muted small"><i class="fas fa-lock mr-1"></i>Protected</span>';
        } else {
            if (canEdit) {
                actions += `<button data-action="roles.edit" class="btn btn-light btn-circle btn-sm me-1" title="Edit">
                                <i class="fas fa-edit text-info"></i></button>`;
            }
            if (canDelete) {
                actions += `<button data-action="roles.delete" class="btn btn-light btn-circle btn-sm me-1" title="Delete">
                                <i class="fas fa-trash text-danger"></i></button>`;
            }
        }

        rolesDataTable.row.add([
            role.id,
            role.name,
            nameHtml,
            escHtml(role.description ?? '—'),
            actions
        ]);
    });

    rolesDataTable.draw(false);
    rolesDataTable.columns.adjust();
}

function renderTemplateRolesTable(templates) {
    const card = document.getElementById('templateRolesCard');
    if (!card) return;

    // Show/hide the template card based on whether templates exist
    if (!templates || templates.length === 0) {
        card.style.display = 'none';
        return;
    }
    card.style.display = '';

    if (!templateDataTable) {
        templateDataTable = createDataTable('#templateRolesTable', {
            columnDefs: [
                { targets: [0, 1], visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 4, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    templateDataTable.clear();

    const canCreate = hasPermission('roles.create');

    templates.forEach(role => {
        // Template role name — clickable to view details
        let nameHtml = `<button data-action="roles.view" class="btn btn-link p-0 text-primary fw-bold text-decoration-none">${escHtml(role.name)}</button>`;
        nameHtml += ' <span class="badge badge-secondary ml-1">Template</span>';

        // Actions: Clone to Business button
        let actions = '';
        if (canCreate) {
            actions += `<button data-action="roles.clone" class="btn btn-success btn-sm" title="Clone to Business">
                            <i class="fas fa-copy mr-1"></i>Clone to Business</button>`;
        }

        templateDataTable.row.add([
            role.id,
            role.name,
            nameHtml,
            escHtml(role.description ?? '—'),
            actions
        ]);
    });

    templateDataTable.draw(false);
    templateDataTable.columns.adjust();
}
