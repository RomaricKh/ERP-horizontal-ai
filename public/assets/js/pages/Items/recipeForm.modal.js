// assets/js/pages/Items/recipeForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let currentRecipeId = null;
let availableItems = [];

const F = {
    title:       () => document.getElementById('recipeModalTitle'),
    name:        () => document.getElementById('inputRecipeName'),
    unit:        () => document.getElementById('inputRecipeUnit'),
    yieldQty:    () => document.getElementById('inputYieldQty'),
    salePrice:   () => document.getElementById('inputRecipeSalePrice'),
    autoMarkup:  () => document.getElementById('inputAutoMarkup'),
    minStock:    () => document.getElementById('inputRecipeMinStock'),
    hasExpiry:   () => document.getElementById('inputRecipeHasExpiry'),
    shelfLife:   () => document.getElementById('inputRecipeShelfLife'),
    shelfLifeGroup: () => document.getElementById('recipeShelfLifeGroup'),
    notes:       () => document.getElementById('inputRecipeNotes'),
    tbody:       () => document.getElementById('recipeComponentsBody'),
    batchCost:   () => document.getElementById('recipeBatchCost'),
    costPerUnit: () => document.getElementById('recipeCostPerUnit'),
    saveBtn:     () => document.getElementById('recipeSaveBtn'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot      = root;
    currentRecipeId = data?.recipe_id ?? null;

    F.title().textContent = currentRecipeId ? 'Edit Recipe' : 'New Recipe';

    await loadAvailableItems();

    if (currentRecipeId) {
        await fetchAndFill(currentRecipeId);
    } else {
        addComponentRow();
    }

    // Attach input listeners for real-time cost recalculation
    F.yieldQty().addEventListener('input', recalculateCost);

    // Auto-markup: when markup % changes, recalculate sale price from cost per unit
    F.autoMarkup().addEventListener('input', () => {
        const markup = parseFloat(F.autoMarkup().value);
        if (!markup || markup <= 0) return;
        const costPerUnit = getCurrentCostPerUnit();
        if (costPerUnit > 0) {
            const salePrice = costPerUnit * (1 + markup / 100);
            F.salePrice().value = salePrice.toFixed(2);
        }
    });

    // If sale price is manually set and cost exists, auto-calculate markup %
    F.salePrice().addEventListener('input', () => {
        const sale = parseFloat(F.salePrice().value);
        const costPerUnit = getCurrentCostPerUnit();
        if (sale > 0 && costPerUnit > 0) {
            const markup = ((sale - costPerUnit) / costPerUnit) * 100;
            F.autoMarkup().value = markup.toFixed(2);
        }
    });

    // Show/hide shelf life field when expiry tracking is toggled
    F.hasExpiry().addEventListener('change', () => {
        const group = F.shelfLifeGroup();
        if (group) {
            group.classList.toggle('d-none', !F.hasExpiry().checked);
        }
    });
    // Initial state
    if (F.hasExpiry().checked) {
        F.shelfLifeGroup()?.classList.remove('d-none');
    }

    unregister = registerPageActions({
        'recipe.add-component': () => {
            addComponentRow();
        },
        'recipe.save': async () => {
            if (!validateForm()) return;
            await saveRecipe();
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentRecipeId = null;
    availableItems = [];
    F.yieldQty()?.removeEventListener('input', recalculateCost);
}

// ─── Load available items for component selectors ───────────────────────────

async function loadAvailableItems() {
    try {
        const res  = await apiFetch('/api/items');
        const json = await res.json();
        if (!json.success) return;
        availableItems = json.data.items || [];
    } catch (err) {
        console.error('loadAvailableItems failed:', err);
    }
}

// ─── Fetch and fill for edit mode ───────────────────────────────────────────

async function fetchAndFill(recipeId) {
    try {
        const res  = await apiFetch(`/api/manufacturing/recipes/${recipeId}`);
        const json = await res.json();
        if (!json.success) return;

        const recipe = json.data.recipe;
        F.name().value      = recipe.manufactured_item_name ?? '';
        F.unit().value      = recipe.unit ?? '';
        F.yieldQty().value  = recipe.yield_quantity ?? 1;
        F.salePrice().value = recipe.sale_price ?? '';
        F.autoMarkup().value = recipe.markup_percent ?? '';
        F.minStock().value  = recipe.min_stock ?? '';
        F.hasExpiry().checked = parseInt(recipe.has_expiry) === 1;
        if (parseInt(recipe.has_expiry) === 1) {
            F.shelfLifeGroup()?.classList.remove('d-none');
            F.shelfLife().value = recipe.shelf_life_days ?? '';
        }
        F.notes().value     = recipe.notes ?? '';

        // Populate component rows
        const items = recipe.items || [];
        items.forEach(item => {
            addComponentRow({
                component_item_id: item.component_item_id,
                quantity: item.quantity
            });
        });

        recalculateCost();
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

// ─── Component row management ───────────────────────────────────────────────

function addComponentRow(prefill = null) {
    const tbody = F.tbody();
    const index = tbody.rows.length;

    const row = document.createElement('tr');
    row.dataset.rowIndex = index;

    // Build item selector options
    let optionsHtml = '<option value="">Select item...</option>';
    availableItems.forEach(item => {
        // Exclude manufactured items from component selection
        const selected = prefill && String(prefill.component_item_id) === String(item.id) ? 'selected' : '';
        optionsHtml += `<option value="${item.id}" 
            data-unit="${item.unit || ''}" 
            data-cost="${item.cost_price ?? 0}"
            ${selected}>${escHtml(item.name)}${item.sku ? ' (' + escHtml(item.sku) + ')' : ''}</option>`;
    });

    row.innerHTML = `
        <td>
            <select class="form-select form-select-sm component-item-select">
                ${optionsHtml}
            </select>
        </td>
        <td>
            <input class="form-control form-control-sm component-qty-input" 
                   type="number" step="0.0001" min="0.0001" 
                   value="${prefill?.quantity ?? ''}" placeholder="0">
        </td>
        <td class="component-unit text-muted">—</td>
        <td class="component-cost text-muted">—</td>
        <td class="component-line-total text-muted">—</td>
        <td>
            <button class="btn btn-outline-danger btn-sm" type="button" data-action="recipe.remove-row" data-row-index="${index}">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

    tbody.appendChild(row);

    // Attach event listeners to the new row
    const select = row.querySelector('.component-item-select');
    const qtyInput = row.querySelector('.component-qty-input');
    const removeBtn = row.querySelector('[data-action="recipe.remove-row"]');

    select.addEventListener('change', () => {
        updateRowDisplay(row);
        recalculateCost();
    });

    qtyInput.addEventListener('input', () => {
        updateRowDisplay(row);
        recalculateCost();
    });

    removeBtn.addEventListener('click', () => {
        removeComponentRow(row);
    });

    // If prefilled, update display immediately
    if (prefill) {
        updateRowDisplay(row);
    }
}

function removeComponentRow(row) {
    row.remove();
    recalculateCost();
}

function updateRowDisplay(row) {
    const select   = row.querySelector('.component-item-select');
    const qtyInput = row.querySelector('.component-qty-input');
    const unitCell = row.querySelector('.component-unit');
    const costCell = row.querySelector('.component-cost');
    const totalCell = row.querySelector('.component-line-total');

    const selectedOption = select.options[select.selectedIndex];
    if (!selectedOption || !selectedOption.value) {
        unitCell.textContent  = '—';
        costCell.textContent  = '—';
        totalCell.textContent = '—';
        return;
    }

    const unit = selectedOption.dataset.unit || '—';
    const cost = parseFloat(selectedOption.dataset.cost) || 0;
    const qty  = parseFloat(qtyInput.value) || 0;

    unitCell.textContent  = unit;
    costCell.textContent  = getFormatter().formatNumber(cost, 2);
    totalCell.textContent = getFormatter().formatNumber(cost * qty, 2);
}

// ─── Real-time cost recalculation ───────────────────────────────────────────

function recalculateCost() {
    const tbody = F.tbody();
    const rows  = tbody.querySelectorAll('tr');
    let totalBatchCost = 0;

    rows.forEach(row => {
        const select   = row.querySelector('.component-item-select');
        const qtyInput = row.querySelector('.component-qty-input');

        if (!select || !qtyInput) return;

        const selectedOption = select.options[select.selectedIndex];
        if (!selectedOption || !selectedOption.value) return;

        const cost = parseFloat(selectedOption.dataset.cost) || 0;
        const qty  = parseFloat(qtyInput.value) || 0;

        totalBatchCost += cost * qty;
    });

    const yieldQty = parseFloat(F.yieldQty().value) || 1;
    const costPerUnit = yieldQty > 0 ? totalBatchCost / yieldQty : 0;

    F.batchCost().textContent  = getFormatter().formatNumber(totalBatchCost, 2);
    F.costPerUnit().textContent = getFormatter().formatNumber(costPerUnit, 2);

    // If auto-markup is set, recalculate sale price automatically
    const markup = parseFloat(F.autoMarkup().value);
    if (markup > 0 && costPerUnit > 0) {
        const salePrice = costPerUnit * (1 + markup / 100);
        F.salePrice().value = salePrice.toFixed(2);
    }
}

function getCurrentCostPerUnit() {
    const tbody = F.tbody();
    const rows  = tbody.querySelectorAll('tr');
    let totalBatchCost = 0;

    rows.forEach(row => {
        const select   = row.querySelector('.component-item-select');
        const qtyInput = row.querySelector('.component-qty-input');
        if (!select || !qtyInput) return;
        const selectedOption = select.options[select.selectedIndex];
        if (!selectedOption || !selectedOption.value) return;
        const cost = parseFloat(selectedOption.dataset.cost) || 0;
        const qty  = parseFloat(qtyInput.value) || 0;
        totalBatchCost += cost * qty;
    });

    const yieldQty = parseFloat(F.yieldQty().value) || 1;
    return yieldQty > 0 ? totalBatchCost / yieldQty : 0;
}

// ─── Form validation ────────────────────────────────────────────────────────

function validateForm() {
    clearValidation();
    let valid = true;

    // Name required
    if (!F.name().value.trim()) {
        markInvalid(F.name(), 'Manufactured item name is required');
        valid = false;
    }

    // Yield > 0
    const yieldQty = parseFloat(F.yieldQty().value);
    if (!yieldQty || yieldQty <= 0) {
        markInvalid(F.yieldQty(), 'Yield quantity must be greater than zero');
        valid = false;
    }

    // At least one component
    const tbody = F.tbody();
    const rows  = tbody.querySelectorAll('tr');
    if (rows.length === 0) {
        showFormError('At least one component item is required');
        valid = false;
        return valid;
    }

    // All components must have item selected and quantity > 0
    rows.forEach(row => {
        const select   = row.querySelector('.component-item-select');
        const qtyInput = row.querySelector('.component-qty-input');

        if (!select.value) {
            markInvalid(select, 'Please select a component item');
            valid = false;
        }

        const qty = parseFloat(qtyInput.value);
        if (!qty || qty <= 0) {
            markInvalid(qtyInput, 'Quantity must be greater than zero');
            valid = false;
        }
    });

    return valid;
}

// ─── Save recipe ────────────────────────────────────────────────────────────

async function saveRecipe() {
    const tbody = F.tbody();
    const rows  = tbody.querySelectorAll('tr');

    const items = [];
    rows.forEach(row => {
        const select   = row.querySelector('.component-item-select');
        const qtyInput = row.querySelector('.component-qty-input');
        if (select.value && parseFloat(qtyInput.value) > 0) {
            items.push({
                item_id: parseInt(select.value, 10),
                quantity: parseFloat(qtyInput.value)
            });
        }
    });

    const payload = {
        name:           F.name().value.trim(),
        unit:           F.unit().value.trim() || null,
        yield_quantity: parseInt(F.yieldQty().value, 10),
        sale_price:     F.salePrice().value !== '' ? parseFloat(F.salePrice().value) : null,
        auto_markup_percent: F.autoMarkup().value !== '' ? parseFloat(F.autoMarkup().value) : null,
        min_stock:      F.minStock().value !== '' ? parseFloat(F.minStock().value) : null,
        has_expiry:     F.hasExpiry().checked ? 1 : 0,
        shelf_life_days: F.hasExpiry().checked && F.shelfLife().value !== '' ? parseInt(F.shelfLife().value, 10) : null,
        notes:          F.notes().value.trim() || null,
        components:     items
    };

    const url    = currentRecipeId ? `/api/manufacturing/recipes/${currentRecipeId}` : '/api/manufacturing/recipes';
    const method = currentRecipeId ? 'PUT' : 'POST';

    try {
        const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
        const json = await res.json();

        if (!res.ok || !json.success) {
            const message = json.message || 'Operation failed';
            // Handle field-level validation errors if provided
            if (json.errors && typeof json.errors === 'object') {
                Object.entries(json.errors).forEach(([field, msg]) => {
                    showFormError(Array.isArray(msg) ? msg.join(', ') : msg);
                });
            } else {
                showFormError(message);
            }
            return;
        }

        bootstrap.Modal.getInstance(modalRoot).hide();
        document.dispatchEvent(new CustomEvent('items.updated'));
    } catch (err) {
        console.error('saveRecipe failed:', err);
        showFormError(err.message || 'Unable to save recipe');
    }
}

// ─── Validation helpers ─────────────────────────────────────────────────────

function clearValidation() {
    modalRoot.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
    modalRoot.querySelector('.modal-footer .form-error')?.remove();
}

function markInvalid(input, message) {
    if (!input) return;
    input.classList.add('is-invalid');
    let fb = input.parentElement.querySelector('.invalid-feedback');
    if (!fb) {
        fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        input.parentElement.appendChild(fb);
    }
    fb.textContent = message;
}

function showFormError(message) {
    const footer = modalRoot.querySelector('.modal-footer');
    let el = footer?.querySelector('.form-error');
    if (!el) {
        el = document.createElement('div');
        el.className = 'form-error text-danger me-auto';
        footer?.prepend(el);
    }
    el.textContent = message;
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
