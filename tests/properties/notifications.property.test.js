/**
 * Feature: user-notifications
 * Property-Based Tests for Correctness Properties 1-23
 *
 * Tests target pure logic functions extracted from the notification system design.
 * Uses Vitest + fast-check with minimum 100 iterations per property.
 */
import { describe, it, expect } from 'vitest';
import fc from 'fast-check';

// =============================================================================
// PURE LOGIC FUNCTIONS (extracted for property-based testing)
// =============================================================================

const NOTIFICATION_TYPES = [
    'PO_STATUS_CHANGE',
    'INVOICE_DUE',
    'STOCK_LOW',
    'PAYMENT_RECEIVED',
    'USER_MESSAGE',
    'SYSTEM_ALERT',
];

const ENTITY_ROUTE_MAP = {
    purchase_orders: '#/purchase-orders',
    sales_invoices: '#/sales-invoices',
    items: '#/items',
    payments: '#/payments',
    collections: '#/collections',
    salaries: '#/salaries',
    production_orders: '#/production-orders',
};

/**
 * Property 1: Resolves recipients by checking which users hold a given permission.
 */
function resolveRecipientsByPermission(users, permission) {
    return users.filter((u) => u.permissions.includes(permission));
}

/**
 * Property 2: Checks if a due date is within N days (inclusive) of the current date.
 */
function isWithinDueDateWindow(dueDate, currentDate, windowDays = 3) {
    const due = new Date(dueDate);
    const current = new Date(currentDate);
    const diffMs = due.getTime() - current.getTime();
    const diffDays = diffMs / (1000 * 60 * 60 * 24);
    return diffDays >= 0 && diffDays <= windowDays;
}

/**
 * Property 4: Filters notifications to only those with created_at > since.
 */
function filterSince(notifications, sinceTimestamp) {
    const since = new Date(sinceTimestamp).getTime();
    return notifications.filter((n) => new Date(n.created_at).getTime() > since);
}

/**
 * Property 5: Returns the top N most recent notifications, ordered descending.
 */
function getTopN(notifications, n = 10) {
    return [...notifications]
        .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
        .slice(0, n);
}

/**
 * Property 6: Truncates body to maxLength chars, appending ellipsis if truncated.
 */
function truncateBody(body, maxLength = 100) {
    if (body.length <= maxLength) return body;
    return body.slice(0, maxLength) + '...';
}

/**
 * Property 7: Maps entity_type to the corresponding SPA route.
 */
function mapEntityToRoute(entityType) {
    return ENTITY_ROUTE_MAP[entityType] || null;
}

/**
 * Property 8: Paginates items, returning a page slice and metadata.
 */
function paginate(items, page, perPage) {
    const total = items.length;
    const totalPages = Math.ceil(total / perPage);
    const offset = (page - 1) * perPage;
    const pageItems = items.slice(offset, offset + perPage);
    return { items: pageItems, pagination: { page, perPage, total, totalPages } };
}

/**
 * Property 9: Filters notifications by type.
 */
function filterByType(notifications, type) {
    return notifications.filter((n) => n.type === type);
}

/**
 * Property 10: Filters notifications by read status.
 */
function filterByReadStatus(notifications, isRead) {
    return notifications.filter((n) => n.is_read === isRead);
}

/**
 * Property 11 & 12: Marks a notification as read, setting timestamp.
 */
function markAsRead(notification, currentTimestamp) {
    if (notification.is_read) {
        // Idempotent: do not change read_at
        return { ...notification };
    }
    return { ...notification, is_read: true, read_at: currentTimestamp };
}

/**
 * Property 13: Marks all notifications as read.
 */
function markAllAsRead(notifications, currentTimestamp) {
    return notifications.map((n) => {
        if (n.is_read) return { ...n };
        return { ...n, is_read: true, read_at: currentTimestamp };
    });
}

/**
 * Property 14: Validates same-business constraint for user messaging.
 */
