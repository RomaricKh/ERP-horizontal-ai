// assets/js/pages/PurchaseOrders/poForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { calcBc, formatAmount } from '../../utils/currency.js';
import { populatePayeeSelect } from '../../utils/payeeSelect.js';
import { attachRateAutoFetch } from '../../utils/exchangeRateHelper.js';
import { renderSupplierPricesPanel } from '../../components/supplierPricesPanel.js';
import { MODAL_SCOPE, openModal } from '../../components/modals.js';

let modalRoot;
let unregister;
let cleanupRateAutoFetch;
let currentPoId = null;
let lineRowIndex = 0;
let itemsList    = [];

const F = {
    title:        () => document.getElementById('poModalTitle'),
    supplier:     () => document.getElementById('inputPoSupplier'),
    poNumber:     () => document.getElementById('inputPoNumber'),
    poDate:       () => document.getElementById('inputPoDate'),
    currency:     () => document.getElementById('inputPoCurrency'),
    exchangeRate: () => document.getElementById('inputPoExchangeRate'),
    notes:        () => document.getElementById('inputPoNotes'),
    globalDiscount: () => document.getElementById('inputPoGlobalDiscount'),
    linesBody:    () => document.getElementById('poLinesBody'),
    subtotalFc:   () => document.getElementById('poSubtotalFc'),
    subtotalBc:   () => document.getElementById('poSubtotalBc'),
    afterDiscountFc: () => document.getElementById('poAfterDiscountFc'),
    taxTotalFc:   () => document.getElementById('poTaxTotalFc'),
    taxTotalBc:   () => document.getElementById('poTaxTotalBc'),
    grandTotalFc: () => document.getElementById('poGrandTotalFc'),
    grandTotalBc: () => document.getElementById('poGrandTotalBc'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot   = root;
    currentPoId = data?.po_id ?? null;
    lineRowIndex = 0;

    F.title().textContent = currentPoId ? 'Edit Purchase Order' : 'New Purchase Order';

    // Populate supplier select
    await populatePayeeSelect(F.supplier(), { typeFilter: 'Supplier' });

    // Load items list for line item selects
    await loadItems();

    // Recalculate expiry dates if the PO date is changed
    F.poDate().addEventListener('change', recalcExpiryDates);

    if (currentPoId) {
        await fetchAndFill(currentPoId);
        // For non-DRAFT POs: render read-only
        const poStatus = modalRoot.dataset.poStatus;
        if (poStatus && poStatus !== 'DRAFT') {
            modalRoot.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = true;
            });
            modalRoot.querySelectorAll('[data-action="po.save"]').forEach(el => {
                el.style.display = 'none';
            });
            modalRoot.querySelectorAll('[data-action="po.add-line"]').forEach(el => {
                el.style.display = 'none';
            });
            modalRoot.querySelectorAll('[data-action="po.remove-line"]').forEach(el => {
                el.style.display = 'none';
            });
        }
    } else {
        // Auto-suggest PO number
        await suggestPoNumber();
        // Set today's date
        F.poDate().value = new Date().toISOString().slice(0, 10);
    }

    // Recalculate BC when exchange rate changes
    F.exchangeRate().addEventListener('input', () => recalcAllLines());

    // Recalculate when global discount changes
    F.globalDiscount()?.addEventListener('input', () => recalcGrandTotal());

    // Auto-fetch exchange rate when currency changes
    cleanupRateAutoFetch = attachRateAutoFetch(F.currency(), F.exchangeRate());

    unregister = registerPageActions({

        'po.add-line': () => {
            addLineRow();
        },

        'po.create-supplier': async () => {
            await openModal({
                scope: MODAL_SCOPE.STACKED,
                template: 'html/People/personForm.html',
                controller: '../pages/People/personForm.modal.js',
                data: {
                    onComplete: async (newPerson) => {
                        await populatePayeeSelect(F.supplier(), { typeFilter: 'Supplier' });
                        F.supplier().value = newPerson.id;
                    }
                }
            });
        },

        'po.create-item': async ({ el }) => {
            const lineIdx = el.dataset.line;
            await openModal({
                scope: MODAL_SCOPE.STACKED,
                template: 'html/Items/itemForm.html',
                controller: '../pages/Items/itemForm.modal.js',
                data: {
                    onComplete: async (newItem) => {
                        await loadItems();
                        document.querySelectorAll('.line-item-select').forEach(sel => {
                            const currentVal = sel.value;
                            sel.innerHTML = buildItemOptions(currentVal);
                        });
                        const rowSelect = document.querySelector(`.line-item-select[data-line="${lineIdx}"]`);
                        if (rowSelect) {
                            rowSelect.value = newItem.id;
                            rowSelect.dispatchEvent(new Event('change'));
                        }
                    }
                }
            });
        },

        'po.remove-line': ({ el }) => {
            const row = el.closest('tr');
            if (row) {
                row.remove();
                recalcGrandTotal();
            }
        },

        'po.view-supplier-prices': async ({ el }) => {
            const tr = el.closest('tr');
            if (!tr) return;
            const select = tr.querySelector('.line-item-select');
            const itemId = select?.value;
            if (!itemId) return;

            const itemName = select.options[select.selectedIndex]?.textContent || 'Item';
            const section = document.getElementById('poSupplierPricesSection');
            const nameSpan = document.getElementById('poSupplierPricesItemName');
            if (!section) return;

            if (nameSpan) nameSpan.textContent = itemName;
            section.classList.remove('d-none');
            await renderSupplierPricesPanel('poSupplierPricesContainer', itemId);
        },

        'po.close-supplier-prices': () => {
            const section = document.getElementById('poSupplierPricesSection');
            if (section) section.classList.add('d-none');
        },

        'po.save': async () => {
            if (!validateForm()) return;

            const lines = collectLines();
            const payload = {
                supplier_id:      F.supplier().value     || null,
                po_number:        F.poNumber().value.trim(),
                po_date:          F.poDate().value,
                currency:         F.currency().value,
                exchange_rate:    parseFloat(F.exchangeRate().value),
                discount_percent: parseFloat(F.globalDiscount()?.value) || 0,
                notes:            F.notes().value.trim() || null,
                lines:            lines,
            };

            try {
                let res, json;
                if (currentPoId) {
                    res = await apiFetch(`/api/purchase-orders/${currentPoId}`, {
                        method: 'PUT',
                        body:   JSON.stringify(payload)
                    });
                } else {
                    res = await apiFetch('/api/purchase-orders', {
                        method: 'POST',
                        body:   JSON.stringify(payload)
                    });
                }
                json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Operation failed');
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('module.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save purchase order');
            }
        }
    });
}

