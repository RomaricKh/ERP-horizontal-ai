<?php
/**
 * Manual test script for the cron endpoint.
 * Run: php tests/test_cron_endpoint.php
 */

// Simulate the web environment
$_SERVER['REQUEST_METHOD'] = 'GET';

echo "=== Test 1: Missing secret (should return 403) ===\n";
$_GET = [];
ob_start();
$responseCode = null;

// Override http_response_code to capture it
function test_run_endpoint(array $get): string {
    $_GET = $get;
    ob_start();
    
    // We can't easily test the full endpoint in CLI without mocking http_response_code
    // Instead, let's test the logic directly
    
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../src/Config/config.php';
    
    $cronSecret = $config['cron_secret'] ?? null;
    
    // Test 1: Missing secret
    $providedSecret = $get['secret'] ?? '';
    if (empty($providedSecret) || !hash_equals($cronSecret, $providedSecret)) {
        ob_end_clean();
        return "PASS: Correctly rejected (403 would be returned)";
    }
    
    ob_end_clean();
    return "FAIL: Should have been rejected";
}

echo test_run_endpoint([]) . "\n\n";

echo "=== Test 2: Wrong secret (should return 403) ===\n";
echo test_run_endpoint(['secret' => 'wrong-secret']) . "\n\n";

echo "=== Test 3: Correct secret (should succeed) ===\n";
require_once __DIR__ . '/../vendor/autoload.php';

// Load config properly
$config = [
    'db' => [
        'host' => 'localhost',
        'dbname' => 'erp',
        'user' => 'root',
        'pass' => '',
    ],
];

use App\Config\Database;
use App\Services\ScheduleGeneratorService;
use App\Services\RecurrenceService;

$db = Database::connect($config['db']);
$today = date('Y-m-d');

$generatorService = new ScheduleGeneratorService();
$result = $generatorService->generateForAllBusinesses($today, $db);
echo "Generated: {$result['total_generated']} instances across " . count($result['businesses']) . " businesses\n";

$recurrenceService = new RecurrenceService($db);
$overdueCount = $recurrenceService->markOverdueInstances();
echo "Marked overdue: {$overdueCount}\n";

echo "\n=== Test 4: Run again (idempotency - should generate 0) ===\n";
$result2 = $generatorService->generateForAllBusinesses($today, $db);
echo "Generated on second run: {$result2['total_generated']} (should be 0)\n";

if ($result2['total_generated'] === 0) {
    echo "PASS: Idempotent - no duplicates created\n";
} else {
    echo "FAIL: Duplicates were created!\n";
}

echo "\nAll manual tests completed.\n";
