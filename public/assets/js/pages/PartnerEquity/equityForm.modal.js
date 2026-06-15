// assets/js/pages/PartnerEquity/equityForm.modal.js
import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { calcBc, formatAmount } from '../../utils/currency.js';
import { attachRateAutoFetch } from '../../utils/exchangeRateHelper.js';

let modalRoot;
let unregister;
let cleanupRateAutoFetch;

const F = {
    partner:      () => document.getElementById('inputEquityPartner'),
    type:         () => document.getElementById('inputEquityType'),
    account:      () => document.getElementById('inputEquityAccount'),
    currency:     () => document.getElementById('inputEquityCurrency'),
    exchangeRate: () => document.getElementById('inputEquityExchangeRate'),
    amountFc:     () => document.getElementById('inputEquityAmountFc'),
    amountBc:     () => document.getElementById('inputEquityAmountBc'),
    notes:        () => document.getElementById('inputEquityNotes'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    // Populate partner dropdown from partner shares
    await loadPartnerDropdown();

    // Populate account dropdown
    await loadAccountDropdown();

    // Pre-select equity type if passed from parent
    if (data?.equity_type) {
        F.type().value = data.equity_type;
        applyEquityTypeTheme(data.equity_type);
    }

    // Change theme when type dropdown changes
    F.type().addEventListener('change', () => {
        applyEquityTypeTheme(F.type().value);
    });

    // Auto-calculate amount_bc when amount_fc or exchange_rate changes
    F.amountFc().addEventListener('input', recalcBc);
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
        await loadAccountDropdown(selectedCurrency || null);
        if (currentAccount) {
            const stillExists = F.account().querySelector(`option[value="${currentAccount}"]`);
            if (stillExists) {
                F.account().value = currentAccount;
            } else {
                F.account().value = '';
            }
        }
    });

    unregister = registerPageActions({
        'partnerEquity.save': async () => {
            if (!validateForm()) return;

            const payload = {
                partner_id:    parseInt(F.partner().value, 10),
                equity_type:   F.type().value,
                account_id:    parseInt(F.account().value, 10),
                currency:      F.currency().value,
                exchange_rate: parseFloat(F.exchangeRate().value),
                amount:        parseFloat(F.amountFc().value),
                notes:         F.notes().value.trim() || null
            };

            try {
                const res = await apiFetch('/api/partner-equity', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    throw new Error(json.message || 'Failed to record equity entry');
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('partnerEquity.updated'));
            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save equity entry');
            }
        }
    });
}

export function unmount() {
    cleanupRateAutoFetch?.();
    cleanupRateAutoFetch = null;
    unregister?.();
    unregister = null;
}

function recalcBc() {
    const fc   = parseFloat(F.amountFc().value)     || 0;
    const rate = parseFloat(F.exchangeRate().value) || 0;
    F.amountBc().value = formatAmount(calcBc(fc, rate));
}

function applyEquityTypeTheme(equityType) {
    const header = modalRoot.querySelector('.modal-header');
    const sectionHeadings = modalRoot.querySelectorAll('h6.fw-bold');
    const saveBtn = modalRoot.querySelector('[data-action="partnerEquity.save"]');
    const titleSpan = modalRoot.querySelector('#equityModalTitle span') || modalRoot.querySelector('#equityModalTitle');

    if (equityType === 'CONTRIBUTION') {
        // Green theme for contributions
        header.className = 'modal-header bg-gradient-success text-white';
        sectionHeadings.forEach(h => {
            h.classList.remove('text-warning', 'text-danger');
            h.classList.add('text-success');
        });
        if (saveBtn) {
            saveBtn.className = 'btn btn-success';
        }
        if (titleSpan) titleSpan.textContent = 'Record Contribution';
    } else if (equityType === 'WITHDRAWAL') {
        // Yellow/warning theme for withdrawals
        header.className = 'modal-header bg-gradient-warning';
        sectionHeadings.forEach(h => {
            h.classList.remove('text-success', 'text-danger');
            h.classList.add('text-warning');
        });
        if (saveBtn) {
            saveBtn.className = 'btn btn-warning';
        }
        if (titleSpan) titleSpan.textContent = 'Record Withdrawal';
    } else {
        // Default neutral
        header.className = 'modal-header bg-gradient-primary text-white';
        sectionHeadings.forEach(h => {
            h.classList.remove('text-success', 'text-warning');
            h.classList.add('text-primary');
        });
        if (saveBtn) {
            saveBtn.className = 'btn btn-primary';
        }
        if (titleSpan) titleSpan.textContent = 'Record Equity Entry';
    }
}

async function loadPartnerDropdown() {
    const select = F.partner();
    if (!select) return;

    try {
        const res = await apiFetch('/api/partner-shares');
        const json = await res.json();
        const shares = json.data?.shares ?? json.data?.partner_shares ?? json.data ?? [];

        const placeholder = select.querySelector('option[value=""]');
        select.innerHTML = '';
        if (placeholder) select.appendChild(placeholder);

        for (const share of shares) {
            const option = document.createElement('option');
            option.value = share.partner_id;
            option.textContent = share.partner_name;
            select.appendChild(option);
        }
    } catch (err) {
        console.error('Failed to load partners:', err);
    }
}

async function loadAccountDropdown(currency) {
    const select = F.account();
    if (!select) return;

    try {
        const url = currency
            ? `/api/accounts?currency=${encodeURIComponent(currency)}`
            : '/api/accounts';
        const res = await apiFetch(url);
        const json = await res.json();
        const accounts = json.data?.accounts ?? json.data ?? [];

        select.innerHTML = '';

        if (accounts.length === 0 && currency) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.disabled = true;
            opt.selected = true;
            opt.textContent = `No accounts for ${currency}`;
            select.appendChild(opt);
        } else {
            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = '— Select Account —';
            select.appendChild(defaultOpt);

            for (const account of accounts) {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name + ' (' + account.currency + ')';
                option.dataset.currency = account.currency;
                select.appendChild(option);
            }
        }
    } catch (err) {
        console.error('Failed to load accounts:', err);
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    if (!F.partner().value) { markInvalid(F.partner(), 'Partner is required'); valid = false; }
    if (!F.type().value) { markInvalid(F.type(), 'Equity type is required'); valid = false; }
    if (!F.account().value) { markInvalid(F.account(), 'Account is required'); valid = false; }
    if (!F.currency().value) { markInvalid(F.currency(), 'Currency is required'); valid = false; }
    if (!F.exchangeRate().value || parseFloat(F.exchangeRate().value) <= 0) {
        markInvalid(F.exchangeRate(), 'Exchange rate is required'); valid = false;
    }
    if (!F.amountFc().value || parseFloat(F.amountFc().value) <= 0) {
        markInvalid(F.amountFc(), 'Amount must be greater than zero'); valid = false;
    }

    return valid;
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
