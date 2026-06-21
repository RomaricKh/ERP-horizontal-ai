// assets/js/pages/Items/items.js

import { hasPermission } from '../../Auth/auth.js';
import { registerPageActions } from '../Core/pageActions.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';
import { isModuleActive } from '../../services/ModuleService.js';
import { createMultiSelect } from '../../components/multiSelect.js';

let items_container;
let unregister;
let itemsUpdatedHandler;
let itemsDataTable = null;
let itemsCache = [];
let typeFilterSelect = null;

export async function mount() {
    await fetchItems();

    itemsUpdatedHandler = async () => { await fetchItems(); };
    document.addEventListener('items.updated', itemsUpdatedHandler);
    document.addEventListener('items:prices_updated', itemsUpdatedHandler);

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

        'items.reactivate': async ({ el }) => {
            const tr      = el.closest('tr');
            const rowData = itemsDataTable.row(tr).data();
            const id      = rowData[0];
            const name    = rowData[1];
            if (!id) return;

            const confirmed = await confirm({
                title:   'Reactivate item',
                message: `Reactivate "${name}"? It will appear in active listings again.`
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/items/${id}/reactivate`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to reactivate item' });
                } else {
                    await fetchItems();
                }
            } catch (err) {
                console.error('Reactivate item failed:', err);
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
        },

        'items.pending-prices': async () => {
            await openModal({
                scope: MODAL_SCOPE.PAGE,
                template: 'html/Items/pendingPrices.html',
                controller: '../pages/Items/pendingPrices.modal.js',
                data: {},
                options: { backdrop: false, keyboard: true, focus: true }
            });
        }
    });
}

export function unmount() {
    if (itemsUpdatedHandler) {
        document.removeEventListener('items.updated', itemsUpdatedHandler);
        document.removeEventListener('items:prices_updated', itemsUpdatedHandler);
        itemsUpdatedHandler = null;
    }
    unregister?.();
    unregister = null;
    typeFilterSelect?.destroy();
    typeFilterSelect = null;
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
    const res  = await apiFetch('/api/items?include_inactive=1');
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

        // Build type filter next to search
        buildTypeFilter();
    }

    itemsDataTable.clear();

    const canEdit    = hasPermission('items.edit');
    const canDelete  = hasPermission('items.delete');
    const canAdjust  = hasPermission('stock.adjust');
    const canProduce = hasPermission('manufacturing.create') && isModuleActive('manufacturing');
    const canEditMfg = hasPermission('manufacturing.edit') && isModuleActive('manufacturing');

    items.forEach(item => {
        let actionItems = [];

        if (canEdit) {
            actionItems.push({ action: 'items.edit', icon: 'fa-edit text-info', label: 'Edit' });
        }
        if (hasPermission('items.view')) {
            actionItems.push({ action: 'items.price-history', icon: 'fa-history text-secondary', label: 'Price History' });
        }
        if (canProduce && item.type_name === 'Manufactured') {
            actionItems.push({ action: 'items.produce', icon: 'fa-industry text-primary', label: 'Produce' });
        }
        if (canAdjust) {
            actionItems.push({ action: 'items.stock-adjust', icon: 'fa-boxes text-success', label: 'Adjust Stock' });
        }
        if (parseInt(item.has_expiry) === 1) {
            actionItems.push({ action: 'items.view-batches', icon: 'fa-clock text-warning', label: 'View Batches' });
        }
        if (parseInt(item.has_cost_change_flag) === 1 && canEditMfg) {
            actionItems.push({ action: 'items.dismiss-cost-flag', icon: 'fa-check text-warning', label: 'Dismiss Cost Flag' });
        }
        if (canDelete) {
            if (parseInt(item.is_active) === 1) {
                actionItems.push({ action: 'items.delete', icon: 'fa-ban text-danger', label: 'Deactivate', divider: true });
            } else {
                actionItems.push({ action: 'items.reactivate', icon: 'fa-redo text-success', label: 'Reactivate', divider: true });
            }
        }

        // Render actions with overflow
        let actions = '';
        if (parseInt(item.has_cost_change_flag) === 1) {
            const oldCost = item.flag_old_cost != null ? getFormatter().formatNumber(item.flag_old_cost, 2) : '?';
            const newCost = item.flag_new_cost != null ? getFormatter().formatNumber(item.flag_new_cost, 2) : '?';
            actions += `<span class="badge bg-warning text-dark me-1" title="Cost changed: ${oldCost} → ${newCost}">
                            <i class="fas fa-exclamation-triangle"></i></span>`;
        }

        if (actionItems.length <= 3) {
            actions += actionItems.map(a => `<button data-action="${a.action}" class="btn btn-light btn-circle btn-sm me-1" title="${escHtml(a.label)}"><i class="fas ${a.icon}"></i></button>`).join('');
        } else {
            const inline = actionItems.slice(0, 2);
            const overflow = actionItems.slice(2);
            actions += inline.map(a => `<button data-action="${a.action}" class="btn btn-light btn-circle btn-sm me-1" title="${escHtml(a.label)}"><i class="fas ${a.icon}"></i></button>`).join('');
            actions += `<div class="dropdown d-inline-block">
                <button class="btn btn-light btn-circle btn-sm" data-bs-toggle="dropdown" title="More"><i class="fas fa-ellipsis-v"></i></button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    ${overflow.map(a => `${a.divider ? '<li><hr class="dropdown-divider"></li>' : ''}<li><button class="dropdown-item small" data-action="${a.action}"><i class="fas ${a.icon} me-2"></i>${escHtml(a.label)}</button></li>`).join('')}
                </ul>
            </div>`;
        }

        // Stock display with health indicator
        const qty = parseFloat(item.stock_qty ?? 0);
        const minStock = item.min_stock ? parseFloat(item.min_stock) : null;
        let stockDisplay;
        if (qty <= 0) {
            stockDisplay = `<span class="badge bg-danger">${getFormatter().formatNumber(qty, 3)}</span>`;
        } else if (minStock && qty <= minStock) {
            stockDisplay = `<span class="badge bg-warning text-dark" title="Below min stock (${getFormatter().formatNumber(minStock, 2)})">${getFormatter().formatNumber(qty, 3)} ⚠</span>`;
        } else {
            stockDisplay = `<span class="badge bg-success">${getFormatter().formatNumber(qty, 3)}</span>`;
        }

        const customerBadge = item.customer_id && item.customer_name
            ? `<span class="badge bg-info text-white">${escHtml(item.customer_name)}</span>`
            : '<span class="badge bg-secondary">Global</span>';

        itemsDataTable.row.add([
            item.id,
            parseInt(item.is_active) === 1 ? item.name : `<span class="text-muted"><i class="fas fa-ban me-1 small"></i>${escHtml(item.name)}</span>`,
            item.sku   || '—',
            item.type_name,
            item.unit  || '—',
            item.cost_price  != null ? getFormatter().formatNumber(item.cost_price, 2)  : '—',
            item.sale_price  != null ? getFormatter().formatNumber(item.sale_price, 2)  : '—',
            stockDisplay,
            customerBadge,
            actions
        ]);
    });

    itemsDataTable.draw(false);
    itemsDataTable.columns.adjust();
}

async function buildTypeFilter() {
    let types = [];
    try {
        const res = await apiFetch('/api/items/types');
        const json = await res.json();
        if (json.success) types = json.data?.item_types || [];
    } catch (err) { console.error('Failed to load item types:', err); }

    if (types.length === 0) return;

    const dtSearch = items_container.querySelector('.dt-search') || items_container.querySelector('.dataTables_filter');
    if (!dtSearch) return;

    const filterHost = document.createElement('div');
    filterHost.style.minWidth = '220px';
    const searchParent = dtSearch.parentElement;
    searchParent.style.display = 'flex';
    searchParent.style.alignItems = 'flex-start';
    searchParent.style.gap = '1rem';
    searchParent.style.flexWrap = 'wrap';
    searchParent.insertBefore(filterHost, dtSearch);

    typeFilterSelect = createMultiSelect({
        container: filterHost,
        placeholder: 'Filter by Type',
        icon: 'fas fa-tags',
        options: types.map(t => ({ value: String(t.id), label: t.name })),
        onChange: (selectedValues) => {
            if (selectedValues.length === 0) {
                itemsDataTable.column(3).search('').draw();
            } else {
                const selectedNames = selectedValues.map(v => {
                    const t = types.find(tp => String(tp.id) === v);
                    return t ? t.name : v;
                });
                const regex = selectedNames.map(s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
                itemsDataTable.column(3).search(regex, true, false).draw();
            }
        }
    });
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
