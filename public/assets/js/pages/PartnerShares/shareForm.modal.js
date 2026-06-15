// assets/js/pages/PartnerShares/shareForm.modal.js
import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { populatePayeeSelect } from '../../utils/payeeSelect.js';
import { calcBc, formatAmount } from '../../utils/currency.js';
import { attachRateAutoFetch } from '../../utils/exchangeRateHelper.js';

let modalRoot;
let unregister;
let cleanupRateAutoFetch;
let currentShareId = null;

const F = {
    partner:      () => document.getElementById('inputSharePartner'),
    percent:      () => document.getElementById('inputSharePercent'),
    account:      () => document.getElementById('inputShareAccount'),
    currency:     () => document.getElementById('inputShareCurrency'),
    exchangeRate: () => document.getElementById('inputShareExchangeRate'),
    capital:      () => document.getElementById('inputInvestedCapital'),
    amountBc:     () => document.getElementById('inputShareAmountBc'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    currentShareId = data?.share_id ?? null;

    const titleEl = document.getElementById('shareModalTitle');
    if (titleEl) {
        titleEl.textContent = currentShareId ? 'Edit Partner Share' : 'Add Partner Share';
    }

    // Load partner dropdown (filtered to Partner payee type)
    await populatePayeeSelect(F.partner(), { typeFilter: 'Partner' });

    // Load accounts
    await loadAccounts();

    // If editing, populate form and hide investment section (use equity form for changes)
    if (currentShareId) {
        document.getElementById('inputShareId').value = currentShareId;
        F.partner().disabled = true;

        // Hide investment section on edit (invested capital changes go through Partner Equity)
        const investSection = document.getElementById('investmentSection');
        if (investSection) {
            investSection.style.display = 'none';
        }

        try {
            const res = await apiFetch('/api/partner-shares');
            const json = await res.json();
            if (json.success) {
                const shares = json.data.shares ?? json.data?.partner_shares ?? json.data ?? [];
                const share = shares.find(s => s.id == currentShareId);
                if (share) {
                    F.partner().value = share.partner_id;
                    F.percent().value = share.share_percent;
                }
            }
        } catch (err) {
            console.error('Fetch share for edit failed:', err);
        }
    } else {
        F.partner().disabled = false;

        // Auto-calculate BC when amount or rate changes
        F.capital().addEventListener('input', recalcBc);
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

        // Sync: when currency changes, filter accounts
        F.currency().addEventListener('change', async () => {
            const selectedCurrency = F.currency().value;
            const currentAccount = F.account().value;
            await loadAccounts(selectedCurrency || null);
            if (currentAccount) {
                const stillExists = F.account().querySelector(`option[value="${currentAccount}"]`);
                if (stillExists) F.account().value = currentAccount;
                else F.account().value = '';
            }
        });
    }

    unregister = registerPageActions({
        'partnerShares.save': async () => {
            if (!validateForm()) return;

            const id = document.getElementById('inputShareId').value;

            if (id) {
                // Edit mode: only update share_percent
                const body = {
                    share_percent: parseFloat(F.percent().value),
                };

                try {
                    const res = await apiFetch(`/api/partner-shares/${id}`, {
                        method: 'PUT',
                        body: JSON.stringify(body)
                    });
                    const json = await res.json();
                    if (!res.ok || !json.success) {
                        throw new Error(json.message || 'Failed to update partner share');
                    }
                    bootstrap.Modal.getInstance(modalRoot).hide();
                    document.dispatchEvent(new CustomEvent('partnerShares.updated'));
                } catch (err) {
                    console.error(err);
                    showFormError(err.message || 'Unable to update partner share');
                }
            } else {
                // Create mode: create share + initial contribution via equity
                const body = {
                    partner_id:      parseInt(F.partner().value, 10),
                    share_percent:   parseFloat(F.percent().value),
                    invested_capital: parseFloat(F.capital().value) || 0,
                    account_id:      parseInt(F.account().value, 10),
                    currency:        F.currency().value,
                    exchange_rate:   parseFloat(F.exchangeRate().value),
                };

                try {
                    const res = await apiFetch('/api/partner-shares', {
                        method: 'POST',
                        body: JSON.stringify(body)
                    });
                    const json = await res.json();
                    if (!res.ok || !json.success) {
                        throw new Error(json.message || 'Failed to create partner share');
                    }
                    bootstrap.Modal.getInstance(modalRoot).hide();
                    document.dispatchEvent(new CustomEvent('partnerShares.updated'));
                } catch (err) {
                    console.error(err);
                    showFormError(err.message || 'Unable to create partner share');
                }
            }
        }
    });
}

export function unmount() {
    cleanupRateAutoFetch?.();
    cleanupRateAutoFetch = null;
    unregister?.();
    unregister = null;
    currentShareId = null;
}

function recalcBc() {
    const fc   = parseFloat(F.capital().value)      || 0;
    const rate = parseFloat(F.exchangeRate().value) || 0;
    F.amountBc().value = formatAmount(calcBc(fc, rate));
}

async function loadAccounts(currency) {
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
            defaultOpt.textContent = '— Select account —';
            select.appendChild(defaultOpt);

            accounts.forEach(acc => {
                const opt = document.createElement('option');
                opt.value = acc.id;
                opt.textContent = acc.name + ' (' + acc.currency + ')';
                opt.dataset.currency = acc.currency;
                select.appendChild(opt);
            });
        }
    } catch (err) {
        console.error('loadAccounts failed:', err);
    }
}

function validateForm() {
    clearValidation();
    let valid = true;

    const id = document.getElementById('inputShareId').value;

    if (!id && !F.partner().value) { markInvalid(F.partner(), 'Please select a partner'); valid = false; }
    if (!F.percent().value || parseFloat(F.percent().value) <= 0) { markInvalid(F.percent(), 'Share percentage must be greater than 0'); valid = false; }
    if (parseFloat(F.percent().value) > 100) { markInvalid(F.percent(), 'Share percentage cannot exceed 100%'); valid = false; }

    // Validate investment fields only on create
    if (!id) {
        if (!F.account().value) { markInvalid(F.account(), 'Account is required'); valid = false; }
        if (!F.currency().value) { markInvalid(F.currency(), 'Currency is required'); valid = false; }
        if (!F.exchangeRate().value || parseFloat(F.exchangeRate().value) <= 0) {
            markInvalid(F.exchangeRate(), 'Exchange rate is required'); valid = false;
        }
        if (!F.capital().value || parseFloat(F.capital().value) < 0) {
            markInvalid(F.capital(), 'Invested capital must be 0 or greater'); valid = false;
        }
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
