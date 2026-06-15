// assets/js/pages/Expenses/auditHistory.modal.js
// Modal controller for displaying audit history of recurring instances/schedules

import { apiFetch } from '../../Auth/apiFetch.js';
import { getFormatter } from '../../i18n/translationClient.js';

let modalRoot;

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

export async function mount({ modalRoot: root, data }) {
    modalRoot = root;

    const entityType = data?.entity_type ?? 'instance'; // 'instance' or 'schedule'
    const entityId = data?.entity_id ?? null;
    const entityName = data?.entity_name ?? '';

    if (!entityId) {
        showError('No entity specified.');
        return;
    }

    // Populate entity info
    const titleEl = modalRoot.querySelector('#auditHistoryTitle');
    const typeEl = modalRoot.querySelector('#auditEntityType');
    const idEl = modalRoot.querySelector('#auditEntityId');
    const nameEl = modalRoot.querySelector('#auditEntityName');

    if (titleEl) titleEl.textContent = `Audit History — ${entityType === 'schedule' ? 'Schedule' : 'Instance'} #${entityId}`;
    if (typeEl) typeEl.textContent = entityType === 'schedule' ? 'Schedule' : 'Instance';
    if (idEl) idEl.textContent = `#${entityId}`;
    if (nameEl) nameEl.textContent = entityName || '—';

    await loadAuditHistory(entityType, entityId);
}

export function unmount() {
    modalRoot = null;
}

async function loadAuditHistory(entityType, entityId) {
    const loadingEl = modalRoot.querySelector('#auditHistoryLoading');
    const emptyEl = modalRoot.querySelector('#auditHistoryEmpty');
    const contentEl = modalRoot.querySelector('#auditHistoryContent');

    try {
        const res = await apiFetch(
            `/api/recurring-audit-log?entity_type=${encodeURIComponent(entityType)}&entity_id=${encodeURIComponent(entityId)}`
        );
        const json = await res.json();

        if (loadingEl) loadingEl.classList.add('d-none');

        if (!res.ok || !json.success) {
            showError(json.message || 'Failed to load audit history.');
            return;
        }

        const entries = json.data?.entries ?? json.data ?? [];

        if (entries.length === 0) {
            if (emptyEl) emptyEl.classList.remove('d-none');
            return;
        }

        // Sort chronologically (oldest first)
        entries.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));

        renderAuditEntries(entries);
        if (contentEl) contentEl.classList.remove('d-none');
    } catch (err) {
        console.error('Failed to load audit history:', err);
        if (loadingEl) loadingEl.classList.add('d-none');
        showError('Failed to load audit history.');
    }
}

function renderAuditEntries(entries) {
    const tbody = modalRoot.querySelector('#auditHistoryBody');
    if (!tbody) return;

    const fmt = getFormatter();

    tbody.innerHTML = entries.map(entry => {
        const timestamp = formatTimestamp(entry.created_at, fmt);
        const actionBadge = buildActionBadge(entry.action_type);
        const user = escHtml(entry.user_name ?? entry.user_id ?? 'System');
        const prevState = formatState(entry.previous_state);
        const newState = formatState(entry.new_state);

        return `<tr>
            <td><small class="text-muted">${timestamp}</small></td>
            <td>${actionBadge}</td>
            <td>${user}</td>
            <td>${prevState}</td>
            <td>${newState}</td>
        </tr>`;
    }).join('');
}

function formatTimestamp(dateStr, fmt) {
    if (!dateStr) return '—';
    try {
        const d = new Date(dateStr);
        // Format as date + time
        const date = fmt.formatDate(d.toISOString().split('T')[0]);
        const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        return `${escHtml(date)}<br>${escHtml(time)}`;
    } catch {
        return escHtml(dateStr);
    }
}

function buildActionBadge(actionType) {
    const actionMap = {
        'status_change':          { class: 'bg-primary',   icon: 'fa-exchange-alt', label: 'Status Change' },
        'schedule_created':       { class: 'bg-success',   icon: 'fa-plus-circle',  label: 'Created' },
        'schedule_modified':      { class: 'bg-warning text-dark', icon: 'fa-edit', label: 'Modified' },
        'schedule_deactivated':   { class: 'bg-danger',    icon: 'fa-ban',          label: 'Deactivated' },
        'schedule_deleted':       { class: 'bg-danger',    icon: 'fa-trash',        label: 'Deleted' },
        'bulk_advance_payment':   { class: 'bg-info',      icon: 'fa-forward',      label: 'Bulk Advance' },
        'instance_paid':          { class: 'bg-success',   icon: 'fa-check',        label: 'Paid' },
        'instance_skipped':       { class: 'bg-secondary', icon: 'fa-forward',      label: 'Skipped' },
        'instance_overdue':       { class: 'bg-danger',    icon: 'fa-clock',        label: 'Overdue' },
        'payment_reversed':       { class: 'bg-warning text-dark', icon: 'fa-undo', label: 'Reversed' },
    };

    const info = actionMap[actionType] ?? { class: 'bg-light text-dark', icon: 'fa-circle', label: actionType };

    return `<span class="badge ${info.class}"><i class="fas ${info.icon} me-1"></i>${escHtml(info.label)}</span>`;
}

function formatState(state) {
    if (!state) return '<span class="text-muted">—</span>';

    // state might be a JSON string or an object
    let obj = state;
    if (typeof state === 'string') {
        try {
            obj = JSON.parse(state);
        } catch {
            return `<small>${escHtml(state)}</small>`;
        }
    }

    if (typeof obj !== 'object' || obj === null) {
        return `<small>${escHtml(String(obj))}</small>`;
    }

    // Render key-value pairs compactly
    const keys = Object.keys(obj);
    if (keys.length === 0) return '<span class="text-muted">—</span>';

    const items = keys.slice(0, 5).map(k => {
        const val = obj[k];
        const displayVal = val === null ? 'null' : String(val);
        return `<strong>${escHtml(k)}:</strong> ${escHtml(displayVal)}`;
    });

    let html = `<small>${items.join('<br>')}</small>`;
    if (keys.length > 5) {
        html += `<br><small class="text-muted">... +${keys.length - 5} more</small>`;
    }
    return html;
}

function showError(message) {
    const loadingEl = modalRoot.querySelector('#auditHistoryLoading');
    if (loadingEl) loadingEl.classList.add('d-none');

    const body = modalRoot.querySelector('.modal-body');
    if (body) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger';
        alert.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>${escHtml(message)}`;
        body.appendChild(alert);
    }
}