export function unmount() {
    cleanupRateAutoFetch?.();
    cleanupRateAutoFetch = null;
    unregister?.();
    unregister    = null;
    currentPoId   = null;
    lineRowIndex  = 0;
    itemsList     = [];
}

/* ── Private helpers ──────────────────────────────────────────────────── */

async function loadItems() {
    try {
        const res  = await apiFetch('/api/items');
        const json = await res.json();
        itemsList  = json.success ? (json.data?.items ?? []) : [];
    } catch (err) {
        console.error('loadItems failed:', err);
        itemsList = [];
    }
}

async function suggestPoNumber() {
    try {
        // We'll generate client-side suggestion; server will auto-generate if blank
        // Just leave the field empty — server generates on save
        F.poNumber().placeholder = 'Auto-generated on save';
    } catch (err) {
        // ignore
    }
}

async function fetchAndFill(poId) {
    try {
        const res  = await apiFetch(`/api/purchase-orders/${poId}`);
        const json = await res.json();
        if (!json.success) return;

        const po = json.data?.purchase_order ?? {};

        // Store status for read-only check
        modalRoot.dataset.poStatus = po.status || '';

        F.title().textContent = po.status === 'DRAFT' ? 'Edit Purchase Order' : `Purchase Order: ${po.po_number || ''}`;
        F.supplier().value     = po.supplier_id    ?? '';
        F.poNumber().value     = po.po_number      ?? '';
        F.poDate().value       = po.po_date        ?? '';
        F.currency().value     = po.currency       ?? '';
        F.exchangeRate().value = po.exchange_rate  ?? '';
        F.notes().value        = po.notes          ?? '';
        if (F.globalDiscount()) F.globalDiscount().value = po.discount_percent ?? '0';

        // Render existing lines
        const lines = po.lines ?? [];
        lines.forEach(line => {
            addLineRow({
                item_id:          line.item_id,
                quantity:         line.quantity,
                unit_price_fc:    line.unit_price_fc,
                unit_price_bc:    line.unit_price_bc,
                tax_rate:         line.tax_rate,
                discount_percent: line.discount_percent,
                expiry_date:      line.expiry_date || '',
            });
        });

        recalcGrandTotal();
        
        if (po.status === 'DRAFT') {
            recalcExpiryDates();
        }
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

function recalcExpiryDates() {
    const poDateStr = F.poDate().value;
    if (!poDateStr) return;
    
    const parts = poDateStr.split('-');
    const poDateObj = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));

    const tbody = F.linesBody();
    tbody.querySelectorAll('tr').forEach(tr => {
        const itemSelect = tr.querySelector('.line-item-select');
        const expiryInput = tr.querySelector('.line-expiry-date');
        const selectedOption = itemSelect.options[itemSelect.selectedIndex];
        
        const itemHasExpiry = selectedOption?.dataset?.hasExpiry === '1';
        const shelfLife = selectedOption?.dataset?.shelfLife;
        
        if (itemHasExpiry && shelfLife && shelfLife !== '') {
            const tempDate = new Date(poDateObj.getTime());
            tempDate.setDate(tempDate.getDate() + parseInt(shelfLife, 10));
            
            const yyyy = tempDate.getFullYear();
            const mm = String(tempDate.getMonth() + 1).padStart(2, '0');
            const dd = String(tempDate.getDate()).padStart(2, '0');
            expiryInput.value = `${yyyy}-${mm}-${dd}`;
        }
    });
}

