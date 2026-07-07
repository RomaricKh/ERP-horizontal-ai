// assets/js/pages/Settings/settings.js

import { registerPageActions } from '../Core/pageActions.js';
import { hasPermission } from '../../Auth/auth.js';
import { alert } from '../../components/modals.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let settings_container = null;
let unregister = null;

export async function render(container) {
    settings_container = container;
    const html = await fetch('html/Settings/settings.html').then(r => r.text());
    container.innerHTML = html;

    // Inject balance recalculation section for admin users
    if (hasPermission('settings.manage')) {
        injectBalanceRecalcSection();
    }
}

export async function mount() {
    await fetchSettings();
    await fetchSmtpSettings();

    // Load accounts for the recalculation selector if admin
    if (hasPermission('settings.manage')) {
        await loadAccountsForRecalc();
    }

    unregister = registerPageActions({

        'settings.save': async () => {
            const language       = settings_container.querySelector('#settingsLanguage')?.value       ?? '';
            const timezone       = settings_container.querySelector('#settingsTimezone')?.value       ?? '';
            const date_format    = settings_container.querySelector('#settingsDateFormat')?.value     ?? '';
            const number_format  = settings_container.querySelector('#settingsNumberFormat')?.value   ?? '';
            const currency_format = settings_container.querySelector('#settingsCurrencyFormat')?.value ?? '';
            const phone_format   = settings_container.querySelector('#settingsPhoneFormat')?.value    ?? '';
            const audit_enabled  = settings_container.querySelector('#settingsAuditEnabled')?.checked ?? true;

            try {
                const res  = await apiFetch('/api/settings', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        language,
                        timezone,
                        date_format,
                        number_format,
                        currency_format,
                        phone_format,
                        audit_enabled
                    })
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to save settings.' });
                } else {
                    window.dispatchEvent(new CustomEvent('settings:changed'));
                    await alert({ title: 'Success', alertText: 'Settings saved successfully.' });
                }
            } catch (err) {
                console.error('Save settings failed:', err);
                await alert({ title: 'Error', alertText: 'An unexpected error occurred while saving settings.' });
            }
        },

        'settings.recalculate-all': async () => {
            await runRecalculation(null);
        },

        'settings.recalculate-single': async () => {
            const accountId = settings_container.querySelector('#recalcAccountSelect')?.value;
            if (!accountId) {
                await alert({ title: 'Error', alertText: 'Please select an account to recalculate.' });
                return;
            }
            await runRecalculation(accountId);
        },

        'settings.save-smtp': async () => {
            const host         = settings_container.querySelector('#smtpHost')?.value ?? '';
            const port         = settings_container.querySelector('#smtpPort')?.value ?? '587';
            const username     = settings_container.querySelector('#smtpUsername')?.value ?? '';
            const password     = settings_container.querySelector('#smtpPassword')?.value ?? '';
            const from_address = settings_container.querySelector('#smtpFromAddress')?.value ?? '';
            const from_name    = settings_container.querySelector('#smtpFromName')?.value ?? '';
            const is_active    = settings_container.querySelector('#smtpIsActive')?.checked ? 1 : 0;

            if (!host || !username || !from_address || !from_name) {
                await alert({ title: 'Error', alertText: 'Please fill in all required SMTP fields.' });
                return;
            }

            try {
                const res = await apiFetch('/api/settings/smtp', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ host, port, username, password, from_address, from_name, is_active })
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    await alert({ title: 'Error', alertText: data.message || 'Failed to save SMTP settings.' });
                } else {
                    await alert({ title: 'Success', alertText: 'SMTP settings saved successfully.' });
                }
            } catch (err) {
                console.error('Save SMTP settings failed:', err);
                await alert({ title: 'Error', alertText: 'An unexpected error occurred while saving SMTP settings.' });
            }
        },
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    settings_container = null;
}

async function fetchSettings() {
    try {
        const res  = await apiFetch('/api/settings');
        const data = await res.json();
        if (!data.success) return;

        const s = data.data ?? {};

        const set = (id, value) => {
            const el = settings_container?.querySelector(`#${id}`);
            if (el && value != null) el.value = value;
        };

        set('settingsLanguage',       s.language);
        set('settingsTimezone',       s.timezone);
        set('settingsDateFormat',     s.date_format);
        set('settingsNumberFormat',   s.number_format);
        set('settingsCurrencyFormat', s.currency_format);
        set('settingsPhoneFormat',    s.phone_format);

        // Set audit_enabled toggle (defaults to true if not present)
        const auditToggle = settings_container?.querySelector('#settingsAuditEnabled');
        if (auditToggle) {
            auditToggle.checked = s.audit_enabled !== undefined ? Boolean(s.audit_enabled) : true;
        }
    } catch (err) {
        console.error('Failed to fetch settings:', err);
    }
}

