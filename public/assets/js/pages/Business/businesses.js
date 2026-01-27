// JavaScript Document

import { getDefaultAuthRoute } from '../../router/router.config.js';
import { getToken, hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer } from '../../components/modals.js';

let unregister;

export async function mount() {
    unregister = registerPageActions({
        'businesses.create': async () => await openModal({
			scope: MODAL_SCOPE.PAGE,
			template: 'html/Users/userForm.html',
			controller: '../pages/Users/userForm.modal.js',
			data: { user_id: '' },
			options: { backdrop: false, keyboard: true, focus: true }
		}),
        'businesses.edit': async ({ el }) => {
            const business_id = el.closest('tr')?.dataset.business_id;
            if (business_id) {
				await openModal({
					scope: MODAL_SCOPE.PAGE,
					template: 'html/Users/userForm.html',
					controller: '../pages/Users/userForm.modal.js',
					data: { user_id: business_id },
					options: { backdrop: false, keyboard: true, focus: true }
				});
			}
        },
        'businesses.delete': async ({ el }) => {
            const business_id = el.closest('tr')?.dataset.business_id;
            const name = el.closest('tr')?.dataset.name;
			if (name) {
				await openModal({
					scope: MODAL_SCOPE.GLOBAL,
					template: 'html/components/ConfirmationModal.html',
					controller: '../pages/Business/deleteBusiness.modal.js',
					data: { name: name, business_id: business_id },
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
	const html = await fetch(`html/Business/businesses.html`).then(r => r.text());
    container.innerHTML = html;
	container.querySelectorAll('[data-action]')
	  .forEach(el => {
		  const permission = el.dataset.action;
		  if (!hasPermission(permission)) {
			  el.remove();
		  }
	  });
	await fetchBusinesses();	
	
	const datatablesDesignScript = document.createElement('script');
	datatablesDesignScript.src = `assets/js/templates/datatables.design.js`;
	container.appendChild(datatablesDesignScript);
}

async function fetchBusinesses() {
	const tbody = document.querySelector('tbody');
	tbody.innerHTML = '';
	const token = getToken();
	const savedBusinessId = localStorage.getItem('business_id');
	
	try {
		const res = await fetch('api/businesses', {
			method: 'GET',
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
            console.error(data.message || 'Failed to fetch businesses');
            return;			
		} else{
			const canEdit = hasPermission('businesses.edit');
			const canDelete = hasPermission('businesses.delete');	
			const businesses = data.data.businesses;
			for (const business of businesses){
				const tr = document.createElement('tr');
				tr.dataset.business_id = business.id;
				tr.dataset.name = business.name;
				tr.innerHTML = `
					<td>${business.id}</td>
					<td>${business.name}</td>
					<td>${business.legal_name}</td>
					<td>${business.registration_no}</td>
					<td>${business.address}</td>
					<td>${business.phone}</td>
					<td>${business.email}</td>
					<td>${business.currency}</td>
				`;

				const actionsTd = document.createElement('td');
				actionsTd.style = "text-align: center";

				if (canEdit) {
					const btnEdit = document.createElement('button');
					btnEdit.type = 'button';
					btnEdit.title = 'businesses edit';
					btnEdit.setAttribute('data-action', 'businesses.edit');
					btnEdit.className = 'btn btn-info btn-circle btn-sm mr-1';
					btnEdit.innerHTML = `<i class="fas fa-fw fa-edit"></i>`;
					actionsTd.appendChild(btnEdit);
				}

				if (canDelete) {
					const btnDelete = document.createElement('button');
					btnDelete.type = 'button';
					btnDelete.title = 'businesses delete';
					btnDelete.setAttribute('data-action', 'businesses.delete');
					btnDelete.className = 'btn btn-danger btn-circle btn-sm mr-1';
					btnDelete.innerHTML = `<i class="fas fa-fw fa-trash"></i>`;
					actionsTd.appendChild(btnDelete);
				}

				tr.appendChild(actionsTd);
				
				tbody.appendChild(tr);
			}			
		}		
	} catch (err){
        console.error('fetchBusinesses failed:', err);		
	}
}


