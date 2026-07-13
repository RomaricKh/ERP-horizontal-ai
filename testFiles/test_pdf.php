<?php
require_once __DIR__ . '/bootstrap.php';

$controller = new \App\Controllers\PayeeStatementController();
$_GET['start_date'] = '2026-01-01';
$_GET['end_date'] = '2026-06-28';

// We need to bypass auth. Let's just mock the auth context.
// Actually it's easier to mock it directly in the controller or just simulate the $_SERVER headers.
// Or even simpler: let's test if the PDF generation fails!
$businessId = 1;
$payeeId = 19;
$db = \App\Config\Database::getConnection();

$nameStmt = $db->prepare("SELECT name FROM people WHERE id = (SELECT people_id FROM payees WHERE id = :id LIMIT 1) LIMIT 1");
$nameStmt->execute(['id' => $payeeId]);
$payeeName = $nameStmt->fetchColumn() ?: 'Unknown';

$bizStmt = $db->prepare("SELECT * FROM business_profiles WHERE id = :bid LIMIT 1");
$bizStmt->execute(['bid' => $businessId]);
$business = $bizStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

$transactions = []; // empty for test
$openingBalance = 0;
$closingBalance = 0;

$html = \App\Templates\StatementPdf::render(
    $payeeName,
    $_GET['start_date'],
    $_GET['end_date'],
    $openingBalance,
    $closingBalance,
    $transactions,
    $business
);

try {
    $pdfService = new \App\Services\PdfService();
    $pdf = $pdfService->generateRaw($html);
    echo "PDF size: " . strlen($pdf) . " bytes\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
