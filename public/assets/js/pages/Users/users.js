// JavaScript Document

import { getToken, hasPermission } from '../../Auth/auth.js';
import { registerPageActions, handlePageAction } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { invalidateAndReconnect } from '../../Shells/appShell.js';

let users_container;
let unregister;
let usersUpdatedHandler;
let usersSelfModifiedHandler;
let usersDataTable = null;

export async function mount() {
	await fetchUsers();

	usersUpdatedHandler = async () => {
		await fetchUsers();
	};
	usersSelfModifiedHandler = async () => {
		await invalidateAndReconnect();
	};

	document.addEventListener('users.updated', usersUpdatedHandler);
	document.addEventListener('users.selfModified', usersSelfModifiedHandler);

	unregister = registerPageActions({
		'users.create': async () => {
			await openModal({
				scope: MODAL_SCOPE.PAGE,
				template: 'html/Users/userForm.html',
				controller: '../pages/Users/userForm.modal.js',
				data: { user_id: '' },
				options: { backdrop: false, keyboard: true, focus: true }
			});
		},

		'users.edit': async ({ el }) => {
			const table = usersDataTable;
			const tr = el.closest('tr');
			const rowData = table.row(tr).data();

			const user_id = rowData[0]; // first column
			if (!user_id) return;

			await openModal({
				scope: MODAL_SCOPE.PAGE,
				template: 'html/Users/userForm.html',
				controller: '../pages/Users/userForm.modal.js',
				data: { user_id },
				options: { backdrop: false, keyboard: true, focus: true }
			});
		},

		'users.delete': async ({ el }) => {
			const table = usersDataTable;
			const tr = el.closest('tr');
			const rowData = table.row(tr).data();
			
			const user_id = rowData[0];
			const username = rowData[1];

			if (!user_id) return;

			const confirmed = await confirm({
				title: 'Delete user',
				message: `Remove ${username} from this business?`
			});

			if (confirmed) {
				try {
					const res = await apiFetch(`/api/users/${user_id}`, {
						method: 'DELETE'
					});

					if (!res.ok) {
						throw new Error(`HTTP error ${res.status}`);
					}

					const data = await res.json();

					if (!data.success){
						await alert({
							title: 'Delete user',
							alertText: data.message || 'Failed to fetch users'
						});
					} else{
						if (data.data.selfDeleted){
							await invalidateAndReconnect();
						} else {
							await fetchUsers();
						}
						await alert({
							title: 'Delete user',
							alertText: `The user ${username} was removed successfully from business.`
						});
					}
				} catch (err){
					console.error('fetchUsers failed:', err);		
				}
			}
		},

		'users.toggleActive': async ({ el }) => {
			const table = usersDataTable;
			const tr = el.closest('tr');
			const rowData = table.row(tr).data();

			const user_id = rowData[0];
			const username = rowData[1];

			if (!user_id) return;

			const confirmed = await confirm({
				title: 'Toggle Account Status',
				message: `This will enable/disable the entire account for "${username}" across ALL businesses. Continue?`
			});

			if (confirmed) {
				try {
					const res = await apiFetch(`/api/users/${user_id}/active`, {
						method: 'PATCH'
					});

					const data = await res.json();

					if (!data.success) {
						await alert({
							title: 'Toggle Account',
							alertText: data.message || 'Failed to toggle account status'
						});
					} else {
						await fetchUsers();
					}
				} catch (err) {
					console.error('toggleActive failed:', err);
				}
			}
		},

		'users.toggleStatus': async ({ el }) => {
			const table = usersDataTable;
			const tr = el.closest('tr');
			const rowData = table.row(tr).data();

			const user_id = rowData[0];
			const username = rowData[1];

			if (!user_id) return;

			// Determine current business-level status from the button
			const isCurrentlyActive = el.querySelector('.fa-toggle-on') !== null;
			const newState = isCurrentlyActive ? 0 : 1;
			const action = isCurrentlyActive ? 'deactivate' : 'activate';

			const confirmed = await confirm({
				title: 'Toggle Business Access',
				message: `This will ${action} "${username}" in this business only. Continue?`
			});

			if (confirmed) {
				try {
					const res = await apiFetch(`/api/users/${user_id}/status`, {
						method: 'PATCH',
						body: JSON.stringify({ is_active: newState })
					});

					const data = await res.json();

					if (!data.success) {
						await alert({
							title: 'Toggle Business Access',
							alertText: data.message || 'Failed to toggle business status'
						});
					} else {
						await fetchUsers();
					}
				} catch (err) {
					console.error('toggleStatus failed:', err);
				}
			}
		}
	});
}

