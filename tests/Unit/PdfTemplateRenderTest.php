<?php

namespace Tests\Unit;

use App\Templates\InvoicePdf;
use App\Templates\PurchaseOrderPdf;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests verifying that PDF templates render correctly with all required data.
 *
 * Validates: Requirements REQ-013
 */
class PdfTemplateRenderTest extends TestCase
{
    private array $business = [
        'name'       => 'Acme Corp',
        'legal_name' => 'Acme Corporation LLC',
        'address'    => '123 Main St, Springfield',
        'phone'      => '+1-555-0100',
        'email'      => 'info@acme.com',
    ];

    // ─── Invoice PDF Tests ───────────────────────────────────────────────

    public function testInvoicePdfRendersBusinessHeader(): void
    {
        $invoice = $this->sampleInvoice();
        $lines   = $this->sampleInvoiceLines();
        $customer = $this->sampleCustomer();

        $html = InvoicePdf::render($invoice, $lines, $this->business, $customer);

        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('123 Main St, Springfield', $html);
        $this->assertStringContainsString('+1-555-0100', $html);
        $this->assertStringContainsString('info@acme.com', $html);
    }

    public function testInvoicePdfRendersCustomerInfo(): void
    {
        $invoice = $this->sampleInvoice();
        $lines   = $this->sampleInvoiceLines();
        $customer = $this->sampleCustomer();

        $html = InvoicePdf::render($invoice, $lines, $this->business, $customer);

        $this->assertStringContainsString('John Doe', $html);
        $this->assertStringContainsString('456 Oak Ave, Shelbyville', $html);
        $this->assertStringContainsString('+1-555-0200', $html);
        $this->assertStringContainsString('john@example.com', $html);
    }

    public function testInvoicePdfRendersInvoiceDetails(): void
    {
        $invoice = $this->sampleInvoice();
        $lines   = $this->sampleInvoiceLines();
        $customer = $this->sampleCustomer();

        $html = InvoicePdf::render($invoice, $lines, $this->business, $customer);

        $this->assertStringContainsString('INV-001', $html);
        $this->assertStringContainsString('2024-01-15', $html);
        $this->assertStringContainsString('DELIVERED', $html);
        $this->assertStringContainsString('USD', $html);
        $this->assertStringContainsString('2024-02-14', $html); // due_date
    }

    public function testInvoicePdfRendersLineItems(): void
    {
        $invoice = $this->sampleInvoice();
        $lines   = $this->sampleInvoiceLines();
        $customer = $this->sampleCustomer();

        $html = InvoicePdf::render($invoice, $lines, $this->business, $customer);

        // Line 1: Widget
        $this->assertStringContainsString('Widget', $html);
        $this->assertStringContainsString('10.00', $html); // quantity
        $this->assertStringContainsString('25.00', $html); // unit price

        // Line 2: Gadget
        $this->assertStringContainsString('Gadget', $html);
        $this->assertStringContainsString('5.00', $html);  // quantity
        $this->assertStringContainsString('50.00', $html); // unit price
    }

    public function testInvoicePdfRendersTotals(): void
    {
        $invoice = $this->sampleInvoice();
        $lines   = $this->sampleInvoiceLines();
        $customer = $this->sampleCustomer();

        $html = InvoicePdf::render($invoice, $lines, $this->business, $customer);

        // Subtotal: 250 + 250 = 500
        $this->assertStringContainsString('500.00', $html);
        // Tax: 25 + 25 = 50
        $this->assertStringContainsString('50.00', $html);
        // Grand Total: 550
        $this->assertStringContainsString('550.00', $html);
    }

    public function testInvoicePdfRendersNotes(): void
    {
        $invoice = $this->sampleInvoice();
        $lines   = $this->sampleInvoiceLines();
        $customer = $this->sampleCustomer();

        $html = InvoicePdf::render($invoice, $lines, $this->business, $customer);

        $this->assertStringContainsString('Payment due within 30 days', $html);
    }

    public function testInvoicePdfHandlesEmptyLines(): void
    {
        $invoice = $this->sampleInvoice();
        $customer = $this->sampleCustomer();

        $html = InvoicePdf::render($invoice, [], $this->business, $customer);

        // Should still render without errors
        $this->assertStringContainsString('INV-001', $html);
        $this->assertStringContainsString('0.00', $html); // totals should be zero
    }

