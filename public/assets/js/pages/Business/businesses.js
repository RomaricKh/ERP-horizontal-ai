// JavaScript Document

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { invalidateAndReconnect } from '../../Shells/appShell.js';
import { getFormatter } from '../../i18n/translationClient.js';

let business_container;
let unregister;
let businessUpdatedHandler;
let businessDataTable = null;

export async function mount() {
	await fetchBusinesses();

	businessUpdatedHandler = async () => {
		await fetchBusinesses();
	};

	document.addEventListener('businesses.updated', businessUpdatedHandler);

	unregister = registerPageActions({
		'businesses.create': async () => {
			await openModal({
				scope: MODAL_SCOPE.PAGE,
				template: 'html/Business/businessForm.html',
				controller: '../pages/Business/businessForm.modal.js',
				data: {},
				options: { backdrop: false, keyboard: true, focus: true }
			});
		},

		'businesses.edit': async ({ el }) => {
			const table = businessDataTable;
			const tr = el.closest('tr');
			const rowData = table.row(tr).data();

			const business_id = rowData[0];
			if (!business_id) return;

			await openModal({
				scope: MODAL_SCOPE.PAGE,
				template: 'html/Business/businessForm.html',
				controller: '../pages/Business/businessForm.modal.js',
				data: { business_id },
				options: { backdrop: false, keyboard: true, focus: true }
			});
		},

		'businesses.delete': async ({ el }) => {
			const table = businessDataTable;
			const tr = el.closest('tr');
			const rowData = table.row(tr).data();

			const business_id = rowData[0];
			const name = rowData[1];

			if (!business_id) return;

			const confirmed = await confirm({
				title: 'Delete business',
				message: `Delete the business "${name}" and all its data?`
			});

			if (confirmed) {
				try {
					const res = await apiFetch(`/api/businesses/${business_id}`, {
						method: 'DELETE'
					});

					const data = await res.json();

					if (!res.ok || !data.success) {
						await alert({
							title: 'Delete business',
							alertText: data.message || 'Failed to delete business'
						});
					} else {
						await fetchBusinesses();
						await alert({
							title: 'Delete business',
							alertText: `The business "${name}" was deleted successfully.`
						});
					}
				} catch (err) {
					console.error('Delete business failed:', err);
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

function renderBusinessTable(businesses) {
	if (!businessDataTable) {
		businessDataTable = createDataTable('#dataTable', {
			columnDefs: [
				{ targets: 0, visible: false, searchable: false },
				{ targets: '_all', className: 'text-nowrap' },
				{ targets: 7, className: 'text-center', orderable: false, searchable: false }
			]
		});
	}

	businessDataTable.clear();

	const canEdit = hasPermission('businesses.edit');
	const canDelete = hasPermission('businesses.delete');
	let actions = '';
	if (canEdit) {
		actions += `
			<button data-action="businesses.edit" title="Edit business"
				class="btn btn-info btn-circle btn-sm me-1">
				<i class="fas fa-edit"></i>
			</button>`;
	}
	if (canDelete) {
		actions += `
			<button data-action="businesses.delete" title="Delete business"
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
			business.phone ? getFormatter().formatPhone(business.phone) : '',
			business.email,
			business.currency,
			actions
		]);
	});

	businessDataTable.draw(false);
	businessDataTable.columns.adjust();
}
