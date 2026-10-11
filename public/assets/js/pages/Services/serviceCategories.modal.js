// assets/js/pages/Services/serviceCategories.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { confirm, alert } from '../../components/modals.js';

let modalRoot;
let unregister;
let categories = [];

export async function mount({ modalRoot: root }) {
    modalRoot = root;
    await loadCategories();

    unregister = registerPageActions({

        'service-categories.add': async () => {
            const input = document.getElementById('newServiceCategoryName');
            const name  = input?.value.trim();
            if (!name) { input?.classList.add('is-invalid'); return; }
            input.classList.remove('is-invalid');

            try {
                const res  = await apiFetch('/api/services/categories', {
                    method: 'POST', body: JSON.stringify({ name })
                });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Failed');
                input.value = '';
                await loadCategories();
                document.dispatchEvent(new CustomEvent('services.updated'));
            } catch (err) {
                console.error(err);
                await alert({ title: 'Error', alertText: err.message || 'Failed to add category' });
            }
        },

        'service-categories.edit': async ({ el }) => {
            const id   = parseInt(el.dataset.id, 10);
            const cat  = categories.find(c => c.id === id);
            if (!cat) return;

            const row   = el.closest('.category-row');
            const label = row?.querySelector('.category-label');
            if (!label) return;

            const input = document.createElement('input');
            input.type      = 'text';
            input.className = 'form-control form-control-sm d-inline-block w-auto';
            input.value     = cat.name;
            label.replaceWith(input);
            input.focus();

            const save = async () => {
                const newName = input.value.trim();
                if (!newName || newName === cat.name) { await loadCategories(); return; }
                try {
                    const res  = await apiFetch(`/api/services/categories/${id}`, {
                        method: 'PUT', body: JSON.stringify({ name: newName })
                    });
                    const json = await res.json();
                    if (!res.ok || !json.success) throw new Error(json.message || 'Failed');
                    await loadCategories();
                    document.dispatchEvent(new CustomEvent('services.updated'));
                } catch (err) {
                    console.error(err);
                    await alert({ title: 'Error', alertText: err.message || 'Failed to update category' });
                    await loadCategories();
                }
            };

            input.addEventListener('blur', save);
            input.addEventListener('keydown', e => {
                if (e.key === 'Enter')  { e.preventDefault(); save(); }
                if (e.key === 'Escape') { loadCategories(); }
            });
        },

        'service-categories.delete': async ({ el }) => {
            const id  = parseInt(el.dataset.id, 10);
            const cat = categories.find(c => c.id === id);
            if (!cat) return;

            const confirmed = await confirm({
                title:   'Delete category',
                message: `Delete "${cat.name}"? This cannot be undone.`
            });
            if (!confirmed) return;

            try {
                el.disabled = true;
                const res  = await apiFetch(`/api/services/categories/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message);
                await loadCategories();
                document.dispatchEvent(new CustomEvent('module.updated'));
            } catch (err) {
                await alert({ title: 'Error', alertText: err.message || 'Failed to delete category' });
            } finally {
                el.disabled = false;
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    categories = [];
}

async function loadCategories() {
    try {
        const res  = await apiFetch('/api/services/categories');
        const data = await res.json();
        categories = data.success ? (data.data.categories || data.data.service_categories || []) : [];
        renderList();
    } catch (err) { console.error('loadCategories failed:', err); }
}

function renderList() {
    const container = document.getElementById('serviceCategoriesList');
    if (!container) return;

    if (categories.length === 0) {
        container.innerHTML = '<p class="text-muted text-center py-3">No service categories defined yet.</p>';
        return;
    }

    container.innerHTML = categories.map(cat => {
        const isSystem = parseInt(cat.is_system) === 1;
        const badge = isSystem ? ' <span class="badge bg-secondary ms-1">System</span>' : '';
        const actions = isSystem
            ? '<span class="text-muted small"><i class="fas fa-lock me-1"></i>Protected</span>'
            : `<div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" data-action="service-categories.edit" data-id="${cat.id}" title="Edit">
                    <i class="fas fa-pencil-alt"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" data-action="service-categories.delete" data-id="${cat.id}" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
               </div>`;

        return `
            <div class="category-row d-flex align-items-center justify-content-between border-bottom py-2">
                <span class="category-label">${escHtml(cat.name)}${badge}</span>
                ${actions}
            </div>
        `;
    }).join('');
}

function escHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