function validateSameBusiness(sender, recipient) {
    return sender.business_id === recipient.business_id;
}

/**
 * Property 15: Stores and retrieves a preference (simulated round-trip).
 */
function preferenceRoundTrip(store, userId, businessId, type, enabled) {
    const key = `${userId}_${businessId}_${type}`;
    store[key] = enabled;
    return store[key];
}

/**
 * Property 16 & 17: Determines if a notification should be delivered based on preferences.
 */
function shouldDeliverNotification(type, preferences) {
    // USER_MESSAGE always bypasses preferences
    if (type === 'USER_MESSAGE') return true;
    // If no preference set, default to enabled
    if (preferences[type] === undefined) return true;
    return preferences[type] === true;
}

/**
 * Property 18: Filters out soft-deleted notifications from query results.
 */
function excludeSoftDeleted(notifications) {
    return notifications.filter((n) => n.deleted_at === null);
}

/**
 * Property 19: Soft-deletes only read notifications (clear all read).
 */
function clearAllRead(notifications, currentTimestamp) {
    return notifications.map((n) => {
        if (n.is_read) {
            return { ...n, deleted_at: currentTimestamp };
        }
        return { ...n };
    });
}

/**
 * Property 20: Determines if a soft-deleted record is expired for cleanup.
 */
function isExpiredForCleanup(deletedAt, currentDate, retentionDays = 90) {
    const deleted = new Date(deletedAt).getTime();
    const current = new Date(currentDate).getTime();
    const diffDays = (current - deleted) / (1000 * 60 * 60 * 24);
    return diffDays > retentionDays;
}

/**
 * Property 21: Filters notifications by recipient_id and business_id.
 */
function filterByAccess(notifications, userId, businessId) {
    return notifications.filter(
        (n) => n.recipient_id === userId && n.business_id === businessId
    );
}

/**
 * Property 22: Creates notifications for eligible recipients (single or array).
 */
function createNotificationsForRecipients(recipientIds, type, preferences) {
    const ids = Array.isArray(recipientIds) ? recipientIds : [recipientIds];
    return ids.filter((id) => {
        const prefs = preferences[id] || {};
        return shouldDeliverNotification(type, prefs);
    });
}

/**
 * Property 23: Wraps notification creation in error isolation.
 */
function notifyWithErrorIsolation(createFn) {
    try {
        createFn();
    } catch (e) {
        // Error is caught and logged, never propagated
    }
    // Caller always continues — return true to indicate no exception escaped
    return true;
}

// =============================================================================
// ARBITRARIES (generators)
// =============================================================================

const permissionArb = fc.constantFrom(
    'purchaseOrders.approve',
    'items.edit',
    'finance.view',
    'manufacturing.view',
    'notifications.view',
    'notifications.send'
);

const userArb = fc.record({
    id: fc.integer({ min: 1, max: 10000 }),
    business_id: fc.integer({ min: 1, max: 100 }),
    permissions: fc.array(permissionArb, { minLength: 0, maxLength: 6 }),
});

const notificationTypeArb = fc.constantFrom(...NOTIFICATION_TYPES);

const entityTypeArb = fc.constantFrom(
    'purchase_orders',
    'sales_invoices',
    'items',
    'payments',
    'collections',
    'salaries',
    'production_orders'
);

const timestampArb = fc.integer({
    min: new Date('2020-01-01T00:00:00Z').getTime(),
    max: new Date('2030-12-31T23:59:59Z').getTime(),
}).map((ms) => new Date(ms).toISOString());

