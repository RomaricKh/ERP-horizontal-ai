// assets/js/pages/Items/items.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';
import { isModuleActive } from '../../services/ModuleService.js';

let items_container;
let unregister;
let itemsUpdatedHandler;
let itemsDataTable = null;
let itemsCache = [];

export async function mount() {
    await fetchItems();

    itemsUpdatedHandler = async () => { await fetchItems(); };
    document.addEventListener('items.updated', itemsUpdatedHandler);

    unregister = registerPageActions({

        'items.create': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Items/itemForm.html',
                controller: '../pages/Items/itemForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'items.create-recipe': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Items/recipeForm.html',
                controller: '../pages/Items/recipeForm.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'items.edit': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = itemsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            // Find the item in cache to check type
            const item = itemsCache.find(i => String(i.id) === String(id));

            if (item && item.type_name === 'Manufactured') {
                // Fetch recipe by item_id and open recipe form
                try {
                    const res = await apiFetch(`/api/manufacturing/recipes?item_id=${id}`);
                    const json = await res.json();
                    const recipeId = json.success && json.data?.recipe?.id
                        ? json.data.recipe.id
                        : null;

                    await openModal({
                        scope: MODAL_SCOPE.PAGE,
                        template: 'html/Items/recipeForm.html',
                        controller: '../pages/Items/recipeForm.modal.js',
                        data: { recipe_id: recipeId },
                        options: { backdrop: false, keyboard: true, focus: true }
                    });
                } catch (err) {
                    console.error('Failed to fetch recipe for manufactured item:', err);
                    // Fallback to recipe form without recipe_id
                    await openModal({
                        scope: MODAL_SCOPE.PAGE,
                        template: 'html/Items/recipeForm.html',
                        controller: '../pages/Items/recipeForm.modal.js',
                        data: {},
                        options: { backdrop: false, keyboard: true, focus: true }
                    });
                }
            } else {
                // Non-manufactured item: open regular item form
                await openModal({
                    scope: MODAL_SCOPE.PAGE,
                    template: 'html/Items/itemForm.html',
                    controller: '../pages/Items/itemForm.modal.js',
                    data: { item_id: id },
                    options: { backdrop: false, keyboard: true, focus: true }
                });
            }
        },

        'items.delete': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = itemsDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Deactivate item',
                message: `Deactivate "${name}"? It will no longer appear in lists.`
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/items/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to deactivate item' });
                } else {
                    await fetchItems();
                }
            } catch (err) {
                console.error('Delete item failed:', err);
            }
        },

        'items.stock-adjust': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = itemsDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1];
            const qty     = rowData[7]; // stock_qty column
            if (!id) return;

            // Look up has_expiry from cached item data
            const item = itemsCache.find(i => String(i.id) === String(id));
            const hasExpiry = item ? (parseInt(item.has_expiry) === 1 ? 1 : 0) : 0;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Items/stockAdjust.html',
                controller: '../pages/Items/stockAdjust.modal.js',
                data: { item_id: id, item_name: name, current_qty: qty, has_expiry: hasExpiry },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'items.produce': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = itemsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            // Find the item in cache to get its recipe
            const item = itemsCache.find(i => String(i.id) === String(id));
            if (!item) return;

            try {
                const res = await apiFetch(`/api/manufacturing/recipes?item_id=${id}`);
                const json = await res.json();
                const recipeId = json.success && json.data?.recipe?.id
                    ? json.data.recipe.id
                    : null;

                await openModal({
                    scope: MODAL_SCOPE.PAGE,
                    template: 'html/Items/productionOrderForm.html',
                    controller: '../pages/Items/productionOrderForm.modal.js',
                    data: { recipe_id: recipeId },
                    options: { backdrop: false, keyboard: true, focus: true }
                });
            } catch (err) {
                console.error('Failed to open production order form:', err);
            }
        },

        'items.view-batches': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = itemsDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1];
            if (!id) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Items/batches.html',
                controller: '../pages/Items/batches.js',
                data: { item_id: id, item_name: name },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'items.price-history': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = itemsDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1];
            if (!id) return;

            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Items/priceHistory.html',
                controller: '../pages/Items/priceHistory.modal.js',
                data: { item_id: id, item_name: name },
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'items.dismiss-cost-flag': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = itemsDataTable.row(tr).data();
            const id      = rowData[0];
            if (!id) return;

            try {
                const res  = await apiFetch(`/api/manufacturing/items/${id}/dismiss-cost-flag`, { method: 'POST' });
                const json = await res.json();
                if (res.ok && json.success) {
                    await fetchItems();
                } else {
                    await alert({ title: 'Error', alertText: json.message || 'Failed to dismiss cost flag' });
                }
            } catch (err) {
                console.error('Dismiss cost flag failed:', err);
            }
        },

        'item-types.manage': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Items/itemTypes.html',
                controller: '../pages/Items/itemTypes.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        },

        'stock.view': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Items/stockView.html',
                controller: '../pages/Items/stockView.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        }
    });
}

