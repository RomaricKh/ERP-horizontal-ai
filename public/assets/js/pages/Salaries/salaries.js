// assets/js/pages/Salaries/salaries.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

let salaries_container;
let unregister;
let salariesUpdatedHandler;
let salariesDataTable = null;

export async function mount() {
    await fetchSalaries();

    salariesUpdatedHandler = async () => { await fetchSalaries(); };
    document.addEventListener('salaries.updated', salariesUpdatedHandler);

    unregister = registerPageActions({

        'salaries.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Salaries/salaryForm.html',
                controller: '../pages/Salaries/salaryForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'salaries.edit': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = salariesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Salaries/salaryForm.html',
                controller: '../pages/Salaries/salaryForm.modal.js',
                data: { salary_id: id },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'salaries.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = salariesDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Delete salary record',
                message: 'Delete this salary record? This action cannot be undone.'
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/salaries/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to delete salary record' });
                } else {
                    await fetchSalaries();
                }
            } catch (err) {
                console.error('Delete salary failed:', err);
            }
        }
    });
}

export function unmount() {
    if (salariesUpdatedHandler) {
        document.removeEventListener('salaries.updated', salariesUpdatedHandler);
        salariesUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    salariesDataTable?.destroy();
    salariesDataTable = null;
    closeModalsInContainer(salaries_container);
}

export async function render(container) {
    salaries_container = container;
    const html = await fetch('html/Salaries/salaries.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    container.querySelectorAll('[data-action]').forEach(el => {
        if (el.dataset.action === 'salaries.create' && !hasPermission('salaries.create')) {
            el.remove();
        }
    });
}

async function fetchSalaries() {
    const res  = await apiFetch('/api/salaries');
    const data = await res.json();
    if (!data.success) return;
    renderTable(data.data.salaries ?? []);
}

function renderTable(salaries) {
    if (!salariesDataTable) {
        salariesDataTable = createDataTable('#salariesTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 8, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    salariesDataTable.clear();

    const canEdit   = hasPermission('salaries.edit');
    const canDelete = hasPermission('salaries.delete');

    salaries.forEach(salary => {
        let actions = '';
        if (canEdit) {
            actions += `<button data-action="salaries.edit" class="btn btn-info btn-circle btn-sm me-1" title="Edit">
                            <i class="fas fa-edit"></i></button>`;
        }
        if (canDelete) {
            actions += `<button data-action="salaries.delete" class="btn btn-danger btn-circle btn-sm me-1" title="Delete">
                            <i class="fas fa-trash"></i></button>`;
        }

        const grossFc  = salary.gross_amount_fc != null ? getFormatter().formatNumber(salary.gross_amount_fc, 2) : '0.00';
        const taxFc    = salary.tax_fc          != null ? getFormatter().formatNumber(salary.tax_fc, 2)          : '0.00';
        const netFc    = salary.net_amount_fc   != null ? getFormatter().formatNumber(salary.net_amount_fc, 2)   : '0.00';
        const netBc    = salary.net_amount_bc   != null ? getFormatter().formatNumber(salary.net_amount_bc, 2)   : '0.00';

        const fmtDate = getFormatter().formatDate;
        const period = escHtml((fmtDate(salary.period_start ?? '') || (salary.period_start ?? '')) + ' to ' + (fmtDate(salary.period_end ?? '') || (salary.period_end ?? '')));

        salariesDataTable.row.add([
            salary.id,
            escHtml(salary.employee_name ?? '—'),
            period,
            escHtml(salary.currency      ?? ''),
            grossFc,
            taxFc,
            netFc,
            netBc,
            actions
        ]);
    });

    salariesDataTable.draw(false);
    salariesDataTable.columns.adjust();
}
