// assets/js/pages/Salaries/payrollForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { createMultiSelect } from '../../components/multiSelect.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;
let unregister;
let employeeSelect = null;
let selectedPayeeIds = [];

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    // Set default period to current month
    const now = new Date();
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);

    const startInput = document.getElementById('inputPayrollPeriodStart');
    const endInput = document.getElementById('inputPayrollPeriodEnd');

    if (startInput) startInput.value = formatDate(firstDay);
    if (endInput) endInput.value = formatDate(lastDay);

    // Load employees for the multi-select
    await loadEmployees();

    unregister = registerPageActions({
        'payroll.generate': async () => {
            if (!validateForm()) return;

            const payload = {
                period_start: startInput.value,
                period_end: endInput.value,
            };

            if (selectedPayeeIds.length > 0) {
                payload.payee_ids = selectedPayeeIds.map(id => parseInt(id, 10));
            }

            const btn = document.getElementById('payrollGenerateBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';

            try {
                const res = await apiFetch('/api/salaries/generate-payroll', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    showFormError(json.message || 'Failed to generate payroll');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-play me-1"></i>Generate Payroll';
                    return;
                }

                // Show results
                showResults(json.data);
                btn.innerHTML = '<i class="fas fa-check me-1"></i>Done';

                // Notify parent to refresh
                document.dispatchEvent(new CustomEvent('salaries.updated'));
            } catch (err) {
                console.error('Payroll generation failed:', err);
                showFormError('An error occurred while generating payroll');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-play me-1"></i>Generate Payroll';
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    employeeSelect = null;
    selectedPayeeIds = [];
}

async function loadEmployees() {
    try {
        const res = await apiFetch('/api/people');
        const json = await res.json();
        const allPeople = json.success ? (json.data?.people ?? []) : [];

        // Filter to only people with 'Employee' in their payee_types
        const employees = allPeople.filter(p => {
            const types = (p.payee_types || '').toLowerCase();
            return types.includes('employee');
        });

        const container = document.getElementById('payrollEmployeeSelectContainer');
        if (!container) return;

        if (employees.length === 0) {
            container.innerHTML = '<span class="text-muted small"><i class="fas fa-info-circle me-1"></i>No employees found. Add people with the "Employee" type first.</span>';
            return;
        }

        const options = employees.map(p => ({
            value: String(p.payee_id || p.id),
            label: p.name || `Employee #${p.id}`
        }));

        employeeSelect = createMultiSelect({
            container,
            options,
            placeholder: 'All employees (leave empty for all)',
            onChange: (selected) => {
                selectedPayeeIds = selected;
            }
        });
    } catch (err) {
        console.warn('Failed to load employees for payroll:', err);
    }
}

function showResults(data) {
    const preview = document.getElementById('payrollPreview');
    const results = document.getElementById('payrollResults');
    if (!preview || !results) return;

    preview.classList.remove('d-none');

    const created = data.created || 0;
    const skipped = data.skipped || 0;
    const details = data.details || [];

    let html = `
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="text-center p-3 border rounded bg-white">
                    <h4 class="text-success mb-0">${created}</h4>
                    <small class="text-muted">Created</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-3 border rounded bg-white">
                    <h4 class="text-warning mb-0">${skipped}</h4>
                    <small class="text-muted">Skipped</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-3 border rounded bg-white">
                    <h4 class="text-primary mb-0">${created + skipped}</h4>
                    <small class="text-muted">Total Processed</small>
                </div>
            </div>
        </div>
    `;

    if (details.length > 0) {
        html += '<div class="table-responsive"><table class="table table-sm table-bordered">';
        html += '<thead><tr><th>Employee</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        details.forEach(d => {
            const badge = d.status === 'created'
                ? '<span class="badge bg-success">Created</span>'
                : '<span class="badge bg-warning text-dark">Skipped</span>';
            const info = d.status === 'created'
                ? `${getFormatter().formatNumber(d.gross_amount_fc, 2)} ${escHtml(d.currency || '')}`
                : escHtml(d.reason || '');
            html += `<tr><td>${escHtml(d.employee_name)}</td><td>${badge}</td><td>${info}</td></tr>`;
        });
        html += '</tbody></table></div>';
    }

    results.innerHTML = html;
}

function validateForm() {
    clearValidation();
    let valid = true;

    const startInput = document.getElementById('inputPayrollPeriodStart');
    const endInput = document.getElementById('inputPayrollPeriodEnd');

    if (!startInput.value) {
        markInvalid(startInput, 'Period start is required');
        valid = false;
    }
    if (!endInput.value) {
        markInvalid(endInput, 'Period end is required');
        valid = false;
    }
    if (startInput.value && endInput.value && endInput.value < startInput.value) {
        markInvalid(endInput, 'Period end must be after start');
        valid = false;
    }

    return valid;
}

function formatDate(d) {
    return d.toISOString().split('T')[0];
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