export function unmount() {
    if (itemsUpdatedHandler) {
        document.removeEventListener('items.updated', itemsUpdatedHandler);
        itemsUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    itemsDataTable?.destroy();
    itemsDataTable = null;
    itemsCache = [];
    closeModalsInContainer(items_container);
}

export async function render(container) {
    items_container = container;
    const html = await fetch('html/Items/items.html').then(r => r.text());
    container.innerHTML = html;

    // Hide buttons the user has no permission for
    const permMap = {
        'items.create':        'items.create',
        'items.create-recipe': 'manufacturing.create',
        'item-types.manage':   'item-types.manage',
        'stock.view':          'stock.view',
    };
    const moduleMap = {
        'items.create-recipe': 'manufacturing',
    };
    container.querySelectorAll('[data-action]').forEach(el => {
        const action = el.dataset.action;
        const perm = permMap[action];
        if (perm && !hasPermission(perm)) { el.remove(); return; }
        const mod = moduleMap[action];
        if (mod && !isModuleActive(mod)) { el.remove(); }
    });
}

async function fetchItems() {
    const res  = await apiFetch('/api/items');
    const data = await res.json();
    if (!data.success) return;
    itemsCache = data.data.items || [];
    renderTable(itemsCache);
}

function renderTable(items) {
    if (!itemsDataTable) {
        itemsDataTable = createDataTable('#itemsTable', {
            columnDefs: [
                { targets: 0, visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                { targets: 9, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    itemsDataTable.clear();

    const canEdit    = hasPermission('items.edit');
    const canDelete  = hasPermission('items.delete');
    const canAdjust  = hasPermission('stock.adjust');
    const canProduce = hasPermission('manufacturing.create') && isModuleActive('manufacturing');
    const canEditMfg = hasPermission('manufacturing.edit') && isModuleActive('manufacturing');

    items.forEach(item => {
        let actions = '';

        // Cost change flag indicator
        if (parseInt(item.has_cost_change_flag) === 1) {
            const oldCost = item.flag_old_cost != null ? getFormatter().formatNumber(item.flag_old_cost, 2) : '?';
            const newCost = item.flag_new_cost != null ? getFormatter().formatNumber(item.flag_new_cost, 2) : '?';
            actions += `<span class="badge badge-warning text-dark me-1" 
                            title="Cost changed: ${oldCost} → ${newCost}" 
                            style="cursor: pointer;">
                            <i class="fas fa-exclamation-triangle"></i> Cost Δ
                        </span>`;
            if (canEditMfg) {
                actions += `<button data-action="items.dismiss-cost-flag" class="btn btn-outline-warning btn-sm me-1" title="Dismiss cost change flag">
                                <i class="fas fa-check"></i></button>`;
            }
        }

        if (canEdit) {
            actions += `<button data-action="items.edit" class="btn btn-info btn-circle btn-sm me-1" title="Edit">
                            <i class="fas fa-edit"></i></button>`;
        }
        if (hasPermission('items.view')) {
            actions += `<button data-action="items.price-history" class="btn btn-secondary btn-circle btn-sm me-1" title="Price History">
                            <i class="fas fa-history"></i></button>`;
        }
        if (canProduce && item.type_name === 'Manufactured') {
            actions += `<button data-action="items.produce" class="btn btn-primary btn-circle btn-sm me-1" title="Produce">
                            <i class="fas fa-industry"></i></button>`;
        }
        if (canAdjust) {
            actions += `<button data-action="items.stock-adjust" class="btn btn-success btn-circle btn-sm me-1" title="Adjust stock">
                            <i class="fas fa-boxes"></i></button>`;
        }
        if (parseInt(item.has_expiry) === 1) {
            actions += `<button data-action="items.view-batches" class="btn btn-warning btn-circle btn-sm me-1" title="View batches">
                            <i class="fas fa-clock"></i></button>`;
        }
        if (canDelete) {
            actions += `<button data-action="items.delete" class="btn btn-danger btn-circle btn-sm me-1" title="Deactivate">
                            <i class="fas fa-ban"></i></button>`;
        }

        const qty = parseFloat(item.stock_qty ?? 0);
        const qtyBadge = qty <= 0
            ? `<span class="badge badge-danger text-white">${getFormatter().formatNumber(qty, 3)}</span>`
            : `<span class="badge badge-success text-white">${getFormatter().formatNumber(qty, 3)}</span>`;

        const customerBadge = item.customer_id && item.customer_name
            ? `<span class="badge bg-info text-white">${item.customer_name}</span>`
            : '';

        itemsDataTable.row.add([
            item.id,
            item.name,
            item.sku   || '—',
            item.type_name,
            item.unit  || '—',
            item.cost_price  != null ? getFormatter().formatNumber(item.cost_price, 2)  : '—',
            item.sale_price  != null ? getFormatter().formatNumber(item.sale_price, 2)  : '—',
            qty,   // raw for sorting
            customerBadge,
            actions
        ]);
    });

    // Override stock column display after draw
    // Note: column 0 (ID) is hidden, so visible <td> indices are shifted by -1
    // Data index 7 (Stock) → visible td index 6
    itemsDataTable.on('draw', function() {
        itemsDataTable.rows().every(function() {
            const data = this.data();
            const qty  = parseFloat(data[7] ?? 0);
            const node = this.node();
            if (node) {
                const cells = node.querySelectorAll('td');
                if (cells[6]) {
                    cells[6].innerHTML = qty <= 0
                        ? `<span class="badge badge-danger text-white">${getFormatter().formatNumber(qty, 3)}</span>`
                        : `<span class="badge badge-success text-white">${getFormatter().formatNumber(qty, 3)}</span>`;
                }
            }
        });
    });

    itemsDataTable.draw(false);
    itemsDataTable.columns.adjust();
}