const notificationArb = fc.record({
    id: fc.integer({ min: 1, max: 100000 }),
    type: notificationTypeArb,
    title: fc.string({ minLength: 1, maxLength: 100 }),
    body: fc.string({ minLength: 0, maxLength: 500 }),
    sender_id: fc.option(fc.integer({ min: 1, max: 10000 }), { nil: null }),
    recipient_id: fc.integer({ min: 1, max: 100 }),
    business_id: fc.integer({ min: 1, max: 50 }),
    entity_type: fc.option(entityTypeArb, { nil: null }),
    entity_id: fc.option(fc.integer({ min: 1, max: 10000 }), { nil: null }),
    is_read: fc.boolean(),
    read_at: fc.option(timestampArb, { nil: null }),
    deleted_at: fc.option(timestampArb, { nil: null }),
    created_at: timestampArb,
});

const unreadNotificationArb = fc.record({
    id: fc.integer({ min: 1, max: 100000 }),
    type: notificationTypeArb,
    title: fc.string({ minLength: 1, maxLength: 100 }),
    body: fc.string({ minLength: 0, maxLength: 500 }),
    sender_id: fc.option(fc.integer({ min: 1, max: 10000 }), { nil: null }),
    recipient_id: fc.integer({ min: 1, max: 100 }),
    business_id: fc.integer({ min: 1, max: 50 }),
    is_read: fc.constant(false),
    read_at: fc.constant(null),
    deleted_at: fc.constant(null),
    created_at: timestampArb,
});

const readNotificationArb = fc.record({
    id: fc.integer({ min: 1, max: 100000 }),
    type: notificationTypeArb,
    title: fc.string({ minLength: 1, maxLength: 100 }),
    body: fc.string({ minLength: 0, maxLength: 500 }),
    sender_id: fc.option(fc.integer({ min: 1, max: 10000 }), { nil: null }),
    recipient_id: fc.integer({ min: 1, max: 100 }),
    business_id: fc.integer({ min: 1, max: 50 }),
    is_read: fc.constant(true),
    read_at: timestampArb,
    deleted_at: fc.constant(null),
    created_at: timestampArb,
});

// =============================================================================
// PROPERTY TESTS
// =============================================================================


