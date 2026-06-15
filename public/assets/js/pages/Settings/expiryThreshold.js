// assets/js/pages/Settings/expiryThreshold.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { alert } from '../../components/modals.js';

let container;
let unregister;

export async function render(cont) {
    container = cont;
    const html = await fetch('html/Settings/expiryThreshold.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    await fetchCurrentThreshold();

    unregister = registerPageActions({
        'settings.save-expiry-threshold': async () => {
            const input = document.getElementById('inputExpiryThresholdDays');
            const value = parseInt(input?.value, 10);

            if (!value || value < 1) {
                await alert({ title: 'Validation', alertText: 'Threshold must be at least 1 day.' });
                return;
            }

            try {
                const res = await apiFetch('/api/settings', {
                    method: 'PUT',
                    body: JSON.stringify({ expiry_threshold_days: value })
                });
                const body = await res.json();

                if (!res.ok || !body.success) {
                    await alert({ title: 'Error', alertText: body.message || 'Failed to save threshold.' });
                } else {
                    await alert({ title: 'Success', alertText: 'Expiry threshold saved successfully.' });
                }
            } catch (err) {
                console.error('Save expiry threshold error:', err);
                await alert({ title: 'Error', alertText: 'An error occurred while saving.' });
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
}

async function fetchCurrentThreshold() {
    try {
        const res = await apiFetch('/api/settings');
        const body = await res.json();

        if (res.ok && body.success) {
            const settings = body.data?.settings ?? body.data ?? {};
            const input = document.getElementById('inputExpiryThresholdDays');
            if (input && settings.expiry_threshold_days) {
                input.value = settings.expiry_threshold_days;
            }
        }
    } catch (err) {
        console.error('Fetch settings failed:', err);
    }
}
