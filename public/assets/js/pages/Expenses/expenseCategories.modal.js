// assets/js/pages/Expenses/expenseCategories.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { confirm } from '../../components/modals.js';

let modalRoot;
let unregister;
let categories = [];
let inputListener = null;

export async function mount({ modalRoot: root }) {
    modalRoot = root;
    await loadCategories();

    // Clear validation state when user starts typing
    const nameInput = document.getElementById('newExpenseCategoryName');
    if (nameInput) {
        inputListener = () => {
            nameInput.classList.remove('is-invalid');
            const fb = nameInput.parentElement?.querySelector('.invalid-feedback');
            if (fb) fb.remove();
        };
        nameInput.addEventListener('input', inputListener);
    }

    unregister = registerPageActions({

        'expense-categories.add': async () => {
            if (!validateForm()) return;

            const nameInput = document.getElementById('newExpenseCategoryName');
            const name = nameInput?.value.trim();

            try {
                const res  = await apiFetch('/api/expenses/categories', {
                    method: 'POST',
                    body: JSON.stringify({ name })
                });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Failed');
                nameInput.value = '';
                clearValidation();
                await loadCategories();
                document.dispatchEvent(new CustomEvent('expenses.updated'));
            } catch (err) {
                console.error(err);
            }
        },

        'expense-categories.edit': async ({ el }) => {
            const id  = parseInt(el.dataset.id, 10);
            const cat = categories.find(c => c.id === id);
            if (!cat) return;

            const row   = el.closest('.expense-category-row');
            const label = row?.querySelector('.category-label');
            if (!label) return;

            // Inline edit: replace label with input
            const input = document.createElement('input');
            input.type      = 'text';
            input.className = 'form-control form-control-sm d-inline-block w-auto';
            input.value     = cat.name;
            label.replaceWith(input);
            input.focus();

            const save = async () => {
                const newName = input.value.trim();
                if (!newName) {
                    input.classList.add('is-invalid');
                    return;
                }
                if (newName.length > 100) {
                    input.classList.add('is-invalid');
                    return;
                }
                if (newName === cat.name) {
                    await loadCategories();
                    return;
                }
                try {
                    const res  = await apiFetch(`/api/expenses/categories/${id}`, {
                        method: 'PUT',
                        body: JSON.stringify({ name: newName, is_fixed: cat.is_fixed ? 1 : 0 })
                    });
                    const json = await res.json();
                    if (!res.ok || !json.success) throw new Error(json.message || 'Failed');
                    await loadCategories();
                    document.dispatchEvent(new CustomEvent('expenses.updated'));
                } catch (err) {
                    console.error(err);
                    await loadCategories();
                }
            };

            input.addEventListener('blur',    save);
            input.addEventListener('keydown', e => {
                if (e.key === 'Enter')  { e.preventDefault(); save(); }
                if (e.key === 'Escape') { loadCategories(); }
            });
        },

        'expense-categories.delete': async ({ el }) => {
            const id  = parseInt(el.dataset.id, 10);
            const cat = categories.find(c => c.id === id);
            if (!cat) return;

            const confirmed = await confirm({
                title:   'Delete expense category',
                message: `Delete the category "${cat.name}"? This cannot be undone.`
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/expenses/categories/${id}`, { method: 'DELETE' });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Failed');
                await loadCategories();
                document.dispatchEvent(new CustomEvent('expenses.updated'));
            } catch (err) {
                console.error(err);
            }
        }
    });
}

export function unmount() {
    // Remove input listener from name field
    const nameInput = document.getElementById('newExpenseCategoryName');
    if (nameInput && inputListener) {
        nameInput.removeEventListener('input', inputListener);
    }
    inputListener = null;

    unregister?.();
    unregister = null;
    categories = [];
    modalRoot = null;
}

function validateForm() {
    clearValidation();
    let valid = true;

    const nameInput = document.getElementById('newExpenseCategoryName');
    const name = nameInput?.value.trim();

    if (!name) {
        markInvalid(nameInput, 'Category name is required');
        valid = false;
    } else if (name.length > 100) {
        markInvalid(nameInput, 'Category name must not exceed 100 characters');
        valid = false;
    }

    return valid;
}

function clearValidation() {
    modalRoot?.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot?.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
}

function markInvalid(input, message) {
    if (!input) return;
    input.classList.add('is-invalid');
    let fb = input.parentElement?.querySelector('.invalid-feedback');
    if (!fb) {
        fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        input.parentElement?.appendChild(fb);
    }
    fb.textContent = message;
}

async function loadCategories() {
    try {
        const res  = await apiFetch('/api/expenses/categories');
        const data = await res.json();
        categories = data.success ? (data.data?.categories ?? []) : [];
        renderList();
    } catch (err) {
        console.error('loadCategories failed:', err);
    }
}

function renderList() {
    const container = document.getElementById('expenseCategoriesList');
    if (!container) return;

    if (categories.length === 0) {
        container.innerHTML = '<p class="text-muted text-center py-3">No expense categories defined yet.</p>';
        return;
    }

    container.innerHTML = categories.map(cat => {
        const isSystem = parseInt(cat.is_system) === 1;
        const badge = isSystem ? ' <span class="badge bg-secondary ms-1">System</span>' : '';
        const actions = isSystem
            ? '<span class="text-muted small"><i class="fas fa-lock me-1"></i>Protected</span>'
            : `<div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" data-action="expense-categories.edit" data-id="${cat.id}" title="Edit">
                    <i class="fas fa-pencil-alt"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" data-action="expense-categories.delete" data-id="${cat.id}" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
               </div>`;

        return `
            <div class="expense-category-row d-flex align-items-center justify-content-between border-bottom py-2">
                <span class="category-label">${escHtml(cat.name)}${badge}</span>
                ${actions}
            </div>
        `;
    }).join('');
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
