// assets/js/pages/Items/productionOrderForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { confirm } from '../../components/modals.js';
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
    F.recipeSelect()?.addEventListener('change', onRecipeChange);
    F.productionQty()?.addEventListener('input', onQuantityChange);
    document.getElementById('btnAddWasteRow')?.addEventListener('click', () => addWasteRow());

    unregister = registerPageActions({
        'production-order.execute': async () => {
            if (!validateForm()) return;
            await executeOrder();
        },
        'production-order.remove-waste': (e) => {
            const btn = e.target.closest('button');
            if (btn) removeWasteRow(btn.closest('tr'));
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
            // Auto-fill dates using shelf life
            const mfgDateInput = document.getElementById('inputProductionMfgDate');
            const expiryDateInput = document.getElementById('inputProductionExpiry');
            const today = new Date();
            const todayStr = today.toISOString().split('T')[0];

            if (mfgDateInput && !mfgDateInput.value) {
                mfgDateInput.value = todayStr;
            }

            const shelfLifeDays = parseInt(selectedRecipeDetails.shelf_life_days);
            if (shelfLifeDays > 0 && expiryDateInput && !expiryDateInput.value) {
                const expiryDate = new Date(today);
                expiryDate.setDate(expiryDate.getDate() + shelfLifeDays);
                expiryDateInput.value = expiryDate.toISOString().split('T')[0];
            }

            // If quantity is already set, do a full preview including component constraints
            if (parseFloat(F.productionQty().value) > 0) {
                previewExpiry();
            }
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
        // Recalculate expiry preview when quantity changes
        if (parseInt(selectedRecipeDetails.has_expiry) === 1) {
            previewExpiry();
        }
    }
}

async function previewExpiry() {
    const recipeId = F.recipeSelect().value;
    const qty = parseFloat(F.productionQty().value);
    if (!recipeId || !qty || qty <= 0) return;

    try {
        const res = await apiFetch('/api/manufacturing/preview-expiry', {
            method: 'POST',
            body: JSON.stringify({ recipe_id: parseInt(recipeId, 10), production_quantity: qty })
        });
        const json = await res.json();
        if (!json.success || !json.data?.effective_expiry) return;

        const data = json.data;
        const expiryInput = document.getElementById('inputProductionExpiry');
        const mfgInput = document.getElementById('inputProductionMfgDate');

        // Auto-fill mfg date with today if empty
        if (mfgInput && !mfgInput.value) {
            mfgInput.value = new Date().toISOString().split('T')[0];
        }

        // Auto-fill expiry with the effective date
        if (expiryInput) {
            expiryInput.value = data.effective_expiry;
        }

        // Show a hint about why this expiry was chosen
        let hintEl = document.getElementById('expiryHint');
        if (!hintEl) {
            hintEl = document.createElement('small');
            hintEl.id = 'expiryHint';
            hintEl.className = 'form-text';
            expiryInput?.parentElement?.appendChild(hintEl);
        }

        if (data.constrained_by === 'component') {
            hintEl.className = 'form-text text-warning';
            hintEl.textContent = `⚠ Limited by ${data.constraining_component} (expires ${data.component_constraint_expiry})`;
        } else if (data.shelf_life_days > 0) {
            hintEl.className = 'form-text text-muted';
            hintEl.textContent = `Based on ${data.shelf_life_days}-day shelf life`;
        } else {
            hintEl.textContent = '';
        }
    } catch (err) {
        console.error('previewExpiry failed:', err);
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
    let hasShortage = false;
    let shortageItems = [];

    recipeItems.forEach(item => {
        const requiredQty = (parseFloat(item.quantity) / yieldQty) * productionQty;
        const unitCost = parseFloat(item.cost_price) || 0;
        const lineCost = unitCost * requiredQty;
        const availableStock = parseFloat(item.available_stock) || 0;
        const deficit = requiredQty - availableStock;
        const isShort = deficit > 0;
        totalCost += lineCost;

        if (isShort) {
            hasShortage = true;
            shortageItems.push({ name: item.component_item_name, deficit, available: availableStock, required: requiredQty });
        }

        const row = document.createElement('tr');
        if (isShort) row.classList.add('table-danger');

        row.innerHTML = `
            <td>${escapeHtml(item.component_item_name || '')}</td>
            <td class="text-end">${getFormatter().formatNumber(requiredQty, 4)}</td>
            <td>${escapeHtml(item.unit || '—')}</td>
            <td class="text-end">
                ${isShort
                    ? `<span class="text-danger fw-bold">${getFormatter().formatNumber(availableStock, 4)}</span>
                       <small class="text-danger d-block">Short: ${getFormatter().formatNumber(deficit, 4)}</small>`
                    : `<span class="text-success">${getFormatter().formatNumber(availableStock, 4)}</span>`
                }
            </td>
        `;

        tbody.appendChild(row);
    });

    // Calculate waste cost
    const wasteCost = calculateWasteCost();
    totalCost += wasteCost;

    // Update cost display
    const unitCost = productionQty > 0 ? totalCost / productionQty : 0;
    F.totalCost().textContent = getFormatter().formatNumber(totalCost, 2);
    F.unitCost().textContent = getFormatter().formatNumber(unitCost, 2);

    // Show/hide shortage warning
    let warningEl = modalRoot.querySelector('#stockShortageWarning');
    if (hasShortage) {
        if (!warningEl) {
            warningEl = document.createElement('div');
            warningEl.id = 'stockShortageWarning';
            warningEl.className = 'alert alert-danger d-flex align-items-start gap-2 mt-3';
            const tableContainer = modalRoot.querySelector('#materialRequirementsTable')?.parentElement;
            tableContainer?.after(warningEl);
        }
        const maxProducible = calculateMaxProducible(recipeItems, yieldQty);
        warningEl.innerHTML = `
            <i class="fas fa-exclamation-triangle mt-1"></i>
            <div>
                <strong>Insufficient stock</strong> for ${shortageItems.length} component${shortageItems.length > 1 ? 's' : ''}.
                ${maxProducible > 0
                    ? `<br><small>Maximum producible with current stock: <strong>${getFormatter().formatNumber(maxProducible, 2)} units</strong></small>`
                    : `<br><small>Cannot produce any units — components are out of stock.</small>`
                }
            </div>
        `;
        warningEl.style.display = '';
    } else if (warningEl) {
        warningEl.style.display = 'none';
    }
}

function calculateMaxProducible(recipeItems, yieldQty) {
    let maxUnits = Infinity;
    recipeItems.forEach(item => {
        const requiredPerUnit = parseFloat(item.quantity) / yieldQty;
        const available = parseFloat(item.available_stock) || 0;
        if (requiredPerUnit > 0) {
            const possibleFromThis = available / requiredPerUnit;
            maxUnits = Math.min(maxUnits, possibleFromThis);
        }
    });
    return maxUnits === Infinity ? 0 : Math.floor(maxUnits);
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

// ─── Waste handling ─────────────────────────────────────────────────────────

function addWasteRow() {
    const tbody = document.getElementById('wasteBody');
    const placeholder = document.getElementById('wastePlaceholder');
    if (placeholder) placeholder.style.display = 'none';

    const index = tbody.querySelectorAll('tr:not(#wastePlaceholder)').length;
    const row = document.createElement('tr');
    
    // Build options from recipe items
    let optionsHtml = '<option value="">Select component...</option>';
    if (selectedRecipeDetails && selectedRecipeDetails.items) {
        selectedRecipeDetails.items.forEach(item => {
            optionsHtml += `<option value="${item.component_item_id}" data-cost="${item.cost_price || 0}">
                ${escapeHtml(item.component_item_name)}
            </option>`;
        });
    }

    row.innerHTML = `
        <td>
            <select class="form-select form-select-sm waste-item-select" required>
                ${optionsHtml}
            </select>
        </td>
        <td>
            <input class="form-control form-control-sm waste-qty-input" type="number" step="0.0001" min="0.0001" placeholder="Quantity" required>
        </td>
        <td class="text-center align-middle">
            <button class="btn btn-outline-danger btn-sm border-0" type="button" data-action="production-order.remove-waste">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(row);

    const select = row.querySelector('.waste-item-select');
    const input = row.querySelector('.waste-qty-input');
    
    select.addEventListener('change', () => renderMaterialRequirements());
    input.addEventListener('input', () => renderMaterialRequirements());
}

function removeWasteRow(row) {
    row.remove();
    const tbody = document.getElementById('wasteBody');
    const remaining = tbody.querySelectorAll('tr:not(#wastePlaceholder)');
    if (remaining.length === 0) {
        const placeholder = document.getElementById('wastePlaceholder');
        if (placeholder) placeholder.style.display = '';
    }
    renderMaterialRequirements(); // recalculates cost
}

function calculateWasteCost() {
    const tbody = document.getElementById('wasteBody');
    if (!tbody) return 0;
    let cost = 0;
    const rows = tbody.querySelectorAll('tr:not(#wastePlaceholder)');
    rows.forEach(row => {
        const select = row.querySelector('.waste-item-select');
        const qtyInput = row.querySelector('.waste-qty-input');
        if (!select || !qtyInput) return;
        
        const option = select.options[select.selectedIndex];
        if (option && option.value) {
            const unitCost = parseFloat(option.dataset.cost) || 0;
            const qty = parseFloat(qtyInput.value) || 0;
            cost += (unitCost * qty);
        }
    });
    return cost;
}

// ─── Execute production order ───────────────────────────────────────────────

async function executeOrder() {
    const wasteComponents = [];
    const wasteRows = document.querySelectorAll('#wasteBody tr:not(#wastePlaceholder)');
    wasteRows.forEach(row => {
        const select = row.querySelector('.waste-item-select');
        const qtyInput = row.querySelector('.waste-qty-input');
        if (select?.value && qtyInput?.value) {
            wasteComponents.push({
                item_id: parseInt(select.value, 10),
                quantity: parseFloat(qtyInput.value)
            });
        }
    });

    const payload = {
        recipe_id: parseInt(F.recipeSelect().value, 10),
        production_quantity: parseFloat(F.productionQty().value),
        waste_components: wasteComponents
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

    // Build confirmation summary
    const recipeItems = selectedRecipeDetails.items || [];
    const yieldQty = selectedRecipeDetails.yield_quantity || 1;
    const productionQty = payload.production_quantity;
    const fmt = getFormatter();

    let componentsHtml = recipeItems.map(item => {
        const required = ((parseFloat(item.quantity) / yieldQty) * productionQty);
        return `<tr>
            <td>${escapeHtml(item.component_item_name)}</td>
            <td class="text-end">${fmt.formatNumber(required, 4)}</td>
            <td>${escapeHtml(item.unit || '—')}</td>
        </tr>`;
    }).join('');

    const totalCostText = F.totalCost().textContent;
    const unitCostText = F.unitCost().textContent;
    const expiryText = payload.expiry_date || 'N/A';

    const confirmHtml = `
        <div class="text-start">
            <div class="mb-3 p-3 bg-light rounded border">
                <div class="row g-2">
                    <div class="col-6"><strong>Recipe:</strong> ${escapeHtml(selectedRecipeDetails.manufactured_item_name)}</div>
                    <div class="col-6"><strong>Quantity:</strong> ${fmt.formatNumber(productionQty, 2)} ${escapeHtml(selectedRecipeDetails.unit || 'units')}</div>
                    <div class="col-6"><strong>Total Cost:</strong> ${totalCostText}</div>
                    <div class="col-6"><strong>Unit Cost:</strong> ${unitCostText}</div>
                    ${payload.expiry_date ? `<div class="col-6"><strong>Expiry:</strong> ${expiryText}</div>` : ''}
                </div>
            </div>
            <p class="fw-bold mb-2">Components to consume:</p>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>Component</th><th class="text-end">Qty</th><th>Unit</th></tr></thead>
                <tbody>${componentsHtml}</tbody>
            </table>
        </div>
    `;

    // Show confirmation dialog
    const confirmed = await confirm({
        title: 'Confirm Production Order',
        message: confirmHtml,
        isHtml: true
    });

    if (!confirmed) return;

    // Execute
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