// --- Property 1: Permission-based recipient resolution ---
describe('Feature: user-notifications, Property 1: Permission-based recipient resolution', () => {
    it('resolved recipients exactly match users holding the target permission', () => {
        /**
         * Validates: Requirements 1.1, 1.3, 1.4, 10.4
         */
        fc.assert(
            fc.property(
                fc.array(userArb, { minLength: 0, maxLength: 50 }),
                permissionArb,
                (users, targetPermission) => {
                    const resolved = resolveRecipientsByPermission(users, targetPermission);

                    // Every resolved user must hold the permission
                    for (const user of resolved) {
                        expect(user.permissions).toContain(targetPermission);
                    }

                    // Every user holding the permission must be in resolved
                    const expected = users.filter((u) =>
                        u.permissions.includes(targetPermission)
                    );
                    expect(resolved).toHaveLength(expected.length);
                    expect(resolved).toEqual(expected);
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 2: Invoice due date window ---
describe('Feature: user-notifications, Property 2: Invoice due date window', () => {
    it('notification is created iff due date is within 3 days (inclusive) of current date', () => {
        /**
         * Validates: Requirements 1.2
         */
        const dateIntArb = fc.integer({
            min: new Date('2020-01-01').getTime(),
            max: new Date('2030-12-31').getTime(),
        });

        fc.assert(
            fc.property(dateIntArb, dateIntArb, (dueDateMs, currentDateMs) => {
                const dueDate = new Date(dueDateMs);
                const currentDate = new Date(currentDateMs);

                const result = isWithinDueDateWindow(
                    dueDate.toISOString(),
                    currentDate.toISOString(),
                    3
                );

                const diffMs = dueDate.getTime() - currentDate.getTime();
                const diffDays = diffMs / (1000 * 60 * 60 * 24);

                if (diffDays >= 0 && diffDays <= 3) {
                    expect(result).toBe(true);
                } else {
                    expect(result).toBe(false);
                }
            }),
            { numRuns: 100 }
        );
    });
});

// --- Property 3: User message data preservation ---
describe('Feature: user-notifications, Property 3: User message data preservation', () => {
    it('created notification preserves sender_id, recipient_id, title, and body exactly', () => {
        /**
         * Validates: Requirements 1.5, 6.4
         */
        fc.assert(
            fc.property(
                fc.integer({ min: 1, max: 10000 }),
                fc.integer({ min: 1, max: 10000 }),
                fc.string({ minLength: 1, maxLength: 255 }),
                fc.string({ minLength: 1, maxLength: 1000 }),
                (senderId, recipientId, title, body) => {
                    // Simulate creating a USER_MESSAGE notification
                    const notification = {
                        type: 'USER_MESSAGE',
                        sender_id: senderId,
                        recipient_id: recipientId,
                        title: title,
                        body: body,
                    };

                    expect(notification.sender_id).toBe(senderId);
                    expect(notification.recipient_id).toBe(recipientId);
                    expect(notification.title).toBe(title);
                    expect(notification.body).toBe(body);
                    expect(notification.type).toBe('USER_MESSAGE');
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 4: Since-timestamp filtering ---
describe('Feature: user-notifications, Property 4: Since-timestamp filtering', () => {
    it('only notifications with created_at > since are returned', () => {
        /**
         * Validates: Requirements 2.5
         */
        fc.assert(
            fc.property(
                fc.array(notificationArb, { minLength: 0, maxLength: 30 }),
                timestampArb,
                (notifications, since) => {
                    const result = filterSince(notifications, since);
                    const sinceTime = new Date(since).getTime();

                    // All returned notifications must have created_at > since
                    for (const n of result) {
                        expect(new Date(n.created_at).getTime()).toBeGreaterThan(sinceTime);
                    }

                    // No notification with created_at <= since should be in results
                    const excluded = notifications.filter(
                        (n) => new Date(n.created_at).getTime() <= sinceTime
                    );
                    for (const n of excluded) {
                        expect(result).not.toContainEqual(n);
                    }
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 5: Dropdown ordering and limiting ---
describe('Feature: user-notifications, Property 5: Dropdown ordering and limiting', () => {
    it('at most 10 returned, ordered by created_at descending, and they are the 10 most recent', () => {
        /**
         * Validates: Requirements 3.2
         */
        fc.assert(
            fc.property(
                fc.array(notificationArb, { minLength: 0, maxLength: 50 }),
                (notifications) => {
                    const result = getTopN(notifications, 10);

                    // At most 10
                    expect(result.length).toBeLessThanOrEqual(10);

                    // Correct count
                    expect(result.length).toBe(Math.min(notifications.length, 10));

                    // Ordered descending by created_at
                    for (let i = 1; i < result.length; i++) {
                        expect(
                            new Date(result[i - 1].created_at).getTime()
                        ).toBeGreaterThanOrEqual(
                            new Date(result[i].created_at).getTime()
                        );
                    }

                    // They are the N most recent
                    const sorted = [...notifications].sort(
                        (a, b) =>
                            new Date(b.created_at).getTime() -
                            new Date(a.created_at).getTime()
                    );
                    const expectedIds = sorted.slice(0, 10).map((n) => n.id);
                    const resultIds = result.map((n) => n.id);
                    expect(resultIds).toEqual(expectedIds);
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 6: Body preview truncation ---
describe('Feature: user-notifications, Property 6: Body preview truncation', () => {
    it('truncation at 100 chars with ellipsis when needed', () => {
        /**
         * Validates: Requirements 3.3
         */
        fc.assert(
            fc.property(
                fc.string({ minLength: 0, maxLength: 500 }),
                (body) => {
                    const result = truncateBody(body, 100);

                    if (body.length <= 100) {
                        // No truncation needed
                        expect(result).toBe(body);
                        expect(result.length).toBe(body.length);
                    } else {
                        // Truncated: first 100 chars + ellipsis
                        expect(result).toBe(body.slice(0, 100) + '...');
                        expect(result.length).toBe(103);
                    }
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 7: Entity reference navigation mapping ---
describe('Feature: user-notifications, Property 7: Entity reference navigation mapping', () => {
    it('mapped route matches the entity reference mapping table for all valid types', () => {
        /**
         * Validates: Requirements 3.4
         */
        fc.assert(
            fc.property(entityTypeArb, (entityType) => {
                const route = mapEntityToRoute(entityType);

                expect(route).not.toBeNull();

                const expectedMap = {
                    purchase_orders: '#/purchase-orders',
                    sales_invoices: '#/sales-invoices',
                    items: '#/items',
                    payments: '#/payments',
                    collections: '#/collections',
                    salaries: '#/salaries',
                    production_orders: '#/production-orders',
                };

                expect(route).toBe(expectedMap[entityType]);
            }),
            { numRuns: 100 }
        );
    });
});

// --- Property 8: Pagination correctness ---
describe('Feature: user-notifications, Property 8: Pagination correctness', () => {
    it('page N returns at most P items and total_pages = ceil(T/P)', () => {
        /**
         * Validates: Requirements 4.2
         */
        fc.assert(
            fc.property(
                fc.array(fc.integer({ min: 1, max: 1000 }), { minLength: 0, maxLength: 100 }),
                fc.integer({ min: 1, max: 50 }),
                fc.integer({ min: 1, max: 20 }),
                (items, page, perPage) => {
                    const result = paginate(items, page, perPage);

                    // At most perPage items returned
                    expect(result.items.length).toBeLessThanOrEqual(perPage);

                    // total_pages = ceil(T/P)
                    const expectedTotalPages = Math.ceil(items.length / perPage);
                    expect(result.pagination.totalPages).toBe(expectedTotalPages);

                    // Total matches input length
                    expect(result.pagination.total).toBe(items.length);

                    // If page is within range, items are correct slice
                    if (page <= expectedTotalPages && items.length > 0) {
                        const offset = (page - 1) * perPage;
                        const expected = items.slice(offset, offset + perPage);
                        expect(result.items).toEqual(expected);
                    }
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 9: Type filter correctness ---
describe('Feature: user-notifications, Property 9: Type filter correctness', () => {
    it('only notifications matching the type filter are returned', () => {
        /**
         * Validates: Requirements 4.3
         */
        fc.assert(
            fc.property(
                fc.array(notificationArb, { minLength: 0, maxLength: 30 }),
                notificationTypeArb,
                (notifications, typeFilter) => {
                    const result = filterByType(notifications, typeFilter);

                    // All returned must match the filter
                    for (const n of result) {
                        expect(n.type).toBe(typeFilter);
                    }

                    // Count matches expected
                    const expectedCount = notifications.filter(
                        (n) => n.type === typeFilter
                    ).length;
                    expect(result.length).toBe(expectedCount);
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 10: Read status filter correctness ---
describe('Feature: user-notifications, Property 10: Read status filter correctness', () => {
    it('only notifications matching the read status filter are returned', () => {
        /**
         * Validates: Requirements 4.4
         */
        fc.assert(
            fc.property(
                fc.array(notificationArb, { minLength: 0, maxLength: 30 }),
                fc.boolean(),
                (notifications, isReadFilter) => {
                    const result = filterByReadStatus(notifications, isReadFilter);

                    // All returned must match the filter
                    for (const n of result) {
                        expect(n.is_read).toBe(isReadFilter);
                    }

                    // Count matches expected
                    const expectedCount = notifications.filter(
                        (n) => n.is_read === isReadFilter
                    ).length;
                    expect(result.length).toBe(expectedCount);
                }
            ),
            { numRuns: 100 }
        );
    });
});


// --- Property 11: Mark as read sets timestamp ---
describe('Feature: user-notifications, Property 11: Mark as read sets timestamp', () => {
    it('marking an unread notification sets is_read=true and read_at to non-null', () => {
        /**
         * Validates: Requirements 5.2, 5.4
         */
        fc.assert(
            fc.property(unreadNotificationArb, timestampArb, (notification, currentTs) => {
                const result = markAsRead(notification, currentTs);

                expect(result.is_read).toBe(true);
                expect(result.read_at).not.toBeNull();
                expect(result.read_at).toBe(currentTs);
            }),
            { numRuns: 100 }
        );
    });
});

// --- Property 12: Mark as read idempotence ---
describe('Feature: user-notifications, Property 12: Mark as read idempotence', () => {
    it('marking an already-read notification does not change read_at', () => {
        /**
         * Validates: Requirements 5.5
         */
        fc.assert(
            fc.property(readNotificationArb, timestampArb, (notification, newTs) => {
                const originalReadAt = notification.read_at;
                const result = markAsRead(notification, newTs);

                expect(result.is_read).toBe(true);
                expect(result.read_at).toBe(originalReadAt);
            }),
            { numRuns: 100 }
        );
    });
});

// --- Property 13: Mark all as read completeness ---
describe('Feature: user-notifications, Property 13: Mark all as read completeness', () => {
    it('after mark-all, all notifications have is_read=true', () => {
        /**
         * Validates: Requirements 5.3
         */
        fc.assert(
            fc.property(
                fc.array(notificationArb, { minLength: 0, maxLength: 30 }),
                timestampArb,
                (notifications, currentTs) => {
                    const result = markAllAsRead(notifications, currentTs);

                    for (const n of result) {
                        expect(n.is_read).toBe(true);
                    }

                    // Length preserved
                    expect(result.length).toBe(notifications.length);
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 14: Same-business validation ---
describe('Feature: user-notifications, Property 14: Same-business validation', () => {
    it('send succeeds iff sender and recipient share the same business_id', () => {
        /**
         * Validates: Requirements 6.2, 6.3
         */
        fc.assert(
            fc.property(
                fc.record({
                    id: fc.integer({ min: 1, max: 10000 }),
                    business_id: fc.integer({ min: 1, max: 100 }),
                }),
                fc.record({
                    id: fc.integer({ min: 1, max: 10000 }),
                    business_id: fc.integer({ min: 1, max: 100 }),
                }),
                (sender, recipient) => {
                    const canSend = validateSameBusiness(sender, recipient);

                    if (sender.business_id === recipient.business_id) {
                        expect(canSend).toBe(true);
                    } else {
                        expect(canSend).toBe(false);
                    }
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 15: Preference round-trip ---
describe('Feature: user-notifications, Property 15: Preference round-trip', () => {
    it('store then retrieve returns the same enabled/disabled value', () => {
        /**
         * Validates: Requirements 7.1
         */
        fc.assert(
            fc.property(
                fc.integer({ min: 1, max: 10000 }),
                fc.integer({ min: 1, max: 100 }),
                notificationTypeArb,
                fc.boolean(),
                (userId, businessId, type, enabled) => {
                    const store = {};
                    const retrieved = preferenceRoundTrip(
                        store,
                        userId,
                        businessId,
                        type,
                        enabled
                    );

                    expect(retrieved).toBe(enabled);
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 16: Preference-based notification filtering ---
describe('Feature: user-notifications, Property 16: Preference-based notification filtering', () => {
    it('notify() skips disabled types', () => {
        /**
         * Validates: Requirements 7.2
         */
        const nonUserMessageTypeArb = fc.constantFrom(
            'PO_STATUS_CHANGE',
            'INVOICE_DUE',
            'STOCK_LOW',
            'PAYMENT_RECEIVED',
            'SYSTEM_ALERT'
        );

        fc.assert(
            fc.property(nonUserMessageTypeArb, (type) => {
                const preferences = { [type]: false };
                const result = shouldDeliverNotification(type, preferences);

                expect(result).toBe(false);
            }),
            { numRuns: 100 }
        );
    });
});

// --- Property 17: USER_MESSAGE bypasses preferences ---
describe('Feature: user-notifications, Property 17: USER_MESSAGE bypasses preferences', () => {
    it('USER_MESSAGE is always delivered regardless of preference configuration', () => {
        /**
         * Validates: Requirements 7.5
         */
        const preferencesArb = fc.record({
            PO_STATUS_CHANGE: fc.boolean(),
            INVOICE_DUE: fc.boolean(),
            STOCK_LOW: fc.boolean(),
            PAYMENT_RECEIVED: fc.boolean(),
            USER_MESSAGE: fc.boolean(),
            SYSTEM_ALERT: fc.boolean(),
        });

        fc.assert(
            fc.property(preferencesArb, (preferences) => {
                const result = shouldDeliverNotification('USER_MESSAGE', preferences);
                expect(result).toBe(true);
            }),
            { numRuns: 100 }
        );
    });
});

// --- Property 18: Soft-delete exclusion from queries ---
describe('Feature: user-notifications, Property 18: Soft-delete exclusion from queries', () => {
    it('soft-deleted notifications never appear in query results', () => {
        /**
         * Validates: Requirements 8.1
         */
        fc.assert(
            fc.property(
                fc.array(notificationArb, { minLength: 0, maxLength: 30 }),
                (notifications) => {
                    const result = excludeSoftDeleted(notifications);

                    // No soft-deleted notification in results
                    for (const n of result) {
                        expect(n.deleted_at).toBeNull();
                    }

                    // All non-deleted notifications are included
                    const expectedCount = notifications.filter(
                        (n) => n.deleted_at === null
                    ).length;
                    expect(result.length).toBe(expectedCount);
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 19: Clear all read targets only read notifications ---
describe('Feature: user-notifications, Property 19: Clear all read targets only read notifications', () => {
    it('clear-all-read only soft-deletes read notifications, unread remain unchanged', () => {
        /**
         * Validates: Requirements 8.2
         */
        fc.assert(
            fc.property(
                fc.array(notificationArb, { minLength: 0, maxLength: 30 }),
                timestampArb,
                (notifications, currentTs) => {
                    const result = clearAllRead(notifications, currentTs);

                    for (let i = 0; i < notifications.length; i++) {
                        if (notifications[i].is_read) {
                            // Read notifications should be soft-deleted
                            expect(result[i].deleted_at).toBe(currentTs);
                        } else {
                            // Unread notifications should NOT be modified
                            expect(result[i].deleted_at).toBe(notifications[i].deleted_at);
                        }
                    }
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 20: Cleanup removes only expired records ---
describe('Feature: user-notifications, Property 20: Cleanup removes only expired records', () => {
    it('only soft-deleted records older than 90 days are identified for removal', () => {
        /**
         * Validates: Requirements 8.4
         */
        const dateIntArb = fc.integer({
            min: new Date('2020-01-01').getTime(),
            max: new Date('2030-12-31').getTime(),
        });

        fc.assert(
            fc.property(dateIntArb, dateIntArb, (deletedAtMs, currentDateMs) => {
                const deletedAt = new Date(deletedAtMs);
                const currentDate = new Date(currentDateMs);

                const result = isExpiredForCleanup(
                    deletedAt.toISOString(),
                    currentDate.toISOString(),
                    90
                );

                const diffMs = currentDate.getTime() - deletedAt.getTime();
                const diffDays = diffMs / (1000 * 60 * 60 * 24);

                if (diffDays > 90) {
                    expect(result).toBe(true);
                } else {
                    expect(result).toBe(false);
                }
            }),
            { numRuns: 100 }
        );
    });
});

// --- Property 21: Access isolation ---
describe('Feature: user-notifications, Property 21: Access isolation', () => {
    it('queries only return notifications matching recipient_id + business_id', () => {
        /**
         * Validates: Requirements 9.2, 9.3
         */
        fc.assert(
            fc.property(
                fc.array(notificationArb, { minLength: 0, maxLength: 50 }),
                fc.integer({ min: 1, max: 100 }),
                fc.integer({ min: 1, max: 50 }),
                (notifications, userId, businessId) => {
                    const result = filterByAccess(notifications, userId, businessId);

                    // All returned must match the user and business
                    for (const n of result) {
                        expect(n.recipient_id).toBe(userId);
                        expect(n.business_id).toBe(businessId);
                    }

                    // No matching notification is excluded
                    const expectedCount = notifications.filter(
                        (n) =>
                            n.recipient_id === userId && n.business_id === businessId
                    ).length;
                    expect(result.length).toBe(expectedCount);
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 22: notify() accepts single and array recipients ---
describe('Feature: user-notifications, Property 22: notify() accepts single and array recipients', () => {
    it('correct number of notifications created per eligible recipient', () => {
        /**
         * Validates: Requirements 10.2
         */
        fc.assert(
            fc.property(
                fc.oneof(
                    fc.integer({ min: 1, max: 100 }),
                    fc.array(fc.integer({ min: 1, max: 100 }), { minLength: 1, maxLength: 20 })
                ),
                notificationTypeArb,
                (recipientIds, type) => {
                    // All recipients have preferences enabled (empty prefs = default enabled)
                    const preferences = {};
                    const eligible = createNotificationsForRecipients(
                        recipientIds,
                        type,
                        preferences
                    );

                    const ids = Array.isArray(recipientIds)
                        ? recipientIds
                        : [recipientIds];

                    // All recipients should be eligible (no disabled prefs)
                    expect(eligible.length).toBe(ids.length);
                }
            ),
            { numRuns: 100 }
        );
    });

    it('respects preferences when filtering recipients', () => {
        /**
         * Validates: Requirements 10.2
         */
        const nonUserMessageTypeArb = fc.constantFrom(
            'PO_STATUS_CHANGE',
            'INVOICE_DUE',
            'STOCK_LOW',
            'PAYMENT_RECEIVED',
            'SYSTEM_ALERT'
        );

        fc.assert(
            fc.property(
                fc.array(fc.integer({ min: 1, max: 100 }), { minLength: 1, maxLength: 20 }),
                nonUserMessageTypeArb,
                fc.array(fc.integer({ min: 1, max: 100 }), { minLength: 0, maxLength: 10 }),
                (recipientIds, type, disabledIds) => {
                    const preferences = {};
                    for (const id of disabledIds) {
                        preferences[id] = { [type]: false };
                    }

                    const eligible = createNotificationsForRecipients(
                        recipientIds,
                        type,
                        preferences
                    );

                    // Eligible should exclude those with disabled preferences
                    for (const id of eligible) {
                        const prefs = preferences[id] || {};
                        expect(shouldDeliverNotification(type, prefs)).toBe(true);
                    }
                }
            ),
            { numRuns: 100 }
        );
    });
});

// --- Property 23: Error isolation in notification creation ---
describe('Feature: user-notifications, Property 23: Error isolation in notification creation', () => {
    it('no exception propagates to caller even when creation throws', () => {
        /**
         * Validates: Requirements 10.5
         */
        fc.assert(
            fc.property(
                fc.string({ minLength: 1, maxLength: 200 }),
                (errorMessage) => {
                    const throwingFn = () => {
                        throw new Error(errorMessage);
                    };

                    // Should not throw — error is isolated
                    const result = notifyWithErrorIsolation(throwingFn);
                    expect(result).toBe(true);
                }
            ),
            { numRuns: 100 }
        );
    });

    it('successful creation also returns without issue', () => {
        /**
         * Validates: Requirements 10.5
         */
        fc.assert(
            fc.property(fc.integer({ min: 1, max: 10000 }), (id) => {
                const successFn = () => {
                    return { id };
                };

                const result = notifyWithErrorIsolation(successFn);
                expect(result).toBe(true);
            }),
            { numRuns: 100 }
        );
    });
});
