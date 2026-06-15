// assets/js/pages/Items/productionOrderForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let availableRecipes = [];
let selectedRecipeDetails = null;

const F = {
    title:          () => document.getElementById('productionOrderModalTitle'),
    recipeSelect:   () => document.getElementById('selectRecipe'),
    productionQty:  () => document.getElementById('inputProductionQty'),
    tbody:          () => document.getElementById('materialRequirementsBody'),
    placeholder:    () => document.getElementById('materialRequirementsPlaceholder'),
    totalCost:      () => document.getElementById('productionTotalCost'),
    unitCost:       () => document.getElementById('productionUnitCost'),
    executeBtn:     () => document.getElementById('productionOrderExecuteBtn'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    await loadRecipes();

    // If a recipe_id was provided, pre-select it
    if (data?.recipe_id) {
        F.recipeSelect().value = String(data.recipe_id);
        await onRecipeChange();
    }

    // Attach event listeners
    F.recipeSelect().addEventListener('change', onRecipeChange);
    F.productionQty().addEventListener('input', onQuantityChange);

    unregister = registerPageActions({
        'production-order.execute': async () => {
            if (!validateForm()) return;
            await executeOrder();
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    availableRecipes = [];
    selectedRecipeDetails = null;
    F.recipeSelect()?.removeEventListener('change', onRecipeChange);
    F.productionQty()?.removeEventListener('input', onQuantityChange);
}

// ─── Load recipes for dropdown ──────────────────────────────────────────────

async function loadRecipes() {
    try {
        const res  = await apiFetch('/api/manufacturing/recipes');
        const json = await res.json();
        if (!json.success) return;
        availableRecipes = json.data.recipes || [];

        const select = F.recipeSelect();
        // Clear existing options except the placeholder
        select.innerHTML = '<option value="">Select a recipe</option>';
        availableRecipes.forEach(recipe => {
            const opt = document.createElement('option');
            opt.value = recipe.id;
            opt.textContent = recipe.manufactured_item_name || recipe.name || `Recipe #${recipe.id}`;
            select.appendChild(opt);
        });
    } catch (err) {
        console.error('loadRecipes failed:', err);
    }
}

// ─── Recipe selection handler ───────────────────────────────────────────────

async function onRecipeChange() {
    const recipeId = F.recipeSelect().value;
    if (!recipeId) {
        selectedRecipeDetails = null;
        showPlaceholder();
        resetCostDisplay();
        hideExpirySection();
        return;
    }

    try {
        const res  = await apiFetch(`/api/manufacturing/recipes/${recipeId}`);
        const json = await res.json();
        if (!json.success) {
            selectedRecipeDetails = null;
            showPlaceholder();
            resetCostDisplay();
            hideExpirySection();
            return;
        }

        selectedRecipeDetails = json.data.recipe;
        renderMaterialRequirements();

        // Show expiry section if the manufactured item has expiry tracking
        if (parseInt(selectedRecipeDetails.has_expiry) === 1) {
            showExpirySection();
        } else {
            hideExpirySection();
        }
    } catch (err) {
        console.error('onRecipeChange failed:', err);
        selectedRecipeDetails = null;
        showPlaceholder();
        resetCostDisplay();
        hideExpirySection();
    }
}

function showExpirySection() {
    const section = document.getElementById('productionExpirySection');
    if (section) section.classList.remove('d-none');
}

function hideExpirySection() {
    const section = document.getElementById('productionExpirySection');
    if (section) section.classList.add('d-none');
}

// ─── Quantity change handler ────────────────────────────────────────────────

function onQuantityChange() {
    if (selectedRecipeDetails) {
        renderMaterialRequirements();
    }
}

// ─── Render material requirements table ─────────────────────────────────────

function renderMaterialRequirements() {
    const tbody = F.tbody();
    const productionQty = parseFloat(F.productionQty().value) || 0;
    const yieldQty = selectedRecipeDetails.yield_quantity || 1;
    const recipeItems = selectedRecipeDetails.items || [];

    // Clear table
    tbody.innerHTML = '';

    if (recipeItems.length === 0 || productionQty <= 0) {
        showPlaceholder();
        resetCostDisplay();
        return;
    }

    let totalCost = 0;

    recipeItems.forEach(item => {
        const requiredQty = (parseFloat(item.quantity) / yieldQty) * productionQty;
        const unitCost = parseFloat(item.cost_price) || 0;
        const lineCost = unitCost * requiredQty;
        totalCost += lineCost;

        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(item.component_item_name || '')}</td>
            <td>${getFormatter().formatNumber(requiredQty, 4)}</td>
            <td>${escapeHtml(item.unit || '—')}</td>
            <td>${item.available_stock !== undefined ? getFormatter().formatNumber(item.available_stock, 4) : '—'}</td>
        `;

        // Highlight insufficient stock
        if (item.available_stock !== undefined && parseFloat(item.available_stock) < requiredQty) {
            row.classList.add('table-danger');
        }

        tbody.appendChild(row);
    });

    // Update cost display
    const unitCost = productionQty > 0 ? totalCost / productionQty : 0;
    F.totalCost().textContent = getFormatter().formatNumber(totalCost, 2);
    F.unitCost().textContent = getFormatter().formatNumber(unitCost, 2);
}

// ─── Form validation ────────────────────────────────────────────────────────

function validateForm() {
    clearValidation();
    let valid = true;

    // Recipe must be selected
    if (!F.recipeSelect().value) {
        markInvalid(F.recipeSelect(), 'Please select a recipe');
        valid = false;
    }

    // Production quantity must be > 0
    const qty = parseFloat(F.productionQty().value);
    if (!qty || qty <= 0) {
        markInvalid(F.productionQty(), 'Production quantity must be greater than zero');
        valid = false;
    }

    return valid;
}

// ─── Execute production order ───────────────────────────────────────────────

async function executeOrder() {
    const payload = {
        recipe_id: parseInt(F.recipeSelect().value, 10),
        production_quantity: parseFloat(F.productionQty().value)
    };

    // Include batch/expiry data if manufactured item tracks expiry
    if (selectedRecipeDetails && parseInt(selectedRecipeDetails.has_expiry) === 1) {
        const expiryDate = document.getElementById('inputProductionExpiry')?.value;
        const mfgDate = document.getElementById('inputProductionMfgDate')?.value;
        const batchNumber = document.getElementById('inputProductionBatch')?.value?.trim();

        if (!expiryDate) {
            showFormError('Expiry date is required for this item');
            return;
        }

        payload.expiry_date = expiryDate;
        payload.manufacturing_date = mfgDate || null;
        payload.batch_number = batchNumber || null;
    }

    try {
        const res  = await apiFetch('/api/manufacturing/orders', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        const json = await res.json();

        if (!res.ok || !json.success) {
            const message = json.message || 'Production order failed';
            // Handle insufficient stock details
            if (json.errors && Array.isArray(json.errors)) {
                const details = json.errors.map(e => e.message || e).join('\n');
                showFormError(message + '\n' + details);
            } else if (json.errors && typeof json.errors === 'object') {
                Object.values(json.errors).forEach(msg => {
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
        console.error('executeOrder failed:', err);
        showFormError(err.message || 'Unable to execute production order');
    }
}

// ─── Helper functions ───────────────────────────────────────────────────────

function showPlaceholder() {
    const tbody = F.tbody();
    tbody.innerHTML = `
        <tr id="materialRequirementsPlaceholder">
            <td colspan="4" class="text-center text-muted py-3">
                Select a recipe and enter production quantity to see material requirements
            </td>
        </tr>
    `;
}

function resetCostDisplay() {
    F.totalCost().textContent = '0.00';
    F.unitCost().textContent = '0.00';
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
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
