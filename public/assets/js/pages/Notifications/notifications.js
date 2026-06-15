// assets/js/pages/Notifications/notifications.js

import { apiFetch } from '../../Auth/apiFetch.js';
import { hasPermission } from '../../Auth/auth.js';
import { MODAL_SCOPE, openModal, closeModalsInContainer, confirm, alert } from '../../components/modals.js';

// Notification type display configuration
const TYPE_BADGES = {
    PO_STATUS_CHANGE: { label: 'PO Status', class: 'bg-primary' },
    INVOICE_DUE:      { label: 'Invoice Due', class: 'bg-warning text-dark' },
    STOCK_LOW:        { label: 'Stock Low', class: 'bg-danger' },
    PAYMENT_RECEIVED: { label: 'Payment', class: 'bg-success' },
    USER_MESSAGE:     { label: 'Message', class: 'bg-info' },
    SYSTEM_ALERT:     { label: 'System', class: 'bg-secondary' }
};

const NOTIFICATION_TYPES = [
    'PO_STATUS_CHANGE',
    'INVOICE_DUE',
    'STOCK_LOW',
    'PAYMENT_RECEIVED',
    'USER_MESSAGE',
    'SYSTEM_ALERT'
];

const PER_PAGE = 20;

let _container = null;
let _currentPage = 1;
let _totalPages = 1;
let _typeFilter = '';
let _readFilter = '';
let _notifications = [];
let _preferences = {};
let _notificationsUpdatedHandler = null;

export async function render(container) {
    _container = container;
    const html = await fetch('html/Notifications/notifications.html').then(r => r.text());
    container.innerHTML = html;

    // Hide send button if no permission
    if (!hasPermission('notifications.send')) {
        const sendBtn = container.querySelector('#sendNotificationBtn');
        if (sendBtn) sendBtn.style.display = 'none';
    }
}

export async function mount() {
    // Wire filter change handlers
    const typeFilter = _container.querySelector('#typeFilter');
    const readFilter = _container.querySelector('#readFilter');
    typeFilter?.addEventListener('change', handleTypeFilterChange);
    readFilter?.addEventListener('change', handleReadFilterChange);

    // Wire action buttons
    _container.querySelector('#clearAllReadBtn')?.addEventListener('click', handleClearAllRead);
    _container.querySelector('#sendNotificationBtn')?.addEventListener('click', handleSendNotification);
    _container.querySelector('#togglePreferencesBtn')?.addEventListener('click', togglePreferencesPanel);
    _container.querySelector('#savePreferencesBtn')?.addEventListener('click', handleSavePreferences);

    // Listen for notification updates (from send modal)
    _notificationsUpdatedHandler = () => { fetchNotifications(); };
    document.addEventListener('notifications.updated', _notificationsUpdatedHandler);

    // Initial data fetch
    await Promise.all([
        fetchNotifications(),
        fetchPreferences()
    ]);
}

export function unmount() {
    if (_notificationsUpdatedHandler) {
        document.removeEventListener('notifications.updated', _notificationsUpdatedHandler);
        _notificationsUpdatedHandler = null;
    }

    _container = null;
    _currentPage = 1;
    _totalPages = 1;
    _typeFilter = '';
    _readFilter = '';
    _notifications = [];
    _preferences = {};
}

// --- Data Fetching ---

async function fetchNotifications() {
    const params = new URLSearchParams();
    params.set('page', String(_currentPage));
    params.set('per_page', String(PER_PAGE));
    if (_typeFilter) params.set('type', _typeFilter);
    if (_readFilter !== '') params.set('is_read', _readFilter);

    try {
        const res = await apiFetch(`/api/notifications?${params.toString()}`);
        const json = await res.json();

        if (!res.ok || !json.success) {
            renderEmptyState('Failed to load notifications');
            return;
        }

        _notifications = json.data?.notifications ?? [];
        const pagination = json.data?.pagination ?? {};
        _currentPage = pagination.page ?? 1;
        _totalPages = pagination.total_pages ?? 1;

        renderNotificationsList();
        renderPagination(pagination);
    } catch (err) {
        console.error('[Notifications] Fetch failed:', err);
        renderEmptyState('Failed to load notifications');
    }
}

async function fetchPreferences() {
    try {
        const res = await apiFetch('/api/notifications/preferences');
        const json = await res.json();

        if (res.ok && json.success) {
            _preferences = json.data?.preferences ?? {};
            renderPreferencesPanel();
        }
    } catch (err) {
        console.warn('[Notifications] Failed to load preferences:', err);
    }
}

