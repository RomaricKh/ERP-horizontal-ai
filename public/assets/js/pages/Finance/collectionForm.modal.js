// assets/js/pages/Finance/collectionForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { calcBc, formatAmount } from '../../utils/currency.js';
import { populatePayeeSelect } from '../../utils/payeeSelect.js';
import { attachRateAutoFetch } from '../../utils/exchangeRateHelper.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let currentMode = 'create'; // 'create' or 'view'
let currentCollectionId = null;
let outstandingInvoices = [];
let availableAccounts = [];

const F = {
    title:         () => document.getElementById('collectionModalTitle'),
    payee:         () => document.getElementById('inputCollectionPayee'),
    slipNumber:    () => document.getElementById('inputCollectionSlipNumber'),
    slipDate:      () => document.getElementById('inputCollectionSlipDate'),
    notes:         () => document.getElementById('inputCollectionNotes'),
    
    linesBody:     () => document.getElementById('collectionLinesBody'),
    btnAddLine:    () => document.getElementById('btnCollectionAddLine'),
    totalBc:       () => document.getElementById('collectionTotalBc'),

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

    F.btnAddLine().addEventListener('click', () => addLine());

    if (currentMode === 'view') {
        await fetchAndFill(currentCollectionId);
        // Make form read-only
        modalRoot.querySelectorAll('input, select, textarea, button').forEach(el => {
            if (el.dataset.bsDismiss !== 'modal' && !el.classList.contains('btn-close')) {
                el.disabled = true;
            }
        });
        modalRoot.querySelectorAll('[data-action="collections.save"]').forEach(el => {
            el.style.display = 'none';
        });
        if (F.btnFIFO()) F.btnFIFO().style.display = 'none';
        if (F.btnAddLine()) F.btnAddLine().style.display = 'none';
    } else {
        // Apply pre-fill data after dropdowns are loaded
        if (data?.prefill) {
            await applyPrefill(data.prefill);
        } else {
            addLine(); // Add an initial empty line
        }
    }

    // Auto-fill FIFO button
    F.btnFIFO()?.addEventListener('click', handleAutoFillFIFO);

    unregister = registerPageActions({
        'collections.save': async () => {
            if (!validateForm()) return;

            const allocations = gatherAllocations();
            const lines = gatherLines();

            const payload = {
                payee_id:       F.payee().value         || null,
                slip_number:    F.slipNumber().value.trim(),
                slip_date:      F.slipDate().value,
                notes:          F.notes().value.trim() || null,
                lines:          lines,
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
    unregister?.();
    unregister = null;
    currentMode = 'create';
    currentCollectionId = null;
    outstandingInvoices = [];
    availableAccounts = [];
}

// ── Denomination Spilling ───────────────────────────────────────────────

async function enforceDenominationRule(tr) {
    const inpFc = tr.querySelector('.line-fc');
    const selAccount = tr.querySelector('.line-account');
    const inpRate = tr.querySelector('.line-rate');

    if (!inpFc || !selAccount || !inpRate) return;

    const accountId = selAccount.value;
    const account = availableAccounts.find(a => String(a.id) === String(accountId));
    if (!account || !account.cash_denomination || parseFloat(account.cash_denomination) <= 0) return;

    const denom = parseFloat(account.cash_denomination);
    const rawVal = parseFloat(inpFc.value) || 0;
    if (rawVal <= 0) return;

    const remainder = Math.round((rawVal % denom) * 100) / 100;
    if (remainder === 0) return; // already a multiple

    const floored = Math.floor(rawVal / denom) * denom;

    const localAccount = availableAccounts.find(a => a.is_local_currency_account == 1);

    if (!localAccount || localAccount.currency === account.currency) {
        // Round to nearest denomination in-place
        inpFc.value = Math.round(rawVal / denom) * denom;
        const fc = parseFloat(inpFc.value) || 0;
        const rate = parseFloat(inpRate.value) || 0;
        tr.querySelector('.line-bc').value = formatAmount(calcBc(fc, rate));
        updateOverallTotal();
        updateAllocationTotals();
        return;
    }

    // Floor the current line
    inpFc.value = floored;
    const fc = parseFloat(inpFc.value) || 0;
    const rate = parseFloat(inpRate.value) || 0;
    tr.querySelector('.line-bc').value = formatAmount(calcBc(fc, rate));

    const remainderBc = Math.round(remainder * rate * 100) / 100;

    let localRate = 1;
    try {
        const res = await apiFetch(`/api/exchange-rates/latest?currency=${encodeURIComponent(localAccount.currency)}`);
        const json = await res.json();
        if (json.success && json.data?.rate) {
            localRate = parseFloat(json.data.rate);
        }
    } catch (e) {
        console.error('Failed to fetch local currency rate for spillover:', e);
    }

    let localAmountFc = Math.round((remainderBc / localRate) * 100) / 100;
    const localDenom = localAccount.cash_denomination ? parseFloat(localAccount.cash_denomination) : 0;
    if (localDenom > 0) {
        localAmountFc = Math.ceil(localAmountFc / localDenom) * localDenom; // rounded UP
    }

    F.linesBody().querySelectorAll('tr.spillover-line').forEach(r => r.remove());

    addLine({
        account_id: localAccount.id,
        currency: localAccount.currency,
        exchange_rate: localRate,
        amount_fc: localAmountFc,
        amount_bc: Math.round(localAmountFc * localRate * 100) / 100,
        method: 'CASH',
        notes: 'Denomination spillover'
    });

    const lastRow = F.linesBody().querySelector('tr:last-child');
    if (lastRow) lastRow.classList.add('spillover-line');

    updateOverallTotal();
    updateAllocationTotals();
}

// ── Lines Management ──────────────────────────────────────────────────

function addLine(lineData = null) {
    const tbody = F.linesBody();
    const tr = document.createElement('tr');
    tr.className = 'collection-line';
    
    // Method options
    const methods = ['CASH', 'BANK', 'CHECK', 'TRANSFER'];
    let methodOptions = '<option value="">— Select —</option>';
    methods.forEach(m => {
        const selected = (lineData && lineData.method === m) ? 'selected' : '';
        methodOptions += `<option value="${m}" ${selected}>${m}</option>`;
    });

    // Currency options
    const usableCurrencies = [...new Set(availableAccounts.map(a => a.currency))].filter(Boolean);
    let currencyOptions = '<option value="">— Select —</option>';
    usableCurrencies.forEach(c => {
        const selected = (lineData && lineData.currency === c) ? 'selected' : '';
        currencyOptions += `<option value="${c}" ${selected}>${c}</option>`;
    });

    tr.innerHTML = `
        <td><select class="form-select form-select-sm line-account" required></select></td>
        <td><select class="form-select form-select-sm line-method" required>${methodOptions}</select></td>
        <td><select class="form-select form-select-sm line-currency" required>${currencyOptions}</select></td>
        <td><input type="number" class="form-control form-control-sm line-rate" step="0.000000000001" min="0" value="${lineData ? lineData.exchange_rate : 1}" required></td>
        <td><input type="number" class="form-control form-control-sm line-fc" step="0.01" min="0" value="${lineData ? lineData.amount_fc : ''}" required></td>
        <td><input type="text" class="form-control form-control-sm line-bc bg-light" value="${lineData ? lineData.amount_bc : ''}" readonly tabindex="-1"></td>
        <td><input type="text" class="form-control form-control-sm line-notes" value="${lineData ? (lineData.notes||'') : ''}"></td>
        <td class="text-center align-middle">
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-line"><i class="fas fa-trash"></i></button>
        </td>
    `;
    
    tbody.appendChild(tr);

    // Get elements
    const selAccount = tr.querySelector('.line-account');
    const selMethod = tr.querySelector('.line-method');
    const selCurrency = tr.querySelector('.line-currency');
    const inpRate = tr.querySelector('.line-rate');
    const inpFc = tr.querySelector('.line-fc');
    const inpBc = tr.querySelector('.line-bc');
    const btnRemove = tr.querySelector('.btn-remove-line');

    const populateAccounts = (currencyFilter, methodFilter) => {
        const currentVal = selAccount.value;
        selAccount.innerHTML = '<option value="">— Select —</option>';
        
        const typePriority = { 'CASH': 1, 'BANK': 2, 'CREDIT_CARD': 3, 'OTHER': 4 };
        const sortedAccounts = [...availableAccounts].sort((a, b) => (typePriority[a.account_type] || 99) - (typePriority[b.account_type] || 99));

        sortedAccounts.forEach(acc => {
            if (currencyFilter && acc.currency !== currencyFilter) return;
            
            if (methodFilter === 'CASH' && acc.account_type !== 'CASH') return;
            if (['BANK', 'CHECK'].includes(methodFilter) && acc.account_type !== 'BANK') return;

            const opt = document.createElement('option');
            opt.value = acc.id;
            opt.dataset.currency = acc.currency;
            opt.textContent = `${acc.name} (${acc.currency})`;
            selAccount.appendChild(opt);
        });
        
        if (currentVal && selAccount.querySelector(`option[value="${currentVal}"]`)) {
            selAccount.value = currentVal;
        } else if (currencyFilter || methodFilter) {
            const firstOpt = selAccount.querySelector('option[value]:not([value=""])');
            if (firstOpt) selAccount.value = firstOpt.value;
            else selAccount.value = "";
        }
    };

    // Initial population
    if (lineData && lineData.currency) {
        populateAccounts(lineData.currency, lineData.method || '');
        if (lineData.account_id) {
            selAccount.value = lineData.account_id;
        }
    } else {
        populateAccounts('', selMethod.value);
    }

    if (!selMethod.value && selAccount.value) {
        const acc = availableAccounts.find(a => String(a.id) === String(selAccount.value));
        if (acc) {
            if (acc.account_type === 'CASH') selMethod.value = 'CASH';
            else if (acc.account_type === 'BANK') selMethod.value = 'BANK';
            else selMethod.value = 'TRANSFER';
        }
    }

    const updateBc = () => {
        const fc = parseFloat(inpFc.value) || 0;
        const rate = parseFloat(inpRate.value) || 0;
        inpBc.value = formatAmount(calcBc(fc, rate));
        updateOverallTotal();
    };

    inpFc.addEventListener('input', () => {
        updateBc();
        updateAllocationTotals();
        handleAutoFillFIFO();
    });

    // Enforce denomination rule on change
    inpFc.addEventListener('change', async () => {
        await enforceDenominationRule(tr);
    });

    inpRate.addEventListener('input', updateBc);

    // Auto-fetch exchange rate when currency changes
    attachRateAutoFetch(selCurrency, inpRate);
    selCurrency.addEventListener('change', () => {
        updateBc();
        populateAccounts(selCurrency.value, selMethod.value);
    });
    
    selMethod.addEventListener('change', () => {
        populateAccounts(selCurrency.value, selMethod.value);
    });

    selAccount.addEventListener('change', () => {
        const opt = selAccount.options[selAccount.selectedIndex];
        if (opt && opt.dataset.currency && selCurrency.value !== opt.dataset.currency) {
            selCurrency.value = opt.dataset.currency;
            selCurrency.dispatchEvent(new Event('change'));
        }
        
        const account = availableAccounts.find(a => String(a.id) === String(selAccount.value));
        if (account) {
            // Update step on amount input based on denomination
            if (account.cash_denomination && parseFloat(account.cash_denomination) > 0) {
                inpFc.step = parseFloat(account.cash_denomination);
            } else {
                inpFc.step = '0.01';
            }

            // Auto-update method based on account type
            if (account.account_type === 'CASH' && selMethod.value !== 'CASH') {
                selMethod.value = 'CASH';
            } else if (account.account_type === 'BANK' && !['BANK', 'CHECK', 'TRANSFER'].includes(selMethod.value)) {
                selMethod.value = 'BANK';
            } else if (!['CASH', 'BANK'].includes(account.account_type)) {
                selMethod.value = 'TRANSFER';
            }
        }
    });

    btnRemove.addEventListener('click', () => {
        tr.remove();
        updateOverallTotal();
        updateAllocationTotals();
        handleAutoFillFIFO();
    });

    if (lineData) {
        // Just trigger BC calculation
        updateBc();
    }

    // Set step on amount input based on selected account denomination
    const selectedAccount = availableAccounts.find(a => String(a.id) === String(selAccount.value));
    if (selectedAccount && selectedAccount.cash_denomination && parseFloat(selectedAccount.cash_denomination) > 0) {
        inpFc.step = parseFloat(selectedAccount.cash_denomination);
    }
}

function updateOverallTotal() {
    let totalBc = 0;
    F.linesBody().querySelectorAll('.line-bc').forEach(inp => {
        totalBc += parseFloat(inp.value) || 0;
    });
    F.totalBc().textContent = formatAmount(totalBc);
}

function gatherLines() {
    const lines = [];
    F.linesBody().querySelectorAll('tr').forEach(tr => {
        lines.push({
            account_id:    tr.querySelector('.line-account').value,
            method:        tr.querySelector('.line-method').value,
            currency:      tr.querySelector('.line-currency').value,
            exchange_rate: parseFloat(tr.querySelector('.line-rate').value),
            amount_fc:     parseFloat(tr.querySelector('.line-fc').value),
            amount_bc:     parseFloat(tr.querySelector('.line-bc').value),
            notes:         tr.querySelector('.line-notes').value.trim()
        });
    });
    return lines;
}

function getSlipTotalBc() {
    let totalBc = 0;
    F.linesBody().querySelectorAll('.line-bc').forEach(inp => {
        totalBc += parseFloat(inp.value) || 0;
    });
    return totalBc;
}

function getSlipTotalFc() {
    let totalFc = 0;
    F.linesBody().querySelectorAll('.line-fc').forEach(inp => {
        totalFc += parseFloat(inp.value) || 0;
    });
    return totalFc;
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
    const amountBc = getSlipTotalBc();
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

    const slipAmount = getSlipTotalBc();
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
        } else if (totalAllocated > 0 && totalAllocated < slipAmount) {
            const surplus = round2(slipAmount - totalAllocated);
            statusEl.className = 'badge bg-info text-white';
            statusEl.innerHTML = `<i class="fas fa-info-circle me-1"></i>Credit surplus: ${surplus.toFixed(2)}`;
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
    const slipAmountBc = getSlipTotalBc();
    const slipAmountFc = getSlipTotalFc();

    let totalAllocatedBc = 0;
    let totalAllocatedFc = 0;
    const inputs = Array.from(tbody.querySelectorAll('.alloc-amount')).filter(inp => (parseFloat(inp.value) || 0) > 0);

    inputs.forEach((input, index) => {
        const amountBc = parseFloat(input.value) || 0;
        const idx = parseInt(input.dataset.idx);
        const doc = outstandingInvoices[idx];
        if (!doc) return;

        let amountFc = 0;
        if (doc.remaining_bc > 0) {
            amountFc = (amountBc / doc.remaining_bc) * doc.remaining_fc;
        } else {
            amountFc = amountBc;
        }
        
        amountFc = Math.round(amountFc * 100) / 100;

        totalAllocatedBc += amountBc;
        totalAllocatedFc += amountFc;

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

        F.payee().value        = c.payee_id       ?? '';
        F.slipNumber().value   = c.slip_number    ?? '';
        F.slipDate().value     = c.slip_date      ?? '';
        F.notes().value        = c.notes          ?? '';
        
        // Add lines
        if (c.lines && c.lines.length > 0) {
            c.lines.forEach(line => addLine(line));
        }

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
            <td class="align-middle">${escHtml(alloc.doc_number || ('#' + alloc.reference_id))}</td>
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
    if (slipEl) slipEl.textContent = fmt.formatNumber(getSlipTotalBc(), 2);
    if (statusEl) {
        statusEl.className = 'badge bg-success';
        statusEl.innerHTML = '<i class="fas fa-check-circle me-1"></i>Balanced';
    }
}

// ── Helpers ───────────────────────────────────────────────────────────

async function loadAccounts() {
    try {
        const res  = await apiFetch('/api/accounts');
        const json = await res.json();
        availableAccounts = json.success ? (json.data?.accounts ?? []) : [];
    } catch (err) {
        console.error('loadAccounts failed:', err);
    }
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

    const amountFc = parseFloat(prefill.amount_fc) || 0;
    const prefillRate = prefill.exchange_rate || 1;

    // Add the main prefill line exactly as provided
    addLine({
        account_id: prefill.account_id || '',
        currency: prefill.currency || '',
        exchange_rate: prefillRate,
        amount_fc: amountFc,
        amount_bc: prefill.amount_bc || 0,
        method: prefill.method || '',
        notes: ''
    });

    const addedTr = F.linesBody().querySelector('tr:last-child');
    if (addedTr) {
        await enforceDenominationRule(addedTr);
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

    const amountBc = getSlipTotalBc();

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
    if (!F.slipDate().value) {
        markInvalid(F.slipDate(), 'Slip date is required');
        valid = false;
    }

    const lines = F.linesBody().querySelectorAll('tr');
    if (lines.length === 0) {
        showFormError('At least one payment line is required');
        valid = false;
    }

    lines.forEach(tr => {
        const acc = tr.querySelector('.line-account');
        const meth = tr.querySelector('.line-method');
        const curr = tr.querySelector('.line-currency');
        const rate = tr.querySelector('.line-rate');
        const fc = tr.querySelector('.line-fc');

        if (!acc.value) { markInvalid(acc, 'Req'); valid = false; }
        if (!meth.value) { markInvalid(meth, 'Req'); valid = false; }
        if (!curr.value) { markInvalid(curr, 'Req'); valid = false; }
        if (!rate.value) { markInvalid(rate, 'Req'); valid = false; }
        if (!fc.value) { markInvalid(fc, 'Req'); valid = false; }
    });

    // Validate allocations don't exceed slip amount
    if (outstandingInvoices.length > 0) {
        const allocations = gatherAllocations();
        if (allocations.length > 0) {
            const totalAlloc = allocations.reduce((sum, a) => sum + a.amount_bc, 0);
            const slipAmount = getSlipTotalBc();
            if (totalAlloc > slipAmount + 0.01) {
                showFormError('Allocations cannot exceed the collection amount');
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
