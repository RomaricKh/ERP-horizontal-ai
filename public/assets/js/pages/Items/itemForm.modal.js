// assets/js/pages/Items/itemForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { openPriceHistoryModal } from '../../components/priceHistoryModal.js';
import { renderSupplierPricesPanel } from '../../components/supplierPricesPanel.js';

let modalRoot;
let unregister;
let currentItemId = null;
let allCustomers = []; // Full list of customers for search/filter

const F = {
    title:          () => document.getElementById('itemModalTitle'),
    name:           () => document.getElementById('inputItemName'),
    sku:            () => document.getElementById('inputItemSku'),
    unit:           () => document.getElementById('inputItemUnit'),
    typeSelect:     () => document.getElementById('itemTypeSelect'),
    customerSelect: () => document.getElementById('itemCustomerSelect'),
    customerSearch: () => document.getElementById('itemCustomerSearch'),
    notes:          () => document.getElementById('inputItemNotes'),
    cost:           () => document.getElementById('inputCostPrice'),
    sale:           () => document.getElementById('inputSalePrice'),
    minStock:       () => document.getElementById('inputMinStock'),
    hasExpiry:      () => document.getElementById('inputHasExpiry'),
};

// Track original has_expiry value for disable-warning logic
let originalHasExpiry = false;

export async function mount({ modalRoot: root, data }) {
    modalRoot     = root;
    currentItemId = data?.item_id ?? null;
    originalHasExpiry = false;

    F.title().textContent = currentItemId ? 'Edit Item' : 'Add Item';

    // Show Price History and Supplier Prices buttons only when editing an existing item
    if (currentItemId) {
        const priceHistoryBtn = document.getElementById('btnPriceHistory');
        if (priceHistoryBtn) priceHistoryBtn.classList.remove('d-none');
        const supplierPricesBtn = document.getElementById('btnSupplierPrices');
        if (supplierPricesBtn) supplierPricesBtn.classList.remove('d-none');
    }

    await loadTypes();
    await loadCustomers();

    // Set up customer search/filter
    const searchInput = F.customerSearch();
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            filterCustomerDropdown(searchInput.value.trim());
        });
    }

    if (currentItemId) {
        await fetchAndFill(currentItemId);
    }

    unregister = registerPageActions({
        'items.price-history': async () => {
            if (currentItemId) {
                await openPriceHistoryModal(currentItemId);
            }
        },

        'items.supplier-prices': async () => {
            if (!currentItemId) return;
            const section = document.getElementById('supplierPricesSection');
            if (!section) return;

            // Toggle visibility
            if (section.classList.contains('d-none')) {
                section.classList.remove('d-none');
                await renderSupplierPricesPanel('supplierPricesContainer', currentItemId);
            } else {
                section.classList.add('d-none');
            }
        },

        'items.save': async () => {
            if (!validateForm()) return;

            const hasExpiryChecked = F.hasExpiry().checked;

            // Warn when disabling expiry tracking on an item that previously had it enabled
            if (currentItemId && originalHasExpiry && !hasExpiryChecked) {
                const confirmed = confirm(
                    'Warning: Disabling expiry tracking requires that all active batches are resolved (written off or consumed). ' +
                    'If active batches still exist, the server will reject this change.\n\nDo you want to proceed?'
                );
                if (!confirmed) return;
            }

            const payload = {
                name:          F.name().value.trim(),
                sku:           F.sku().value.trim()   || null,
                unit:          F.unit().value.trim()  || null,
                item_type_id:  parseInt(F.typeSelect().value, 10),
                notes:         F.notes().value.trim() || null,
                cost_price:    F.cost().value !== '' ? parseFloat(F.cost().value) : null,
                sale_price:    F.sale().value !== '' ? parseFloat(F.sale().value) : null,
                min_stock:     F.minStock().value !== '' ? parseFloat(F.minStock().value) : null,
                has_expiry:    hasExpiryChecked ? 1 : 0,
                customer_id:   F.customerSelect().value ? parseInt(F.customerSelect().value, 10) : null,
            };

            const url    = currentItemId ? `/api/items/${currentItemId}` : '/api/items';
            const method = currentItemId ? 'PUT' : 'POST';

            try {
                const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Operation failed');
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('items.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save item');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentItemId = null;
    originalHasExpiry = false;
    allCustomers = [];
}

async function loadTypes() {
    try {
        const res  = await apiFetch('/api/items/types');
        const data = await res.json();
        if (!data.success) return;

        const sel = F.typeSelect();
        while (sel.options.length > 1) sel.remove(1);

        // Types are already module-gated by the backend (REQ-031)
        data.data.item_types.forEach(t => {
            const opt = document.createElement('option');
            opt.value       = t.id;
            opt.textContent = t.name;
            sel.appendChild(opt);
        });
    } catch (err) {
        console.error('loadTypes failed:', err);
    }
}

async function loadCustomers() {
    try {
        const res  = await apiFetch('/api/people');
        const json = await res.json();
        if (!json.success) return;

        const people = json.data?.people ?? [];

        // Filter to only people with "Customer" payee type
        allCustomers = people.filter(p =>
            p.payee_id != null &&
            (p.payee_types || '').toLowerCase().includes('customer')
        );

        populateCustomerSelect(allCustomers);
    } catch (err) {
        console.error('loadCustomers failed:', err);
    }
}

function populateCustomerSelect(customers, preserveValue) {
    const sel = F.customerSelect();
    const currentValue = preserveValue ?? sel.value;

    // Keep the first "global" option, remove the rest
    while (sel.options.length > 1) sel.remove(1);

    customers.forEach(c => {
        const opt = document.createElement('option');
        opt.value       = c.id;  // people.id — matches items.customer_id FK
        opt.textContent = c.name;
        sel.appendChild(opt);
    });

    // Restore selection if it still exists in the filtered list
    if (currentValue) {
        sel.value = currentValue;
    }
}

function filterCustomerDropdown(searchText) {
    if (!searchText) {
        populateCustomerSelect(allCustomers);
        return;
    }

    const lower = searchText.toLowerCase();
    const filtered = allCustomers.filter(c =>
        c.name.toLowerCase().includes(lower)
    );
    populateCustomerSelect(filtered);
}

async function fetchAndFill(itemId) {
    try {
        const res  = await apiFetch(`/api/items/${itemId}`);
        const data = await res.json();
        if (!data.success) return;

        const item = data.data.item;
        F.name().value        = item.name          ?? '';
        F.sku().value         = item.sku           ?? '';
        F.unit().value        = item.unit          ?? '';
        F.typeSelect().value  = item.item_type_id  ?? '';
        F.notes().value       = item.notes         ?? '';
        F.cost().value        = item.cost_price    ?? '';
        F.sale().value        = item.sale_price    ?? '';
        F.minStock().value    = item.min_stock     ?? '';

        // Pre-populate customer dropdown
        if (item.customer_id) {
            F.customerSelect().value = item.customer_id;
        }

        // Pre-populate has_expiry toggle
        const hasExpiry = parseInt(item.has_expiry, 10) === 1;
        F.hasExpiry().checked = hasExpiry;
        originalHasExpiry = hasExpiry;
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.name().value.trim()) {
        markInvalid(F.name(), 'Item name is required');
        valid = false;
    }
    if (!F.typeSelect().value) {
        markInvalid(F.typeSelect(), 'Item type is required');
        valid = false;
    }
    return valid;
}

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