// --- Rendering ---

function renderNotificationsList() {
    const listEl = _container?.querySelector('#notificationsList');
    if (!listEl) return;

    if (_notifications.length === 0) {
        renderEmptyState('No notifications found');
        return;
    }

    listEl.innerHTML = _notifications.map(n => {
        const typeBadge = TYPE_BADGES[n.type] || { label: n.type, class: 'bg-secondary' };
        const isUnread = !n.is_read;
        const unreadClass = isUnread ? 'border-start border-primary border-4' : '';
        const senderName = n.sender_name || 'System';
        const createdAt = formatTimestamp(n.created_at);

        return `
            <div class="notification-item p-3 border-bottom ${unreadClass}" data-notification-id="${n.id}">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center mb-1">
                            <span class="badge ${escHtml(typeBadge.class)} me-2">${escHtml(typeBadge.label)}</span>
                            <strong class="${isUnread ? 'text-dark' : 'text-muted'}">${escHtml(n.title)}</strong>
                            ${isUnread ? '<span class="badge bg-primary rounded-pill ms-2">New</span>' : ''}
                        </div>
                        <p class="mb-1 text-gray-700">${escHtml(n.body)}</p>
                        <small class="text-muted">
                            <i class="fas fa-user me-1"></i>${escHtml(senderName)}
                            <span class="mx-2">&middot;</span>
                            <i class="fas fa-clock me-1"></i>${createdAt}
                        </small>
                    </div>
                    <button class="btn btn-outline-danger btn-sm ms-3 delete-notification-btn" 
                            data-id="${n.id}" title="Delete notification">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
    }).join('');

    // Attach delete handlers
    listEl.querySelectorAll('.delete-notification-btn').forEach(btn => {
        btn.addEventListener('click', handleDeleteNotification);
    });
}

function renderEmptyState(message) {
    const listEl = _container?.querySelector('#notificationsList');
    if (!listEl) return;

    listEl.innerHTML = `
        <div class="text-center py-5 text-muted">
            <i class="fas fa-bell-slash fa-3x mb-3"></i>
            <p>${escHtml(message)}</p>
        </div>
    `;
}

function renderPagination(pagination) {
    const controlsEl = _container?.querySelector('#paginationControls');
    if (!controlsEl) return;

    const total = pagination.total ?? 0;
    const page = pagination.page ?? 1;
    const totalPages = pagination.total_pages ?? 1;

    if (totalPages <= 1) {
        controlsEl.innerHTML = `<small class="text-muted">${total} notification${total !== 1 ? 's' : ''}</small>`;
        return;
    }

    let pagesHtml = '';

    // Previous button
    pagesHtml += `<button class="btn btn-sm btn-outline-primary pagination-btn" 
                          data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>
                    <i class="fas fa-chevron-left"></i> Previous
                  </button>`;

    // Page numbers
    pagesHtml += '<div class="d-flex align-items-center mx-2">';
    const startPage = Math.max(1, page - 2);
    const endPage = Math.min(totalPages, page + 2);

    if (startPage > 1) {
        pagesHtml += `<button class="btn btn-sm btn-outline-secondary mx-1 pagination-btn" data-page="1">1</button>`;
        if (startPage > 2) pagesHtml += '<span class="mx-1">...</span>';
    }

    for (let i = startPage; i <= endPage; i++) {
        const activeClass = i === page ? 'btn-primary' : 'btn-outline-secondary';
        pagesHtml += `<button class="btn btn-sm ${activeClass} mx-1 pagination-btn" data-page="${i}">${i}</button>`;
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) pagesHtml += '<span class="mx-1">...</span>';
        pagesHtml += `<button class="btn btn-sm btn-outline-secondary mx-1 pagination-btn" data-page="${totalPages}">${totalPages}</button>`;
    }
    pagesHtml += '</div>';

    // Next button
    pagesHtml += `<button class="btn btn-sm btn-outline-primary pagination-btn" 
                          data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>
                    Next <i class="fas fa-chevron-right"></i>
                  </button>`;

    controlsEl.innerHTML = pagesHtml;

    // Attach pagination handlers
    controlsEl.querySelectorAll('.pagination-btn').forEach(btn => {
        btn.addEventListener('click', handlePageChange);
    });
}

function renderPreferencesPanel() {
    const container = _container?.querySelector('#preferencesContainer');
    if (!container) return;

    container.innerHTML = NOTIFICATION_TYPES.map(type => {
        const typeBadge = TYPE_BADGES[type] || { label: type, class: 'bg-secondary' };
        const isUserMessage = type === 'USER_MESSAGE';
        const enabled = isUserMessage ? true : (_preferences[type] !== false);

        return `
            <div class="col-md-4 col-sm-6">
                <div class="form-check form-switch">
                    <input class="form-check-input pref-toggle" type="checkbox" 
                           id="pref_${type}" data-type="${type}"
                           ${enabled ? 'checked' : ''} 
                           ${isUserMessage ? 'disabled' : ''}>
                    <label class="form-check-label" for="pref_${type}">
                        <span class="badge ${escHtml(typeBadge.class)} me-1">${escHtml(typeBadge.label)}</span>
                        ${isUserMessage ? '<small class="text-muted">(always on)</small>' : ''}
                    </label>
                </div>
            </div>
        `;
    }).join('');
}

// --- Event Handlers ---

function handleTypeFilterChange(e) {
    _typeFilter = e.target.value;
    _currentPage = 1;
    fetchNotifications();
}

function handleReadFilterChange(e) {
    _readFilter = e.target.value;
    _currentPage = 1;
    fetchNotifications();
}

function handlePageChange(e) {
    const btn = e.currentTarget;
    const page = parseInt(btn.dataset.page, 10);
    if (isNaN(page) || page < 1 || page > _totalPages) return;
    _currentPage = page;
    fetchNotifications();
}

async function handleDeleteNotification(e) {
    const btn = e.currentTarget;
    const id = btn.dataset.id;
    if (!id) return;

    try {
        const res = await apiFetch(`/api/notifications/${id}`, { method: 'DELETE' });
        const json = await res.json();

        if (res.ok && json.success) {
            await fetchNotifications();
        } else {
            await alert({ title: 'Error', alertText: json.message || 'Failed to delete notification' });
        }
    } catch (err) {
        console.error('[Notifications] Delete failed:', err);
    }
}

async function handleClearAllRead() {
    const confirmed = await confirm({
        title: 'Clear All Read',
        message: 'This will remove all read notifications. This action cannot be undone.'
    });
    if (!confirmed) return;

    try {
        const res = await apiFetch('/api/notifications/read', { method: 'DELETE' });
        const json = await res.json();

        if (res.ok && json.success) {
            _currentPage = 1;
            await fetchNotifications();
        } else {
            await alert({ title: 'Error', alertText: json.message || 'Failed to clear notifications' });
        }
    } catch (err) {
        console.error('[Notifications] Clear all read failed:', err);
    }
}

async function handleSendNotification() {
    await openModal({
        scope: MODAL_SCOPE.PAGE,
        template: 'html/Notifications/sendNotification.html',
        controller: '../pages/Notifications/sendNotification.modal.js',
        data: {},
        options: { backdrop: false, keyboard: true, focus: true }
    });
}

function togglePreferencesPanel() {
    const panel = _container?.querySelector('#preferencesPanel');
    if (!panel) return;

    if (panel.style.display === 'none') {
        panel.style.display = '';
    } else {
        panel.style.display = 'none';
    }
}

async function handleSavePreferences() {
    const toggles = _container?.querySelectorAll('.pref-toggle:not(:disabled)');
    if (!toggles) return;

    const preferences = {};
    toggles.forEach(toggle => {
        const type = toggle.dataset.type;
        preferences[type] = toggle.checked;
    });

    const saveBtn = _container?.querySelector('#savePreferencesBtn');
    if (saveBtn) saveBtn.disabled = true;

    try {
        const res = await apiFetch('/api/notifications/preferences', {
            method: 'PUT',
            body: JSON.stringify({ preferences })
        });
        const json = await res.json();

        if (res.ok && json.success) {
            _preferences = preferences;
            await alert({ title: 'Success', alertText: 'Preferences saved successfully.' });
        } else {
            await alert({ title: 'Error', alertText: json.message || 'Failed to save preferences' });
        }
    } catch (err) {
        console.error('[Notifications] Save preferences failed:', err);
        await alert({ title: 'Error', alertText: 'An unexpected error occurred' });
    } finally {
        if (saveBtn) saveBtn.disabled = false;
    }
}

// --- Utilities ---

function formatTimestamp(timestamp) {
    if (!timestamp) return '';
    try {
        const date = new Date(timestamp);
        return date.toLocaleString();
    } catch {
        return timestamp;
    }
}

function escHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
