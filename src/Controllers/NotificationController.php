<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\NotificationService;

class NotificationController extends BaseController {

    private NotificationService $service;

    public function __construct() {
        $this->service = new NotificationService(Database::getConnection());
    }

    /**
     * GET /notifications/poll
     * Returns notifications since a given timestamp and unread count.
     */
    public function poll(): void {
        try {
            $ctx = $this->auth();

            $since = $_GET['since'] ?? null;
            if (!$since) {
                $this->json(false, null, 'Missing required query parameter: since', 400);
                return;
            }

            // Validate timestamp format
            $timestamp = strtotime($since);
            if ($timestamp === false) {
                $this->json(false, null, 'Invalid timestamp format for "since" parameter', 400);
                return;
            }

            // Tenant owners without a business context get an empty poll result
            if ($ctx->business_id === null) {
                $this->json(true, ['notifications' => [], 'unread_count' => 0]);
                return;
            }

            $result = $this->service->pollSince($ctx->user_id, $ctx->business_id, $since);
            $this->json(true, $result);
        } catch (\Throwable $e) {
            $this->serverError('Failed to poll notifications');
        }
    }

    /**
     * GET /notifications
     * Returns paginated list of notifications with optional filters.
     */
    public function index(): void {
        try {
            $ctx = $this->auth();

            $page    = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));

            $filters = [];
            if (isset($_GET['type']) && $_GET['type'] !== '') {
                $filters['type'] = $_GET['type'];
            }
            if (isset($_GET['is_read']) && $_GET['is_read'] !== '') {
                $filters['is_read'] = (int) $_GET['is_read'];
            }

            $result = $this->service->getAll($ctx->user_id, $ctx->business_id, $filters, $page, $perPage);
            $this->json(true, $result);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch notifications');
        }
    }

    /**
     * GET /notifications/unread-count
     * Returns the count of unread notifications.
     */
    public function unreadCount(): void {
        try {
            $ctx = $this->auth();

            $count = $this->service->getUnreadCount($ctx->user_id, $ctx->business_id);
            $this->json(true, ['unread_count' => $count]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch unread count');
        }
    }

    /**
     * PATCH /notifications/{id}/read
     * Marks a single notification as read.
     */
    public function markAsRead(int $id): void {
        try {
            $ctx = $this->auth();

            $this->service->markAsRead($id, $ctx->user_id, $ctx->business_id);
            $this->json(true, ['id' => $id]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to mark notification as read');
        }
    }

    /**
     * PATCH /notifications/read-all
     * Marks all notifications as read for the current user.
     */
    public function markAllAsRead(): void {
        try {
            $ctx = $this->auth();

            $this->service->markAllAsRead($ctx->user_id, $ctx->business_id);
            $this->json(true);
        } catch (\Throwable $e) {
            $this->serverError('Failed to mark all notifications as read');
        }
    }

    /**
     * PATCH /notifications/read-batch
     * Marks a batch of notifications as read.
     */
    public function markBatchAsRead(): void {
        try {
            $ctx = $this->auth();

            $data = $this->input(['ids']);
            $ids = $data['ids'];

            if (!is_array($ids) || empty($ids)) {
                $this->json(false, null, 'Field "ids" must be a non-empty array', 400);
                return;
            }

            // Sanitize IDs to integers
            $ids = array_map('intval', $ids);

            $this->service->markBatchAsRead($ids, $ctx->user_id, $ctx->business_id);
            $this->json(true);
        } catch (\Throwable $e) {
            $this->serverError('Failed to mark notifications as read');
        }
    }

    /**
     * POST /notifications/send
     * Sends a user-to-user notification.
     */
    public function send(): void {
        try {
            $ctx = $this->auth();

            $data = $this->input(['recipient_id', 'title', 'body']);

            $recipientId = (int) $data['recipient_id'];
            $title       = $data['title'];
            $body        = $data['body'];

            $this->service->sendUserMessage(
                $ctx->user_id,
                $recipientId,
                $title,
                $body,
                $ctx->business_id
            );

            $this->json(true, null, null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to send notification');
        }
    }

    /**
     * DELETE /notifications/{id}
     * Soft-deletes a single notification.
     */
    public function delete(int $id): void {
        try {
            $ctx = $this->auth();

            $this->service->softDelete($id, $ctx->user_id, $ctx->business_id);
            $this->json(true, ['id' => $id]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete notification');
        }
    }

    /**
     * DELETE /notifications/read
     * Soft-deletes all read notifications for the current user.
     */
    public function clearAllRead(): void {
        try {
            $ctx = $this->auth();

            $this->service->clearAllRead($ctx->user_id, $ctx->business_id);
            $this->json(true);
        } catch (\Throwable $e) {
            $this->serverError('Failed to clear read notifications');
        }
    }

    /**
     * GET /notifications/preferences
     * Returns notification preferences for the current user.
     */
    public function preferences(): void {
        try {
            $ctx = $this->auth();

            $preferences = $this->service->getPreferences($ctx->user_id, $ctx->business_id);
            $this->json(true, ['preferences' => $preferences]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch notification preferences');
        }
    }

    /**
     * PUT /notifications/preferences
     * Updates notification preferences for the current user.
     */
    public function updatePreferences(): void {
        try {
            $ctx = $this->auth();

            $data = $this->input(['preferences']);
            $preferences = $data['preferences'];

            if (!is_array($preferences)) {
                $this->json(false, null, 'Field "preferences" must be an array', 400);
                return;
            }

            $this->service->updatePreferences($ctx->user_id, $ctx->business_id, $preferences);
            $this->json(true);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update notification preferences');
        }
    }
}
