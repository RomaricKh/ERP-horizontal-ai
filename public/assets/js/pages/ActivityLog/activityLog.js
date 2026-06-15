// assets/js/pages/ActivityLog/activityLog.js

import { registerPageActions } from '../Core/pageActions.js';
import { createDataTable } from '../../templates/datatables.design.js';
import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

/**
 * Pretty-print a JSON value (string or object) with indentation.
 * Returns '—' if the value is null/undefined/empty.
 */
function formatJson(value) {
    if (value === null || value === undefined || value === '') return '—';
    try {
        const obj = typeof value === 'string' ? JSON.parse(value) : value;
        return JSON.stringify(obj, null, 2);
    } catch {
        return String(value);
    }
}

let activityLog_container = null;
let unregister = null;
let activityLogDataTable = null;

// Current filter state
let currentFilters = {
    event_type: '',
    from: '',
    to: '',
    page: 1,
    per_page: 25
};

// Track which row IDs currently have their details panel open
const openDetailRows = new Set();

export async function render(container) {
    activityLog_container = container;
    const html = await fetch('html/ActivityLog/activityLog.html').then(r => r.text());
    container.innerHTML = html;
}

export async function mount() {
    await fetchActivityLog();

    unregister = registerPageActions({

        'activityLog.applyFilters': ({ event }) => {
            event.preventDefault();
            const form = activityLog_container.querySelector('#activityLogFilterForm');
            if (!form) return;
            currentFilters.event_type = form.querySelector('[name="event_type"]')?.value ?? '';
            currentFilters.from       = form.querySelector('[name="from"]')?.value ?? '';
            currentFilters.to         = form.querySelector('[name="to"]')?.value ?? '';
            currentFilters.page       = 1;
            openDetailRows.clear();
            fetchActivityLog();
        },

        'activityLog.toggleDetails': ({ el }) => {
            const tr = el.closest('tr');
            if (!tr) return;

            const rowData = activityLogDataTable.row(tr).data();
            if (!rowData) return;

            // rowData[0] is the hidden ID column
            const rowId = rowData[0];

            // Check if a details row already exists right after this row
            const nextTr = tr.nextElementSibling;
            if (nextTr && nextTr.classList.contains('activity-log-details-row')) {
                // Toggle: remove the details row
                nextTr.remove();
                openDetailRows.delete(rowId);
                el.classList.remove('active');
                return;
            }

            // Build the details panel from the <template>
            const tpl = activityLog_container.querySelector('#activityLogDetailsTpl');
            if (!tpl) return;

            const detailsNode = tpl.content.cloneNode(true);
            const detailsDiv  = detailsNode.querySelector('.activity-log-details');

            // rowData indices: 0=id, 1=date, 2=user, 3=event_type, 4=table, 5=action, 6=record_id, 7=old_values(raw), 8=new_values(raw)
            const oldValues = rowData[7];
            const newValues = rowData[8];

            const oldPre = detailsDiv.querySelector('.activity-log-old-values');
            const newPre = detailsDiv.querySelector('.activity-log-new-values');

            if (oldPre) oldPre.textContent = formatJson(oldValues);
            if (newPre) newPre.textContent = formatJson(newValues);

            // Insert a new <tr> spanning all columns right after the current row
            const colCount = tr.querySelectorAll('td').length;
            const detailsTr = document.createElement('tr');
            detailsTr.classList.add('activity-log-details-row');
            const detailsTd = document.createElement('td');
            detailsTd.colSpan = colCount || 7;
            detailsTd.appendChild(detailsDiv);
            detailsTr.appendChild(detailsTd);

            tr.insertAdjacentElement('afterend', detailsTr);
            openDetailRows.add(rowId);
            el.classList.add('active');
        }
    });
}

export function unmount() {
    unregister?.();
    unregister = null;
    activityLogDataTable?.destroy();
    activityLogDataTable = null;
    activityLog_container = null;
    openDetailRows.clear();
}

async function fetchActivityLog() {
    const params = new URLSearchParams();
    if (currentFilters.event_type) params.set('event_type', currentFilters.event_type);
    if (currentFilters.from)       params.set('from',       currentFilters.from);
    if (currentFilters.to)         params.set('to',         currentFilters.to);
    params.set('page',     String(currentFilters.page));
    params.set('per_page', String(currentFilters.per_page));

    try {
        const res  = await apiFetch(`/api/activity-log?${params.toString()}`);
        const data = await res.json();
        if (!data.success) return;
        renderTable(data.data.entries ?? []);
    } catch (err) {
        console.error('Failed to fetch activity log:', err);
    }
}

function renderTable(entries) {
    if (!activityLogDataTable) {
        activityLogDataTable = createDataTable('#activityLogTable', {
            columnDefs: [
                // Hidden columns: id (0), old_values (7), new_values (8)
                { targets: [0, 7, 8], visible: false, searchable: false },
                { targets: '_all', className: 'text-nowrap' },
                // Details button column (index 9) — no sort, no search
                { targets: 9, className: 'text-center', orderable: false, searchable: false }
            ]
        });
    }

    activityLogDataTable.clear();
    openDetailRows.clear();

    entries.forEach(entry => {
        const eventBadgeClass = {
            CREATE: 'badge bg-success',
            UPDATE: 'badge bg-warning text-dark',
            DELETE: 'badge bg-danger',
            LOGIN:  'badge bg-info text-dark',
            LOGOUT: 'badge bg-secondary'
        }[entry.event_type] ?? 'badge bg-light text-dark';

        const eventBadge = `<span class="${eventBadgeClass}">${escHtml(entry.event_type ?? '')}</span>`;

        const detailsBtn = `<button
            data-action="activityLog.toggleDetails"
            class="btn btn-outline-secondary btn-sm"
            title="Show Details"
        ><i class="fas fa-code"></i></button>`;

        activityLogDataTable.row.add([
            entry.id,                                          // 0 — hidden ID
            getFormatter().formatDate(entry.created_at ?? '') || escHtml(entry.created_at ?? ''), // 1 — Date
            escHtml(entry.user_name  ?? entry.user_id ?? '—'), // 2 — User
            eventBadge,                                        // 3 — Event Type
            escHtml(entry.table_name ?? '—'),                  // 4 — Table
            escHtml(entry.action     ?? '—'),                  // 5 — Action
            escHtml(String(entry.record_id ?? '—')),           // 6 — Record ID (visible)
            entry.old_values ?? null,                          // 7 — hidden raw old_values
            entry.new_values ?? null,                          // 8 — hidden raw new_values
            detailsBtn                                         // 9 — Details toggle
        ]);
    });

    activityLogDataTable.draw(false);
    activityLogDataTable.columns.adjust();
}
