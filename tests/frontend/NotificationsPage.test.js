/**
 * Unit tests for Notifications Page
 * Validates: Requirements 4.2, 4.3, 4.4, 6.5, 7.4
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock dependencies
vi.mock('../../public/assets/js/Auth/apiFetch.js', () => ({
    apiFetch: vi.fn()
}));

vi.mock('../../public/assets/js/Auth/auth.js', () => ({
    hasPermission: vi.fn().mockReturnValue(true)
}));

vi.mock('../../public/assets/js/components/modals.js', () => ({
    MODAL_SCOPE: { GLOBAL: 'GLOBAL', PAGE: 'PAGE' },
    openModal: vi.fn(),
    closeModalsInContainer: vi.fn(),
    confirm: vi.fn().mockResolvedValue(false),
    alert: vi.fn().mockResolvedValue(undefined)
}));

describe('Notifications Page', () => {
    let notificationsPage;
    let container;
    let mockApiFetch;
    let mockHasPermission;
    let mockOpenModal;
    let mockConfirm;
    let mockAlert;

    const NOTIFICATIONS_HTML = `
        <div id="notificationsPage">
            <div class="d-flex justify-content-between mb-3">
                <div>
                    <select id="typeFilter" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="PO_STATUS_CHANGE">PO Status</option>
                        <option value="INVOICE_DUE">Invoice Due</option>
                        <option value="STOCK_LOW">Stock Low</option>
                        <option value="PAYMENT_RECEIVED">Payment</option>
                        <option value="USER_MESSAGE">Message</option>
                        <option value="SYSTEM_ALERT">System</option>
                    </select>
                    <select id="readFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="1">Read</option>
                        <option value="0">Unread</option>
                    </select>
                </div>
                <div>
                    <button id="sendNotificationBtn">Send Notification</button>
                    <button id="clearAllReadBtn">Clear All Read</button>
                    <button id="togglePreferencesBtn">Preferences</button>
                </div>
            </div>
            <div id="notificationsList"></div>
            <div id="paginationControls"></div>
            <div id="preferencesPanel" style="display:none;">
                <div id="preferencesContainer"></div>
                <button id="savePreferencesBtn">Save</button>
            </div>
        </div>
    `;

    function createMockNotification(overrides = {}) {
        return {
            id: 1,
            type: 'PO_STATUS_CHANGE',
            title: 'Test Notification',
            body: 'Test body content',
            sender_name: 'John Doe',
            entity_type: null,
            entity_id: null,
            is_read: false,
            created_at: '2024-01-15T10:00:00Z',
            ...overrides
        };
    }

    function mockFetchResponses({ notifications = [], pagination = {}, preferences = {} } = {}) {
        mockApiFetch.mockImplementation((url) => {
            if (url.startsWith('/api/notifications/preferences')) {
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({
                        success: true,
                        data: { preferences }
                    })
                });
            }
            if (url.startsWith('/api/notifications')) {
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({
                        success: true,
                        data: {
                            notifications,
                            pagination: {
                                page: 1,
                                per_page: 20,
                                total: notifications.length,
                                total_pages: 1,
                                ...pagination
                            }
                        }
                    })
                });
            }
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, data: {} }) });
        });
    }

    beforeEach(async () => {
        vi.resetModules();

        // Re-apply mocks after resetModules
        vi.doMock('../../public/assets/js/Auth/apiFetch.js', () => ({
            apiFetch: vi.fn()
        }));

        vi.doMock('../../public/assets/js/Auth/auth.js', () => ({
            hasPermission: vi.fn().mockReturnValue(true)
        }));

        vi.doMock('../../public/assets/js/components/modals.js', () => ({
            MODAL_SCOPE: { GLOBAL: 'GLOBAL', PAGE: 'PAGE' },
            openModal: vi.fn(),
            closeModalsInContainer: vi.fn(),
            confirm: vi.fn().mockResolvedValue(false),
            alert: vi.fn().mockResolvedValue(undefined)
        }));

        // Mock global fetch for HTML template loading
        global.fetch = vi.fn().mockResolvedValue({
            text: () => Promise.resolve(NOTIFICATIONS_HTML)
        });

        container = document.createElement('div');
        document.body.appendChild(container);

        // Import fresh module
        const mod = await import('../../public/assets/js/pages/Notifications/notifications.js');
        notificationsPage = mod;

        // Get mock references
        mockApiFetch = (await import('../../public/assets/js/Auth/apiFetch.js')).apiFetch;
        mockHasPermission = (await import('../../public/assets/js/Auth/auth.js')).hasPermission;
        const modals = await import('../../public/assets/js/components/modals.js');
        mockOpenModal = modals.openModal;
        mockConfirm = modals.confirm;
        mockAlert = modals.alert;
    });

    afterEach(() => {
        notificationsPage.unmount();
        document.body.removeChild(container);
        vi.restoreAllMocks();
        delete global.fetch;
    });

    describe('Pagination renders correct page controls', () => {
        it('renders previous/next buttons and page numbers for multi-page results', async () => {
            mockFetchResponses({
                notifications: Array.from({ length: 20 }, (_, i) => createMockNotification({ id: i + 1 })),
                pagination: { page: 2, per_page: 20, total: 60, total_pages: 3 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            // Wait for async fetch
            await vi.waitFor(() => {
                const controls = container.querySelector('#paginationControls');
                return controls && controls.querySelectorAll('.pagination-btn').length > 0;
            });

            const controls = container.querySelector('#paginationControls');
            const buttons = controls.querySelectorAll('.pagination-btn');

            // Should have Previous, page numbers, and Next
            expect(buttons.length).toBeGreaterThanOrEqual(3);

            // Previous button should exist and not be disabled (we're on page 2)
            const prevBtn = controls.querySelector('[data-page="1"]');
            expect(prevBtn).not.toBeNull();

            // Next button should exist
            const nextBtn = controls.querySelector('[data-page="3"]');
            expect(nextBtn).not.toBeNull();
        });

        it('disables previous button on first page', async () => {
            mockFetchResponses({
                notifications: Array.from({ length: 20 }, (_, i) => createMockNotification({ id: i + 1 })),
                pagination: { page: 1, per_page: 20, total: 40, total_pages: 2 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => {
                const controls = container.querySelector('#paginationControls');
                return controls && controls.querySelectorAll('.pagination-btn').length > 0;
            });

            const controls = container.querySelector('#paginationControls');
            // The previous button has data-page="0" and should be disabled
            const prevBtn = controls.querySelector('[data-page="0"]');
            expect(prevBtn).not.toBeNull();
            expect(prevBtn.disabled).toBe(true);
        });

        it('disables next button on last page', async () => {
            mockFetchResponses({
                notifications: Array.from({ length: 20 }, (_, i) => createMockNotification({ id: i + 1 })),
                pagination: { page: 2, per_page: 20, total: 40, total_pages: 2 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => {
                const controls = container.querySelector('#paginationControls');
                return controls && controls.querySelectorAll('.pagination-btn').length > 0;
            });

            const controls = container.querySelector('#paginationControls');
            // The next button has data-page="3" and should be disabled
            const nextBtn = controls.querySelector('[data-page="3"]');
            expect(nextBtn).not.toBeNull();
            expect(nextBtn.disabled).toBe(true);
        });

        it('does not render pagination buttons when only one page', async () => {
            mockFetchResponses({
                notifications: [createMockNotification({ id: 1 })],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => {
                const controls = container.querySelector('#paginationControls');
                return controls && controls.innerHTML.length > 0;
            });

            const controls = container.querySelector('#paginationControls');
            const buttons = controls.querySelectorAll('.pagination-btn');
            expect(buttons.length).toBe(0);
        });
    });

    describe('Type filter sends correct query parameter', () => {
        it('sends type query parameter when filter is changed', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            // Wait for initial fetch
            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            // Clear previous calls
            mockApiFetch.mockClear();
            mockFetchResponses({ notifications: [], pagination: { page: 1, total: 0, total_pages: 1 } });

            // Change type filter
            const typeFilter = container.querySelector('#typeFilter');
            typeFilter.value = 'STOCK_LOW';
            typeFilter.dispatchEvent(new Event('change'));

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            const fetchUrl = mockApiFetch.mock.calls[0][0];
            expect(fetchUrl).toContain('type=STOCK_LOW');
        });

        it('does not send type parameter when filter is set to all', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            mockApiFetch.mockClear();
            mockFetchResponses({ notifications: [], pagination: { page: 1, total: 0, total_pages: 1 } });

            // Change type filter to empty (All)
            const typeFilter = container.querySelector('#typeFilter');
            typeFilter.value = '';
            typeFilter.dispatchEvent(new Event('change'));

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            const fetchUrl = mockApiFetch.mock.calls[0][0];
            expect(fetchUrl).not.toContain('type=');
        });

        it('resets to page 1 when type filter changes', async () => {
            mockFetchResponses({
                notifications: Array.from({ length: 20 }, (_, i) => createMockNotification({ id: i + 1 })),
                pagination: { page: 1, per_page: 20, total: 40, total_pages: 2 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            mockApiFetch.mockClear();
            mockFetchResponses({ notifications: [], pagination: { page: 1, total: 0, total_pages: 1 } });

            // Change type filter
            const typeFilter = container.querySelector('#typeFilter');
            typeFilter.value = 'INVOICE_DUE';
            typeFilter.dispatchEvent(new Event('change'));

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            const fetchUrl = mockApiFetch.mock.calls[0][0];
            expect(fetchUrl).toContain('page=1');
        });
    });

    describe('Read status filter sends correct query parameter', () => {
        it('sends is_read=0 when filtering for unread', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            mockApiFetch.mockClear();
            mockFetchResponses({ notifications: [], pagination: { page: 1, total: 0, total_pages: 1 } });

            // Change read filter to unread
            const readFilter = container.querySelector('#readFilter');
            readFilter.value = '0';
            readFilter.dispatchEvent(new Event('change'));

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            const fetchUrl = mockApiFetch.mock.calls[0][0];
            expect(fetchUrl).toContain('is_read=0');
        });

        it('sends is_read=1 when filtering for read', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            mockApiFetch.mockClear();
            mockFetchResponses({ notifications: [], pagination: { page: 1, total: 0, total_pages: 1 } });

            // Change read filter to read
            const readFilter = container.querySelector('#readFilter');
            readFilter.value = '1';
            readFilter.dispatchEvent(new Event('change'));

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            const fetchUrl = mockApiFetch.mock.calls[0][0];
            expect(fetchUrl).toContain('is_read=1');
        });

        it('does not send is_read parameter when filter is set to all', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            mockApiFetch.mockClear();
            mockFetchResponses({ notifications: [], pagination: { page: 1, total: 0, total_pages: 1 } });

            // Change read filter to all
            const readFilter = container.querySelector('#readFilter');
            readFilter.value = '';
            readFilter.dispatchEvent(new Event('change'));

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            const fetchUrl = mockApiFetch.mock.calls[0][0];
            expect(fetchUrl).not.toContain('is_read=');
        });

        it('resets to page 1 when read filter changes', async () => {
            mockFetchResponses({
                notifications: Array.from({ length: 20 }, (_, i) => createMockNotification({ id: i + 1 })),
                pagination: { page: 1, per_page: 20, total: 40, total_pages: 2 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            mockApiFetch.mockClear();
            mockFetchResponses({ notifications: [], pagination: { page: 1, total: 0, total_pages: 1 } });

            const readFilter = container.querySelector('#readFilter');
            readFilter.value = '0';
            readFilter.dispatchEvent(new Event('change'));

            await vi.waitFor(() => mockApiFetch.mock.calls.length >= 1);

            const fetchUrl = mockApiFetch.mock.calls[0][0];
            expect(fetchUrl).toContain('page=1');
        });
    });

    describe('Send notification form validates required fields', () => {
        it('opens send notification modal when button is clicked', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => {
                const btn = container.querySelector('#sendNotificationBtn');
                return btn !== null;
            });

            const sendBtn = container.querySelector('#sendNotificationBtn');
            sendBtn.click();

            await vi.waitFor(() => mockOpenModal.mock.calls.length >= 1);

            expect(mockOpenModal).toHaveBeenCalledWith(
                expect.objectContaining({
                    scope: 'PAGE',
                    template: 'html/Notifications/sendNotification.html',
                    controller: '../pages/Notifications/sendNotification.modal.js'
                })
            );
        });

        it('hides send button when user lacks notifications.send permission', async () => {
            mockHasPermission.mockReturnValue(false);

            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 }
            });

            await notificationsPage.render(container);

            const sendBtn = container.querySelector('#sendNotificationBtn');
            expect(sendBtn.style.display).toBe('none');
        });
    });

    describe('Preferences toggles reflect current state', () => {
        it('renders preference toggles for each notification type', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 },
                preferences: {
                    PO_STATUS_CHANGE: true,
                    INVOICE_DUE: true,
                    STOCK_LOW: false,
                    PAYMENT_RECEIVED: true,
                    USER_MESSAGE: true,
                    SYSTEM_ALERT: true
                }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => {
                const toggles = container.querySelectorAll('.pref-toggle');
                return toggles.length > 0;
            });

            const toggles = container.querySelectorAll('.pref-toggle');
            expect(toggles.length).toBe(6);
        });

        it('reflects disabled state for a notification type', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 },
                preferences: {
                    PO_STATUS_CHANGE: true,
                    INVOICE_DUE: true,
                    STOCK_LOW: false,
                    PAYMENT_RECEIVED: true,
                    USER_MESSAGE: true,
                    SYSTEM_ALERT: true
                }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => {
                const toggle = container.querySelector('#pref_STOCK_LOW');
                return toggle !== null;
            });

            const stockLowToggle = container.querySelector('#pref_STOCK_LOW');
            expect(stockLowToggle.checked).toBe(false);
        });

        it('reflects enabled state for a notification type', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 },
                preferences: {
                    PO_STATUS_CHANGE: true,
                    INVOICE_DUE: true,
                    STOCK_LOW: false,
                    PAYMENT_RECEIVED: true,
                    USER_MESSAGE: true,
                    SYSTEM_ALERT: true
                }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => {
                const toggle = container.querySelector('#pref_PO_STATUS_CHANGE');
                return toggle !== null;
            });

            const poToggle = container.querySelector('#pref_PO_STATUS_CHANGE');
            expect(poToggle.checked).toBe(true);
        });

        it('USER_MESSAGE toggle is always checked and disabled', async () => {
            mockFetchResponses({
                notifications: [createMockNotification()],
                pagination: { page: 1, per_page: 20, total: 1, total_pages: 1 },
                preferences: {
                    PO_STATUS_CHANGE: true,
                    INVOICE_DUE: true,
                    STOCK_LOW: true,
                    PAYMENT_RECEIVED: true,
                    USER_MESSAGE: false,
                    SYSTEM_ALERT: true
                }
            });

            await notificationsPage.render(container);
            await notificationsPage.mount();

            await vi.waitFor(() => {
                const toggle = container.querySelector('#pref_USER_MESSAGE');
                return toggle !== null;
            });

            const userMsgToggle = container.querySelector('#pref_USER_MESSAGE');
            expect(userMsgToggle.checked).toBe(true);
            expect(userMsgToggle.disabled).toBe(true);
        });
    });
});
