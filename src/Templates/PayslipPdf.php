<?php

namespace App\Templates;

class PayslipPdf
{
    /**
     * Render the payslip PDF HTML template.
     *
     * @param array $salary   Salary record data (employee_name, period_start, period_end, currency, etc.)
     * @param array $business Business info (name, legal_name, address, phone, email)
     * @return string Rendered HTML
     */
    public static function render(array $salary, array $business): string
    {
        $employeeName  = htmlspecialchars($salary['employee_name'] ?? 'Employee');
        $periodStart   = htmlspecialchars($salary['period_start'] ?? '');
        $periodEnd     = htmlspecialchars($salary['period_end'] ?? '');
        $currency      = htmlspecialchars($salary['currency'] ?? 'USD');
        $exchangeRate  = rtrim(rtrim(number_format((float)($salary['exchange_rate'] ?? 1), 12), '0'), '.');
        $notes         = htmlspecialchars($salary['notes'] ?? '');

        $grossFc = number_format((float)($salary['gross_amount_fc'] ?? 0), 2);
        $grossBc = number_format((float)($salary['gross_amount_bc'] ?? 0), 2);
        $taxFc   = number_format((float)($salary['tax_fc'] ?? 0), 2);
        $taxBc   = number_format((float)($salary['tax_bc'] ?? 0), 2);
        $netFc   = number_format((float)($salary['net_amount_fc'] ?? 0), 2);
        $netBc   = number_format((float)($salary['net_amount_bc'] ?? 0), 2);

        $businessName  = htmlspecialchars($business['name'] ?? $business['legal_name'] ?? '');
        $businessAddr  = htmlspecialchars($business['address'] ?? '');
        $businessPhone = htmlspecialchars($business['phone'] ?? '');
        $businessEmail = htmlspecialchars($business['email'] ?? '');

        $generatedDate = date('Y-m-d');

        $notesHtml = $notes
            ? "<div class=\"notes\"><strong>Notes:</strong><br>{$notes}</div>"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payslip - {$employeeName} ({$periodStart} to {$periodEnd})</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .header {
            border-bottom: 2px solid #36b9cc;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #36b9cc;
            margin: 0 0 5px 0;
            font-size: 24px;
        }
        .header .business-info {
            font-size: 11px;
            color: #666;
        }
        .payslip-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin: 20px 0;
            padding: 10px;
            background-color: #f8f9fc;
            border: 1px solid #e3e6f0;
        }
        .meta-table {
            width: 100%;
            margin-bottom: 20px;
        }
        .meta-table td {
            vertical-align: top;
            width: 50%;
            padding: 5px 0;
        }
        .label {
            font-weight: bold;
            color: #555;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #36b9cc;
            margin: 20px 0 10px 0;
            border-bottom: 1px solid #ddd;
            padding-bottom: 3px;
        }
        .earnings-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .earnings-table th {
            background-color: #36b9cc;
            color: #fff;
            padding: 10px 8px;
            text-align: left;
            font-size: 11px;
        }
        .earnings-table td {
            padding: 8px;
            border-bottom: 1px solid #eee;
        }
        .earnings-table tr:nth-child(even) {
            background-color: #f8f9fc;
        }
        .earnings-table .total-row {
            font-weight: bold;
            background-color: #d1ecf1 !important;
            border-top: 2px solid #36b9cc;
        }
        .summary-box {
            width: 300px;
            margin-left: auto;
            border: 2px solid #36b9cc;
            border-radius: 5px;
            overflow: hidden;
        }
        .summary-box .box-header {
            background-color: #36b9cc;
            color: #fff;
            padding: 8px 12px;
            font-weight: bold;
            text-align: center;
        }
        .summary-box .box-body {
            padding: 12px;
        }
        .summary-box .box-body table {
            width: 100%;
        }
        .summary-box .box-body td {
            padding: 4px 0;
        }
        .summary-box .net-pay {
            font-size: 16px;
            font-weight: bold;
            color: #1cc88a;
            border-top: 1px solid #ddd;
            padding-top: 8px;
            margin-top: 5px;
        }
        .notes {
            margin-top: 20px;
            padding: 10px;
            background-color: #f8f9fc;
            border-left: 3px solid #36b9cc;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .confidential {
            text-align: center;
            font-size: 9px;
            color: #e74a3b;
            margin-top: 10px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{$businessName}</h1>
        <div class="business-info">
            {$businessAddr}<br>
            Phone: {$businessPhone} | Email: {$businessEmail}
        </div>
    </div>

    <div class="payslip-title">PAYSLIP</div>

    <table class="meta-table">
        <tr>
            <td>
                <p><span class="label">Employee:</span> {$employeeName}</p>
                <p><span class="label">Pay Period:</span> {$periodStart} to {$periodEnd}</p>
            </td>
            <td>
                <p><span class="label">Currency:</span> {$currency}</p>
                <p><span class="label">Exchange Rate:</span> {$exchangeRate}</p>
                <p><span class="label">Date Generated:</span> {$generatedDate}</p>
            </td>
        </tr>
    </table>

    <div class="section-title">Earnings &amp; Deductions</div>
    <table class="earnings-table">
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align:right;">Amount ({$currency})</th>
                <th style="text-align:right;">Amount (BC)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Gross Salary</td>
                <td style="text-align:right;">{$grossFc}</td>
                <td style="text-align:right;">{$grossBc}</td>
            </tr>
            <tr>
                <td>Tax Deductions</td>
                <td style="text-align:right;">({$taxFc})</td>
                <td style="text-align:right;">({$taxBc})</td>
            </tr>
            <tr class="total-row">
                <td>Net Pay</td>
                <td style="text-align:right;">{$netFc}</td>
                <td style="text-align:right;">{$netBc}</td>
            </tr>
        </tbody>
    </table>

    <div class="summary-box">
        <div class="box-header">Payment Summary</div>
        <div class="box-body">
            <table>
                <tr>
                    <td><span class="label">Gross Pay:</span></td>
                    <td style="text-align:right;">{$currency} {$grossFc}</td>
                </tr>
                <tr>
                    <td><span class="label">Tax:</span></td>
                    <td style="text-align:right;">- {$currency} {$taxFc}</td>
                </tr>
            </table>
            <div class="net-pay">
                Net Pay: {$currency} {$netFc}
            </div>
        </div>
    </div>

    {$notesHtml}

    <div class="confidential">CONFIDENTIAL — This payslip is intended solely for the named employee.</div>

    <div class="footer">
        Generated on {$generatedDate} | {$businessName}
    </div>
</body>
</html>
HTML;
    }
}