export function unmount() {
	if (usersUpdatedHandler) {
		document.removeEventListener('users.updated', usersUpdatedHandler);
		usersUpdatedHandler = null;
	}
	if (usersSelfModifiedHandler) {
		document.removeEventListener('users.selfModified', usersSelfModifiedHandler);
		usersSelfModifiedHandler = null;
	}

	unregister?.();
	unregister = null;

	usersDataTable?.destroy();
	usersDataTable = null;

	closeModalsInContainer(users_container);
}

export async function render(container) {
	users_container = container;

	const html = await fetch('html/Users/users.html').then(r => r.text());
	container.innerHTML = html;

	container.querySelectorAll('[data-action]').forEach(el => {
		if (!hasPermission(el.dataset.action)) {
			el.remove();
		}
	});
}

async function fetchUsers() {
	const res = await apiFetch('/api/users');

	const data = await res.json();
	if (!data.success) return;

	renderUsersTable(data.data.users);
}

function renderUsersTable(users) {
	const table = document.getElementById('dataTable');

	if (!usersDataTable) {
		usersDataTable = createDataTable('#dataTable', {
			columnDefs: [
				{ targets: 0, visible: false, searchable: false },
				{ targets: [1, 2, 3, 4, 5], className: 'text-nowrap' },
				{ targets: 6, className: 'text-center', width: '160px' },
				{ targets: 7, className: 'text-center', orderable: false, searchable: false }
			]
		});
	}

	usersDataTable.clear();

	const canEdit = hasPermission('users.edit');
	const canDelete = hasPermission('users.delete');
	let actions = '';
	if (canEdit) {
		actions += `
			<button data-action="users.edit" title="Edit user"
				class="btn btn-info btn-circle btn-sm me-1">
				<i class="fas fa-user-edit"></i>
			</button>`;
	}
	if (canDelete) {
		actions += `
			<button data-action="users.delete" title="Remove from business"
				class="btn btn-danger btn-circle btn-sm me-1">
				<i class="fas fa-user-times"></i>
			</button>`;
	}
	users.forEach(user => {
		// Build a unified status column showing both account and business status
		let statusHtml = '';
		if (!user.is_account_active) {
			// Account disabled — this overrides everything
			statusHtml = canEdit
				? `<button data-action="users.toggleActive" class="btn btn-outline-danger btn-sm d-block w-100 mb-1" title="Account is disabled globally. Click to activate.">
					<i class="fas fa-ban me-1"></i>Account Disabled
				   </button>
				   <small class="text-muted"><i class="fas fa-info-circle me-1"></i>Cannot access any business</small>`
				: `<span class="badge bg-danger text-white d-block mb-1"><i class="fas fa-ban me-1"></i>Account Disabled</span>
				   <small class="text-muted">Cannot access any business</small>`;
		} else if (!user.is_active) {
			// Account active but inactive in this business
			statusHtml = canEdit
				? `<span class="badge bg-success mb-1" title="Account is active globally"><i class="fas fa-user-check me-1"></i>Account Active</span><br>
				   <button data-action="users.toggleStatus" class="btn btn-outline-warning btn-sm mt-1" title="Inactive in this business. Click to activate.">
					<i class="fas fa-toggle-off me-1"></i>Inactive here
				   </button>`
				: `<span class="badge bg-success mb-1"><i class="fas fa-user-check me-1"></i>Account Active</span><br>
				   <span class="badge bg-warning text-dark mt-1"><i class="fas fa-toggle-off me-1"></i>Inactive here</span>`;
		} else {
			// Fully active — both account and business
			statusHtml = canEdit
				? `<span class="badge bg-success mb-1" title="Account is active globally"><i class="fas fa-user-check me-1"></i>Account Active</span><br>
				   <button data-action="users.toggleStatus" class="btn btn-outline-success btn-sm mt-1" title="Active in this business. Click to deactivate.">
					<i class="fas fa-toggle-on me-1"></i>Active here
				   </button>`
				: `<span class="badge bg-success mb-1"><i class="fas fa-user-check me-1"></i>Account Active</span><br>
				   <span class="badge bg-success mt-1"><i class="fas fa-toggle-on me-1"></i>Active here</span>`;
		}

		usersDataTable.row.add([
			user.user_id,
			user.username,
			user.name,
			user.email,
			user.role_name,
			user.business_name,
			statusHtml,
			actions
		]);
	});

	usersDataTable.draw(false);
	usersDataTable.columns.adjust();
}
