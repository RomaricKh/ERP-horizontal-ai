// assets/js/pages/Tenant/businesses.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
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
				template: 'html/Tenant/businessForm.html',
				controller: '../pages/Tenant/businessForm.modal.js',
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
				template: 'html/Tenant/businessForm.html',
				controller: '../pages/Tenant/businessForm.modal.js',
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
				message: `This will permanently delete "${name}". This only works if the business has no users, transactions, or other data. Are you sure?`
			});

			if (confirmed) {
				try {
					const res = await apiFetch(`/api/tenant/businesses/${business_id}`, {
						method: 'DELETE'
					});

					const data = await res.json();

					if (!res.ok || !data.success) {
						await alert({
							title: 'Cannot delete business',
							alertText: data.message || 'Failed to delete business. The business may still contain data.'
						});
					} else {
						await fetchBusinesses();
						await alert({
							title: 'Business deleted',
							alertText: `The business "${name}" was deleted successfully.`
						});
					}
				} catch (err) {
					console.error('Delete business failed:', err);
				}
			}
		},

		'businesses.toggleActive': async ({ el }) => {
			const table = businessDataTable;
			const tr = el.closest('tr');
			const rowData = table.row(tr).data();

			const business_id = rowData[0];
			const name = rowData[1];

			if (!business_id) return;

			// Determine current state from the status badge column (index 8)
			const isCurrentlyActive = rowData[8] && rowData[8].includes('bg-success');
			const newState = !isCurrentlyActive;
			const action = newState ? 'activate' : 'deactivate';

			const message = newState
				? `Activate the business "${name}"? Users will regain access.`
				: `Deactivate the business "${name}"? Users will lose access but all data is preserved.`;

			const confirmed = await confirm({
				title: `${newState ? 'Activate' : 'Deactivate'} Business`,
				message
			});

			if (confirmed) {
				try {
					const res = await apiFetch(`/api/businesses/${business_id}/active`, {
						method: 'PATCH',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ active: newState })
					});

					const data = await res.json();

					if (!res.ok || !data.success) {
						await alert({
							title: `${newState ? 'Activate' : 'Deactivate'} Business`,
							alertText: data.message || `Failed to ${action} business`
						});
					} else {
						await fetchBusinesses();
					}
				} catch (err) {
					console.error(`Toggle business active failed:`, err);
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
	const html = await fetch('html/Tenant/businesses.html').then(r => r.text());
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
	try {
		const res = await apiFetch('/api/tenant/businesses');
		const data = await res.json();
		if (!res.ok || !data.success) {
			showError('Failed to load businesses');
			return;
		}
		renderBusinessTable(data.data?.data || []);
	} catch (err) {
		console.error('Fetch businesses failed:', err);
		showError('Network error loading businesses');
	}
}

function showError(message) {
	const container = document.querySelector('#businessesErrorContainer');
	if (container) {
		container.innerHTML = `<div class="alert alert-danger">${message}</div>`;
	}
}

function renderBusinessTable(businesses) {
	if (!businessDataTable) {
		businessDataTable = createDataTable('#dataTable', {
			columnDefs: [
				{ targets: 0, visible: false, searchable: false },
				{ targets: '_all', className: 'text-nowrap' },
				{ targets: 9, className: 'text-center', orderable: false, searchable: false }
			]
		});
	}

	businessDataTable.clear();

	const canEdit = hasPermission('businesses.edit');
	const canDelete = hasPermission('businesses.delete');
	const canManage = hasPermission('businesses.manage');

	businesses.forEach(business => {
		let actions = '';
		if (canEdit) {
			actions += `
				<button data-action="businesses.edit" title="Edit business"
					class="btn btn-light btn-circle btn-sm me-1">
					<i class="fas fa-edit text-info"></i>
				</button>`;
		}
		if (canManage) {
			const isActive = business.is_active !== undefined ? !!Number(business.is_active) : true;
			const toggleTitle = isActive ? 'Deactivate business' : 'Activate business';
			const toggleIcon = isActive ? 'fa-toggle-on text-success' : 'fa-toggle-off text-secondary';
			actions += `
				<button data-action="businesses.toggleActive" title="${toggleTitle}"
					class="btn btn-light btn-circle btn-sm me-1">
					<i class="fas ${toggleIcon}"></i>
				</button>`;
		}
		if (canDelete) {
			actions += `
				<button data-action="businesses.delete" title="Permanently delete (empty businesses only)"
					class="btn btn-light btn-circle btn-sm">
					<i class="fas fa-trash text-danger"></i>
				</button>`;
		}

		const isActive = business.is_active !== undefined ? !!Number(business.is_active) : true;
		const statusBadge = isActive
			? '<span class="badge bg-success">Active</span>'
			: '<span class="badge bg-secondary">Inactive</span>';

		businessDataTable.row.add([
			business.id,
			business.name,
			business.business_type || '—',
			business.legal_name,
			business.registration_no,
			business.phone ? getFormatter().formatPhone(business.phone) : '',
			business.email,
			business.currency,
			statusBadge,
			actions
		]);
	});

	businessDataTable.draw(false);
	businessDataTable.columns.adjust();
}
