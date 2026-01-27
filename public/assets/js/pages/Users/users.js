// JavaScript Document

import { getDefaultAuthRoute } from '../../router/router.config.js';
import { getToken, hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer } from '../../components/modals.js';

let unregister;

export async function mount() {
    unregister = registerPageActions({
        'users.create': async () => await openModal({
			scope: MODAL_SCOPE.PAGE,
			template: 'html/Users/userForm.html',
			controller: '../pages/Users/userForm.modal.js',
			data: { user_id: '' },
			options: { backdrop: false, keyboard: true, focus: true }
		}),
        'users.edit': async ({ el }) => {
            const user_id = el.closest('tr')?.dataset.user_id;
            if (user_id) {
				await openModal({
					scope: MODAL_SCOPE.PAGE,
					template: 'html/Users/userForm.html',
					controller: '../pages/Users/userForm.modal.js',
					data: { user_id: user_id },
					options: { backdrop: false, keyboard: true, focus: true }
				});
			}
        },
        'users.delete': async ({ el }) => {
            const user_id = el.closest('tr')?.dataset.user_id;
            const username = el.closest('tr')?.dataset.username;
			if (username) {
				await openModal({
					scope: MODAL_SCOPE.GLOBAL,
					template: 'html/components/ConfirmationModal.html',
					controller: '../pages/Users/deleteUser.modal.js',
					data: { username: username, user_id: user_id },
					options: { backdrop: 'static', keyboard: true, focus: true }
				});
			}
        }
    });
}

export function unmount() {
    if (unregister) {
        unregister();
        unregister = null;
    }
	closeModalsInContainer(document.getElementById('content'));
}

export async function render(container) {
	const html = await fetch(`html/Users/users.html`).then(r => r.text());
    container.innerHTML = html;
	container.querySelectorAll('[data-action]')
	  .forEach(el => {
		  const permission = el.dataset.action;
		  if (!hasPermission(permission)) {
			  el.remove();
		  }
	  });
	await fetchUsers();
	
	const datatablesDesignScript = document.createElement('script');
	datatablesDesignScript.src = `assets/js/templates/datatables.design.js`;
	container.appendChild(datatablesDesignScript);
}

async function fetchUsers() {
	const tbody = document.querySelector('tbody');
	tbody.innerHTML = '';
	const token = getToken();
	const savedBusinessId = localStorage.getItem('business_id');
	
	try {
		const res = await fetch('api/users', {
			method: 'GET',
    		credentials: 'include',
			headers: {
				'Authorization': `Bearer ${token}`,
				'X-Business-Id': savedBusinessId,
				'Accept': 'application/json'
			}
		});

        if (!res.ok) {
            throw new Error(`HTTP error ${res.status}`);
        }

		const data = await res.json();
		
		if (!data.success){
            console.error(data.message || 'Failed to fetch users');
            return;			
		} else{
			const canEdit = hasPermission('users.edit');
			const canDelete = hasPermission('users.delete');	
			const users = data.data.users;
			for (const user of users){
				const tr = document.createElement('tr');
				tr.dataset.user_id = user.user_id;
				tr.dataset.username = user.username;
				const statusClass = user.is_active ? 'badge-success' : 'badge-danger';
				const statusLabel = user.is_active ? 'Active' : 'Inactive';
				tr.innerHTML = `
					<td>${user.user_id}</td>
					<td>${user.username}</td>
					<td>${user.name}</td>
					<td>${user.email}</td>
					<td>${user.role_name}</td>
					<td>${user.business_name}</td>
					<td>
						<span class="badge ${statusClass} text-white">
							${statusLabel}
						</span>
					</td>
				`;

				const actionsTd = document.createElement('td');
				actionsTd.style = "text-align: center";

				if (canEdit) {
					const btnEdit = document.createElement('button');
					btnEdit.type = 'button';
					btnEdit.title = 'users edit';
					btnEdit.setAttribute('data-action', 'users.edit');
					btnEdit.className = 'btn btn-info btn-circle btn-sm mr-1';
					btnEdit.innerHTML = `<i class="fas fa-fw fa-user-edit"></i>`;
					actionsTd.appendChild(btnEdit);
				}

				if (canDelete) {
					const btnDelete = document.createElement('button');
					btnDelete.type = 'button';
					btnDelete.title = 'users delete';
					btnDelete.setAttribute('data-action', 'users.delete');
					btnDelete.className = 'btn btn-danger btn-circle btn-sm mr-1';
					btnDelete.innerHTML = `<i class="fas fa-fw fa-user-times"></i>`;
					actionsTd.appendChild(btnDelete);
				}

				tr.appendChild(actionsTd);
				
				tbody.appendChild(tr);
			}			
		}		
	} catch (err){
        console.error('fetchUsers failed:', err);		
	}
}


