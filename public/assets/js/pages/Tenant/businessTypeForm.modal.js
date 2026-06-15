// assets/js/pages/Tenant/businessTypeForm.modal.js
// Modal to create/edit a business type by selecting a system template and naming it

import { registerPageActions } from '../Core/pageActions.js';
import { apiFetch } from '../../Auth/apiFetch.js';

let modalRoot;
let unregister;
let currentTypeId = null;
let systemTemplates = [];
let allModules = [];

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;
    currentTypeId = data?.type_id ?? null;

    const titleEl = document.getElementById('businessTypeModalTitle');

    // Load available modules
    await loadModules();

    if (currentTypeId) {
        // Edit mode
        if (titleEl) titleEl.textContent = 'Edit Business Type';
        // Hide template selector in edit mode
        const templateGroup = document.getElementById('templateGroup');
        if (templateGroup) templateGroup.style.display = 'none';
        // Show module checkboxes
        const modulesGroup = document.getElementById('modulesGroup');
        if (modulesGroup) modulesGroup.style.display = '';

        // Fill form with existing data
        const nameInput = document.getElementById('inputTypeName');
        const descInput = document.getElementById('inputTypeDescription');
        if (data?.type) {
            if (nameInput) nameInput.value = data.type.name ?? '';
            if (descInput) descInput.value = data.type.description ?? '';
            // Check the modules that are already assigned (modules is array of {id, code, name})
            const existingModules = data.modules || [];
            existingModules.forEach(mod => {
                const moduleId = mod.id || mod;
                const cb = modalRoot.querySelector(`.module-cb[data-module-id="${moduleId}"]`);
                if (cb) cb.checked = true;
            });
        }
    } else {
        // Create mode
        if (titleEl) titleEl.textContent = 'Create Business Type';
        // Show module checkboxes (always visible — user can pick manually or use template)
        const modulesGroup = document.getElementById('modulesGroup');
        if (modulesGroup) modulesGroup.style.display = '';
        // Load system templates into the dropdown
        await loadSystemTemplates();

        // When template is selected, auto-check its modules
        const templateSelect = document.getElementById('inputSystemTemplate');
        templateSelect?.addEventListener('change', onTemplateChange);
    }

    unregister = registerPageActions({
        'businessTypeForm.save': async () => {
            if (!validateForm()) return;

            const nameInput = document.getElementById('inputTypeName');
            const descInput = document.getElementById('inputTypeDescription');
            const templateSelect = document.getElementById('inputSystemTemplate');

            if (currentTypeId) {
                // Edit mode: update modules
                const checkedModuleIds = [...modalRoot.querySelectorAll('.module-cb:checked')]
                    .map(cb => parseInt(cb.dataset.moduleId, 10));

                try {
                    const res = await apiFetch(`/api/tenant/business-types/${currentTypeId}/modules`, {
                        method: 'PUT',
                        body: JSON.stringify({ module_ids: checkedModuleIds })
                    });
                    const json = await res.json();
                    if (!res.ok || !json.success) {
                        throw new Error(json.message || 'Failed to update business type');
                    }
                    bootstrap.Modal.getInstance(modalRoot).hide();
                    document.dispatchEvent(new CustomEvent('businessTypes.updated'));
                } catch (err) {
                    console.error(err);
                    showFormError(err.message || 'Unable to update business type');
                }
            } else {
                // Create mode: copy from system template
                const sourceId = templateSelect?.value;

                if (sourceId) {
                    // Create by copying from system template
                    const payload = {
                        source_system_type_id: parseInt(sourceId, 10),
                        name: nameInput.value.trim()
                    };

                    try {
                        const res = await apiFetch('/api/tenant/business-types', {
                            method: 'POST',
                            body: JSON.stringify(payload)
                        });
                        const json = await res.json();
                        if (!res.ok || !json.success) {
                            throw new Error(json.message || 'Failed to create business type');
                        }

                        // If user modified modules from the template, update them
                        const newTypeId = json.data?.business_type?.id;
                        if (newTypeId) {
                            const checkedModuleIds = [...modalRoot.querySelectorAll('.module-cb:checked')]
                                .map(cb => parseInt(cb.dataset.moduleId, 10));
                            const templateModuleIds = getTemplateModuleIds(sourceId);

                            // Only update if user changed the modules
                            if (!arraysEqual(checkedModuleIds.sort(), templateModuleIds.sort())) {
                                await apiFetch(`/api/tenant/business-types/${newTypeId}/modules`, {
                                    method: 'PUT',
                                    body: JSON.stringify({ module_ids: checkedModuleIds })
                                });
                            }
                        }

                        bootstrap.Modal.getInstance(modalRoot).hide();
                        document.dispatchEvent(new CustomEvent('businessTypes.updated'));
                    } catch (err) {
                        console.error(err);
                        showFormError(err.message || 'Unable to create business type');
                    }
                } else {
                    // Create without template — use the General template as base, then set modules
                    // Find the "General" system type (usually id=1)
                    const generalTemplate = systemTemplates.find(t => t.code === 'general') || systemTemplates[0];
                    if (!generalTemplate) {
                        showFormError('No system template available. Please select a template.');
                        return;
                    }

                    try {
                        const res = await apiFetch('/api/tenant/business-types', {
                            method: 'POST',
                            body: JSON.stringify({
                                source_system_type_id: generalTemplate.id,
                                name: nameInput.value.trim()
                            })
                        });
                        const json = await res.json();
                        if (!res.ok || !json.success) {
                            throw new Error(json.message || 'Failed to create business type');
                        }

                        // Update modules to match user selection
                        const newTypeId = json.data?.business_type?.id;
                        if (newTypeId) {
                            const checkedModuleIds = [...modalRoot.querySelectorAll('.module-cb:checked')]
                                .map(cb => parseInt(cb.dataset.moduleId, 10));
                            await apiFetch(`/api/tenant/business-types/${newTypeId}/modules`, {
                                method: 'PUT',
                                body: JSON.stringify({ module_ids: checkedModuleIds })
                            });
                        }

                        bootstrap.Modal.getInstance(modalRoot).hide();
                        document.dispatchEvent(new CustomEvent('businessTypes.updated'));
                    } catch (err) {
                        console.error(err);
                        showFormError(err.message || 'Unable to create business type');
                    }
                }
            }
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    currentTypeId = null;
    systemTemplates = [];
    allModules = [];
}

function onTemplateChange() {
    const templateSelect = document.getElementById('inputSystemTemplate');
    const selectedId = templateSelect.value;
    const template = systemTemplates.find(t => String(t.id) === String(selectedId));

    // Uncheck all modules first
    modalRoot.querySelectorAll('.module-cb').forEach(cb => { cb.checked = false; });

    // Check modules from the selected template (match by module id)
    if (template && template.modules) {
        template.modules.forEach(mod => {
            const moduleId = mod.id || mod;
            const cb = modalRoot.querySelector(`.module-cb[data-module-id="${moduleId}"]`);
            if (cb) cb.checked = true;
        });
    }
}

async function loadSystemTemplates() {
    const select = document.getElementById('inputSystemTemplate');
    if (!select) return;

    try {
        const res = await apiFetch('/api/tenant/business-types/system');
        const body = await res.json();
        if (!res.ok || !body.success) return;

        systemTemplates = body.data?.system_types || [];
        systemTemplates.forEach(type => {
            const opt = document.createElement('option');
            opt.value = type.id;
            opt.textContent = `${type.name} (${(type.modules || []).length} modules)`;
            select.appendChild(opt);
        });
    } catch (err) {
        console.error('Failed to load system templates:', err);
    }
}

async function loadModules() {
    try {
        const res = await apiFetch('/api/tenant/modules');
        const body = await res.json();
        if (!res.ok || !body.success) return;

        allModules = body.data?.modules || [];
        renderModuleCheckboxes();
    } catch (err) {
        console.error('Failed to load modules:', err);
    }
}

function renderModuleCheckboxes() {
    const container = document.getElementById('modulesContainer');
    if (!container) return;

    if (allModules.length === 0) {
        container.innerHTML = '<span class="text-muted small">No modules available.</span>';
        return;
    }

    container.innerHTML = allModules.map(mod => `
        <div class="col-sm-6 col-md-4">
            <div class="form-check">
                <input class="form-check-input module-cb" type="checkbox"
                       id="mod_${mod.code}" value="${mod.code}" data-module-id="${mod.id}">
                <label class="form-check-label" for="mod_${mod.code}">
                    <i class="${mod.icon || 'fas fa-puzzle-piece'} me-1 text-primary"></i>${mod.name}
                </label>
            </div>
        </div>
    `).join('');
}

function getTemplateModuleIds(templateId) {
    const template = systemTemplates.find(t => String(t.id) === String(templateId));
    if (!template || !template.modules) return [];
    return template.modules.map(m => m.id);
}

function arraysEqual(a, b) {
    if (a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) {
        if (a[i] !== b[i]) return false;
    }
    return true;
}

function validateForm() {
    clearValidation();
    let valid = true;

    const nameInput = document.getElementById('inputTypeName');
    const name = nameInput.value.trim();
    if (!name || name.length < 2) {
        markInvalid(nameInput, 'Name must be at least 2 characters');
        valid = false;
    } else if (name.length > 100) {
        markInvalid(nameInput, 'Name must not exceed 100 characters');
        valid = false;
    }

    // Validate at least one module is selected
    const checkedModules = modalRoot.querySelectorAll('.module-cb:checked');
    if (checkedModules.length === 0) {
        const container = document.getElementById('modulesContainer');
        if (container) {
            const err = document.createElement('div');
            err.className = 'text-danger small mt-2 module-error';
            err.textContent = 'At least one module must be selected';
            container.appendChild(err);
        }
        valid = false;
    }

    return valid;
}

function clearValidation() {
    modalRoot.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
    modalRoot.querySelectorAll('.module-error').forEach(el => el.remove());
    const footer = modalRoot.querySelector('.modal-footer');
    footer?.querySelector('.form-error')?.remove();
}

function markInvalid(input, message) {
    if (!input) return;
    input.classList.add('is-invalid');

    let feedback = input.parentElement.querySelector('.invalid-feedback');
    if (!feedback) {
        feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        input.parentElement.appendChild(feedback);
    }
    feedback.textContent = message;
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
