<?php

namespace App\Templates;

class InvoicePdf
{
    /**
     * Render the invoice PDF HTML template.
     *
     * @param array $invoice  Invoice header data (invoice_number, invoice_date, status, currency, notes, etc.)
     * @param array $lines    Array of line items (item_name, quantity, unit_price_fc, total_price_fc, tax_rate, tax_amount_fc)
     * @param array $business Business info (name, legal_name, address, phone, email)
     * @param array $customer Customer info (name, address, phone, email)
     * @return string Rendered HTML
     */
    public static function render(array $invoice, array $lines, array $business, array $customer): string
    {
        $invoiceNumber = htmlspecialchars($invoice['invoice_number'] ?? '');
        $invoiceDate   = htmlspecialchars($invoice['invoice_date'] ?? '');
        $status        = htmlspecialchars($invoice['status'] ?? '');
        $currency      = htmlspecialchars($invoice['currency'] ?? 'USD');
        $notes         = htmlspecialchars($invoice['notes'] ?? '');
        $dueDate       = htmlspecialchars($invoice['due_date'] ?? '');

        $businessName  = htmlspecialchars($business['name'] ?? $business['legal_name'] ?? '');
        $businessAddr  = htmlspecialchars($business['address'] ?? '');
        $businessPhone = htmlspecialchars($business['phone'] ?? '');
        $businessEmail = htmlspecialchars($business['email'] ?? '');

        $customerName  = htmlspecialchars($customer['name'] ?? '');
        $customerAddr  = htmlspecialchars($customer['address'] ?? '');
        $customerPhone = htmlspecialchars($customer['phone'] ?? '');
        $customerEmail = htmlspecialchars($customer['email'] ?? '');

        // Calculate totals
        $subtotal = 0;
        $taxTotal = 0;
        foreach ($lines as $line) {
            $lineTotal = (float)($line['total_price_fc'] ?? 0);
            $lineTax   = (float)($line['tax_amount_fc'] ?? 0);
            $subtotal += $lineTotal;
            $taxTotal += $lineTax;
        }
        $grandTotal = $subtotal + $taxTotal;

        // Build line items rows
        $lineRows = '';
        $rowNum = 1;
        foreach ($lines as $line) {
            $itemName  = htmlspecialchars($line['item_name'] ?? $line['service_name'] ?? 'Item');
            $qty       = number_format((float)($line['quantity'] ?? 0), 2);
            $unitPrice = number_format((float)($line['unit_price_fc'] ?? 0), 2);
            $taxRate   = number_format((float)($line['tax_rate'] ?? 0), 2);
            $taxAmt    = number_format((float)($line['tax_amount_fc'] ?? 0), 2);
            $lineTotal = number_format((float)($line['total_price_fc'] ?? 0), 2);

            $lineRows .= "
            <tr>
                <td style=\"text-align:center;\">{$rowNum}</td>
                <td>{$itemName}</td>
                <td style=\"text-align:right;\">{$qty}</td>
                <td style=\"text-align:right;\">{$unitPrice}</td>
                <td style=\"text-align:right;\">{$taxRate}%</td>
                <td style=\"text-align:right;\">{$taxAmt}</td>
                <td style=\"text-align:right;\">{$lineTotal}</td>
            </tr>";
            $rowNum++;
        }

        $subtotalFmt   = number_format($subtotal, 2);
        $taxTotalFmt   = number_format($taxTotal, 2);
        $grandTotalFmt = number_format($grandTotal, 2);

        $notesHtml = $notes
            ? "<div class=\"notes\"><strong>Notes:</strong><br>{$notes}</div>"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice {$invoiceNumber}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
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
        .invoice-meta {
            margin-bottom: 20px;
        }
        .invoice-meta table {
            width: 100%;
        }
        .invoice-meta td {
            vertical-align: top;
            width: 50%;
        }
        .invoice-meta .label {
            font-weight: bold;
            color: #555;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #4e73df;
            margin: 15px 0 8px 0;
            border-bottom: 1px solid #ddd;
            padding-bottom: 3px;
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
            font-size: 11px;
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
            padding: 5px 10px;
        }
        .totals-table .total-row {
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #4e73df;
        }
        .notes {
            margin-top: 20px;
            padding: 10px;
            background-color: #f8f9fc;
            border-left: 3px solid #4e73df;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
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

    <div class="invoice-meta">
        <table>
            <tr>
                <td>
                    <div class="section-title">Invoice Details</div>
                    <p><span class="label">Invoice #:</span> {$invoiceNumber}</p>
                    <p><span class="label">Date:</span> {$invoiceDate}</p>
                    <p><span class="label">Due Date:</span> {$dueDate}</p>
                    <p><span class="label">Status:</span> {$status}</p>
                    <p><span class="label">Currency:</span> {$currency}</p>
                </td>
                <td>
                    <div class="section-title">Bill To</div>
                    <p><strong>{$customerName}</strong></p>
                    <p>{$customerAddr}</p>
                    <p>Phone: {$customerPhone}</p>
                    <p>Email: {$customerEmail}</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">Line Items</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="text-align:center;width:30px;">#</th>
                <th>Item / Service</th>
                <th style="text-align:right;">Qty</th>
                <th style="text-align:right;">Unit Price</th>
                <th style="text-align:right;">Tax Rate</th>
                <th style="text-align:right;">Tax Amount</th>
                <th style="text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            {$lineRows}
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td style="text-align:right;"><strong>Subtotal:</strong></td>
            <td style="text-align:right;">{$currency} {$subtotalFmt}</td>
        </tr>
        <tr>
            <td style="text-align:right;"><strong>Tax:</strong></td>
            <td style="text-align:right;">{$currency} {$taxTotalFmt}</td>
        </tr>
        <tr class="total-row">
            <td style="text-align:right;"><strong>Grand Total:</strong></td>
            <td style="text-align:right;">{$currency} {$grandTotalFmt}</td>
        </tr>
    </table>

    {$notesHtml}

    <div class="footer">
        Generated on {$invoiceDate} | {$businessName}
    </div>
</body>
</html>
HTML;
    }
}
