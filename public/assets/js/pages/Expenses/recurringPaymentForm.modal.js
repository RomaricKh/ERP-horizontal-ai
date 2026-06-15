// assets/js/pages/Expenses/recurringPaymentForm.modal.js
// Modal controller for recurring instance payment actions (Mark Paid, Pay Early)

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let instanceId = null;
let action = null; // 'mark_paid' or 'pay_early'
let instanceData = null;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    instanceId = data?.instance_id ?? null;
    action = data?.action ?? 'mark_paid';

    if (!instanceId) {
        showFormError('No instance specified.');
        return;
    }

    // Set modal title based on action
    const titleEl = modalRoot.querySelector('#recurringPaymentModalTitle span');
    const confirmBtn = modalRoot.querySelector('#confirmBtnText');
    if (action === 'pay_early') {
        if (titleEl) titleEl.textContent = 'Pay Early';
        if (confirmBtn) confirmBtn.textContent = 'Confirm Early Payment';
    } else {
        if (titleEl) titleEl.textContent = 'Mark as Paid';
        if (confirmBtn) confirmBtn.textContent = 'Confirm Payment';
    }

    // Load instance details
    await loadInstanceDetails();

    // Load accounts filtered by instance currency
    await loadAccounts(instanceData?.currency ?? null);

    unregister = registerPageActions({
        'recurring-payment.confirm': async () => {
            if (!validateForm()) return;

            const amount = parseFloat(modalRoot.querySelector('#inputRecPaymentAmount').value);
            const accountId = modalRoot.querySelector('#inputRecPaymentAccount').value;
            const paymentMethod = modalRoot.querySelector('#inputRecPaymentMethod').value;

            const payload = {
                status: 'paid',
                account_id: parseInt(accountId, 10),
                amount: amount,
                method: paymentMethod
            };

            try {
                const res = await apiFetch(`/api/recurring-instances/${instanceId}/status`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (!res.ok || !json.success) {
                    throw new Error(json.message || 'Failed to update instance status');
                }
                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('recurring-expenses.updated'));
            } catch (err) {
                console.error('Payment confirmation failed:', err);
                showFormError(err.message || 'Unable to process payment');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    instanceId = null;
    action = null;
    instanceData = null;
}

async function loadInstanceDetails() {
    try {
        const res = await apiFetch('/api/recurring-instances?entity_type=EXPENSE');
        const json = await res.json();
        if (!json.success) return;

        const instances = json.data?.instances ?? json.data ?? [];
        instanceData = instances.find(i => String(i.id) === String(instanceId));

        if (!instanceData) {
            showFormError('Instance not found.');
            return;
        }

        const fmt = getFormatter();

        // Populate read-only info
        const categoryEl = modalRoot.querySelector('#instanceCategoryName');
        const dueDateEl = modalRoot.querySelector('#instanceDueDate');
        const amountEl = modalRoot.querySelector('#instanceScheduledAmount');
        const currencyEl = modalRoot.querySelector('#instanceCurrency');

        if (categoryEl) categoryEl.textContent = instanceData.entity_name ?? '—';
        if (dueDateEl) dueDateEl.textContent = fmt.formatDate(instanceData.due_date ?? '');
        if (amountEl) amountEl.textContent = fmt.formatNumber(instanceData.scheduled_amount, 2);
        if (currencyEl) currencyEl.textContent = instanceData.currency ?? '—';

        // Pre-fill editable amount with scheduled amount
        const amountInput = modalRoot.querySelector('#inputRecPaymentAmount');
        if (amountInput) amountInput.value = instanceData.scheduled_amount ?? '';

        // Set currency display
        const currencyInput = modalRoot.querySelector('#inputRecPaymentCurrency');
        if (currencyInput) currencyInput.value = instanceData.currency ?? '';
    } catch (err) {
        console.error('loadInstanceDetails failed:', err);
        showFormError('Failed to load instance details.');
    }
}

async function loadAccounts(currency) {
    try {
        const url = currency
            ? `/api/accounts?currency=${encodeURIComponent(currency)}`
            : '/api/accounts';
        const res = await apiFetch(url);
        const json = await res.json();
        const accounts = json.success ? (json.data?.accounts ?? []) : [];

        const select = modalRoot.querySelector('#inputRecPaymentAccount');
        if (!select) return;

        select.innerHTML = '';

        if (accounts.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.disabled = true;
            opt.selected = true;
            opt.textContent = currency
                ? `No accounts for ${currency}`
                : 'No accounts available';
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

    const amountInput = modalRoot.querySelector('#inputRecPaymentAmount');
    const accountSelect = modalRoot.querySelector('#inputRecPaymentAccount');
    const methodSelect = modalRoot.querySelector('#inputRecPaymentMethod');

    if (!amountInput?.value || parseFloat(amountInput.value) <= 0) {
        markInvalid(amountInput, 'A valid amount is required');
        valid = false;
    }
    if (!accountSelect?.value) {
        markInvalid(accountSelect, 'Source account is required');
        valid = false;
    }
    if (!methodSelect?.value) {
        markInvalid(methodSelect, 'Payment method is required');
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
