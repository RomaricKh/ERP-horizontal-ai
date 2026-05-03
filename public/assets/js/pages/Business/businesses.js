// JavaScript Document

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let business_container;
let unregister;
let businessUpdatedHandler;
let businessSelfModifiedHandler;
let businessDataTable = null;

export async function mount() {
	await fetchBusinesses();	

	businessUpdatedHandler = async () => {
		await fetchBusinesses();
	};
	businessSelfModifiedHandler = async () => {
		await invalidateAndReconnect();
	};

	document.addEventListener('businesses.updated', businessUpdatedHandler);
	document.addEventListener('businesses.selfModified', businessSelfModifiedHandler);
	
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

				const confirmed = await confirm({
					title: 'Delete business',
					message: `Delete the business ${name} and all it's data?`
				});
				if (confirmed) {
					
				}
			}
        }
    });
}

export function unmount() {
	if (businessUpdatedHandler) {
		document.removeEventListener('businesses.updated', businessUpdatedHandler);
		businessUpdatedHandler = null;
	}
	if (businessSelfModifiedHandler) {
		document.removeEventListener('businesses.selfModified', businessSelfModifiedHandler);
		businessSelfModifiedHandler = null;
	}
    
	unregister?.();
	unregister = null;

	businessDataTable?.destroy();
	businessDataTable = null;

	closeModalsInContainer(business_container);
}

export async function render(container) {
	business_container = container;
	const html = await fetch(`html/Business/businesses.html`).then(r => r.text());
    container.innerHTML = html;
	container.querySelectorAll('[data-action]')
	  .forEach(el => {
		  const permission = el.dataset.action;
		  if (!hasPermission(permission)) {
			  el.remove();
		  }
	  });
}

async function fetchBusinesses() {
	const res = await apiFetch('/api/businesses');

	const data = await res.json();
	if (!data.success) return;

	renderBusinessTable(data.data.businesses);
}

function renderBusinessTable(businesses){
	const table = document.getElementById('dataTable');

	if (!businessDataTable) {
		businessDataTable = createDataTable('#dataTable', {
			columnDefs: [
				{ targets: 0, visible: false, searchable: false },
				{ targets: '_all', className: 'text-nowrap' },
				{ targets: 8, className: 'text-center', orderable: false, searchable: false }
			]
		});
	}

	businessDataTable.clear();
	
	const canEdit = hasPermission('businesses.edit');
	const canDelete = hasPermission('businesses.delete');	
	let actions = '';
	if (canEdit) {
		actions += `
			<button data-action="businesses.edit" title="businesses edit"
				class="btn btn-info btn-circle btn-sm me-1">
				<i class="fas fa-edit"></i>
			</button>`;
	}
	if (canDelete) {
		actions += `
			<button data-action="businesses.delete" title="businesses delete"
				class="btn btn-danger btn-circle btn-sm me-1">
				<i class="fas fa-trash"></i>
			</button>`;
	}
	businesses.forEach(business => {
		businessDataTable.row.add([
			business.id,
			business.name,
			business.legal_name,
			business.registration_no,
			business.address,
			business.phone,
			business.email,
			business.currency,
			actions
		]);
	});

	businessDataTable.draw(false);
	businessDataTable.columns.adjust();
}

