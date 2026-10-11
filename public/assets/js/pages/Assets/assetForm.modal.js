// assets/js/pages/Assets/assetForm.modal.js
import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let currentAssetId = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    currentAssetId = data?.asset_id ?? null;

    const titleEl = document.getElementById('assetModalTitle');

    // Load asset categories from API (module-gated)
    await loadAssetCategories();

    if (currentAssetId) {
        if (titleEl) titleEl.textContent = 'Edit Asset';
        await fetchAndFillAsset(currentAssetId);
    } else {
        if (titleEl) titleEl.textContent = 'Add Asset';
    }

    unregister = registerPageActions({
        'assets.save': async () => {
            if (!validateForm()) return;

            const form = modalRoot.querySelector('#assetForm');
            const payload = {
                name: form.querySelector('#inputAssetName').value.trim(),
                category: form.querySelector('#inputAssetCategory').options[form.querySelector('#inputAssetCategory').selectedIndex]?.text || form.querySelector('#inputAssetCategory').value.trim(),
                category_id: form.querySelector('#inputAssetCategory').value ? parseInt(form.querySelector('#inputAssetCategory').value, 10) : null,
                asset_type: form.querySelector('#inputAssetType').value,
                depreciation_method: form.querySelector('#inputAssetDepreciationMethod').value,
                useful_life: parseInt(form.querySelector('#inputAssetUsefulLife').value) || null,
                salvage_value: parseFloat(form.querySelector('#inputAssetSalvageValue')?.value) || 0,
                notes: form.querySelector('#inputAssetNotes').value.trim() || null
            };

            // Include creation-only fields when creating
            if (!currentAssetId) {
                payload.purchase_date = form.querySelector('#inputAssetPurchaseDate').value;
                payload.purchase_value = parseFloat(form.querySelector('#inputAssetPurchaseValue').value) || null;
                payload.currency = form.querySelector('#inputAssetCurrency').value;
                payload.exchange_rate = parseFloat(form.querySelector('#inputAssetExchangeRate').value) || null;
            }

            const url = currentAssetId ? `/api/assets/${currentAssetId}` : '/api/assets';
            const method = currentAssetId ? 'PUT' : 'POST';

            try {
                const res = await apiFetch(url, { method, body: JSON.stringify(payload) });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    throw new Error(json.message || 'Failed to save asset');
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('assets.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save asset');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentAssetId = null;
}

async function loadAssetCategories() {
    try {
        const res = await apiFetch('/api/assets/categories');
        const json = await res.json();
        const categories = json.success ? (json.data?.categories ?? []) : [];

        const sel = modalRoot.querySelector('#inputAssetCategory');
        if (!sel) return;

        // Keep the first placeholder option
        while (sel.options.length > 1) sel.remove(1);

        categories.forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat.id;
            opt.textContent = cat.name;
            sel.appendChild(opt);
        });
    } catch (err) {
        console.error('loadAssetCategories failed:', err);
    }
}

async function fetchAndFillAsset(id) {
    try {
        const res = await apiFetch(`/api/assets/${id}`);
        const data = await res.json();
        if (!res.ok || !data.success) {
            showFormError(data.message || 'Failed to load asset');
            return;
        }

        const asset = data.data.asset ?? data.data;
        const form = modalRoot.querySelector('#assetForm');

        form.querySelector('#inputAssetName').value = asset.name ?? '';
        form.querySelector('#inputAssetCategory').value = asset.category_id ?? asset.category ?? '';
        form.querySelector('#inputAssetType').value = asset.asset_type ?? '';
        form.querySelector('#inputAssetPurchaseDate').value = asset.purchase_date ?? '';
        form.querySelector('#inputAssetPurchaseValue').value = asset.purchase_value ?? '';
        form.querySelector('#inputAssetCurrency').value = asset.currency ?? '';
        form.querySelector('#inputAssetExchangeRate').value = asset.exchange_rate ?? '';
        form.querySelector('#inputAssetDepreciationMethod').value = asset.depreciation_method ?? '';
        form.querySelector('#inputAssetUsefulLife').value = asset.useful_life ?? '';
        form.querySelector('#inputAssetSalvageValue').value = asset.salvage_value ?? '0';
        form.querySelector('#inputAssetNotes').value = asset.notes ?? '';

        // Disable fields that cannot be modified after creation
        form.querySelector('#inputAssetPurchaseValue').disabled = true;
        form.querySelector('#inputAssetCurrency').disabled = true;
        form.querySelector('#inputAssetExchangeRate').disabled = true;
        form.querySelector('#inputAssetPurchaseDate').disabled = true;
    } catch (err) {
        console.error('Load asset failed:', err);
        showFormError('Failed to load asset data');
    }
}

function validateForm() {
    clearValidation();
    const form = modalRoot.querySelector('#assetForm');
    const errors = [];

    const name = form.querySelector('#inputAssetName');
    const category = form.querySelector('#inputAssetCategory');
    const type = form.querySelector('#inputAssetType');
    const method = form.querySelector('#inputAssetDepreciationMethod');
    const life = form.querySelector('#inputAssetUsefulLife');

    if (!name.value.trim()) { markInvalid(name, 'Name is required'); errors.push(1); }
    if (!category.value.trim()) { markInvalid(category, 'Category is required'); errors.push(1); }
    if (!type.value) { markInvalid(type, 'Asset type is required'); errors.push(1); }
    if (!method.value) { markInvalid(method, 'Depreciation method is required'); errors.push(1); }
    if (!life.value || parseInt(life.value) < 1) { markInvalid(life, 'Useful life must be at least 1 year'); errors.push(1); }

    if (!currentAssetId) {
        const date = form.querySelector('#inputAssetPurchaseDate');
        const value = form.querySelector('#inputAssetPurchaseValue');
        const currency = form.querySelector('#inputAssetCurrency');
        const rate = form.querySelector('#inputAssetExchangeRate');

        if (!date.value) { markInvalid(date, 'Purchase date is required'); errors.push(1); }
        if (!value.value || parseFloat(value.value) <= 0) { markInvalid(value, 'Purchase value must be greater than 0'); errors.push(1); }
        if (!currency.value) { markInvalid(currency, 'Currency is required'); errors.push(1); }
        if (!rate.value || parseFloat(rate.value) <= 0) { markInvalid(rate, 'Exchange rate must be greater than 0'); errors.push(1); }
    }

    return errors.length === 0;
}

function clearValidation() {
    modalRoot.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
    const footer = modalRoot.querySelector('.modal-footer');
    footer?.querySelector('.form-error')?.remove();
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
