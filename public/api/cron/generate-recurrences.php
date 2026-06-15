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

    // Task 1: Generate instances for all active schedules up to today
    $generatorService = new ScheduleGeneratorService();
    $generateResult = $generatorService->generateForAllBusinesses($today, $db);

    // Task 2: Mark overdue instances (pending with due_date < today)
    $recurrenceService = new RecurrenceService($db);
    $overdueCount = $recurrenceService->markOverdueInstances();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Cron job completed successfully.',
        'data' => [
            'generated' => $generateResult['total_generated'],
            'businesses_affected' => count($generateResult['businesses']),
            'marked_overdue' => $overdueCount,
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
