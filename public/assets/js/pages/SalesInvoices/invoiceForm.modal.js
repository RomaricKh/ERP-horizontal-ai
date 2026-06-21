// assets/js/pages/SalesInvoices/invoiceForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { calcBc, formatAmount } from '../../utils/currency.js';
import { populatePayeeSelect } from '../../utils/payeeSelect.js';
import { attachRateAutoFetch } from '../../utils/exchangeRateHelper.js';
import { isModuleActive } from '../../services/ModuleService.js';
import { MODAL_SCOPE, openModal } from '../../components/modals.js';

let modalRoot;
let unregister;
let cleanupRateAutoFetch;
let currentInvoiceId = null;
let lineRowIndex     = 0;
let itemsList        = [];
let servicesList     = [];

// Module state flags
let itemsModuleActive    = false;
let servicesModuleActive = false;
let mixedMode            = false; // both items and services active

const F = {
    title:        () => document.getElementById('invoiceModalTitle'),
    customer:     () => document.getElementById('inputInvoiceCustomer'),
    invoiceNumber:() => document.getElementById('inputInvoiceNumber'),
    invoiceDate:  () => document.getElementById('inputInvoiceDate'),
    dueDate:      () => document.getElementById('inputInvoiceDueDate'),
    currency:     () => document.getElementById('inputInvoiceCurrency'),
    exchangeRate: () => document.getElementById('inputInvoiceExchangeRate'),
    notes:        () => document.getElementById('inputInvoiceNotes'),
    linesBody:    () => document.getElementById('invoiceLinesBody'),
    subtotalFc:   () => document.getElementById('invoiceSubtotalFc'),
    subtotalBc:   () => document.getElementById('invoiceSubtotalBc'),
    taxTotalFc:   () => document.getElementById('invoiceTaxTotalFc'),
    taxTotalBc:   () => document.getElementById('invoiceTaxTotalBc'),
    grandTotalFc: () => document.getElementById('invoiceGrandTotalFc'),
    grandTotalBc: () => document.getElementById('invoiceGrandTotalBc'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot        = root;
    currentInvoiceId = data?.invoice_id ?? null;
    lineRowIndex     = 0;

    F.title().textContent = currentInvoiceId ? 'Edit Sales Invoice' : 'New Sales Invoice';

    // Detect active modules
    itemsModuleActive    = isModuleActive('items');
    servicesModuleActive = isModuleActive('services');
    mixedMode            = itemsModuleActive && servicesModuleActive;

    // Configure table headers based on module state
    configureTableHeaders();

    // Populate customer select
    await populatePayeeSelect(F.customer(), { typeFilter: 'Customer' });

    // Load items list filtered by selected customer (or global only if none selected)
    const initialCustomerId = F.customer().value || null;
    if (itemsModuleActive) {
        await loadItems(initialCustomerId);
    }

    // Load services list if services module is active
    if (servicesModuleActive) {
        await loadServices();
    }

    // Re-fetch items whenever the customer selection changes
    const customerChangeHandler = async () => {
        const customerId = F.customer().value || null;
        if (itemsModuleActive) {
            await loadItems(customerId);
            refreshLineItemDropdowns();
        }
    };
    F.customer().addEventListener('change', customerChangeHandler);
    F.customer().addEventListener('input', customerChangeHandler);

    if (currentInvoiceId) {
        await fetchAndFill(currentInvoiceId);
        // Re-load items filtered by the invoice's customer
        const editCustomerId = F.customer().value || null;
        if (itemsModuleActive && editCustomerId !== initialCustomerId) {
            await loadItems(editCustomerId);
            refreshLineItemDropdowns();
        }
        // Render read-only: disable all inputs and hide save button
        modalRoot.querySelectorAll('input, select, textarea').forEach(el => {
            el.disabled = true;
        });
        modalRoot.querySelectorAll('[data-action="invoice.save"]').forEach(el => {
            el.style.display = 'none';
        });
        modalRoot.querySelectorAll('[data-action="invoice.add-line"]').forEach(el => {
            el.style.display = 'none';
        });
        modalRoot.querySelectorAll('[data-action="invoice.remove-line"]').forEach(el => {
            el.style.display = 'none';
        });
    } else {
        // Placeholder — server auto-generates on save
        F.invoiceNumber().placeholder = 'Auto-generated on save';
        // Set today's date
        const today = new Date().toISOString().slice(0, 10);
        F.invoiceDate().value = today;
        // Default due date: invoice_date + 30 days
        const dueDate = new Date();
        dueDate.setDate(dueDate.getDate() + 30);
        F.dueDate().value = dueDate.toISOString().slice(0, 10);
    }

    // Update due date when invoice date changes (keep +30 days offset)
    F.invoiceDate().addEventListener('change', () => {
        const invDate = F.invoiceDate().value;
        if (invDate && !currentInvoiceId) {
            const d = new Date(invDate);
            d.setDate(d.getDate() + 30);
            F.dueDate().value = d.toISOString().slice(0, 10);
        }
    });

    // Recalculate BC when exchange rate changes
    F.exchangeRate().addEventListener('input', () => recalcAllLines());

    // Recalculate when global discount changes
    const globalDiscInput = document.getElementById('inputInvoiceGlobalDiscount');
    if (globalDiscInput) globalDiscInput.addEventListener('input', () => recalcGrandTotal());

    // Auto-fetch exchange rate when currency changes
    cleanupRateAutoFetch = attachRateAutoFetch(F.currency(), F.exchangeRate());

    unregister = registerPageActions({

        'invoice.add-line': () => {
            addLineRow();
        },

        'invoice.create-customer': async () => {
            await openModal({
                scope: MODAL_SCOPE.STACKED,
                template: 'html/People/personForm.html',
                controller: '../pages/People/personForm.modal.js',
                data: {
                    onComplete: async (newPerson) => {
                        await populatePayeeSelect(F.customer(), { typeFilter: 'Customer' });
                        F.customer().value = newPerson.id;
                        F.customer().dispatchEvent(new Event('change'));
                    }
                }
            });
        },

        'invoice.create-service': async ({ el }) => {
            const lineIdx = el.dataset.line;
            await openModal({
                scope: MODAL_SCOPE.STACKED,
                template: 'html/Services/serviceForm.html',
                controller: '../pages/Services/serviceForm.modal.js',
                data: {
                    onComplete: async (newService) => {
                        await loadServices();
                        document.querySelectorAll('.line-service-select').forEach(sel => {
                            const currentVal = sel.value;
                            sel.innerHTML = buildServiceOptions(currentVal);
                        });
                        const rowSelect = document.querySelector(`.line-service-select[data-line="${lineIdx}"]`);
                        if (rowSelect) {
                            rowSelect.value = newService.id;
                            rowSelect.dispatchEvent(new Event('change'));
                        }
                    }
                }
            });
        },

        'invoice.remove-line': ({ el }) => {
            const row = el.closest('tr');
            if (row) {
                row.remove();
                recalcGrandTotal();
            }
        },

        'invoice.save': async () => {
            if (!validateForm()) return;

            const lines   = collectLines();
            const payload = {
                customer_id:      F.customer().value      || null,
                invoice_number:   F.invoiceNumber().value.trim(),
                invoice_date:     F.invoiceDate().value,
                due_date:         F.dueDate().value || null,
                currency:         F.currency().value,
                exchange_rate:    parseFloat(F.exchangeRate().value),
                discount_percent: parseFloat(document.getElementById('inputInvoiceGlobalDiscount')?.value) || 0,
                notes:            F.notes().value.trim() || null,
                lines:            lines,
            };

            try {
                let res, json;
                if (currentInvoiceId) {
                    // For existing invoices, header edits are not supported in this version
                    res  = await apiFetch(`/api/sales-invoices/${currentInvoiceId}`, {
                        method: 'PUT',
                        body:   JSON.stringify(payload)
                    });
                } else {
                    res  = await apiFetch('/api/sales-invoices', {
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
                showFormError(err.message || 'Unable to save sales invoice');
            }
        }
    });
}

export function unmount() {
    cleanupRateAutoFetch?.();
    cleanupRateAutoFetch = null;
    unregister?.();
    unregister       = null;
    currentInvoiceId = null;
    lineRowIndex     = 0;
    itemsList        = [];
    servicesList     = [];
    itemsModuleActive    = false;
    servicesModuleActive = false;
    mixedMode            = false;
}

/* ── Private helpers ──────────────────────────────────────────────────── */

function configureTableHeaders() {
    const lineTypeHeader   = document.getElementById('lineTypeHeader');
    const lineItemServiceHeader   = document.getElementById('lineItemServiceHeader');

    if (mixedMode) {
        // Both modules active: show type toggle column, item/service gets two cells per row
        lineTypeHeader?.classList.remove('d-none');
        if (lineItemServiceHeader) {
            lineItemServiceHeader.innerHTML = 'Item/Service';
        }
    } else if (servicesModuleActive && !itemsModuleActive) {
        // Service-only business: hide type column, show single service column
        lineTypeHeader?.classList.add('d-none');
        if (lineItemServiceHeader) {
            lineItemServiceHeader.innerHTML = 'Service';
        }
    } else {
        // Items-only (default): hide type column, show single item column
        lineTypeHeader?.classList.add('d-none');
        if (lineItemServiceHeader) {
            lineItemServiceHeader.innerHTML = 'Item';
        }
    }
}

async function loadServices() {
    try {
        const res  = await apiFetch('/api/services');
        const json = await res.json();
        servicesList = json.success ? (json.data?.services ?? []) : [];
    } catch (err) {
        console.error('loadServices failed:', err);
        servicesList = [];
    }
}

async function loadItems(customerId) {
    try {
        // Use path-based endpoint to avoid query param issues with Apache rewrite
        const url = customerId
            ? `/api/items/for-payee/${customerId}`
            : '/api/items';
        const res  = await apiFetch(url);
        const json = await res.json();
        const allItems = json.success ? (json.data?.items ?? []) : [];

        // If no customer selected, filter to global only (client-side fallback)
        if (!customerId) {
            itemsList = allItems.filter(item => !item.customer_id);
        } else {
            itemsList = allItems;
        }
    } catch (err) {
        console.error('loadItems failed:', err);
        itemsList = [];
    }
}

function refreshLineItemDropdowns() {
    const tbody = F.linesBody();
    const validItemIds = new Set(itemsList.map(i => String(i.id)));

    tbody.querySelectorAll('tr').forEach(tr => {
        const lineType = tr.dataset.lineType || 'item';
        if (lineType !== 'item') return; // Don't touch service lines

        const select = tr.querySelector('.line-item-select');
        if (!select) return;

        const currentValue = select.value;

        // If the current item is no longer valid for this customer, remove the line
        if (currentValue && !validItemIds.has(currentValue)) {
            tr.remove();
            return;
        }

        // Rebuild options keeping current selection
        const currentText = select.options[select.selectedIndex]?.textContent || '';
        select.innerHTML = buildItemOptions(currentValue, currentText);
    });

    recalcGrandTotal();
}

async function fetchAndFill(invoiceId) {
    try {
        const res  = await apiFetch(`/api/sales-invoices/${invoiceId}`);
        const json = await res.json();
        if (!json.success) return;

        const inv = json.data?.sales_invoice ?? {};

        F.customer().value     = inv.customer_id    ?? '';
        F.invoiceNumber().value= inv.invoice_number ?? '';
        F.invoiceDate().value  = inv.invoice_date   ?? '';
        F.dueDate().value      = inv.due_date       ?? '';
        F.currency().value     = inv.currency       ?? '';
        F.exchangeRate().value = inv.exchange_rate  ?? '';
        F.notes().value        = inv.notes          ?? '';

        // Load global discount
        const globalDiscEl = document.getElementById('inputInvoiceGlobalDiscount');
        if (globalDiscEl) globalDiscEl.value = inv.discount_percent ?? '0';

        // Render existing lines
        const lines = inv.lines ?? [];
        lines.forEach(line => {
            const lineType = line.line_type || (line.service_id ? 'service' : 'item');
            addLineRow({
                line_type:        lineType,
                item_id:          line.item_id,
                item_name:        line.item_name,
                service_id:       line.service_id,
                service_name:     line.service_name,
                service_unit:     line.service_unit,
                quantity:         line.quantity,
                unit_price_fc:    line.unit_price_fc,
                unit_price_bc:    line.unit_price_bc,
                tax_rate:         line.tax_rate,
                discount_percent: line.discount_percent,
            });
        });

        recalcGrandTotal();
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

function buildItemOptions(selectedId, fallbackName) {
    let opts = '<option value="">— Select item —</option>';
    let found = false;
    itemsList.forEach(item => {
        const sel = String(item.id) === String(selectedId) ? ' selected' : '';
        if (sel) found = true;
        const salePrice = item.sale_price != null ? parseFloat(item.sale_price) : 0;
        opts += `<option value="${item.id}"${sel} data-sale-price="${salePrice}">${escHtml(item.name)}</option>`;
    });
    // If the selected item was filtered out, add it as a fallback option
    if (selectedId && !found && fallbackName) {
        opts += `<option value="${selectedId}" selected>${escHtml(fallbackName)}</option>`;
    }
    return opts;
}

function buildServiceOptions(selectedId, fallbackName) {
    let opts = '<option value="">— Select service —</option>';
    let found = false;
    servicesList.forEach(svc => {
        const sel = String(svc.id) === String(selectedId) ? ' selected' : '';
        if (sel) found = true;
        const rate = svc.rate != null ? parseFloat(svc.rate) : 0;
        const unit = svc.unit || '';
        opts += `<option value="${svc.id}"${sel} data-rate="${rate}" data-unit="${escHtml(unit)}">${escHtml(svc.name)}</option>`;
    });
    // If the selected service was filtered out, add it as a fallback option
    if (selectedId && !found && fallbackName) {
        opts += `<option value="${selectedId}" selected>${escHtml(fallbackName)}</option>`;
    }
    return opts;
}

function addLineRow(prefill) {
    const idx   = lineRowIndex++;
    const tbody = F.linesBody();

    const qty    = prefill?.quantity      ?? '';
    const unitFc = prefill?.unit_price_fc ?? '';
    const unitBc = prefill?.unit_price_bc ?? '';
    const taxRate = prefill?.tax_rate     ?? '';
    const discountPercent = prefill?.discount_percent ?? '';
    const discount = parseFloat(discountPercent) || 0;
    const totalFc = (qty && unitFc) ? formatAmount(parseFloat(qty) * parseFloat(unitFc) * (1 - discount / 100)) : '0.00';

    // Determine line type from prefill or module state
    let lineType = prefill?.line_type ?? (servicesModuleActive && !itemsModuleActive ? 'service' : 'item');

    const tr = document.createElement('tr');
    tr.dataset.lineIdx = idx;
    tr.dataset.lineType = lineType;

    // Build line type toggle cell (only shown in mixed mode)
    const lineTypeCell = mixedMode
        ? `<td>
            <select class="form-select form-select-sm line-type-select" data-line="${idx}">
                <option value="item"${lineType === 'item' ? ' selected' : ''}>Item</option>
                <option value="service"${lineType === 'service' ? ' selected' : ''}>Service</option>
            </select>
           </td>`
        : '';

    // Build item selector cell (no quick add button)
    const showItemCell = itemsModuleActive;
    const itemCellHidden = (lineType === 'service') ? ' style="display:none;"' : '';
    const itemCell = showItemCell
        ? `<td class="line-item-cell"${itemCellHidden}>
            <select class="form-select form-select-sm line-item-select" data-line="${idx}">
                ${buildItemOptions(prefill?.item_id, prefill?.item_name)}
            </select>
           </td>`
        : '';

    // Build service selector cell with Quick Add shortcut
    const showServiceCell = servicesModuleActive;
    const serviceCellHidden = (lineType === 'item' && mixedMode) ? ' style="display:none;"' : '';
    const serviceCell = showServiceCell
        ? `<td class="line-service-cell"${serviceCellHidden}>
            <div class="input-group input-group-sm">
                <select class="form-select line-service-select" data-line="${idx}">
                    ${buildServiceOptions(prefill?.service_id, prefill?.service_name)}
                </select>
                <button class="btn btn-outline-secondary" type="button" data-action="invoice.create-service" data-line="${idx}" title="New Service">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
           </td>`
        : '';

    // Build quantity cell with optional service unit display
    const serviceUnit = prefill?.service_unit || '';
    const unitDisplay = (lineType === 'service' && serviceUnit) ? serviceUnit : '';

    tr.innerHTML = `
        ${lineTypeCell}
        ${itemCell}
        ${serviceCell}
        <td>
            <div class="d-flex align-items-center gap-1">
                <input type="number" class="form-control form-control-sm line-qty"
                       data-line="${idx}" step="0.001" min="0"
                       value="${escHtml(String(qty))}" placeholder="0">
                <span class="line-service-unit text-muted small text-nowrap">${escHtml(unitDisplay)}</span>
            </div>
            <div class="stock-error text-danger small mt-1" style="display:none;"></div>
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
            <button type="button" class="btn btn-danger btn-circle btn-sm"
                    data-action="invoice.remove-line" title="Remove line">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

    // Line type toggle event (mixed mode only)
    const lineTypeSelect = tr.querySelector('.line-type-select');
    if (lineTypeSelect) {
        lineTypeSelect.addEventListener('change', () => {
            const newType = lineTypeSelect.value;
            tr.dataset.lineType = newType;
            toggleLineType(tr, newType);
        });
    }

    // When item is selected, pre-fill unit_price_fc with sale_price
    const itemSelect = tr.querySelector('.line-item-select');
    if (itemSelect) {
        itemSelect.addEventListener('change', () => {
            const selectedOption = itemSelect.options[itemSelect.selectedIndex];
            const salePrice = parseFloat(selectedOption.dataset.salePrice || 0);
            if (salePrice > 0) {
                tr.querySelector('.line-unit-fc').value = salePrice.toFixed(2);
            }
            recalcLine(tr);
            validateLineStock(tr);
        });
    }

    // When service is selected, auto-populate unit_price_fc with service rate and show unit
    const serviceSelect = tr.querySelector('.line-service-select');
    if (serviceSelect) {
        serviceSelect.addEventListener('change', () => {
            const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
            const rate = parseFloat(selectedOption.dataset.rate || 0);
            const unit = selectedOption.dataset.unit || '';
            if (rate > 0) {
                tr.querySelector('.line-unit-fc').value = rate.toFixed(2);
            }
            // Display service unit next to quantity field
            const unitSpan = tr.querySelector('.line-service-unit');
            if (unitSpan) {
                unitSpan.textContent = unit;
            }
            recalcLine(tr);
        });
    }

    // Attach input listeners
    tr.querySelector('.line-qty').addEventListener('input', () => {
        recalcLine(tr);
        if (tr.dataset.lineType === 'item') {
            validateLineStock(tr);
        }
    });
    tr.querySelector('.line-unit-fc').addEventListener('input', () => recalcLine(tr));
    tr.querySelector('.line-tax-rate').addEventListener('input', () => recalcLine(tr));
    tr.querySelector('.line-discount').addEventListener('input', () => recalcLine(tr));

    tbody.appendChild(tr);
    recalcGrandTotal();
}

function toggleLineType(tr, lineType) {
    const itemCell    = tr.querySelector('.line-item-cell');
    const serviceCell = tr.querySelector('.line-service-cell');
    const unitSpan    = tr.querySelector('.line-service-unit');

    if (lineType === 'service') {
        // Show service selector, hide item selector
        if (itemCell) itemCell.style.display = 'none';
        if (serviceCell) serviceCell.style.display = '';
        // Clear item selection
        const itemSelect = tr.querySelector('.line-item-select');
        if (itemSelect) itemSelect.value = '';
        // Show service unit if a service is selected
        const serviceSelect = tr.querySelector('.line-service-select');
        if (serviceSelect && serviceSelect.value) {
            const opt = serviceSelect.options[serviceSelect.selectedIndex];
            if (unitSpan) unitSpan.textContent = opt.dataset.unit || '';
        }
    } else {
        // Show item selector, hide service selector
        if (itemCell) itemCell.style.display = '';
        if (serviceCell) serviceCell.style.display = 'none';
        // Clear service selection
        const serviceSelect = tr.querySelector('.line-service-select');
        if (serviceSelect) serviceSelect.value = '';
        // Hide service unit
        if (unitSpan) unitSpan.textContent = '';
    }

    // Clear stock error when switching types
    const errorDiv = tr.querySelector('.stock-error');
    if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
    }
    tr.querySelector('.line-qty')?.classList.remove('is-invalid');
}

function recalcLine(tr) {
    const qty      = parseFloat(tr.querySelector('.line-qty').value)      || 0;
    const unitFc   = parseFloat(tr.querySelector('.line-unit-fc').value)  || 0;
    const discount = parseFloat(tr.querySelector('.line-discount').value) || 0;
    const rate     = parseFloat(F.exchangeRate().value)                   || 0;

    const unitBc  = calcBc(unitFc, rate);
    const totalFc = round2(qty * unitFc * (1 - discount / 100));

    tr.querySelector('.line-unit-bc').value  = formatAmount(unitBc);
    tr.querySelector('.line-total-fc').value = formatAmount(totalFc);

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
    const globalDiscountEl = document.getElementById('inputInvoiceGlobalDiscount');
    const globalDiscount = parseFloat(globalDiscountEl?.value) || 0;
    const discountMultiplier = 1 - (globalDiscount / 100);
    const afterDiscountFc = round2(subtotalFc * discountMultiplier);
    const afterDiscountBc = round2(subtotalBc * discountMultiplier);

    F.subtotalFc().textContent  = formatAmount(subtotalFc);
    F.subtotalBc().textContent  = formatAmount(subtotalBc);
    const afterDiscEl = document.getElementById('invoiceAfterDiscountFc');
    if (afterDiscEl) afterDiscEl.textContent = formatAmount(afterDiscountFc);
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
        const lineType = tr.dataset.lineType || 'item';
        const qty      = parseFloat(tr.querySelector('.line-qty')?.value)      || 0;
        const unitFc   = parseFloat(tr.querySelector('.line-unit-fc')?.value)  || 0;
        const taxRate  = parseFloat(tr.querySelector('.line-tax-rate')?.value) || 0;
        const discount = parseFloat(tr.querySelector('.line-discount')?.value) || 0;
        const unitBc   = calcBc(unitFc, rate);

        if (lineType === 'service') {
            const serviceId = tr.querySelector('.line-service-select')?.value;
            if (serviceId && qty > 0) {
                lines.push({
                    service_id:       parseInt(serviceId, 10),
                    quantity:         qty,
                    unit_price_fc:    unitFc,
                    unit_price_bc:    unitBc,
                    tax_rate:         taxRate,
                    discount_percent: discount,
                });
            }
        } else {
            const itemId = tr.querySelector('.line-item-select')?.value;
            if (itemId && qty > 0) {
                lines.push({
                    item_id:          parseInt(itemId, 10),
                    quantity:         qty,
                    unit_price_fc:    unitFc,
                    unit_price_bc:    unitBc,
                    tax_rate:         taxRate,
                    discount_percent: discount,
                });
            }
        }
    });

    return lines;
}

async function validateLineStock(tr) {
    // Only validate stock for item lines
    if (tr.dataset.lineType === 'service') return;

    const itemSelect = tr.querySelector('.line-item-select');
    const qtyInput   = tr.querySelector('.line-qty');
    const errorDiv   = tr.querySelector('.stock-error');
    const itemId     = itemSelect?.value;
    const qty        = parseFloat(qtyInput?.value) || 0;

    if (!errorDiv) return;

    // Clear previous error
    errorDiv.style.display = 'none';
    errorDiv.textContent = '';
    qtyInput?.classList.remove('is-invalid');

    if (!itemId || qty <= 0) return;

    try {
        const res  = await apiFetch(`/api/items/${itemId}/stock-check?quantity=${qty}`);
        const json = await res.json();
        if (!res.ok || !json.success) {
            errorDiv.textContent = json.message || 'Insufficient stock';
            errorDiv.style.display = 'block';
            qtyInput?.classList.add('is-invalid');
        }
    } catch (err) {
        // Silently ignore network errors for stock check
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.invoiceDate().value) {
        markInvalid(F.invoiceDate(), 'Invoice date is required');
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
