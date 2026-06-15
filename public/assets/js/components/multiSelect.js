/**
 * Custom multi-select dropdown with tag chips.
 * Shows selected items as pills/tags below the trigger button.
 * 
 * Usage:
 *   import { createMultiSelect } from '../../components/multiSelect.js';
 *   const ms = createMultiSelect({
 *       container: document.getElementById('filterArea'),
 *       placeholder: 'Filter by Type',
 *       options: [{id: 1, label: 'Customer'}, {id: 2, label: 'Supplier'}],
 *       onChange: (selected) => { ... }
 *   });
 */

export function createMultiSelect({ container, placeholder = 'Select...', options = [], onChange }) {
    const wrapper = document.createElement('div');
    wrapper.className = 'ms-multi-select position-relative d-inline-block';

    // Trigger button
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'ms-trigger btn btn-sm btn-outline-secondary d-flex align-items-center gap-1';
    trigger.innerHTML = `<i class="fas fa-filter"></i><span class="ms-label">${placeholder}</span><i class="fas fa-chevron-down ms-1 small"></i>`;

    // Dropdown panel
    const panel = document.createElement('div');
    panel.className = 'ms-panel position-absolute bg-white border rounded shadow-sm mt-1 p-2';
    panel.style.cssText = 'z-index:1050; min-width:220px; max-height:260px; overflow-y:auto; display:none;';

    // Option items
    options.forEach(opt => {
        const item = document.createElement('label');
        item.className = 'ms-option d-flex align-items-center gap-2 px-2 py-1 rounded cursor-pointer';
        item.style.cursor = 'pointer';
        item.innerHTML = `
            <input type="checkbox" class="form-check-input m-0" value="${opt.id}" data-label="${escAttr(opt.label)}">
            <span class="small">${esc(opt.label)}</span>
        `;
        item.addEventListener('mouseenter', () => item.style.backgroundColor = '#f0f4ff');
        item.addEventListener('mouseleave', () => item.style.backgroundColor = '');
        panel.appendChild(item);
    });

    // Clear link
    if (options.length > 0) {
        const divider = document.createElement('hr');
        divider.className = 'my-1';
        panel.appendChild(divider);
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'btn btn-sm btn-link text-decoration-none p-0 px-2 text-muted';
        clearBtn.innerHTML = '<i class="fas fa-times me-1"></i>Clear all';
        clearBtn.addEventListener('click', () => {
            panel.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = false; });
            update();
        });
        panel.appendChild(clearBtn);
    }

    // Tags area (shows selected items as pills)
    const tags = document.createElement('div');
    tags.className = 'ms-tags d-flex flex-wrap gap-1 mt-1';
    tags.style.display = 'none';

    wrapper.appendChild(trigger);
    wrapper.appendChild(panel);
    wrapper.appendChild(tags);
    container.appendChild(wrapper);

    // Toggle panel
    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = panel.style.display !== 'none';
        panel.style.display = isOpen ? 'none' : 'block';
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (!wrapper.contains(e.target)) {
            panel.style.display = 'none';
        }
    });

    // On checkbox change
    panel.addEventListener('change', (e) => {
        if (e.target.type === 'checkbox') update();
    });

    function update() {
        const checked = [...panel.querySelectorAll('input[type=checkbox]:checked')];
        const selected = checked.map(cb => ({ id: cb.value, label: cb.dataset.label }));

        // Update trigger label
        const labelEl = trigger.querySelector('.ms-label');
        if (selected.length === 0) {
            labelEl.textContent = placeholder;
            trigger.classList.remove('btn-primary');
            trigger.classList.add('btn-outline-secondary');
            tags.style.display = 'none';
            tags.innerHTML = '';
        } else {
            labelEl.textContent = `${placeholder} (${selected.length})`;
            trigger.classList.remove('btn-outline-secondary');
            trigger.classList.add('btn-primary');

            // Render tags
            tags.style.display = 'flex';
            tags.innerHTML = selected.map(s => `
                <span class="badge bg-primary d-flex align-items-center gap-1 py-1 px-2">
                    ${esc(s.label)}
                    <i class="fas fa-times-circle ms-tag-remove" data-id="${s.id}" style="cursor:pointer; opacity:0.7"></i>
                </span>
            `).join('');
        }

        if (onChange) onChange(selected.map(s => s.label));
    }

    // Remove tag on click
    tags.addEventListener('click', (e) => {
        const removeBtn = e.target.closest('.ms-tag-remove');
        if (!removeBtn) return;
        const id = removeBtn.dataset.id;
        const cb = panel.querySelector(`input[value="${id}"]`);
        if (cb) cb.checked = false;
        update();
    });

    // Public API
    return {
        getSelected: () => [...panel.querySelectorAll('input[type=checkbox]:checked')].map(cb => cb.dataset.label),
        clear: () => {
            panel.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = false; });
            update();
        },
        destroy: () => { wrapper.remove(); }
    };
}

function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