async function fetchSmtpSettings() {
    try {
        const res = await apiFetch('/api/settings/smtp');
        const data = await res.json();
        if (!data.success || !data.data) return;

        const s = data.data;

        const set = (id, value) => {
            const el = settings_container?.querySelector(`#${id}`);
            if (el && value != null) el.value = value;
        };

        set('smtpHost', s.host);
        set('smtpPort', s.port);
        set('smtpUsername', s.username);
        set('smtpFromAddress', s.from_address);
        set('smtpFromName', s.from_name);
        // Don't set password — it's masked

        const activeToggle = settings_container?.querySelector('#smtpIsActive');
        if (activeToggle) {
            activeToggle.checked = Boolean(s.is_active);
        }
    } catch (err) {
        console.error('Failed to fetch SMTP settings:', err);
    }
}

function injectBalanceRecalcSection() {
    const section = document.createElement('div');
    section.className = 'card shadow mb-4';
    section.id = 'balanceRecalcSection';
    section.innerHTML = `
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Balance Recalculation</h6>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Recalculate account balances from transaction history. Use this to correct any data integrity issues.
            </p>
            <div class="row align-items-end mb-3">
                <div class="col-md-6">
                    <label for="recalcAccountSelect" class="form-label">Account (optional)</label>
                    <select id="recalcAccountSelect" class="form-select">
                        <option value="">— All accounts —</option>
                    </select>
                </div>
                <div class="col-md-6 mt-2 mt-md-0">
                    <button type="button" class="btn btn-warning me-2" data-action="settings.recalculate-single">
                        <i class="fas fa-calculator me-1"></i> Recalculate Selected
                    </button>
                    <button type="button" class="btn btn-danger" data-action="settings.recalculate-all">
                        <i class="fas fa-sync-alt me-1"></i> Recalculate All Balances
                    </button>
                </div>
            </div>
            <div id="recalcReport" style="display:none;">
                <h6 class="font-weight-bold">Recalculation Report</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" id="recalcReportTable">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Previous Balance</th>
                                <th>New Balance</th>
                                <th>Previous Balance (FC)</th>
                                <th>New Balance (FC)</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    // Append after the main settings card
    const mainContent = settings_container.querySelector('.card') || settings_container;
    if (mainContent.parentElement) {
        mainContent.parentElement.appendChild(section);
    } else {
        settings_container.appendChild(section);
    }
}

async function loadAccountsForRecalc() {
    try {
        const res  = await apiFetch('/api/accounts');
        const json = await res.json();
        const accounts = json.success ? (json.data?.accounts ?? []) : [];

        const select = settings_container.querySelector('#recalcAccountSelect');
        if (!select) return;

        accounts.forEach(acc => {
            const opt = document.createElement('option');
            opt.value = acc.id;
            opt.textContent = `${acc.name} (${acc.currency})`;
            select.appendChild(opt);
        });
    } catch (err) {
        console.error('loadAccountsForRecalc failed:', err);
    }
}

async function runRecalculation(accountId) {
    const reportDiv   = settings_container.querySelector('#recalcReport');
    const reportBody  = settings_container.querySelector('#recalcReportTable tbody');

    // Show loading state
    const buttons = settings_container.querySelectorAll('[data-action^="settings.recalculate"]');
    buttons.forEach(btn => { btn.disabled = true; });

    try {
        const url = accountId
            ? `/api/admin/recalculate-balances/${accountId}` // Not testing single account yet
            : '/api/admin/recalculate-balances-fix';

        const res  = await apiFetch(url, { method: 'POST' });
        const json = await res.json();

        if (!res.ok || !json.success) {
            await alert({ title: 'Error', alertText: json.message || 'Recalculation failed.' });
            return;
        }

        // Display the report
        const report = json.data?.report ?? [];
        if (reportBody) {
            reportBody.innerHTML = '';
            report.forEach(entry => {
                const tr = document.createElement('tr');
                const prevBal = getFormatter().formatNumber(entry.prev_balance ?? 0, 2);
                const newBal  = getFormatter().formatNumber(entry.new_balance ?? 0, 2);
                const prevFc  = getFormatter().formatNumber(entry.prev_balance_fc ?? 0, 2);
                const newFc   = getFormatter().formatNumber(entry.new_balance_fc ?? 0, 2);
                const changed = prevBal !== newBal || prevFc !== newFc;

                tr.className = changed ? 'table-warning' : '';
                tr.innerHTML = `
                    <td>${escHtml(entry.account_name || `Account #${entry.account_id}`)}</td>
                    <td>${prevBal}</td>
                    <td>${newBal}</td>
                    <td>${prevFc}</td>
                    <td>${newFc}</td>
                `;
                reportBody.appendChild(tr);
            });
        }

        if (reportDiv) {
            reportDiv.style.display = 'block';
        }

        await alert({ title: 'Success', alertText: `Recalculation complete. ${report.length} account(s) processed.` });
    } catch (err) {
        console.error('Recalculation failed:', err);
        await alert({ title: 'Error', alertText: 'An unexpected error occurred during recalculation.' });
    } finally {
        buttons.forEach(btn => { btn.disabled = false; });
    }
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
