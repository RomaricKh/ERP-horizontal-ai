// assets/js/pages/BusinessProfit/profitForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const id = data?.profit_id || null;
    const titleEl = modalRoot.querySelector('#profitFormModalTitle span');

    if (id) {
        if (titleEl) titleEl.textContent = 'Edit Profit Record';
        document.getElementById('inputProfitId').value = id;

        // Load existing record
        try {
            const res = await apiFetch(`/api/business-profit/${id}`);
            const json = await res.json();
            if (json.success) {
                const record = json.data?.profit || json.data;
                document.getElementById('inputPeriodStart').value = record.period_start || '';
                document.getElementById('inputPeriodEnd').value = record.period_end || '';
                document.getElementById('inputSafetyNetPercent').value = record.safety_net_percent || '';
            }
        } catch (e) { console.error(e); }
    } else {
        if (titleEl) titleEl.textContent = 'New Profit Record';
    }

    unregister = registerPageActions({
        'profit.save': async () => {
            const form = modalRoot.querySelector('#profitForm');
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return;
            }

            const recordId = document.getElementById('inputProfitId').value;
            const payload = {
                period_start: document.getElementById('inputPeriodStart').value,
                period_end: document.getElementById('inputPeriodEnd').value,
                safety_net_percent: parseFloat(document.getElementById('inputSafetyNetPercent').value) || 0
            };

            try {
                let res;
                if (recordId) {
                    res = await apiFetch(`/api/business-profit/${recordId}`, {
                        method: 'PUT',
                        body: JSON.stringify(payload)
                    });
                } else {
                    res = await apiFetch('/api/business-profit', {
                        method: 'POST',
                        body: JSON.stringify(payload)
                    });
                }

                const json = await res.json();
                if (!res.ok || !json.success) {
                    showError(json.message || 'Failed to save');
                    return;
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('profit.updated'));
            } catch (err) {
                console.error(err);
                showError(err.message || 'Unable to save');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
}

function showError(msg) {
    const footer = modalRoot.querySelector('.modal-footer');
    let el = footer?.querySelector('.form-error');
    if (!el) {
        el = document.createElement('div');
        el.className = 'form-error text-danger me-auto';
        footer?.prepend(el);
    }
    el.textContent = msg;
}
