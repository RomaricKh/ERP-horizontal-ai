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
				message: `Toggle account active status for ${username}?`
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
				{ targets: '_all', className: 'text-nowrap' },
				{ targets: 8, className: 'text-center', orderable: false, searchable: false }
			]
		});
	}

	usersDataTable.clear();

	const canEdit = hasPermission('users.edit');
	const canDelete = hasPermission('users.delete');
	let actions = '';
	if (canEdit) {
		actions += `
			<button data-action="users.edit" title="users edit"
				class="btn btn-info btn-circle btn-sm me-1">
				<i class="fas fa-user-edit"></i>
			</button>`;
	}
	if (canDelete) {
		actions += `
			<button data-action="users.delete" title="users delete"
				class="btn btn-danger btn-circle btn-sm me-1">
				<i class="fas fa-user-times"></i>
			</button>`;
	}
	users.forEach(user => {
		const accountToggle = canEdit
			? (user.is_account_active
				? `<button data-action="users.toggleActive" class="btn btn-success btn-sm" title="Deactivate account"><i class="fas fa-toggle-on"></i> Active</button>`
				: `<button data-action="users.toggleActive" class="btn btn-secondary btn-sm" title="Activate account"><i class="fas fa-toggle-off"></i> Disabled</button>`)
			: (user.is_account_active
				? '<span class="badge badge-success text-white">Active</span>'
				: '<span class="badge badge-danger text-white">Disabled</span>');

		usersDataTable.row.add([
			user.user_id,
			user.username,
			user.name,
			user.email,
			user.role_name,
			user.business_name,
			user.is_active
				? '<span class="badge badge-success text-white">Active</span>'
				: '<span class="badge badge-danger text-white">Inactive</span>',
			accountToggle,
			actions
		]);
	});

	usersDataTable.draw(false);
	usersDataTable.columns.adjust();
}