function buildItemOptions(selectedId) {
    let opts = '<option value="">— Select item —</option>';
    itemsList.forEach(item => {
        const sel = String(item.id) === String(selectedId) ? ' selected' : '';
        const expiryTag = item.has_expiry ? ' 📅' : '';
        opts += `<option value="${item.id}" data-cost="${item.cost_price || ''}" data-has-expiry="${item.has_expiry || 0}" data-shelf-life="${item.shelf_life_days || ''}"${sel}>${escHtml(item.name)}${expiryTag}</option>`;
    });
    return opts;
}

function addLineRow(prefill) {
    const idx  = lineRowIndex++;
    const tbody = F.linesBody();

    const qty      = prefill?.quantity      ?? '';
    const unitFc   = prefill?.unit_price_fc ?? '';
    const unitBc   = prefill?.unit_price_bc ?? '';
    const taxRate  = prefill?.tax_rate      ?? '';
    const discountPercent = prefill?.discount_percent ?? '';
    const expiryDate = prefill?.expiry_date ?? '';
    const discount = parseFloat(discountPercent) || 0;
    const totalFc  = (qty && unitFc) ? formatAmount(parseFloat(qty) * parseFloat(unitFc) * (1 - discount / 100)) : '0.00';

    // Determine if the prefilled item has expiry tracking
    let hasExpiry = false;
    if (prefill?.item_id) {
        const item = itemsList.find(i => String(i.id) === String(prefill.item_id));
        hasExpiry = item && (item.has_expiry == 1 || item.has_expiry === true);
    }

    const tr = document.createElement('tr');
    tr.dataset.lineIdx = idx;
    tr.innerHTML = `
        <td>
            <div class="input-group input-group-sm">
                <select class="form-select line-item-select" data-line="${idx}">
                    ${buildItemOptions(prefill?.item_id)}
                </select>
                <button class="btn btn-outline-secondary" type="button" data-action="po.create-item" data-line="${idx}" title="New Item">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            <input type="date" class="form-control form-control-sm mt-1 line-expiry-date ${hasExpiry ? '' : 'd-none'}"
                   data-line="${idx}" value="${escHtml(String(expiryDate))}" placeholder="Expiry date"
                   title="Expiry date for this batch">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm line-qty"
                   data-line="${idx}" step="0.001" min="0"
                   value="${escHtml(String(qty))}" placeholder="0">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm line-unit-fc"
                   data-line="${idx}" step="0.01" min="0"
                   value="${escHtml(String(unitFc))}" placeholder="0.00">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm line-unit-bc bg-light"
                   data-line="${idx}" readonly
                   value="${escHtml(String(unitBc))}" placeholder="Auto">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm line-tax-rate"
                   data-line="${idx}" step="0.01" min="0" max="100"
                   value="${escHtml(String(taxRate))}" placeholder="0">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm line-discount"
                   data-line="${idx}" step="0.01" min="0" max="100"
                   value="${escHtml(String(discountPercent))}" placeholder="0">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm line-total-fc bg-light"
                   data-line="${idx}" readonly
                   value="${totalFc}" placeholder="0.00">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-info btn-circle btn-sm me-1"
                    data-action="po.view-supplier-prices" title="Supplier Prices">
                <i class="fas fa-truck"></i>
            </button>
            <button type="button" class="btn btn-danger btn-circle btn-sm"
                    data-action="po.remove-line" title="Remove line">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

    // Attach input listeners
    tr.querySelector('.line-qty').addEventListener('input', () => recalcLine(tr));
    tr.querySelector('.line-unit-fc').addEventListener('input', () => recalcLine(tr));
    tr.querySelector('.line-tax-rate').addEventListener('input', () => recalcLine(tr));
    tr.querySelector('.line-discount').addEventListener('input', () => recalcLine(tr));

    // Item select change: auto-fill cost price + show/hide expiry field
    const itemSelect = tr.querySelector('.line-item-select');
    const expiryInput = tr.querySelector('.line-expiry-date');
    itemSelect.addEventListener('change', () => {
        const selectedOption = itemSelect.options[itemSelect.selectedIndex];
        const costPrice = selectedOption?.dataset?.cost;
        const itemHasExpiry = selectedOption?.dataset?.hasExpiry === '1';
        const shelfLife = selectedOption?.dataset?.shelfLife;

        // Auto-fill cost price from item's stored cost_price (always update on item change)
        if (costPrice) {
            tr.querySelector('.line-unit-fc').value = parseFloat(costPrice).toFixed(2);
            recalcLine(tr);
        }

        // Show/hide expiry date input
        if (itemHasExpiry) {
            expiryInput.classList.remove('d-none');
            // Auto-fill expiry date if shelf life is set
            if (shelfLife && shelfLife !== '') {
                const poDate = F.poDate().value || new Date().toISOString().split('T')[0];
                const parts = poDate.split('-');
                const poDateObj = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                poDateObj.setDate(poDateObj.getDate() + parseInt(shelfLife, 10));
                const yyyy = poDateObj.getFullYear();
                const mm = String(poDateObj.getMonth() + 1).padStart(2, '0');
                const dd = String(poDateObj.getDate()).padStart(2, '0');
                expiryInput.value = `${yyyy}-${mm}-${dd}`;
            } else {
                expiryInput.value = '';
            }
        } else {
            expiryInput.classList.add('d-none');
            expiryInput.value = '';
        }
    });

    tbody.appendChild(tr);
    recalcGrandTotal();
}

function recalcLine(tr) {
    const qty      = parseFloat(tr.querySelector('.line-qty').value)      || 0;
    const unitFc   = parseFloat(tr.querySelector('.line-unit-fc').value)  || 0;
    const discount = parseFloat(tr.querySelector('.line-discount').value) || 0;
    const rate     = parseFloat(F.exchangeRate().value)                   || 0;

    const unitBc  = calcBc(unitFc, rate);
    const totalFc = round2(qty * unitFc * (1 - discount / 100));

    tr.querySelector('.line-unit-bc').value   = formatAmount(unitBc);
    tr.querySelector('.line-total-fc').value  = formatAmount(totalFc);

    recalcGrandTotal();
}

function recalcAllLines() {
    const tbody = F.linesBody();
    tbody.querySelectorAll('tr').forEach(tr => recalcLine(tr));
}

function recalcGrandTotal() {
    const tbody = F.linesBody();
    let subtotalFc = 0;
    let subtotalBc = 0;
    let taxFc = 0;
    let taxBc = 0;
    const rate = parseFloat(F.exchangeRate().value) || 0;

    tbody.querySelectorAll('tr').forEach(tr => {
        const qty      = parseFloat(tr.querySelector('.line-qty')?.value)      || 0;
        const unitFc   = parseFloat(tr.querySelector('.line-unit-fc')?.value)  || 0;
        const taxRate  = parseFloat(tr.querySelector('.line-tax-rate')?.value) || 0;
        const discount = parseFloat(tr.querySelector('.line-discount')?.value) || 0;
        const unitBc   = calcBc(unitFc, rate);
        const lineTotalFc = round2(qty * unitFc * (1 - discount / 100));
        const lineTotalBc = round2(qty * unitBc * (1 - discount / 100));
        const lineTaxFc   = round2(lineTotalFc * (taxRate / 100));
        const lineTaxBc   = round2(lineTotalBc * (taxRate / 100));

        subtotalFc += lineTotalFc;
        subtotalBc += lineTotalBc;
        taxFc      += lineTaxFc;
        taxBc      += lineTaxBc;
    });

    // Apply global discount
    const globalDiscount = parseFloat(F.globalDiscount()?.value) || 0;
    const discountMultiplier = 1 - (globalDiscount / 100);
    const afterDiscountFc = round2(subtotalFc * discountMultiplier);
    const afterDiscountBc = round2(subtotalBc * discountMultiplier);

    F.subtotalFc().textContent  = formatAmount(subtotalFc);
    F.subtotalBc().textContent  = formatAmount(subtotalBc);
    if (F.afterDiscountFc()) F.afterDiscountFc().textContent = formatAmount(afterDiscountFc);
    F.taxTotalFc().textContent  = formatAmount(taxFc);
    F.taxTotalBc().textContent  = formatAmount(taxBc);
    F.grandTotalFc().textContent = formatAmount(round2(afterDiscountFc + taxFc));
    F.grandTotalBc().textContent = formatAmount(round2(afterDiscountBc + taxBc));
}

function collectLines() {
    const tbody = F.linesBody();
    const lines = [];
    const rate  = parseFloat(F.exchangeRate().value) || 0;

    tbody.querySelectorAll('tr').forEach(tr => {
        const itemSelect = tr.querySelector('.line-item-select');
        const itemId   = itemSelect?.value;
        const qty      = parseFloat(tr.querySelector('.line-qty')?.value)      || 0;
        const unitFc   = parseFloat(tr.querySelector('.line-unit-fc')?.value)  || 0;
        const taxRate  = parseFloat(tr.querySelector('.line-tax-rate')?.value) || 0;
        const discount = parseFloat(tr.querySelector('.line-discount')?.value) || 0;
        const unitBc   = calcBc(unitFc, rate);
        const expiryDate = tr.querySelector('.line-expiry-date')?.value || null;

        if (itemId && qty > 0) {
            const lineData = {
                item_id:          parseInt(itemId, 10),
                quantity:         qty,
                unit_price_fc:    unitFc,
                unit_price_bc:    unitBc,
                tax_rate:         taxRate,
                discount_percent: discount,
            };
            if (expiryDate) {
                lineData.expiry_date = expiryDate;
            }
            lines.push(lineData);
        }
    });

    return lines;
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.poDate().value) {
        markInvalid(F.poDate(), 'PO date is required');
        valid = false;
    }
    if (!F.currency().value) {
        markInvalid(F.currency(), 'Currency is required');
        valid = false;
    }
    if (!F.exchangeRate().value) {
        markInvalid(F.exchangeRate(), 'Exchange rate is required');
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

function round2(val) {
    return Math.round(val * 100) / 100;
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
