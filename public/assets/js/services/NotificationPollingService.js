// NotificationPollingService.js
// Singleton service that polls the server for new notifications at a configurable interval.
// Integrates with app.js tab visibility events to pause/resume polling.

import { apiFetch } from '../Auth/apiFetch.js';

class NotificationPollingService {
    constructor(intervalMs = 30000) {
        this._intervalMs = intervalMs;
        this._intervalId = null;
        this._listeners = [];
        this._unreadCount = 0;
        this._lastPollTimestamp = null;
        this._polling = false;

        // Bind visibility handlers to existing app.js tab events
        this._onTabActivated = () => this.resume();
        this._onTabDeactivated = () => this.pause();
    }

    /**
     * Begin polling for notifications.
     * Stores the current time as the initial "since" timestamp and starts the interval.
     */
    start() {
        if (this._polling) return;

        this._polling = true;
        this._lastPollTimestamp = new Date().toISOString();

        // Attach tab visibility listeners
        window.addEventListener('tab:activated', this._onTabActivated);
        window.addEventListener('tab:deactivated', this._onTabDeactivated);

        // Perform an immediate poll, then start the interval
        this._poll();
        this._startInterval();
    }

    /**
     * Stop polling entirely and remove event listeners.
     */
    stop() {
        this._polling = false;
        this._clearInterval();
        window.removeEventListener('tab:activated', this._onTabActivated);
        window.removeEventListener('tab:deactivated', this._onTabDeactivated);
    }

    /**
     * Pause polling (e.g., when the tab is hidden).
     */
    pause() {
        this._clearInterval();
    }

    /**
     * Resume polling with an immediate fetch (e.g., when the tab becomes visible again).
     */
    resume() {
        if (!this._polling) return;

        this._poll();
        this._startInterval();
    }

    /**
     * Register a callback to be invoked when new notification data arrives.
     * @param {Function} callback - Receives { notifications: Array, unread_count: number }
     */
    onUpdate(callback) {
        if (typeof callback === 'function') {
            this._listeners.push(callback);
        }
    }

    /**
     * Returns the cached unread notification count.
     * @returns {number}
     */
    getUnreadCount() {
        return this._unreadCount;
    }

    // --- Private methods ---

    _startInterval() {
        this._clearInterval();
        this._intervalId = setInterval(() => this._poll(), this._intervalMs);
    }

    _clearInterval() {
        if (this._intervalId !== null) {
            clearInterval(this._intervalId);
            this._intervalId = null;
        }
    }

    async _poll() {
        try {
            const since = encodeURIComponent(this._lastPollTimestamp);
            const res = await apiFetch(`/api/notifications/poll?since=${since}`);

            if (!res.ok) {
                console.warn('[NotificationPollingService] Poll returned status:', res.status);
                return;
            }

            const json = await res.json();

            if (json.success && json.data) {
                const { notifications, unread_count } = json.data;

                this._unreadCount = unread_count;

                // Update the last poll timestamp to the most recent notification's created_at,
                // or keep the current timestamp if no new notifications arrived.
                if (notifications && notifications.length > 0) {
                    const mostRecent = notifications[0].created_at;
                    this._lastPollTimestamp = mostRecent;
                } else {
                    this._lastPollTimestamp = new Date().toISOString();
                }

                // Notify all registered listeners
                this._notifyListeners({ notifications: notifications || [], unread_count });
            }
        } catch (err) {
            // Handle network errors silently — log and retry on next interval
            console.warn('[NotificationPollingService] Poll error:', err.message || err);
        }
    }

    _notifyListeners(data) {
        for (const cb of this._listeners) {
            try {
                cb(data);
            } catch (err) {
                console.warn('[NotificationPollingService] Listener error:', err);
            }
        }
    }
}

// Export a singleton instance
const notificationPollingService = new NotificationPollingService();
export default notificationPollingService;
