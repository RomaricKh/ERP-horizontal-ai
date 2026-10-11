// assets/js/pages/People/payeeTypes.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { confirm } from '../../components/modals.js';

let modalRoot;
let unregister;
let types = [];
let activeEditListeners = []; // Track inline edit listeners for cleanup

export async function mount({ modalRoot: root }) {
    modalRoot = root;
    await loadTypes();

    unregister = registerPageActions({

        'payee-types.add': async () => {
            const input = document.getElementById('newPayeeTypeName');
            const name  = input?.value.trim();

            // Clear previous validation state
            clearInputValidation(input);

            if (!name) {
                markInputInvalid(input, 'Type name is required and cannot be empty.');
                return;
            }

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
                if (!newName) {
                    input.classList.add('is-invalid');
                    input.focus();
                    return;
                }
                if (newName === type.name) {
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

            const blurHandler = () => save();
            const keydownHandler = (e) => {
                if (e.key === 'Enter')  { e.preventDefault(); save(); }
                if (e.key === 'Escape') { loadTypes(); }
            };

            input.addEventListener('blur', blurHandler);
            input.addEventListener('keydown', keydownHandler);

            // Track listeners for cleanup
            activeEditListeners.push(
                { el: input, event: 'blur', handler: blurHandler },
                { el: input, event: 'keydown', handler: keydownHandler }
            );
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
    // Remove tracked inline edit listeners
    activeEditListeners.forEach(({ el, event, handler }) => {
        el.removeEventListener(event, handler);
    });
    activeEditListeners = [];

    unregister?.();
    unregister = null;
    types = [];
    modalRoot = null;
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

    container.innerHTML = types.map(t => {
        const isSystem = parseInt(t.is_system) === 1;
        const badge = isSystem ? ' <span class="badge bg-secondary ms-1">System</span>' : '';
        const actions = isSystem
            ? '<span class="text-muted small"><i class="fas fa-lock me-1"></i>Protected</span>'
            : `<div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" data-action="payee-types.edit" data-id="${t.id}" title="Edit">
                    <i class="fas fa-pencil-alt"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" data-action="payee-types.delete" data-id="${t.id}" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
               </div>`;

        return `
            <div class="payee-type-row d-flex align-items-center justify-content-between border-bottom py-2">
                <span class="type-label">${escHtml(t.name)}${badge}</span>
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

/**
 * Mark an input as invalid and show feedback message.
 */
function markInputInvalid(input, message) {
    if (!input) return;
    input.classList.add('is-invalid');
    let fb = input.parentElement.querySelector('.invalid-feedback');
    if (!fb) {
        fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        input.parentElement.appendChild(fb);
    }
    fb.textContent = message;
    fb.style.display = 'block';
}

/**
 * Clear validation state from an input.
 */
function clearInputValidation(input) {
    if (!input) return;
    input.classList.remove('is-invalid');
    const fb = input.parentElement?.querySelector('.invalid-feedback');
    if (fb) {
        fb.style.display = 'none';
    }
}
