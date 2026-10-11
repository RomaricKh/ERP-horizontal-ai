// assets/js/pages/Tenant/reports.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { alert } from '../../components/modals.js';
import { getFormatter } from '../../i18n/translationClient.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

let container;
let unregister;

export async function render(cont) {
    container = cont;
    const html = await fetch('html/Tenant/reports.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    unregister = registerPageActions({
        'tenant-reports.generate': async () => {
            const reportType = document.getElementById('inputReportType')?.value;
            const startDate  = document.getElementById('inputReportFrom')?.value;
            const endDate    = document.getElementById('inputReportTo')?.value;

            if (!reportType) {
                await alert({ title: 'Validation', alertText: 'Please select a report type.' });
                return;
            }
            if (!startDate) {
                await alert({ title: 'Validation', alertText: 'Please select a start date.' });
                return;
            }
            if (!endDate) {
                await alert({ title: 'Validation', alertText: 'Please select an end date.' });
                return;
            }

            const btn = document.querySelector('[data-action="tenant-reports.generate"]');
            const originalContent = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...';
            }

            try {
                const res = await apiFetch('/api/tenant/reports', {
                    method: 'POST',
                    body: JSON.stringify({
                        report_type: reportType,
                        start_date: startDate,
                        end_date: endDate,
                        format: 'pdf'
                    })
                });
                const body = await res.json();

                if (!res.ok || !body.success) {
                    await alert({ title: 'Error', alertText: body.message || 'Failed to generate report.' });
                    return;
                }

                renderReport(body.data);
            } catch (err) {
                console.error('Generate report error:', err);
                await alert({ title: 'Error', alertText: 'An error occurred while generating the report.' });
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                }
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
}

function renderReport(data) {
    const resultsDiv = document.getElementById('reportResults');
    if (!resultsDiv) return;

    if (!data || (Array.isArray(data) && data.length === 0)) {
        resultsDiv.innerHTML = '<div class="text-muted text-center py-4">No data found for the selected criteria.</div>';
        return;
    }

    // Generic table rendering for report data
    const report = data.report ?? data.rows ?? data;

    if (Array.isArray(report) && report.length > 0) {
        const keys = Object.keys(report[0]);
        let html = '<div class="table-responsive"><table class="table table-bordered table-sm">';
        html += '<thead class="table-light"><tr>';
        keys.forEach(k => { html += `<th>${escHtml(k)}</th>`; });
        html += '</tr></thead><tbody>';
        report.forEach(row => {
            html += '<tr>';
            keys.forEach(k => {
                const val = row[k] ?? '';
                html += `<td>${escHtml(String(val))}</td>`;
            });
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        resultsDiv.innerHTML = html;
    } else if (typeof report === 'object') {
        let html = '<div class="border rounded p-3 bg-light">';
        Object.entries(report).forEach(([key, value]) => {
            html += `<div class="d-flex justify-content-between mb-1">
                <span class="text-muted">${escHtml(key)}</span>
                <span class="fw-bold">${escHtml(String(value ?? ''))}</span>
            </div>`;
        });
        html += '</div>';
        resultsDiv.innerHTML = html;
    } else {
        resultsDiv.innerHTML = '<div class="text-muted text-center py-4">Report generated successfully.</div>';
    }
}
