/**
 * Unit tests for NotificationPollingService
 * Validates: Requirements 2.1, 2.3, 2.4
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock apiFetch before importing the service
vi.mock('../../public/assets/js/Auth/apiFetch.js', () => ({
    apiFetch: vi.fn()
}));

import { apiFetch } from '../../public/assets/js/Auth/apiFetch.js';

// We need a fresh instance for each test since the module exports a singleton.
// We'll construct a new instance by importing the class directly.
// Since the module only exports the singleton, we'll re-import with resetModules.

describe('NotificationPollingService', () => {
    let service;

    beforeEach(async () => {
        vi.useFakeTimers();
        vi.resetModules();

        // Re-apply mock after resetModules
        vi.doMock('../../public/assets/js/Auth/apiFetch.js', () => ({
            apiFetch: vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({
                    success: true,
                    data: { notifications: [], unread_count: 0 }
                })
            })
        }));

        const mod = await import('../../public/assets/js/services/NotificationPollingService.js');
        service = mod.default;
    });

    afterEach(() => {
        service.stop();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    describe('start()', () => {
        it('initiates polling at the configured interval', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            service.start();

            // start() calls _poll() immediately
            expect(mockFetch).toHaveBeenCalledTimes(1);

            // Advance by one interval (default 30000ms)
            await vi.advanceTimersByTimeAsync(30000);
            expect(mockFetch).toHaveBeenCalledTimes(2);

            // Advance by another interval
            await vi.advanceTimersByTimeAsync(30000);
            expect(mockFetch).toHaveBeenCalledTimes(3);
        });

        it('does not start multiple intervals if called twice', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            service.start();
            service.start(); // second call should be a no-op

            expect(mockFetch).toHaveBeenCalledTimes(1); // only one immediate poll
        });

        it('calls the poll endpoint with a since parameter', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            service.start();

            const callUrl = mockFetch.mock.calls[0][0];
            expect(callUrl).toMatch(/\/api\/notifications\/poll\?since=/);
        });
    });

    describe('pause()', () => {
        it('stops the polling interval', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            service.start();
            expect(mockFetch).toHaveBeenCalledTimes(1);

            service.pause();

            // Advance time — no additional polls should fire
            await vi.advanceTimersByTimeAsync(60000);
            expect(mockFetch).toHaveBeenCalledTimes(1);
        });

        it('is triggered by tab:deactivated event', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            service.start();
            expect(mockFetch).toHaveBeenCalledTimes(1);

            // Simulate tab deactivation
            window.dispatchEvent(new Event('tab:deactivated'));

            // Advance time — no additional polls should fire
            await vi.advanceTimersByTimeAsync(60000);
            expect(mockFetch).toHaveBeenCalledTimes(1);
        });
    });

    describe('resume()', () => {
        it('triggers an immediate fetch when resumed', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            service.start();
            expect(mockFetch).toHaveBeenCalledTimes(1);

            service.pause();
            service.resume();

            // resume() calls _poll() immediately
            expect(mockFetch).toHaveBeenCalledTimes(2);
        });

        it('restarts the interval after resume', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            service.start();
            service.pause();
            service.resume();

            const callsAfterResume = mockFetch.mock.calls.length;

            // Advance by one interval
            await vi.advanceTimersByTimeAsync(30000);
            expect(mockFetch).toHaveBeenCalledTimes(callsAfterResume + 1);
        });

        it('does nothing if service was not started', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            // resume without start — _polling is false, so it should be a no-op
            service.resume();
            expect(mockFetch).not.toHaveBeenCalled();
        });

        it('is triggered by tab:activated event', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            service.start();
            service.pause();

            const callsBefore = mockFetch.mock.calls.length;

            // Simulate tab activation
            window.dispatchEvent(new Event('tab:activated'));

            expect(mockFetch).toHaveBeenCalledTimes(callsBefore + 1);
        });
    });

    describe('onUpdate()', () => {
        it('invokes callbacks with new notification data', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            const notifications = [
                { id: 1, title: 'Test', body: 'Body', created_at: '2024-01-15T10:30:00Z' }
            ];

            mockFetch.mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({
                    success: true,
                    data: { notifications, unread_count: 3 }
                })
            });

            const callback = vi.fn();
            service.onUpdate(callback);
            service.start();

            // Wait for the async _poll() to resolve
            await vi.advanceTimersByTimeAsync(0);

            expect(callback).toHaveBeenCalledWith({
                notifications,
                unread_count: 3
            });
        });

        it('invokes multiple registered callbacks', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            mockFetch.mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({
                    success: true,
                    data: { notifications: [], unread_count: 0 }
                })
            });

            const cb1 = vi.fn();
            const cb2 = vi.fn();
            service.onUpdate(cb1);
            service.onUpdate(cb2);
            service.start();

            await vi.advanceTimersByTimeAsync(0);

            expect(cb1).toHaveBeenCalled();
            expect(cb2).toHaveBeenCalled();
        });

        it('updates the cached unread count', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            mockFetch.mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({
                    success: true,
                    data: { notifications: [], unread_count: 7 }
                })
            });

            service.start();
            await vi.advanceTimersByTimeAsync(0);

            expect(service.getUnreadCount()).toBe(7);
        });

        it('does not register non-function callbacks', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            // Should not throw
            service.onUpdate(null);
            service.onUpdate('not a function');
            service.onUpdate(123);

            mockFetch.mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({
                    success: true,
                    data: { notifications: [], unread_count: 0 }
                })
            });

            // Should not throw when polling
            service.start();
            await vi.advanceTimersByTimeAsync(0);
        });
    });

    describe('network error handling', () => {
        it('handles network errors silently without throwing', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            mockFetch.mockRejectedValue(new Error('Network error'));

            // Should not throw
            service.start();
            await vi.advanceTimersByTimeAsync(0);

            // Service should still be running — next interval should fire
            await vi.advanceTimersByTimeAsync(30000);
            expect(mockFetch).toHaveBeenCalledTimes(2);
        });

        it('handles non-ok response without throwing', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            mockFetch.mockResolvedValue({
                ok: false,
                status: 500
            });

            const callback = vi.fn();
            service.onUpdate(callback);
            service.start();
            await vi.advanceTimersByTimeAsync(0);

            // Callback should not be invoked on error
            expect(callback).not.toHaveBeenCalled();

            // Service continues polling
            await vi.advanceTimersByTimeAsync(30000);
            expect(mockFetch).toHaveBeenCalledTimes(2);
        });

        it('does not invoke callbacks when poll fails', async () => {
            const { apiFetch: mockFetch } = await import('../../public/assets/js/Auth/apiFetch.js');

            mockFetch.mockRejectedValue(new Error('Timeout'));

            const callback = vi.fn();
            service.onUpdate(callback);
            service.start();
            await vi.advanceTimersByTimeAsync(0);

            expect(callback).not.toHaveBeenCalled();
        });
    });
});
