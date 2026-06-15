// assets/js/pages/People/payeeTypes.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { confirm } from '../../components/modals.js';

let modalRoot;
let unregister;
let types = [];

export async function mount({ modalRoot: root }) {
    modalRoot = root;
    await loadTypes();

    unregister = registerPageActions({

        'payee-types.add': async () => {
            const input = document.getElementById('newPayeeTypeName');
            const name  = input?.value.trim();
            if (!name) {
                input?.classList.add('is-invalid');
                return;
            }
            input.classList.remove('is-invalid');

            try {
                const res  = await apiFetch('/api/people/payee-types', {
                    method: 'POST',
                    body: JSON.stringify({ name })
                });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Failed');
                input.value = '';
                await loadTypes();
                document.dispatchEvent(new CustomEvent('people.updated'));
            } catch (err) {
                console.error(err);
            }
        },

        'payee-types.edit': async ({ el }) => {
            const id   = parseInt(el.dataset.id, 10);
            const type = types.find(t => t.id === id);
            if (!type) return;

            const row   = el.closest('.payee-type-row');
            const label = row?.querySelector('.type-label');
            if (!label) return;

            // Inline edit: replace label with input
            const input = document.createElement('input');
            input.type      = 'text';
            input.className = 'form-control form-control-sm d-inline-block w-auto';
            input.value     = type.name;
            label.replaceWith(input);
            input.focus();

            const save = async () => {
                const newName = input.value.trim();
                if (!newName || newName === type.name) {
                    await loadTypes();
                    return;
                }
                try {
                    const res  = await apiFetch(`/api/people/payee-types/${id}`, {
                        method: 'PUT',
                        body: JSON.stringify({ name: newName })
                    });
                    const json = await res.json();
                    if (!res.ok || !json.success) throw new Error(json.message || 'Failed');
                    await loadTypes();
                    document.dispatchEvent(new CustomEvent('people.updated'));
                } catch (err) {
                    console.error(err);
                    await loadTypes();
                }
            };

            input.addEventListener('blur',  save);
            input.addEventListener('keydown', e => {
                if (e.key === 'Enter')  { e.preventDefault(); save(); }
                if (e.key === 'Escape') { loadTypes(); }
            });
        },

        'payee-types.delete': async ({ el }) => {
            const id   = parseInt(el.dataset.id, 10);
            const type = types.find(t => t.id === id);
            if (!type) return;

            const confirmed = await confirm({
                title:   'Delete payee type',
                message: `Delete the type "${type.name}"? This cannot be undone.`
            });
            if (!confirmed) return;

            try {
                const res  = await apiFetch(`/api/people/payee-types/${id}`, { method: 'DELETE' });
                const json = await res.json();
                if (!res.ok || !json.success) throw new Error(json.message || 'Failed');
                await loadTypes();
                document.dispatchEvent(new CustomEvent('people.updated'));
            } catch (err) {
                console.error(err);
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    types = [];
}

async function loadTypes() {
    try {
        const res  = await apiFetch('/api/people/payee-types');
        const data = await res.json();
        types = data.success ? data.data.payee_types : [];
        renderList();
    } catch (err) {
        console.error('loadTypes failed:', err);
    }
}

function renderList() {
    const container = document.getElementById('payeeTypesList');
    if (!container) return;

    if (types.length === 0) {
        container.innerHTML = '<p class="text-muted text-center py-3">No payee types defined yet.</p>';
        return;
    }

    container.innerHTML = types.map(t => `
        <div class="payee-type-row d-flex align-items-center justify-content-between border-bottom py-2">
            <span class="type-label">${escHtml(t.name)}</span>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" data-action="payee-types.edit" data-id="${t.id}" title="Edit">
                    <i class="fas fa-pencil-alt"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" data-action="payee-types.delete" data-id="${t.id}" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `).join('');
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
