// NotificationCenter.js
// Bell icon dropdown component rendered in the top navigation bar.
// Subscribes to NotificationPollingService for real-time badge updates.

import { apiFetch } from '../Auth/apiFetch.js';
import notificationPollingService from '../services/NotificationPollingService.js';
import { go } from '../router/router.js';

// Entity reference mapping: entity_type → SPA route
const ENTITY_ROUTE_MAP = {
    purchase_orders: '#/purchase-orders',
    sales_invoices: '#/sales-invoices',
    items: '#/items',
    payments: '#/payments',
    collections: '#/collections',
    salaries: '#/salaries',
    production_orders: '#/production-orders'
};

let _dropdownOpen = false;
let _autoReadTimer = null;
let _notifications = [];
let _unreadCount = 0;
let _mounted = false;

// DOM element references
let _bellBadge = null;
let _dropdownMenu = null;
let _bellLink = null;

/**
 * Initialize the NotificationCenter component.
 * Hooks into the existing alerts dropdown in the top navigation bar.
 */
export function init() {
    if (_mounted) return;

    _bellLink = document.getElementById('alertsDropdown');
    _bellBadge = _bellLink?.querySelector('.badge-counter');
    _dropdownMenu = _bellLink?.closest('.nav-item')?.querySelector('.dropdown-list');

    if (!_bellLink || !_dropdownMenu) {
        console.warn('[NotificationCenter] Could not find alerts dropdown elements');
        return;
    }

    // Subscribe to polling service updates
    notificationPollingService.onUpdate(_handlePollingUpdate);

    // Set initial unread count from polling service
    _unreadCount = notificationPollingService.getUnreadCount();
    _updateBadge();

    // Listen for dropdown toggle events
    _bellLink.addEventListener('click', _handleBellClick);

    // Listen for clicks outside to detect dropdown close
    document.addEventListener('click', _handleOutsideClick);

    _mounted = true;
}

/**
 * Cleanup the NotificationCenter component.
 * Removes event listeners and cancels timers.
 */
export function cleanup() {
    if (!_mounted) return;

    _cancelAutoReadTimer();

    if (_bellLink) {
        _bellLink.removeEventListener('click', _handleBellClick);
    }
    document.removeEventListener('click', _handleOutsideClick);

    _dropdownOpen = false;
    _notifications = [];
    _unreadCount = 0;
    _mounted = false;
    _bellBadge = null;
    _dropdownMenu = null;
    _bellLink = null;
}

/**
 * Handle polling service updates.
 * @param {{ notifications: Array, unread_count: number }} data
 */
function _handlePollingUpdate(data) {
    _unreadCount = data.unread_count;
    _updateBadge();

    // If dropdown is open, merge new notifications into the view
    if (_dropdownOpen && data.notifications.length > 0) {
        _notifications = [...data.notifications, ..._notifications]
            .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
            .slice(0, 10);
        _renderDropdownContent();
    }
}

/**
 * Handle bell icon click — toggle dropdown panel.
 */
function _handleBellClick(e) {
    e.preventDefault();
    e.stopPropagation();

    if (_dropdownOpen) {
        _closeDropdown();
    } else {
        _openDropdown();
    }
}

/**
 * Handle clicks outside the dropdown to close it.
 */
function _handleOutsideClick(e) {
    if (!_dropdownOpen) return;

    const navItem = _bellLink?.closest('.nav-item');
    if (navItem && !navItem.contains(e.target)) {
        _closeDropdown();
    }
}

/**
 * Open the dropdown and fetch the 10 most recent notifications.
 */
async function _openDropdown() {
    _dropdownOpen = true;
    _dropdownMenu.classList.add('show');
    _bellLink.setAttribute('aria-expanded', 'true');

    // Fetch latest 10 notifications
    try {
        const res = await apiFetch('/api/notifications?page=1&per_page=10');
        if (res.ok) {
            const json = await res.json();
            if (json.success && json.data) {
                _notifications = json.data.notifications || [];
            }
        }
    } catch (err) {
        console.warn('[NotificationCenter] Failed to fetch notifications:', err.message || err);
    }

    _renderDropdownContent();

    // Start 3-second auto-mark-as-read timer
    _startAutoReadTimer();
}

/**
 * Close the dropdown and cancel any pending timers.
 */
function _closeDropdown() {
    _dropdownOpen = false;
    _dropdownMenu.classList.remove('show');
    _bellLink.setAttribute('aria-expanded', 'false');
    _cancelAutoReadTimer();
}

/**
 * Render the dropdown content with notifications.
 */
