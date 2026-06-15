// assets/js/pages/Tenant/users.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';

let container;
let dataTable = null;
let updatedHandler;

export async function render(cont) {
    container = cont;
    const html = await fetch('html/Tenant/users.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    document.getElementById('btn-add-tenant-user')?.addEventListener('click', openCreateUserModal);
    updatedHandler = () => loadUsers();
    document.addEventListener('tenant.users.updated', updatedHandler);
    await loadUsers();
}

export function unmount() {
    document.getElementById('btn-add-tenant-user')?.removeEventListener('click', openCreateUserModal);
    document.removeEventListener('tenant.users.updated', updatedHandler);
    updatedHandler = null;
    dataTable?.destroy();
    dataTable = null;
    closeModalsInContainer(container);
}

async function openCreateUserModal() {
    await openModal({
        scope: MODAL_SCOPE.PAGE,
        template: 'html/Tenant/userForm.html',
        controller: '../pages/Tenant/userForm.modal.js',
        data: {},
        options: { backdrop: false, keyboard: true, focus: true }
    });
}

async function loadUsers(page = 1) {
    try {
        const params = new URLSearchParams({ page, per_page: 50 });
        const res = await apiFetch(`/api/tenant/users?${params}`);
        const body = await res.json();

        if (!res.ok || !body.success) {
            console.error('Failed to load tenant users:', body.message);
            return;
        }

        renderUsersTable(body.data?.data || []);
    } catch (err) {
        console.error('Tenant users load error:', err);
    }
}

function renderUsersTable(users) {
    if (!dataTable) {
        dataTable = createDataTable('#tenantUsersTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 5, className: 'text-center' },
                { targets: 6, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    dataTable.clear();

    users.forEach(user => {
        const businesses = (user.assignments || []).map(a => a.business_name).join(', ') || '—';

        // Determine overall status: active if ANY assignment is active
        const anyActive = (user.assignments || []).some(a => a.is_active === 1);
        const allInactive = (user.assignments || []).length > 0 && !anyActive;

        let statusHtml;
        if (allInactive) {
            statusHtml = `<button class="btn btn-outline-danger btn-sm" data-activate-user="${user.user_id}" title="Deactivated across all businesses. Click to reactivate.">
                <i class="fas fa-ban me-1"></i>Deactivated
            </button>`;
        } else {
            statusHtml = `<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>`;
        }

        let actionsHtml = '';
        if (!allInactive) {
            actionsHtml += `<button class="btn btn-sm btn-outline-danger" data-deactivate-user="${user.user_id}" title="Deactivate across all businesses">
                <i class="fas fa-user-slash"></i>
            </button>`;
        }

        dataTable.row.add([
            user.user_id,
            escHtml(user.username || ''),
            escHtml(`${user.first_name || ''} ${user.last_name || ''}`),
            escHtml(user.email || ''),
            escHtml(businesses),
            statusHtml,
            actionsHtml
        ]);
    });

    dataTable.draw(false);
    dataTable.columns.adjust();

    // Wire up event listeners on the table body
    const tbody = document.querySelector('#tenantUsersTable tbody');
    if (tbody) {
        tbody.removeEventListener('click', handleTableClick);
        tbody.addEventListener('click', handleTableClick);
    }
}

async function handleTableClick(e) {
    const deactivateBtn = e.target.closest('[data-deactivate-user]');
    const activateBtn = e.target.closest('[data-activate-user]');

    if (deactivateBtn) {
        const userId = deactivateBtn.dataset.deactivateUser;
        await handleDeactivate(userId);
    } else if (activateBtn) {
        const userId = activateBtn.dataset.activateUser;
        await handleActivate(userId);
    }
}

async function handleDeactivate(userId) {
    const confirmed = await confirm({
        title: 'Deactivate User',
        message: 'This will deactivate the user across ALL businesses in this tenant. They will not be able to access any business until reactivated. Continue?'
    });

    if (!confirmed) return;

    try {
        const res = await apiFetch(`/api/tenant/users/${userId}/deactivate`, { method: 'PUT' });
        const body = await res.json();

        if (!res.ok || !body.success) {
            await alert({ title: 'Deactivate Failed', alertText: body.message || 'Failed to deactivate user.' });
            return;
        }

        await loadUsers();
    } catch (err) {
        console.error('Deactivate user error:', err);
    }
}

async function handleActivate(userId) {
    const confirmed = await confirm({
        title: 'Reactivate User',
        message: 'This will reactivate the user across ALL businesses in this tenant. Continue?'
    });

    if (!confirmed) return;

    try {
        const res = await apiFetch(`/api/tenant/users/${userId}/activate`, { method: 'PUT' });
        const body = await res.json();

        if (!res.ok || !body.success) {
            await alert({ title: 'Reactivate Failed', alertText: body.message || 'Failed to reactivate user.' });
            return;
        }

        await loadUsers();
    } catch (err) {
        console.error('Reactivate user error:', err);
    }
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