    public function testInvoicePdfEscapesHtmlInData(): void
    {
        $invoice = $this->sampleInvoice();
        $invoice['notes'] = '<script>alert("xss")</script>';
        $customer = $this->sampleCustomer();

        $html = InvoicePdf::render($invoice, [], $this->business, $customer);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ─── Purchase Order PDF Tests ────────────────────────────────────────

    public function testPurchaseOrderPdfRendersBusinessHeader(): void
    {
        $po       = $this->samplePO();
        $lines    = $this->samplePOLines();
        $supplier = $this->sampleSupplier();

        $html = PurchaseOrderPdf::render($po, $lines, $this->business, $supplier);

        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('123 Main St, Springfield', $html);
        $this->assertStringContainsString('+1-555-0100', $html);
        $this->assertStringContainsString('info@acme.com', $html);
    }

    public function testPurchaseOrderPdfRendersSupplierDetails(): void
    {
        $po       = $this->samplePO();
        $lines    = $this->samplePOLines();
        $supplier = $this->sampleSupplier();

        $html = PurchaseOrderPdf::render($po, $lines, $this->business, $supplier);

        $this->assertStringContainsString('Supplier Inc', $html);
        $this->assertStringContainsString('789 Industrial Blvd', $html);
        $this->assertStringContainsString('+1-555-0300', $html);
        $this->assertStringContainsString('sales@supplier.com', $html);
    }

    public function testPurchaseOrderPdfRendersPODetails(): void
    {
        $po       = $this->samplePO();
        $lines    = $this->samplePOLines();
        $supplier = $this->sampleSupplier();

        $html = PurchaseOrderPdf::render($po, $lines, $this->business, $supplier);

        $this->assertStringContainsString('PO-001', $html);
        $this->assertStringContainsString('2024-01-10', $html);
        $this->assertStringContainsString('APPROVED', $html);
        $this->assertStringContainsString('USD', $html);
    }

    public function testPurchaseOrderPdfRendersLineItems(): void
    {
        $po       = $this->samplePO();
        $lines    = $this->samplePOLines();
        $supplier = $this->sampleSupplier();

        $html = PurchaseOrderPdf::render($po, $lines, $this->business, $supplier);

        $this->assertStringContainsString('Raw Material A', $html);
        $this->assertStringContainsString('100.00', $html); // quantity
        $this->assertStringContainsString('15.00', $html);  // unit price

        $this->assertStringContainsString('Raw Material B', $html);
        $this->assertStringContainsString('200.00', $html); // quantity
        $this->assertStringContainsString('8.00', $html);   // unit price
    }

    public function testPurchaseOrderPdfRendersTotals(): void
    {
        $po       = $this->samplePO();
        $lines    = $this->samplePOLines();
        $supplier = $this->sampleSupplier();

        $html = PurchaseOrderPdf::render($po, $lines, $this->business, $supplier);

        // Subtotal: 1500 + 1600 = 3100
        $this->assertStringContainsString('3,100.00', $html);
        // Tax: 150 + 160 = 310
        $this->assertStringContainsString('310.00', $html);
        // Grand Total: 3410
        $this->assertStringContainsString('3,410.00', $html);
    }

    public function testPurchaseOrderPdfRendersNotes(): void
    {
        $po       = $this->samplePO();
        $lines    = $this->samplePOLines();
        $supplier = $this->sampleSupplier();

        $html = PurchaseOrderPdf::render($po, $lines, $this->business, $supplier);

        $this->assertStringContainsString('Deliver to warehouse B', $html);
    }

    public function testPurchaseOrderPdfHandlesEmptyLines(): void
    {
        $po       = $this->samplePO();
        $supplier = $this->sampleSupplier();

        $html = PurchaseOrderPdf::render($po, [], $this->business, $supplier);

        $this->assertStringContainsString('PO-001', $html);
        $this->assertStringContainsString('0.00', $html);
    }

    public function testPurchaseOrderPdfEscapesHtmlInData(): void
    {
        $po = $this->samplePO();
        $po['notes'] = '<img src=x onerror=alert(1)>';
        $supplier = $this->sampleSupplier();

        $html = PurchaseOrderPdf::render($po, [], $this->business, $supplier);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function sampleInvoice(): array
    {
        return [
            'invoice_number' => 'INV-001',
            'invoice_date'   => '2024-01-15',
            'due_date'       => '2024-02-14',
            'status'         => 'DELIVERED',
            'currency'       => 'USD',
            'notes'          => 'Payment due within 30 days',
        ];
    }

    private function sampleInvoiceLines(): array
    {
        return [
            [
                'item_name'      => 'Widget',
                'quantity'       => 10,
                'unit_price_fc'  => 25.00,
                'tax_rate'       => 10.00,
                'tax_amount_fc'  => 25.00,
                'total_price_fc' => 250.00,
            ],
            [
                'item_name'      => 'Gadget',
                'quantity'       => 5,
                'unit_price_fc'  => 50.00,
                'tax_rate'       => 10.00,
                'tax_amount_fc'  => 25.00,
                'total_price_fc' => 250.00,
            ],
        ];
    }

    private function sampleCustomer(): array
    {
        return [
            'name'    => 'John Doe',
            'address' => '456 Oak Ave, Shelbyville',
            'phone'   => '+1-555-0200',
            'email'   => 'john@example.com',
        ];
    }

    private function samplePO(): array
    {
        return [
            'po_number' => 'PO-001',
            'po_date'   => '2024-01-10',
            'status'    => 'APPROVED',
            'currency'  => 'USD',
            'notes'     => 'Deliver to warehouse B',
        ];
    }

    private function samplePOLines(): array
    {
        return [
            [
                'item_name'      => 'Raw Material A',
                'quantity'       => 100,
                'unit_price_fc'  => 15.00,
                'tax_rate'       => 10.00,
                'tax_amount_fc'  => 150.00,
                'total_price_fc' => 1500.00,
            ],
            [
                'item_name'      => 'Raw Material B',
                'quantity'       => 200,
                'unit_price_fc'  => 8.00,
                'tax_rate'       => 10.00,
                'tax_amount_fc'  => 160.00,
                'total_price_fc' => 1600.00,
            ],
        ];
    }

    private function sampleSupplier(): array
    {
        return [
            'name'    => 'Supplier Inc',
            'address' => '789 Industrial Blvd',
            'phone'   => '+1-555-0300',
            'email'   => 'sales@supplier.com',
        ];
    }
}
