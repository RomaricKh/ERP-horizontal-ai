<?php
/**
 * Cron endpoint: Generate recurrence instances and mark overdue.
 *
 * Protected by CRON_SECRET query parameter.
 * Returns JSON summary of operations performed.
 *
 * Usage:
 *   curl "http://localhost/ERP-horizontal-ai/public/api/cron/generate-recurrences.php?secret=YOUR_CRON_SECRET"
 */

// Bootstrap the application (autoload + config + DB)
require_once __DIR__ . '/../../../bootstrap.php';

use App\Config\Database;
use App\Services\ScheduleGeneratorService;
use App\Services\RecurrenceService;
use App\Support\AuthContext;
use PDO;

header('Content-Type: application/json');

// ─── Authenticate via CRON_SECRET ────────────────────────────────────────────

$cronSecret = getenv('CRON_SECRET');
if (!$cronSecret) {
    // Fallback: check config array for cron_secret key
    $cronSecret = $config['cron_secret'] ?? null;
}

if (empty($cronSecret)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'CRON_SECRET is not configured on the server.',
    ]);
    exit;
}

$providedSecret = $_GET['secret'] ?? '';

if (empty($providedSecret) || !hash_equals($cronSecret, $providedSecret)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Forbidden: invalid or missing CRON_SECRET.',
    ]);
    exit;
}

// ─── Execute cron tasks ──────────────────────────────────────────────────────

try {
    $db = Database::getConnection();
    $today = date('Y-m-d');

    // Task 1: Generate instances for all active schedules up to 30 days in advance
    $generatorService = new ScheduleGeneratorService();
    $lookaheadDate = date('Y-m-d', strtotime('+30 days'));
    $generateResult = $generatorService->generateForAllBusinesses($lookaheadDate, $db);

    // Task 2: Mark overdue instances (pending with due_date < today)
    $recurrenceService = new RecurrenceService($db);
    $overdueCount = $recurrenceService->markOverdueInstances();

    // Task 3: Send overdue reminders for all businesses
    // (iterates all distinct businesses with overdue instances)
    $overdueReminderCount = 0;
    $businessIds = getDistinctBusinessIdsWithInstances($db);
    foreach ($businessIds as $businessId) {
        setBusinessContext($businessId);
        $recurrenceService->sendOverdueReminders();
        $overdueReminderCount++;
    }

    // Task 4: Send due notifications for pending instances due within 3 days
    // (iterates all businesses, only sends if notification_sent = 0)
    $dueNotificationCount = 0;
    foreach ($businessIds as $businessId) {
        setBusinessContext($businessId);
        $recurrenceService->sendDueNotifications();
        $dueNotificationCount++;
    }

    // Task 5: Expense recognition for active prepaid assets
    // (iterates all businesses with active prepaid assets)
    $recognizedEntries = [];
    $businessIdsWithAssets = getDistinctBusinessIdsWithPrepaidAssets($db);
    foreach ($businessIdsWithAssets as $businessId) {
        setBusinessContext($businessId);
        $entries = $recurrenceService->performExpenseRecognition();
        $recognizedEntries = array_merge($recognizedEntries, $entries);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Cron job completed successfully.',
        'data' => [
            'generated' => $generateResult['total_generated'],
            'businesses_affected' => count($generateResult['businesses']),
            'marked_overdue' => $overdueCount,
            'overdue_reminders_businesses' => $overdueReminderCount,
            'due_notifications_businesses' => $dueNotificationCount,
            'expense_recognitions' => count($recognizedEntries),
            'run_date' => $today,
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Cron job failed: ' . $e->getMessage(),
    ]);
}

// ─── Helper functions ────────────────────────────────────────────────────────

/**
 * Set a synthetic AuthContext for a given business (cron usage).
 * Creates a minimal context object with the business_id set.
 */
function setBusinessContext(int $businessId): void
{
    AuthContext::set((object) [
        'user_id'     => 0,   // system/cron user
        'tenant_id'   => 0,
        'business_id' => $businessId,
    ]);
}

/**
 * Get all distinct business_ids from recurrence_instances table.
 * Used to iterate businesses for notifications.
 */
function getDistinctBusinessIdsWithInstances(PDO $db): array
{
    $stmt = $db->query("
        SELECT DISTINCT business_id
        FROM recurrence_instances
        WHERE status IN ('pending', 'overdue')
    ");
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Get all distinct business_ids from prepaid_assets table that have active assets.
 * Used to iterate businesses for expense recognition.
 */
function getDistinctBusinessIdsWithPrepaidAssets(PDO $db): array
{
    $stmt = $db->query("
        SELECT DISTINCT business_id
        FROM prepaid_assets
        WHERE status = 'active'
    ");
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
