<?php

namespace App\Controllers;

use App\Config\Database;
use App\Services\PayeeStatementService;
use App\Support\AuthContext;

class PayeeStatementController extends BaseController
{
    private PayeeStatementService $service;

    public function __construct()
    {
        $this->service = new PayeeStatementService(Database::getConnection());
    }

    /**
     * Log an exception with structured context for diagnostics.
     * Format: [People] {method}: {ExceptionClass} - {message} (user={id}, business={id})
     */
    private function logError(string $method, \Throwable $e): void
    {
        try {
            $userId = AuthContext::userId();
        } catch (\Throwable $t) {
            $userId = 'unknown';
        }

        try {
            $businessId = AuthContext::businessId();
        } catch (\Throwable $t) {
            $businessId = 'unknown';
        }

        $class = get_class($e);
        error_log("[People] {$method}: {$class} - {$e->getMessage()} (user={$userId}, business={$businessId})");
        error_log($e->getTraceAsString());
    }

    /**
     * GET /people/{payeeId}/statement?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     */
    public function show(int $payeeId): void
    {
        try {
            $ctx = $this->auth();
            $businessId = $ctx->business_id;

            $startDate = $_GET['start_date'] ?? null;
            $endDate   = $_GET['end_date']   ?? null;

            $result = $this->service->getStatement($payeeId, $businessId, $startDate, $endDate);

            $this->json(true, $result);
        } catch (\InvalidArgumentException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError(__METHOD__, $e);
            $this->serverError('Failed to load statement');
        }
    }

    /**
     * GET /people/{payeeId}/statement/export?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     * Export statement as CSV download.
     */
    public function export(int $payeeId): void
    {
        try {
            $ctx = $this->auth();
            $businessId = $ctx->business_id;

            $startDate = $_GET['start_date'] ?? null;
            $endDate   = $_GET['end_date']   ?? null;

            $result = $this->service->getStatement($payeeId, $businessId, $startDate, $endDate);

            $payeeName      = $result['payee_name'];
            $openingBalance = $result['opening_balance'];
            $transactions   = $result['transactions'];
            $closingBalance = $result['closing_balance'];

            // Generate CSV
            $filename = "statement_{$payeeName}_{$startDate}_{$endDate}.csv";
            $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');

            $output = fopen('php://output', 'w');

            // Header info
            fputcsv($output, ['Statement of Account']);
            fputcsv($output, ['Payee', $payeeName]);
            fputcsv($output, ['Period', $startDate . ' to ' . $endDate]);
            fputcsv($output, ['Opening Balance', number_format($openingBalance, 2, '.', '')]);
            fputcsv($output, []);

            // Column headers
            fputcsv($output, ['Date', 'Type', 'Reference', 'Description', 'Original', 'Debit', 'Credit', 'Running Balance', 'Status']);

            // Data rows
            foreach ($transactions as $txn) {
                $original = number_format((float)($txn['original_amount'] ?? 0), 2, '.', '');
                $currency = $txn['currency'] ?? '';
                $originalDisplay = $original != 0 ? trim("$original $currency") : '';

                $formattedType = ucwords(str_replace('_', ' ', $txn['type']));

                fputcsv($output, [
                    $txn['date'],
                    $formattedType,
                    $txn['reference_number'] ?? '',
                    $txn['description'] ?? '',
                    $originalDisplay,
                    number_format((float)($txn['debit_amount'] ?? 0), 2, '.', ''),
                    number_format((float)($txn['credit_amount'] ?? 0), 2, '.', ''),
                    number_format((float)($txn['running_balance'] ?? 0), 2, '.', ''),
                    $txn['status'] ?? '',
                ]);
            }

            // Footer
            fputcsv($output, []);
            fputcsv($output, ['Closing Balance', '', '', '', '', '', '', number_format($closingBalance, 2, '.', '')]);

            fclose($output);
            exit;
        } catch (\InvalidArgumentException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError(__METHOD__, $e);
            $this->serverError('Failed to export statement');
        }
    }

    /**
     * GET /people/{payeeId}/statement/pdf?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     * Download statement as PDF.
     */
    public function downloadPdf(int $payeeId): void
    {
        try {
            $ctx = $this->auth();
            $businessId = $ctx->business_id;
            $db = \App\Config\Database::getConnection();

            $startDate = $_GET['start_date'] ?? null;
            $endDate   = $_GET['end_date']   ?? null;

            $result = $this->service->getStatement($payeeId, $businessId, $startDate, $endDate);

            $payeeName      = $result['payee_name'];
            $openingBalance = $result['opening_balance'];
            $transactions   = $result['transactions'];
            $closingBalance = $result['closing_balance'];

            // Get Business info for PDF template
            $bizStmt = $db->prepare("SELECT * FROM businesses WHERE id = :bid LIMIT 1");
            $bizStmt->execute(['bid' => $businessId]);
            $business = $bizStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            // Render HTML
            $html = \App\Templates\StatementPdf::render(
                $payeeName,
                $startDate,
                $endDate,
                $openingBalance,
                $closingBalance,
                $transactions,
                $business
            );

            $filename = "Statement_{$payeeName}_{$startDate}_{$endDate}.pdf";
            $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

            $pdfService = new \App\Services\PdfService();
            $pdfService->generate($html, $filename, 'landscape');
            exit;
        } catch (\InvalidArgumentException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError(__METHOD__, $e);
            $this->serverError('Failed to generate PDF');
        }
    }
}
