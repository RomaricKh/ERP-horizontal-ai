// assets/js/pages/Finance/collectionForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { calcBc, formatAmount } from '../../utils/currency.js';
import { populatePayeeSelect } from '../../utils/payeeSelect.js';
import { attachRateAutoFetch } from '../../utils/exchangeRateHelper.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let cleanupRateAutoFetch;
let currentMode = 'create'; // 'create' or 'view'
let currentCollectionId = null;
let outstandingInvoices = [];

const F = {
    title:         () => document.getElementById('collectionModalTitle'),
    account:       () => document.getElementById('inputCollectionAccount'),
    payee:         () => document.getElementById('inputCollectionPayee'),
    slipNumber:    () => document.getElementById('inputCollectionSlipNumber'),
    slipDate:      () => document.getElementById('inputCollectionSlipDate'),
    currency:      () => document.getElementById('inputCollectionCurrency'),
    exchangeRate:  () => document.getElementById('inputCollectionExchangeRate'),
    amountFc:      () => document.getElementById('inputCollectionAmountFc'),
    amountBc:      () => document.getElementById('inputCollectionAmountBc'),
    method:        () => document.getElementById('inputCollectionMethod'),
    notes:         () => document.getElementById('inputCollectionNotes'),
    allocSection:  () => document.getElementById('collectionAllocationSection'),
    allocBody:     () => document.getElementById('collectionAllocBody'),
    allocStatus:   () => document.getElementById('collectionAllocationStatus'),
    allocatedTotal:() => document.getElementById('collectionAllocatedTotal'),
    slipTotal:     () => document.getElementById('collectionSlipTotal'),
    btnFIFO:       () => document.getElementById('btnCollectionAutoFillFIFO'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    currentMode = data?.collection_id ? 'view' : 'create';
    currentCollectionId = data?.collection_id ?? null;

    F.title().textContent = currentMode === 'view' ? 'Collection Details' : 'Add Collection';

    // Populate account select
    await loadAccounts();

    // Populate payee select (customers)
    await populatePayeeSelect(F.payee());

    // Listen for payee changes to load outstanding invoices
    F.payee().addEventListener('change', handlePayeeChange);

    if (currentMode === 'view') {
        await fetchAndFill(currentCollectionId);
        // Make form read-only
        modalRoot.querySelectorAll('input, select, textarea').forEach(el => {
            el.disabled = true;
        });
        modalRoot.querySelectorAll('[data-action="collections.save"]').forEach(el => {
            el.style.display = 'none';
        });
        if (F.btnFIFO()) F.btnFIFO().style.display = 'none';
    } else {
        // Apply pre-fill data after dropdowns are loaded
        await applyPrefill(data?.prefill);
    }

    // Auto-calculate amount_bc when amount_fc or exchange_rate changes
    F.amountFc().addEventListener('input', () => { recalcBc(); updateAllocationTotals(); });
    F.exchangeRate().addEventListener('input', recalcBc);

    // Auto-fetch exchange rate when currency changes
    cleanupRateAutoFetch = attachRateAutoFetch(F.currency(), F.exchangeRate());

    // Sync: when account is selected, force currency to match
    F.account().addEventListener('change', () => {
        const selectedOption = F.account().options[F.account().selectedIndex];
        if (selectedOption && selectedOption.dataset.currency) {
            F.currency().value = selectedOption.dataset.currency;
            F.currency().dispatchEvent(new Event('change'));
        }
    });

    // Sync: when currency changes, filter accounts to matching currency
    F.currency().addEventListener('change', async () => {
        const selectedCurrency = F.currency().value;
        const currentAccount = F.account().value;
        await loadAccounts(selectedCurrency || null);
        if (currentAccount) {
            const stillExists = F.account().querySelector(`option[value="${currentAccount}"]`);
            if (stillExists) {
                F.account().value = currentAccount;
            } else {
                F.account().value = '';
            }
        }
    });

    // Auto-fill FIFO button
    F.btnFIFO()?.addEventListener('click', handleAutoFillFIFO);

    unregister = registerPageActions({
        'collections.save': async () => {
            if (!validateForm()) return;

            const allocations = gatherAllocations();

            const payload = {
                account_id:     F.account().value       || null,
                payee_id:       F.payee().value         || null,
                slip_number:    F.slipNumber().value.trim(),
                slip_date:      F.slipDate().value,
                currency:       F.currency().value,
                exchange_rate:  parseFloat(F.exchangeRate().value),
                amount_fc:      parseFloat(F.amountFc().value),
                method:         F.method().value,
                notes:          F.notes().value.trim() || null,
                allocations:    allocations,
            };

            try {
                const res  = await apiFetch('/api/collections', { method: 'POST', body: JSON.stringify(payload) });
                const json = await res.json();
                if (!res.ok || !json.success) {
                    const errorMsg = json.message || 'Operation failed';
                    showFormError(errorMsg);
                    return;
                }
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('collections.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save collection');
            }
        }
    });
}

export function unmount() {
    cleanupRateAutoFetch?.();
    cleanupRateAutoFetch = null;
    unregister?.();
    unregister = null;
    currentMode = 'create';
    currentCollectionId = null;
    outstandingInvoices = [];
}

// ── Payee Change & Outstanding Invoices ───────────────────────────────

async function handlePayeeChange() {
    const payeeId = F.payee().value;
    if (!payeeId) {
        hideAllocationSection();
        return;
    }
    await loadOutstandingInvoices(payeeId);
}

async function loadOutstandingInvoices(payeeId) {
    try {
        const res = await apiFetch(`/api/collections/outstanding-invoices?payee_id=${payeeId}`);
        const json = await res.json();
        if (!json.success) {
            outstandingInvoices = [];
        } else {
            outstandingInvoices = json.data?.documents ?? [];
        }
        renderAllocationTable();
        showAllocationSection();
    } catch (err) {
        console.error('loadOutstandingInvoices failed:', err);
        outstandingInvoices = [];
        renderAllocationTable();
    }
}

function showAllocationSection() {
    const section = F.allocSection();
    if (section) section.style.display = '';
}

function hideAllocationSection() {
    const section = F.allocSection();
    if (section) section.style.display = 'none';
    outstandingInvoices = [];
}

function renderAllocationTable() {
    const tbody = F.allocBody();
    if (!tbody) return;

    const fmt = getFormatter();

    if (outstandingInvoices.length === 0) {
        tbody.innerHTML = `<tr id="collectionAllocEmpty">
            <td colspan="7" class="text-center text-muted py-3">
                <i class="fas fa-check-circle me-1 text-success"></i>No outstanding invoices for this customer
            </td>
        </tr>`;
        updateAllocationTotals();
        return;
    }

    tbody.innerHTML = '';
    outstandingInvoices.forEach((doc, idx) => {
        const tr = document.createElement('tr');
        tr.dataset.idx = idx;
        tr.innerHTML = `
            <td class="text-center align-middle">
                <input type="checkbox" class="form-check-input alloc-check" data-idx="${idx}">
            </td>
            <td class="align-middle">${escHtml(doc.doc_number)}</td>
            <td class="align-middle">${fmt.formatDate(doc.doc_date)}</td>
            <td class="align-middle text-end">${fmt.formatNumber(doc.total_bc, 2)}</td>
            <td class="align-middle text-end">${fmt.formatNumber(doc.allocated_bc, 2)}</td>
            <td class="align-middle text-end">${fmt.formatNumber(doc.remaining_bc, 2)}</td>
            <td class="align-middle">
                <input type="number" class="form-control form-control-sm text-end alloc-amount"
                       data-idx="${idx}" step="0.01" min="0" max="${doc.remaining_bc}" value="0" placeholder="0.00">
            </td>
        `;
        tbody.appendChild(tr);
    });

    // Attach input listeners
    tbody.querySelectorAll('.alloc-amount').forEach(input => {
        input.addEventListener('input', () => {
            const idx = parseInt(input.dataset.idx);
            const check = tbody.querySelector(`.alloc-check[data-idx="${idx}"]`);
            const val = parseFloat(input.value) || 0;
            if (check) check.checked = val > 0;
            updateAllocationTotals();
        });
    });

    tbody.querySelectorAll('.alloc-check').forEach(check => {
        check.addEventListener('change', () => {
            const idx = parseInt(check.dataset.idx);
            const input = tbody.querySelector(`.alloc-amount[data-idx="${idx}"]`);
            if (!check.checked && input) {
                input.value = '0';
            }
            updateAllocationTotals();
        });
    });

    updateAllocationTotals();
}

function handleAutoFillFIFO() {
    const amountBc = parseFloat(F.amountBc().value) || 0;
    if (amountBc <= 0 || outstandingInvoices.length === 0) return;

    let remaining = amountBc;
    const tbody = F.allocBody();

    outstandingInvoices.forEach((doc, idx) => {
        const input = tbody.querySelector(`.alloc-amount[data-idx="${idx}"]`);
        const check = tbody.querySelector(`.alloc-check[data-idx="${idx}"]`);
        if (!input) return;

        if (remaining <= 0) {
            input.value = '0';
            if (check) check.checked = false;
            return;
        }

        const allocAmount = Math.min(remaining, doc.remaining_bc);
        input.value = allocAmount.toFixed(2);
        if (check) check.checked = allocAmount > 0;
        remaining -= allocAmount;
    });

    updateAllocationTotals();
}

function updateAllocationTotals() {
    const tbody = F.allocBody();
    if (!tbody) return;

    let totalAllocated = 0;
    tbody.querySelectorAll('.alloc-amount').forEach(input => {
        totalAllocated += parseFloat(input.value) || 0;
    });

    const slipAmount = parseFloat(F.amountBc().value) || 0;
    const fmt = getFormatter();

    const allocatedEl = F.allocatedTotal();
    const slipEl = F.slipTotal();
    const statusEl = F.allocStatus();

    if (allocatedEl) allocatedEl.textContent = fmt.formatNumber(totalAllocated, 2);
    if (slipEl) slipEl.textContent = fmt.formatNumber(slipAmount, 2);

    if (statusEl) {
        const diff = Math.abs(totalAllocated - slipAmount);
        if (slipAmount > 0 && diff < 0.01) {
            statusEl.className = 'badge bg-success';
            statusEl.innerHTML = '<i class="fas fa-check-circle me-1"></i>Balanced';
        } else if (totalAllocated > slipAmount && slipAmount > 0) {
            statusEl.className = 'badge bg-danger';
            statusEl.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Over-allocated';
        } else {
            statusEl.className = 'badge bg-warning text-dark';
            statusEl.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>Not balanced';
        }
    }
}

function gatherAllocations() {
    const tbody = F.allocBody();
    if (!tbody) return [];

    const allocations = [];
    const exchangeRate = parseFloat(F.exchangeRate().value) || 1;

    tbody.querySelectorAll('.alloc-amount').forEach(input => {
        const amountBc = parseFloat(input.value) || 0;
        if (amountBc <= 0) return;

        const idx = parseInt(input.dataset.idx);
        const doc = outstandingInvoices[idx];
        if (!doc) return;

        const amountFc = exchangeRate > 0 ? round2(amountBc / exchangeRate) : amountBc;

        allocations.push({
            reference_type: 'SALES_INVOICE',
            reference_id:   doc.id,
            amount_fc:      amountFc,
            amount_bc:      amountBc,
        });
    });

    return allocations;
}

// ── View Mode: Fetch & Fill ───────────────────────────────────────────

async function fetchAndFill(collectionId) {
    try {
        const res  = await apiFetch(`/api/collections/${collectionId}`);
        const json = await res.json();
        if (!json.success) return;

        const c = json.data?.collection ?? {};

        F.account().value      = c.account_id     ?? '';
        F.payee().value        = c.payee_id       ?? '';
        F.slipNumber().value   = c.slip_number    ?? '';
        F.slipDate().value     = c.slip_date      ?? '';
        F.currency().value     = c.currency       ?? '';
        F.exchangeRate().value = c.exchange_rate  ?? '';
        F.amountFc().value     = c.amount_fc      ?? '';
        F.amountBc().value     = c.amount_bc      ?? '';
        F.method().value       = c.method         ?? '';
        F.notes().value        = c.notes          ?? '';

        // Load allocations for this collection
        await loadViewAllocations(collectionId);
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

async function loadViewAllocations(collectionId) {
    try {
        const res = await apiFetch(`/api/collections/${collectionId}/allocations`);
        const json = await res.json();
        if (!json.success) return;

        const allocations = json.data?.allocations ?? [];
        if (allocations.length > 0) {
            renderViewAllocations(allocations);
            showAllocationSection();
        }
    } catch (err) {
        console.error('loadViewAllocations failed:', err);
    }
}

function renderViewAllocations(allocations) {
    const tbody = F.allocBody();
    if (!tbody) return;

    const fmt = getFormatter();
    tbody.innerHTML = '';

    allocations.forEach(alloc => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="text-center align-middle"><i class="fas fa-check text-success"></i></td>
            <td class="align-middle">${escHtml(alloc.doc_number || `#${alloc.reference_id}`)}</td>
            <td class="align-middle">${alloc.doc_date ? fmt.formatDate(alloc.doc_date) : '—'}</td>
            <td class="align-middle text-end">${alloc.doc_total_bc ? fmt.formatNumber(alloc.doc_total_bc, 2) : '—'}</td>
            <td class="align-middle text-end">—</td>
            <td class="align-middle text-end">—</td>
            <td class="align-middle text-end fw-bold">${fmt.formatNumber(alloc.amount_bc, 2)}</td>
        `;
        tbody.appendChild(tr);
    });

    // Update totals
    let totalAllocated = 0;
    allocations.forEach(a => { totalAllocated += parseFloat(a.amount_bc) || 0; });
    const allocatedEl = F.allocatedTotal();
    const slipEl = F.slipTotal();
    const statusEl = F.allocStatus();
    if (allocatedEl) allocatedEl.textContent = fmt.formatNumber(totalAllocated, 2);
    if (slipEl) slipEl.textContent = F.amountBc().value || '0.00';
    if (statusEl) {
        statusEl.className = 'badge bg-success';
        statusEl.innerHTML = '<i class="fas fa-check-circle me-1"></i>Balanced';
    }
}

// ── Helpers ───────────────────────────────────────────────────────────

async function loadAccounts(currency) {
    try {
        const url = currency
            ? `/api/accounts?currency=${encodeURIComponent(currency)}`
            : '/api/accounts';
        const res  = await apiFetch(url);
        const json = await res.json();
        const accounts = json.success ? (json.data?.accounts ?? []) : [];

        const select = F.account();
        select.innerHTML = '';

        if (accounts.length === 0 && currency) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.disabled = true;
            opt.selected = true;
            opt.textContent = 'No accounts for this currency';
            select.appendChild(opt);
        } else {
            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = '— Select account —';
            select.appendChild(defaultOpt);

            accounts.forEach(acc => {
                const opt = document.createElement('option');
                opt.value       = acc.id;
                opt.textContent = acc.name + ' (' + acc.currency + ')';
                opt.dataset.currency = acc.currency;
                select.appendChild(opt);
            });
        }
    } catch (err) {
        console.error('loadAccounts failed:', err);
    }
}

function recalcBc() {
    const fc   = parseFloat(F.amountFc().value)     || 0;
    const rate = parseFloat(F.exchangeRate().value) || 0;
    F.amountBc().value = formatAmount(calcBc(fc, rate));
}

async function applyPrefill(prefill) {
    if (!prefill) return;

    if (prefill.payee_id) {
        const payeeSelect = F.payee();
        const option = payeeSelect.querySelector(`option[value="${prefill.payee_id}"]`);
        if (option) {
            payeeSelect.value = prefill.payee_id;
            await loadOutstandingInvoices(prefill.payee_id);
        }
    }

    if (prefill.currency) {
        F.currency().value = prefill.currency;
        await loadAccounts(prefill.currency);
    }

    if (prefill.exchange_rate) {
        F.exchangeRate().value = prefill.exchange_rate;
    }

    if (prefill.amount_fc) {
        F.amountFc().value = prefill.amount_fc;
        recalcBc();
        updateAllocationTotals();
    }

    // Auto-allocate specific invoice if reference_type + reference_id provided
    if (prefill.reference_type && prefill.reference_id) {
        autoAllocateInvoice(prefill.reference_id);
    }
}

/**
 * Auto-select and allocate a specific invoice in the allocation table.
 * Used when collecting payment for a specific invoice directly.
 */
function autoAllocateInvoice(invoiceId) {
    const tbody = F.allocBody();
    if (!tbody) return;

    const amountBc = parseFloat(F.amountBc().value) || 0;

    for (let idx = 0; idx < outstandingInvoices.length; idx++) {
        const doc = outstandingInvoices[idx];
        if (String(doc.id) === String(invoiceId)) {
            const input = tbody.querySelector(`.alloc-amount[data-idx="${idx}"]`);
            const check = tbody.querySelector(`.alloc-check[data-idx="${idx}"]`);
            if (input) {
                const allocAmount = Math.min(amountBc, doc.remaining_bc);
                input.value = allocAmount.toFixed(2);
            }
            if (check) check.checked = true;
            break;
        }
    }

    updateAllocationTotals();
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.payee().value) {
        markInvalid(F.payee(), 'Customer is required');
        valid = false;
    }
    if (!F.account().value) {
        markInvalid(F.account(), 'Account is required');
        valid = false;
    }
    if (!F.slipNumber().value.trim()) {
        markInvalid(F.slipNumber(), 'Slip number is required');
        valid = false;
    }
    if (!F.slipDate().value) {
        markInvalid(F.slipDate(), 'Slip date is required');
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
    if (!F.amountFc().value) {
        markInvalid(F.amountFc(), 'Amount is required');
        valid = false;
    }
    if (!F.method().value) {
        markInvalid(F.method(), 'Payment method is required');
        valid = false;
    }

    // Validate allocations balance (if invoices are shown)
    if (outstandingInvoices.length > 0) {
        const allocations = gatherAllocations();
        if (allocations.length > 0) {
            const totalAlloc = allocations.reduce((sum, a) => sum + a.amount_bc, 0);
            const slipAmount = parseFloat(F.amountBc().value) || 0;
            if (Math.abs(totalAlloc - slipAmount) > 0.01) {
                showFormError('Allocation total must equal the collection amount');
                valid = false;
            }
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

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

function round2(n) {
    return Math.round(n * 100) / 100;
}
