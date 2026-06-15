/**
 * Custom multi-select dropdown with inline tag chips.
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
    // Root wrapper
    const wrapper = document.createElement('div');
    wrapper.className = 'ms-multiselect';
    wrapper.style.cssText = 'position:relative; min-width:240px;';

    // Trigger — styled like an input
    const trigger = document.createElement('div');
    trigger.className = 'ms-trigger';
    trigger.tabIndex = 0;
    trigger.style.cssText = `
        display:flex; align-items:center; flex-wrap:wrap; gap:4px;
        min-height:33px; padding:4px 30px 4px 10px;
        border:1px solid #d1d3e2; border-radius:0.35rem;
        background:#fff; cursor:pointer; position:relative;
        font-size:0.85rem; transition:border-color 0.15s, box-shadow 0.15s;
    `;

    // Placeholder text
    const placeholderEl = document.createElement('span');
    placeholderEl.className = 'ms-placeholder';
    placeholderEl.style.cssText = 'color:#858796; white-space:nowrap; user-select:none;';
    placeholderEl.innerHTML = `<i class="${icon}" style="margin-right:6px; font-size:0.75rem;"></i>${esc(placeholder)}`;
    trigger.appendChild(placeholderEl);

    // Chevron (absolute positioned right)
    const chevron = document.createElement('i');
    chevron.className = 'fas fa-chevron-down';
    chevron.style.cssText = 'position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:0.6rem; color:#858796; transition:transform 0.2s;';
    trigger.appendChild(chevron);

    // Dropdown panel
    const panel = document.createElement('div');
    panel.className = 'ms-panel';
    panel.style.cssText = `
        display:none; position:absolute; top:100%; left:0; right:0;
        background:#fff; border:1px solid #d1d3e2; border-top:none;
        border-radius:0 0 0.35rem 0.35rem;
        box-shadow:0 4px 12px rgba(0,0,0,0.1);
        z-index:1060; max-height:220px; overflow-y:auto;
    `;

    // Search input
    const searchWrap = document.createElement('div');
    searchWrap.style.cssText = 'padding:8px 10px 4px; position:sticky; top:0; background:#fff; z-index:1;';
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Search...';
    searchInput.style.cssText = `
        width:100%; padding:5px 8px; border:1px solid #e3e6f0; border-radius:4px;
        font-size:0.8rem; outline:none;
    `;
    searchInput.addEventListener('focus', () => { searchInput.style.borderColor = '#4e73df'; });
    searchInput.addEventListener('blur', () => { searchInput.style.borderColor = '#e3e6f0'; });
    searchWrap.appendChild(searchInput);
    panel.appendChild(searchWrap);

    // Options list
    const optionsList = document.createElement('div');
    optionsList.style.cssText = 'padding:4px 6px;';

    options.forEach(opt => {
        const item = document.createElement('label');
        item.style.cssText = `
            display:flex; align-items:center; gap:8px;
            padding:6px 8px; margin:0; border-radius:4px;
            cursor:pointer; font-size:0.85rem; transition:background 0.1s;
        `;
        item.dataset.search = opt.label.toLowerCase();

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = opt.id;
        cb.dataset.label = opt.label;
        cb.style.cssText = 'width:15px; height:15px; flex-shrink:0; cursor:pointer; accent-color:#4e73df;';

        const labelText = document.createElement('span');
        labelText.textContent = opt.label;
        labelText.style.cssText = 'white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';

        item.appendChild(cb);
        item.appendChild(labelText);

        item.addEventListener('mouseenter', () => { item.style.backgroundColor = '#f8f9fc'; });
        item.addEventListener('mouseleave', () => { item.style.backgroundColor = ''; });

        optionsList.appendChild(item);
    });
    panel.appendChild(optionsList);

    // Footer
    const footer = document.createElement('div');
    footer.style.cssText = `
        display:flex; justify-content:space-between; align-items:center;
        padding:6px 10px; border-top:1px solid #e3e6f0;
        position:sticky; bottom:0; background:#f8f9fc; font-size:0.75rem;
    `;
    const countEl = document.createElement('span');
    countEl.className = 'ms-count';
    countEl.style.color = '#858796';
    countEl.textContent = '0 selected';
    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.textContent = 'Clear';
    clearBtn.style.cssText = 'border:none; background:none; color:#e74a3b; cursor:pointer; font-size:0.75rem; font-weight:600;';
    clearBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        panel.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = false; });
        update();
    });
    footer.appendChild(countEl);
    footer.appendChild(clearBtn);
    panel.appendChild(footer);

    wrapper.appendChild(trigger);
    wrapper.appendChild(panel);
    container.appendChild(wrapper);

    // --- Interactions ---

    let isOpen = false;

    trigger.addEventListener('click', (e) => {
        if (e.target.closest('.ms-tag-x')) return; // handled separately
        e.stopPropagation();
        isOpen ? close() : open();
    });

    searchInput.addEventListener('click', (e) => e.stopPropagation());
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.toLowerCase();
        optionsList.querySelectorAll('label').forEach(item => {
            item.style.display = item.dataset.search.includes(q) ? '' : 'none';
        });
    });

    panel.addEventListener('change', (e) => {
        if (e.target.type === 'checkbox') { e.stopPropagation(); update(); }
    });
    panel.addEventListener('click', (e) => e.stopPropagation());

    const outsideHandler = (e) => { if (!wrapper.contains(e.target)) close(); };
    document.addEventListener('click', outsideHandler);

    // Tag removal
    trigger.addEventListener('click', (e) => {
        const x = e.target.closest('.ms-tag-x');
        if (!x) return;
        e.stopPropagation();
        const id = x.dataset.id;
        const cb = panel.querySelector(`input[value="${id}"]`);
        if (cb) cb.checked = false;
        update();
    });

    function open() {
        isOpen = true;
        panel.style.display = 'block';
        trigger.style.borderColor = '#4e73df';
        trigger.style.boxShadow = '0 0 0 0.2rem rgba(78,115,223,.15)';
        trigger.style.borderBottomLeftRadius = '0';
        trigger.style.borderBottomRightRadius = '0';
        chevron.style.transform = 'translateY(-50%) rotate(180deg)';
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        setTimeout(() => searchInput.focus(), 30);
    }

    function close() {
        isOpen = false;
        panel.style.display = 'none';
        trigger.style.borderColor = '#d1d3e2';
        trigger.style.boxShadow = 'none';
        trigger.style.borderBottomLeftRadius = '0.35rem';
        trigger.style.borderBottomRightRadius = '0.35rem';
        chevron.style.transform = 'translateY(-50%)';
    }

    function update() {
        const checked = [...panel.querySelectorAll('input[type=checkbox]:checked')];
        const selected = checked.map(cb => ({ id: cb.value, label: cb.dataset.label }));

        // Remove old tags
        trigger.querySelectorAll('.ms-tag').forEach(t => t.remove());

        if (selected.length === 0) {
            placeholderEl.style.display = '';
        } else {
            placeholderEl.style.display = 'none';
            // Add tag pills
            selected.forEach(s => {
                const tag = document.createElement('span');
                tag.className = 'ms-tag';
                tag.style.cssText = `
                    display:inline-flex; align-items:center; gap:4px;
                    padding:2px 8px; border-radius:12px;
                    background:#4e73df; color:#fff; font-size:0.72rem;
                    white-space:nowrap; line-height:1.4;
                `;
                tag.innerHTML = `${esc(s.label)}<span class="ms-tag-x" data-id="${s.id}" style="cursor:pointer; opacity:0.7; font-weight:bold; margin-left:2px;">&times;</span>`;
                // Insert before chevron
                trigger.insertBefore(tag, chevron);
            });
        }

        countEl.textContent = `${selected.length} selected`;
        if (onChange) onChange(selected.map(s => s.label));
    }

    return {
        getSelected: () => [...panel.querySelectorAll('input[type=checkbox]:checked')].map(cb => cb.dataset.label),
        clear: () => { panel.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = false; }); update(); },
        destroy: () => { document.removeEventListener('click', outsideHandler); wrapper.remove(); }
    };
}

function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
