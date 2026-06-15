/**
 * Custom multi-select dropdown with inline tag chips.
 * Looks like a form input field that expands into a dropdown.
 * Selected items show as removable tag pills inside the trigger area.
 *
 * Usage:
 *   import { createMultiSelect } from '../../components/multiSelect.js';
 *   const ms = createMultiSelect({
 *       container: element,
 *       placeholder: 'Filter by Type',
 *       icon: 'fas fa-filter',
 *       options: [{id: 1, label: 'Customer'}, ...],
 *       onChange: (selectedLabels) => { ... }
 *   });
 */

export function createMultiSelect({ container, placeholder = 'Select...', icon = 'fas fa-filter', options = [], onChange }) {
    const wrapper = document.createElement('div');
    wrapper.className = 'ms-multiselect position-relative';
    wrapper.style.minWidth = '200px';

    // Trigger area (looks like a form input with tags)
    const trigger = document.createElement('div');
    trigger.className = 'ms-trigger form-control form-control-sm d-flex align-items-center flex-wrap gap-1 cursor-pointer';
    trigger.style.cssText = 'cursor:pointer; min-height:31px; padding:0.2rem 0.5rem; border-color:#d1d3e2; background:#fff;';
    trigger.tabIndex = 0;

    const placeholderEl = document.createElement('span');
    placeholderEl.className = 'ms-placeholder text-muted small d-flex align-items-center gap-1';
    placeholderEl.innerHTML = `<i class="${icon} small"></i>${esc(placeholder)}`;
    trigger.appendChild(placeholderEl);

    const chevron = document.createElement('i');
    chevron.className = 'fas fa-chevron-down ms-auto text-muted small';
    chevron.style.fontSize = '0.6rem';
    trigger.appendChild(chevron);

    // Dropdown panel
    const panel = document.createElement('div');
    panel.className = 'ms-panel position-absolute start-0 end-0 bg-white border rounded-bottom shadow mt-0';
    panel.style.cssText = 'z-index:1055; max-height:240px; overflow-y:auto; display:none; border-top:none; border-top-left-radius:0; border-top-right-radius:0;';

    // Search within dropdown
    const searchWrap = document.createElement('div');
    searchWrap.className = 'px-2 pt-2 pb-1 sticky-top bg-white';
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'form-control form-control-sm';
    searchInput.placeholder = 'Search...';
    searchInput.style.fontSize = '0.8rem';
    searchWrap.appendChild(searchInput);
    panel.appendChild(searchWrap);

    // Options container
    const optionsContainer = document.createElement('div');
    optionsContainer.className = 'px-1 pb-1';

    options.forEach(opt => {
        const item = document.createElement('label');
        item.className = 'ms-option d-flex align-items-center gap-2 px-2 py-1 rounded mb-0';
        item.style.cssText = 'cursor:pointer; transition:background 0.1s;';
        item.dataset.label = opt.label.toLowerCase();
        item.innerHTML = `
            <input type="checkbox" class="form-check-input m-0" style="width:14px;height:14px;" value="${opt.id}" data-label="${escAttr(opt.label)}">
            <span class="small">${esc(opt.label)}</span>
        `;
        item.addEventListener('mouseenter', () => { item.style.backgroundColor = '#eef2ff'; });
        item.addEventListener('mouseleave', () => { item.style.backgroundColor = ''; });
        optionsContainer.appendChild(item);
    });

    panel.appendChild(optionsContainer);

    // Footer with clear
    if (options.length > 0) {
        const footer = document.createElement('div');
        footer.className = 'border-top px-2 py-1 d-flex justify-content-between align-items-center bg-light';
        footer.style.cssText = 'position:sticky; bottom:0;';
        const countEl = document.createElement('span');
        countEl.className = 'ms-count text-muted small';
        countEl.textContent = '0 selected';
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'btn btn-sm btn-link text-decoration-none p-0 text-danger small';
        clearBtn.textContent = 'Clear';
        clearBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            panel.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = false; });
            update();
        });
        footer.appendChild(countEl);
        footer.appendChild(clearBtn);
        panel.appendChild(footer);
    }

    wrapper.appendChild(trigger);
    wrapper.appendChild(panel);
    container.appendChild(wrapper);

    // Toggle panel
    let isOpen = false;
    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        toggle();
    });

    // Search filter
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.toLowerCase();
        optionsContainer.querySelectorAll('.ms-option').forEach(item => {
            item.style.display = item.dataset.label.includes(q) ? '' : 'none';
        });
    });
    searchInput.addEventListener('click', (e) => e.stopPropagation());

    // Close on outside click
    const outsideHandler = (e) => {
        if (!wrapper.contains(e.target)) close();
    };
    document.addEventListener('click', outsideHandler);

    // On checkbox change
    panel.addEventListener('change', (e) => {
        if (e.target.type === 'checkbox') {
            e.stopPropagation();
            update();
        }
    });

    function toggle() {
        isOpen ? close() : open();
    }

    function open() {
        isOpen = true;
        panel.style.display = 'block';
        trigger.style.borderBottomLeftRadius = '0';
        trigger.style.borderBottomRightRadius = '0';
        trigger.style.borderColor = '#4e73df';
        trigger.style.boxShadow = '0 0 0 0.15rem rgba(78,115,223,.25)';
        chevron.style.transform = 'rotate(180deg)';
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        setTimeout(() => searchInput.focus(), 50);
    }

    function close() {
        isOpen = false;
        panel.style.display = 'none';
        trigger.style.borderBottomLeftRadius = '';
        trigger.style.borderBottomRightRadius = '';
        trigger.style.borderColor = '#d1d3e2';
        trigger.style.boxShadow = '';
        chevron.style.transform = '';
    }

    function update() {
        const checked = [...panel.querySelectorAll('input[type=checkbox]:checked')];
        const selected = checked.map(cb => ({ id: cb.value, label: cb.dataset.label }));

        // Update trigger content
        // Remove old tags
        trigger.querySelectorAll('.ms-tag').forEach(t => t.remove());

        if (selected.length === 0) {
            placeholderEl.style.display = '';
            trigger.style.borderColor = isOpen ? '#4e73df' : '#d1d3e2';
        } else {
            placeholderEl.style.display = 'none';
            // Insert tags before the chevron
            selected.forEach(s => {
                const tag = document.createElement('span');
                tag.className = 'ms-tag badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 d-flex align-items-center gap-1 py-1 px-2';
                tag.style.fontSize = '0.75rem';
                tag.innerHTML = `${esc(s.label)}<i class="fas fa-times ms-tag-remove" data-id="${s.id}" style="cursor:pointer;font-size:0.6rem;opacity:0.6"></i>`;
                trigger.insertBefore(tag, chevron);
            });
        }

        // Update footer count
        const countEl = panel.querySelector('.ms-count');
        if (countEl) countEl.textContent = `${selected.length} selected`;

        if (onChange) onChange(selected.map(s => s.label));
    }

    // Remove tag on click (without opening dropdown)
    trigger.addEventListener('click', (e) => {
        const removeBtn = e.target.closest('.ms-tag-remove');
        if (!removeBtn) return;
        e.stopPropagation();
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
        destroy: () => {
            document.removeEventListener('click', outsideHandler);
            wrapper.remove();
        }
    };
}

function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
