// assets/js/pages/People/personForm.modal.js

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let currentPersonId = null;

const F = {
    title:    () => document.getElementById('personModalTitle'),
    name:     () => document.getElementById('inputPersonName'),
    phone:    () => document.getElementById('inputPersonPhone'),
    email:    () => document.getElementById('inputPersonEmail'),
    address:  () => document.getElementById('inputPersonAddress'),
    tax:      () => document.getElementById('inputPersonTax'),
    iban:     () => document.getElementById('inputPersonIban'),
    notes:    () => document.getElementById('inputPayeeNotes'),
    typesBox: () => document.getElementById('payeeTypesContainer'),
};

export async function mount({ modalRoot: root, data }) {
    modalRoot        = root;
    currentPersonId  = data?.person_id ?? null;

    F.title().textContent = currentPersonId ? 'Edit Person' : 'Add Person';

    // Search existing
    const searchSection = document.getElementById('tenantSearchSection');
    const searchInput = document.getElementById('inputTenantSearch');
    const searchResults = document.getElementById('tenantSearchResults');
    const searchClear = document.getElementById('btnSearchClear');
    const selectedNotice = document.getElementById('selectedPersonNotice');
    let searchTimeout;

    if (currentPersonId) {
        searchSection.style.display = 'none';
    } else {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            const q = searchInput.value.trim();
            if (q.length < 2) {
                searchResults.style.display = 'none';
                return;
            }
            searchTimeout = setTimeout(async () => {
                try {
                    const res = await apiFetch(`/api/people/search-tenant?q=${encodeURIComponent(q)}`);
                    const json = await res.json();
                    if (json.success && json.data.people.length > 0) {
                        searchResults.innerHTML = json.data.people.map(p => `
                            <button type="button" class="list-group-item list-group-item-action py-2 search-result-item" data-id="${p.id}">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold">${escHtml(p.name)}</span>
                                    <small class="text-muted">${escHtml(p.phone || p.email || p.tax_number || '')}</small>
                                </div>
                            </button>
                        `).join('');
                        searchResults.style.display = 'block';
                    } else {
                        searchResults.innerHTML = '<div class="list-group-item text-muted small py-2">No contacts found</div>';
                        searchResults.style.display = 'block';
                    }
                } catch (e) {
                    console.error('Search failed', e);
                }
            }, 300);
        });

        document.addEventListener('click', (e) => {
            if (!searchResults.contains(e.target) && e.target !== searchInput) {
                searchResults.style.display = 'none';
            }
        });

        searchClear.addEventListener('click', () => {
            searchInput.value = '';
            searchClear.style.display = 'none';
            selectedNotice.style.display = 'none';
            currentPersonId = null;
            F.title().textContent = 'Add Person';
            F.name().value = '';
            F.phone().value = '';
            F.email().value = '';
            F.address().value = '';
            F.tax().value = '';
            F.iban().value = '';
            F.notes().value = '';
            [...modalRoot.querySelectorAll('.payee-type-cb')].forEach(cb => cb.checked = false);
        });
    }

    // Load payee types first (needed for checkboxes)
    const types = await fetchPayeeTypes();
    renderTypeCheckboxes(types, []);

    if (!currentPersonId) {
        searchResults.addEventListener('click', async (e) => {
            const btn = e.target.closest('.search-result-item');
            if (!btn) return;
            const id = btn.dataset.id;
            searchResults.style.display = 'none';
            searchInput.value = btn.querySelector('.fw-bold').textContent;
            searchClear.style.display = 'block';
            selectedNotice.style.display = 'block';
            currentPersonId = id;
            F.title().textContent = 'Link Existing Person';
            await fetchAndFill(id, types);
        });
    }

    if (currentPersonId) {
        await fetchAndFill(currentPersonId, types);
    }

    unregister = registerPageActions({
        'people.save': async () => {
            if (!validateForm()) return;

            const checkedIds = [...modalRoot.querySelectorAll('.payee-type-cb:checked')]
                .map(cb => parseInt(cb.value, 10));

            const payload = {
                name:           F.name().value.trim(),
                phone:          F.phone().value.trim()   || null,
                email:          F.email().value.trim()   || null,
                address:        F.address().value.trim() || null,
                tax_number:     F.tax().value.trim()     || null,
                iban:           F.iban().value.trim()    || null,
                payee_notes:    F.notes().value.trim()   || null,
                payee_type_ids: checkedIds,
            };

            const url    = currentPersonId ? `/api/people/${currentPersonId}` : '/api/people';
            const method = currentPersonId ? 'PUT' : 'POST';

            try {
                const res  = await apiFetch(url, { method, body: JSON.stringify(payload) });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    throw new Error(json.message || 'Operation failed');
                }

                bootstrap.Modal.getInstance(modalRoot).hide();
                document.dispatchEvent(new CustomEvent('people.updated'));

                // If launched via Quick Add (stacked), call the onComplete callback
                if (data?.onComplete) {
                    await data.onComplete({ id: json.data.id, name: payload.name });
                }

            } catch (err) {
                console.error(err);
                showFormError(err.message || 'Unable to save person');
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentPersonId = null;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

async function fetchPayeeTypes() {
    try {
        const res  = await apiFetch('/api/people/payee-types');
        const data = await res.json();
        return data.success ? data.data.payee_types : [];
    } catch {
        return [];
    }
}

async function fetchAndFill(personId, types) {
    try {
        const res  = await apiFetch(`/api/people/${personId}`);
        const data = await res.json();
        if (!data.success) return;

        const { person, payee } = data.data;

        F.name().value    = person.name        ?? '';
        F.phone().value   = person.phone       ?? '';
        F.email().value   = person.email       ?? '';
        F.address().value = person.address     ?? '';
        F.tax().value     = person.tax_number  ?? '';
        F.iban().value    = person.iban        ?? '';
        F.notes().value   = payee?.payee?.notes ?? '';

        renderTypeCheckboxes(types, payee?.payee_type_ids ?? []);
    } catch (err) {
        console.error('fetchAndFill failed:', err);
    }
}

function renderTypeCheckboxes(types, selectedIds) {
    const box = F.typesBox();
    if (!box) return;

    if (types.length === 0) {
        box.innerHTML = '<span class="text-muted small">No types defined yet. Use the "Types" button to add some.</span>';
        return;
    }

    box.innerHTML = types.map(t => `
        <div class="form-check form-check-inline">
            <input class="form-check-input payee-type-cb" type="checkbox"
                   id="pt_${t.id}" value="${t.id}"
                   ${selectedIds.includes(parseInt(t.id)) ? 'checked' : ''}>
            <label class="form-check-label" for="pt_${t.id}">${escHtml(t.name)}</label>
        </div>
    `).join('');
}

function validateForm() {
    clearValidation();
    let valid = true;
    const name = F.name();
    if (!name.value.trim()) {
        markInvalid(name, 'Full name is required');
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

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
