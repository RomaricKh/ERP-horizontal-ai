/**
 * Custom multi-select dropdown with inline tag chips.
 * Options are highlighted when selected (no checkboxes).
 * Selected items show as removable tag pills in the trigger.
 */

export function createMultiSelect({ container, placeholder = 'Select...', icon = 'fas fa-filter', options = [], onChange }) {
    const COLORS = {
        tagBg: '#4e73df',
        tagText: '#fff',
        selectedBg: 'rgba(78, 115, 223, 0.12)',
        selectedBorder: 'rgba(78, 115, 223, 0.3)',
        hoverBg: 'rgba(78, 115, 223, 0.06)',
        border: '#d1d3e2',
        focus: '#4e73df',
    };

    const state = new Set(); // selected option IDs

    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'position:relative; min-width:240px;';

    // ─── Trigger ──────────────────────────────────────────────────────────
    const trigger = document.createElement('div');
    trigger.tabIndex = 0;
    trigger.style.cssText = `
        display:flex; align-items:center; flex-wrap:wrap; gap:4px;
        min-height:33px; padding:4px 28px 4px 10px;
        border:1px solid ${COLORS.border}; border-radius:0.35rem;
        background:#fff; cursor:pointer; position:relative;
        font-size:0.85rem; transition:border-color 0.15s, box-shadow 0.15s;
    `;

    const placeholderEl = document.createElement('span');
    placeholderEl.style.cssText = 'color:#858796; white-space:nowrap; user-select:none;';
    placeholderEl.innerHTML = `<i class="${icon}" style="margin-right:6px;font-size:0.75rem;"></i>${esc(placeholder)}`;
    trigger.appendChild(placeholderEl);

    const chevron = document.createElement('i');
    chevron.className = 'fas fa-chevron-down';
    chevron.style.cssText = 'position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:0.6rem;color:#858796;transition:transform 0.2s;';
    trigger.appendChild(chevron);

    // ─── Panel ────────────────────────────────────────────────────────────
    const panel = document.createElement('div');
    panel.style.cssText = `
        display:none; position:absolute; top:100%; left:0; right:0;
        background:#fff; border:1px solid ${COLORS.border}; border-top:none;
        border-radius:0 0 0.35rem 0.35rem;
        box-shadow:0 6px 16px rgba(0,0,0,0.08);
        z-index:1060; max-height:220px; overflow-y:auto;
    `;

    // Search
    const searchWrap = document.createElement('div');
    searchWrap.style.cssText = 'padding:8px 10px 6px; position:sticky; top:0; background:#fff; z-index:1;';
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Search...';
    searchInput.style.cssText = 'width:100%;padding:5px 8px;border:1px solid #e3e6f0;border-radius:4px;font-size:0.8rem;outline:none;';
    searchInput.addEventListener('focus', () => { searchInput.style.borderColor = COLORS.focus; });
    searchInput.addEventListener('blur', () => { searchInput.style.borderColor = '#e3e6f0'; });
    searchWrap.appendChild(searchInput);
    panel.appendChild(searchWrap);

    // Options
    const optionsList = document.createElement('div');
    optionsList.style.cssText = 'padding:2px 6px 6px;';

    const optionEls = [];
    options.forEach(opt => {
        const item = document.createElement('div');
        item.dataset.id = opt.id;
        item.dataset.label = opt.label;
        item.dataset.search = opt.label.toLowerCase();
        item.textContent = opt.label;
        item.style.cssText = `
            padding:7px 10px; margin:2px 0; border-radius:4px;
            cursor:pointer; font-size:0.85rem; user-select:none;
            border:1px solid transparent; transition:all 0.1s;
        `;
        item.addEventListener('mouseenter', () => {
            if (!state.has(String(opt.id))) item.style.backgroundColor = COLORS.hoverBg;
        });
        item.addEventListener('mouseleave', () => {
            if (!state.has(String(opt.id))) item.style.backgroundColor = '';
        });
        item.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleOption(String(opt.id));
        });
        optionsList.appendChild(item);
        optionEls.push(item);
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
    countEl.style.color = '#858796';
    countEl.textContent = '0 selected';
    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.textContent = 'Clear';
    clearBtn.style.cssText = 'border:none;background:none;color:#e74a3b;cursor:pointer;font-size:0.75rem;font-weight:600;';
    clearBtn.addEventListener('click', (e) => { e.stopPropagation(); state.clear(); render(); });
    footer.appendChild(countEl);
    footer.appendChild(clearBtn);
    panel.appendChild(footer);

    wrapper.appendChild(trigger);
    wrapper.appendChild(panel);
    container.appendChild(wrapper);

    // ─── Interactions ─────────────────────────────────────────────────────
    let isOpen = false;

    trigger.addEventListener('click', (e) => {
        if (e.target.closest('.ms-tag-x')) return;
        e.stopPropagation();
        isOpen ? close() : open();
    });

    trigger.addEventListener('click', (e) => {
        const x = e.target.closest('.ms-tag-x');
        if (!x) return;
        e.stopPropagation();
        state.delete(x.dataset.id);
        render();
    });

    searchInput.addEventListener('click', (e) => e.stopPropagation());
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.toLowerCase();
        optionEls.forEach(el => { el.style.display = el.dataset.search.includes(q) ? '' : 'none'; });
    });
    panel.addEventListener('click', (e) => e.stopPropagation());

    const outsideHandler = (e) => { if (!wrapper.contains(e.target)) close(); };
    document.addEventListener('click', outsideHandler);

    function toggleOption(id) {
        if (state.has(id)) state.delete(id);
        else state.add(id);
        render();
    }

    function open() {
        isOpen = true;
        panel.style.display = 'block';
        trigger.style.borderColor = COLORS.focus;
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
        trigger.style.borderColor = COLORS.border;
        trigger.style.boxShadow = 'none';
        trigger.style.borderBottomLeftRadius = '0.35rem';
        trigger.style.borderBottomRightRadius = '0.35rem';
        chevron.style.transform = 'translateY(-50%)';
    }

    function render() {
        // Update option highlight styles
        optionEls.forEach(el => {
            const selected = state.has(el.dataset.id);
            el.style.backgroundColor = selected ? COLORS.selectedBg : '';
            el.style.borderColor = selected ? COLORS.selectedBorder : 'transparent';
            el.style.fontWeight = selected ? '600' : '';
        });

        // Update trigger tags
        trigger.querySelectorAll('.ms-tag').forEach(t => t.remove());
        const selected = [...state].map(id => {
            const opt = options.find(o => String(o.id) === id);
            return opt ? { id, label: opt.label } : null;
        }).filter(Boolean);

        if (selected.length === 0) {
            placeholderEl.style.display = '';
        } else {
            placeholderEl.style.display = 'none';
            selected.forEach(s => {
                const tag = document.createElement('span');
                tag.className = 'ms-tag';
                tag.style.cssText = `
                    display:inline-flex; align-items:center; gap:3px;
                    padding:2px 8px; border-radius:12px;
                    background:${COLORS.tagBg}; color:${COLORS.tagText};
                    font-size:0.72rem; white-space:nowrap; line-height:1.4;
                `;
                tag.innerHTML = `${esc(s.label)}<span class="ms-tag-x" data-id="${s.id}" style="cursor:pointer;opacity:0.7;font-weight:bold;margin-left:2px;">&times;</span>`;
                trigger.insertBefore(tag, chevron);
            });
        }

        countEl.textContent = `${selected.length} selected`;
        if (onChange) onChange(selected.map(s => s.label));
    }

    return {
        getSelected: () => [...state].map(id => { const o = options.find(x => String(x.id) === id); return o?.label; }).filter(Boolean),
        clear: () => { state.clear(); render(); },
        destroy: () => { document.removeEventListener('click', outsideHandler); wrapper.remove(); }
    };
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
