<?php

use App\Controllers\NotificationController;

/* =====================================================================
 * STATIC ROUTES — must be registered BEFORE /{id} dynamic routes
 * ===================================================================== */

// GET /notifications/poll
$router->get('/notifications/poll',
    fn() => (new NotificationController())->poll(),
    ['notifications.view']
);

// GET /notifications/unread-count
$router->get('/notifications/unread-count',
    fn() => (new NotificationController())->unreadCount(),
    ['notifications.view']
);

// PATCH /notifications/read-all
$router->patch('/notifications/read-all',
    fn() => (new NotificationController())->markAllAsRead(),
    ['notifications.view']
);

// PATCH /notifications/read-batch
$router->patch('/notifications/read-batch',
    fn() => (new NotificationController())->markBatchAsRead(),
    ['notifications.view']
);

// POST /notifications/send
$router->post('/notifications/send',
    fn() => (new NotificationController())->send(),
    ['notifications.send']
);

// DELETE /notifications/read
$router->delete('/notifications/read',
    fn() => (new NotificationController())->clearAllRead(),
    ['notifications.view']
);

// GET /notifications/preferences
$router->get('/notifications/preferences',
    fn() => (new NotificationController())->preferences(),
    ['notifications.view']
);

// PUT /notifications/preferences
$router->put('/notifications/preferences',
    fn() => (new NotificationController())->updatePreferences(),
    ['notifications.view']
);

/* =====================================================================
 * DYNAMIC ROUTES
 * ===================================================================== */

// GET /notifications
$router->get('/notifications',
    fn() => (new NotificationController())->index(),
    ['notifications.view']
);

// PATCH /notifications/{id}/read
$router->patch('/notifications/{id}/read',
    fn($id) => (new NotificationController())->markAsRead((int)$id),
    ['notifications.view']
);

// DELETE /notifications/{id}
$router->delete('/notifications/{id}',
    fn($id) => (new NotificationController())->delete((int)$id),
    ['notifications.view']
);
