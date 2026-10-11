// assets/js/pages/Items/stockAdjust.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let currentItemId = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot     = root;
    currentItemId = data.item_id;

    const hasExpiry = parseInt(data.has_expiry) === 1;

    document.getElementById('stockItemName').textContent    = data.item_name    ?? '—';
    document.getElementById('stockCurrentQty').textContent  = getFormatter().formatNumber(data.current_qty ?? 0, 3);

    // Show/hide batch section based on type and expiry tracking
    const typeSelect = document.getElementById('stockTypeSelect');
    const batchSection = document.getElementById('batchInfoSection');

    const updateBatchVisibility = () => {
        if (hasExpiry && typeSelect.value === 'IN') {
            batchSection.classList.remove('d-none');
        } else {
            batchSection.classList.add('d-none');
        }

        // Disable ADJ for batch-tracked items
        const adjOption = typeSelect.querySelector('option[value="ADJ"]');
        if (adjOption) {
            adjOption.disabled = hasExpiry;
            if (hasExpiry && typeSelect.value === 'ADJ') {
                typeSelect.value = '';
            }
        }
    };

    typeSelect?.addEventListener('change', () => {
        updateBatchVisibility();
        const hint = document.getElementById('stockQtyHint');
        if (!hint) return;
        hint.textContent = typeSelect.value === 'ADJ'
            ? 'ADJ sets the stock to this exact quantity.'
            : typeSelect.value === 'OUT'
                ? 'OUT reduces stock by this amount.'
                : 'IN increases stock by this amount.';
    });

    updateBatchVisibility();

    unregister = registerPageActions({
        'stock.save': async () => {
            if (!validateForm()) return;

            const type = document.getElementById('stockTypeSelect').value;
            const payload = {
                type,
                quantity:  parseFloat(document.getElementById('stockQty').value),
                reference: document.getElementById('stockReference').value.trim() || null,
                notes:     document.getElementById('stockNotes').value.trim()     || null,
            };

            // Include batch data for expiry-tracked items with IN transactions
            if (type === 'IN') {
                const expiryDate = document.getElementById('stockExpiryDate')?.value;
                const mfgDate = document.getElementById('stockManufacturingDate')?.value;
                const batchNumber = document.getElementById('stockBatchNumber')?.value?.trim();
                if (expiryDate) {
                    payload.expiry_date = expiryDate;
                    payload.manufacturing_date = mfgDate || null;
                    payload.batch_number = batchNumber || null;
                }
            }

            try {
                el.disabled = true;
                const res  = await apiFetch(`/api/items/${currentItemId}/stock`, {
                    method: 'POST', body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Operation failed');
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('items.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save adjustment');
            } finally {
                el.disabled = false;
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentItemId = null;
}

function validateForm() {
    clearValidation();
    let valid = true;

    const typeEl = document.getElementById('stockTypeSelect');
    const qtyEl  = document.getElementById('stockQty');

    if (!typeEl.value) {
        markInvalid(typeEl, 'Transaction type is required');
        valid = false;
    }
    const qty = parseFloat(qtyEl.value);
    if (!qtyEl.value || isNaN(qty) || qty <= 0) {
        markInvalid(qtyEl, 'Quantity must be greater than zero');
        valid = false;
    }

    // If batch section is visible (expiry item + IN), expiry date is required
    const batchSection = document.getElementById('batchInfoSection');
    if (batchSection && !batchSection.classList.contains('d-none')) {
        const expiryEl = document.getElementById('stockExpiryDate');
        if (!expiryEl?.value) {
            markInvalid(expiryEl, 'Expiry date is required for batch-tracked items');
            valid = false;
        }
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
