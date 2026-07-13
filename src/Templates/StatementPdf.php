<?php

namespace App\Templates;

class StatementPdf
{
    /**
     * Render the statement of account PDF HTML template.
     *
     * @param string $payeeName      Name of the payee
     * @param string $startDate      Start date
     * @param string $endDate        End date
     * @param float $openingBalance  Opening balance
     * @param float $closingBalance  Closing balance
     * @param array $transactions    List of transactions
     * @param array $business        Business info (name, legal_name, address, phone, email)
     * @return string Rendered HTML
     */
    public static function render(
        string $payeeName,
        string $startDate,
        string $endDate,
        float $openingBalance,
        float $closingBalance,
        array $transactions,
        array $business
    ): string {
        $businessName  = htmlspecialchars($business['name'] ?? $business['legal_name'] ?? '');
        $businessAddr  = htmlspecialchars($business['address'] ?? '');
        $businessPhone = htmlspecialchars($business['phone'] ?? '');
        $businessEmail = htmlspecialchars($business['email'] ?? '');

        // Build transaction rows
        $lineRows = '';
        foreach ($transactions as $txn) {
            $date = htmlspecialchars($txn['date'] ?? '');
            $type = htmlspecialchars(ucwords(str_replace('_', ' ', $txn['type'] ?? '')));
            $ref  = htmlspecialchars($txn['reference_number'] ?? '-');
            $desc = htmlspecialchars($txn['description'] ?? '-');
            
            $original = number_format((float)($txn['original_amount'] ?? 0), 2, '.', '');
            $currency = htmlspecialchars($txn['currency'] ?? '');
            $originalDisplay = (float)($txn['original_amount'] ?? 0) != 0 ? trim("$original $currency") : '-';

            $debit = number_format((float)($txn['debit_amount'] ?? 0), 2, '.', '');
            $credit = number_format((float)($txn['credit_amount'] ?? 0), 2, '.', '');
            $balance = number_format((float)($txn['running_balance'] ?? 0), 2, '.', '');
            
            $debitDisplay = (float)($txn['debit_amount'] ?? 0) != 0 ? $debit : '-';
            $creditDisplay = (float)($txn['credit_amount'] ?? 0) != 0 ? $credit : '-';

            $lineRows .= "
            <tr>
                <td>{$date}</td>
                <td>{$type}</td>
                <td>{$ref}</td>
                <td>{$desc}</td>
                <td style=\"text-align:right;\">{$originalDisplay}</td>
                <td style=\"text-align:right; color:#e74a3b;\">{$debitDisplay}</td>
                <td style=\"text-align:right; color:#1cc88a;\">{$creditDisplay}</td>
                <td style=\"text-align:right; font-weight:bold;\">{$balance}</td>
            </tr>";
        }

        $openingBalanceFmt = number_format($openingBalance, 2, '.', '');
        $closingBalanceFmt = number_format($closingBalance, 2, '.', '');
        
        $closingColor = $closingBalance < 0 ? '#e74a3b' : ($closingBalance > 0 ? '#1cc88a' : '#333');
        $generatedOn = date('Y-m-d H:i:s');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Statement of Account - {$payeeName}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .header {
            border-bottom: 2px solid #4e73df;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #4e73df;
            margin: 0 0 5px 0;
            font-size: 24px;
        }
        .header .business-info {
            font-size: 11px;
            color: #666;
        }
        .meta {
            margin-bottom: 20px;
        }
        .meta table {
            width: 100%;
        }
        .meta td {
            vertical-align: top;
            width: 50%;
        }
        .meta .label {
            font-weight: bold;
            color: #555;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th {
            background-color: #4e73df;
            color: #fff;
            padding: 8px 6px;
            text-align: left;
            font-size: 10px;
        }
        .items-table td {
            padding: 6px;
            border-bottom: 1px solid #eee;
        }
        .items-table tr:nth-child(even) {
            background-color: #f8f9fc;
        }
        .totals-table {
            width: 300px;
            margin-left: auto;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 6px;
            text-align: right;
            border-bottom: 1px solid #eee;
        }
        .totals-table .totals-label {
            font-weight: bold;
            color: #555;
        }
        .totals-table .grand-total {
            font-size: 14px;
            font-weight: bold;
            color: {$closingColor};
            border-top: 2px solid #333;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }
    </style>
</head>
<body>

    <div class="header">
        <table style="width: 100%;">
            <tr>
                <td>
                    <h1>Statement of Account</h1>
                    <div><strong>{$payeeName}</strong></div>
                    <div style="color: #666; margin-top: 4px;">Period: {$startDate} to {$endDate}</div>
                </td>
                <td style="text-align: right;">
                    <strong>{$businessName}</strong><br>
                    <div class="business-info">
                        {$businessAddr}<br>
                        {$businessPhone}<br>
                        {$businessEmail}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="meta">
        <table class="totals-table" style="margin-left: 0; width: auto; float: left; border: 1px solid #eee;">
            <tr>
                <td class="totals-label" style="text-align: left; background-color: #f8f9fc; padding: 10px;">Opening Balance</td>
                <td style="font-weight: bold; font-size: 14px; padding: 10px;">{$openingBalanceFmt}</td>
            </tr>
        </table>
        <div style="clear: both;"></div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Reference</th>
                <th>Description</th>
                <th style="text-align:right;">Original</th>
                <th style="text-align:right;">Debit</th>
                <th style="text-align:right;">Credit</th>
                <th style="text-align:right;">Running Balance</th>
            </tr>
        </thead>
        <tbody>
            {$lineRows}
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td class="totals-label">Closing Balance</td>
            <td class="grand-total">{$closingBalanceFmt}</td>
        </tr>
    </table>

    <div class="footer">
        Generated on: {$generatedOn} | Statement Period: {$startDate} to {$endDate}
    </div>

</body>
</html>
HTML;
    }
}
