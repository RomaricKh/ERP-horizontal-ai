// assets/js/pages/Tenant/users.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer } from '../../components/modals.js';

let container;

export async function render(cont) {
    container = cont;
    container.innerHTML = `
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800" data-i18n="tenant_users.title">Tenant Users</h1>
            <button class="btn btn-primary btn-sm" id="btn-add-tenant-user">
                <i class="fas fa-user-plus me-1"></i> Add User
            </button>
        </div>
        <div class="card shadow mb-4">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Businesses</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tenant-users-body">
                            <tr><td colspan="5" class="text-center text-muted py-3">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <nav id="tenant-users-pagination"></nav>
            </div>
        </div>
    `;
}

export async function mount() {
    document.getElementById('btn-add-tenant-user')?.addEventListener('click', openCreateUserModal);
    document.addEventListener('tenant.users.updated', onUsersUpdated);
    await loadUsers();
}

export function unmount() {
    document.getElementById('btn-add-tenant-user')?.removeEventListener('click', openCreateUserModal);
    document.removeEventListener('tenant.users.updated', onUsersUpdated);
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

function onUsersUpdated() {
    loadUsers();
}

async function loadUsers(page = 1) {
    try {
        const params = new URLSearchParams({ page, per_page: 20 });
        const res = await apiFetch(`/api/tenant/users?${params}`);
        const body = await res.json();

        if (!res.ok || !body.success) {
            console.error('Failed to load tenant users:', body.message);
            return;
        }

        renderUsersList(body.data?.data || body.data || []);
    } catch (err) {
        console.error('Tenant users mount error:', err);
    }
}

function renderUsersList(users) {
    const tbody = document.getElementById('tenant-users-body');
    if (!tbody) return;

    if (!users.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No users found.</td></tr>';
        return;
    }

    tbody.innerHTML = users.map(user => `
        <tr>
            <td>${escHtml(user.username || user.name || '')}</td>
            <td>${escHtml(user.email || '')}</td>
            <td>${(user.businesses || []).map(b => escHtml(b.name)).join(', ') || '—'}</td>
            <td>${user.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary me-1" data-view-user="${user.user_id}"><i class="fas fa-eye"></i></button>
                <button class="btn btn-sm btn-outline-warning" data-deactivate-user="${user.user_id}"><i class="fas fa-ban"></i></button>
            </td>
        </tr>
    `).join('');
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