function _renderDropdownContent() {
    if (!_dropdownMenu) return;

    const header = `
        <h6 class="dropdown-header d-flex justify-content-between align-items-center">
            <span>Notifications</span>
            <button class="btn btn-sm btn-link text-white p-0" id="nc-mark-all-read">
                Mark all as read
            </button>
        </h6>
    `;

    let notificationItems = '';

    if (_notifications.length === 0) {
        notificationItems = `
            <div class="dropdown-item text-center text-gray-500 py-3">
                No notifications
            </div>
        `;
    } else {
        notificationItems = _notifications.map(n => {
            const bodyPreview = _truncateBody(n.body, 100);
            const senderName = n.sender_name || 'System';
            const relativeTime = _getRelativeTime(n.created_at);
            const unreadClass = !n.is_read ? 'font-weight-bold' : '';
            const unreadIndicator = !n.is_read
                ? '<span class="badge bg-primary rounded-pill ms-2">&bull;</span>'
                : '';
            const entityAttr = n.entity_type
                ? `data-entity-type="${n.entity_type}" data-entity-id="${n.entity_id}"`
                : '';

            return `
                <a class="dropdown-item d-flex align-items-start nc-notification-item ${unreadClass}"
                   href="#" data-notification-id="${n.id}" ${entityAttr}>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="nc-notification-title ${unreadClass}">${_escapeHtml(n.title)}</span>
                            ${unreadIndicator}
                        </div>
                        <div class="small text-gray-600 text-truncate">${_escapeHtml(bodyPreview)}</div>
                        <div class="small text-gray-400 mt-1">
                            ${_escapeHtml(senderName)} &middot; ${relativeTime}
                        </div>
                    </div>
                </a>
            `;
        }).join('');
    }

    const footer = `
        <a class="dropdown-item text-center small text-gray-500" href="#" id="nc-view-all">
            View all notifications
        </a>
    `;

    _dropdownMenu.innerHTML = header + notificationItems + footer;

    // Attach event listeners
    _dropdownMenu.querySelector('#nc-mark-all-read')?.addEventListener('click', _handleMarkAllAsRead);
    _dropdownMenu.querySelector('#nc-view-all')?.addEventListener('click', _handleViewAll);

    // Attach click handlers to notification items
    _dropdownMenu.querySelectorAll('.nc-notification-item').forEach(item => {
        item.addEventListener('click', _handleNotificationClick);
    });
}

/**
 * Start the 3-second auto-mark-as-read timer.
 * Marks all visible unread notifications as read after 3 seconds.
 */
function _startAutoReadTimer() {
    _cancelAutoReadTimer();

    const unreadIds = _notifications
        .filter(n => !n.is_read)
        .map(n => n.id);

    if (unreadIds.length === 0) return;

    _autoReadTimer = setTimeout(async () => {
        try {
            const res = await apiFetch('/api/notifications/read-batch', {
                method: 'PATCH',
                body: JSON.stringify({ ids: unreadIds })
            });

            if (res.ok) {
                // Update local state
                _notifications = _notifications.map(n =>
                    unreadIds.includes(n.id) ? { ...n, is_read: true } : n
                );
                _unreadCount = Math.max(0, _unreadCount - unreadIds.length);
                _updateBadge();
                _renderDropdownContent();
            }
        } catch (err) {
            console.warn('[NotificationCenter] Auto-mark-as-read failed:', err.message || err);
        }
    }, 3000);
}

/**
 * Cancel the auto-mark-as-read timer.
 */
function _cancelAutoReadTimer() {
    if (_autoReadTimer !== null) {
        clearTimeout(_autoReadTimer);
        _autoReadTimer = null;
    }
}

/**
 * Handle "Mark all as read" button click.
 */
async function _handleMarkAllAsRead(e) {
    e.preventDefault();
    e.stopPropagation();

    try {
        const res = await apiFetch('/api/notifications/read-all', {
            method: 'PATCH'
        });

        if (res.ok) {
            _notifications = _notifications.map(n => ({ ...n, is_read: true }));
            _unreadCount = 0;
            _updateBadge();
            _renderDropdownContent();
        }
    } catch (err) {
        console.warn('[NotificationCenter] Mark all as read failed:', err.message || err);
    }
}

/**
 * Handle "View all" link click — navigate to notifications page.
 */
function _handleViewAll(e) {
    e.preventDefault();
    e.stopPropagation();
    _closeDropdown();
    go('#/notifications');
}

/**
 * Handle individual notification click.
 * If it has an entity_type, navigate to the mapped SPA route.
 */
function _handleNotificationClick(e) {
    e.preventDefault();
    e.stopPropagation();

    const item = e.currentTarget;
    const entityType = item.dataset.entityType;

    if (entityType && ENTITY_ROUTE_MAP[entityType]) {
        _closeDropdown();
        go(ENTITY_ROUTE_MAP[entityType]);
    }
}

/**
 * Update the badge count display.
 */
function _updateBadge() {
    if (!_bellBadge) return;

    if (_unreadCount > 0) {
        _bellBadge.textContent = _unreadCount > 99 ? '99+' : String(_unreadCount);
        _bellBadge.style.display = '';
    } else {
        _bellBadge.textContent = '';
        _bellBadge.style.display = 'none';
    }
}

/**
 * Truncate body text to maxLength characters, appending ellipsis if needed.
 * @param {string} body
 * @param {number} maxLength
 * @returns {string}
 */
export function _truncateBody(body, maxLength = 100) {
    if (!body) return '';
    if (body.length <= maxLength) return body;
    return body.substring(0, maxLength) + '…';
}

/**
 * Get a relative time string from a timestamp.
 * @param {string} timestamp - ISO 8601 timestamp
 * @returns {string}
 */
function _getRelativeTime(timestamp) {
    if (!timestamp) return '';

    const now = new Date();
    const then = new Date(timestamp);
    const diffMs = now - then;
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffHr = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr / 24);

    if (diffSec < 60) return 'just now';
    if (diffMin < 60) return `${diffMin} min ago`;
    if (diffHr < 24) return `${diffHr} hr ago`;
    if (diffDay < 7) return `${diffDay} day${diffDay > 1 ? 's' : ''} ago`;

    return then.toLocaleDateString();
}

/**
 * Escape HTML to prevent XSS.
 * @param {string} str
 * @returns {string}
 */
function _escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Get the entity route map (exported for testing).
 * @returns {Object}
 */
export function getEntityRouteMap() {
    return { ...ENTITY_ROUTE_MAP };
}
