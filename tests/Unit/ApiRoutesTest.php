<?php

namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Controllers\PurchaseOrdersController;
use App\Controllers\SalesInvoicesController;
use App\Services\AuditService;
use App\Services\BalanceRecalculator;
use App\Services\PurchaseOrderService;
use App\Services\SalesInvoiceService;
use App\Support\AuthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for API routes added in Task 12.
 *
 * Tests PO payment status, payment shortcut, invoice payment status,
 * and balance recalculation endpoints.
 *
 * Validates: Requirements 2.5, 3.1, 7.5, 12.4, 12.5
 */
class ApiRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 100,
            'permissions' => ['po.view', 'invoices.view', 'admin'],
        ]);
    }

    // ─── PO Payment Status Endpoint ─────────────────────────────────────────

    /**
     * Test PO payment status endpoint returns correct format.
     *
     * Validates: Requirements 2.5
     */
    public function testPOPaymentStatusEndpointReturnsCorrectFormat(): void
    {
        $service = $this->createMock(PurchaseOrderService::class);
        $service->method('computePaymentStatus')
            ->with(42)
            ->willReturn('PARTIALLY_PAID');

        $controller = $this->buildPOController($service);
        $response = $this->captureOutput(fn() => $controller->paymentStatus(42));

        $this->assertTrue($response['success']);
        $this->assertSame(42, $response['data']['id']);
        $this->assertSame('PARTIALLY_PAID', $response['data']['payment_status']);
    }

    /**
     * Test PO payment status endpoint returns UNPAID.
     *
     * Validates: Requirements 2.5
     */
    public function testPOPaymentStatusEndpointReturnsUnpaid(): void
    {
        $service = $this->createMock(PurchaseOrderService::class);
        $service->method('computePaymentStatus')
            ->with(10)
            ->willReturn('UNPAID');

        $controller = $this->buildPOController($service);
        $response = $this->captureOutput(fn() => $controller->paymentStatus(10));

        $this->assertTrue($response['success']);
        $this->assertSame(10, $response['data']['id']);
        $this->assertSame('UNPAID', $response['data']['payment_status']);
    }

    /**
     * Test PO payment status endpoint returns PAID.
     *
     * Validates: Requirements 2.5
     */
    public function testPOPaymentStatusEndpointReturnsPaid(): void
    {
        $service = $this->createMock(PurchaseOrderService::class);
        $service->method('computePaymentStatus')
            ->with(5)
            ->willReturn('PAID');

        $controller = $this->buildPOController($service);
        $response = $this->captureOutput(fn() => $controller->paymentStatus(5));

        $this->assertTrue($response['success']);
        $this->assertSame(5, $response['data']['id']);
        $this->assertSame('PAID', $response['data']['payment_status']);
    }

    /**
     * Test PO payment status endpoint handles not found PO.
     *
     * Validates: Requirements 2.5
     */
    public function testPOPaymentStatusEndpointHandlesNotFound(): void
    {
        $service = $this->createMock(PurchaseOrderService::class);
        $service->method('computePaymentStatus')
            ->willThrowException(new \RuntimeException('Purchase order not found'));

        $controller = $this->buildPOController($service);
        $response = $this->captureOutput(fn() => $controller->paymentStatus(999));

        $this->assertFalse($response['success']);
        $this->assertSame('Purchase order not found', $response['message']);
    }

    // ─── PO Payment Shortcut Endpoint ───────────────────────────────────────

    /**
     * Test payment shortcut endpoint returns pre-filled data.
     *
     * Validates: Requirements 3.1, 3.2
     */
    public function testPaymentShortcutEndpointReturnsPreFilledData(): void
    {
        $shortcutData = [
            'payee_id'       => 15,
            'amount'         => 2500.00,
            'reference'      => 'PO-2024-001',
            'reference_type' => 'PURCHASE_ORDER',
            'reference_id'   => 42,
        ];

        $service = $this->createMock(PurchaseOrderService::class);
        $service->method('getPaymentShortcutData')
            ->with(42)
            ->willReturn($shortcutData);

        $controller = $this->buildPOController($service);
        $response = $this->captureOutput(fn() => $controller->paymentShortcut(42));

        $this->assertTrue($response['success']);
        $this->assertSame(15, $response['data']['payment_shortcut']['payee_id']);
        $this->assertEquals(2500.00, $response['data']['payment_shortcut']['amount']);
        $this->assertSame('PO-2024-001', $response['data']['payment_shortcut']['reference']);
        $this->assertSame('PURCHASE_ORDER', $response['data']['payment_shortcut']['reference_type']);
        $this->assertSame(42, $response['data']['payment_shortcut']['reference_id']);
    }

    /**
     * Test payment shortcut endpoint rejects non-RECEIVED PO.
     *
     * Validates: Requirements 3.3
     */
    public function testPaymentShortcutEndpointRejectsNonReceivedPO(): void
    {
        $service = $this->createMock(PurchaseOrderService::class);
        $service->method('getPaymentShortcutData')
            ->willThrowException(new \RuntimeException('Payment shortcut is only available for RECEIVED purchase orders.'));

        $controller = $this->buildPOController($service);
        $response = $this->captureOutput(fn() => $controller->paymentShortcut(10));

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('RECEIVED', $response['message']);
    }

    // ─── Invoice Payment Status Endpoint ────────────────────────────────────

    /**
     * Test invoice payment status endpoint returns correct format.
     *
     * Validates: Requirements 7.5
     */
    public function testInvoicePaymentStatusEndpointReturnsCorrectFormat(): void
    {
        $service = $this->createMock(SalesInvoiceService::class);
        $service->method('computePaymentStatus')
            ->with(30)
            ->willReturn('PARTIALLY_PAID');

        $controller = $this->buildInvoiceController($service);
        $response = $this->captureOutput(fn() => $controller->paymentStatus(30));

        $this->assertTrue($response['success']);
        $this->assertSame(30, $response['data']['id']);
        $this->assertSame('PARTIALLY_PAID', $response['data']['payment_status']);
    }

    /**
     * Test invoice payment status endpoint returns UNPAID.
     *
     * Validates: Requirements 7.5
     */
    public function testInvoicePaymentStatusEndpointReturnsUnpaid(): void
    {
        $service = $this->createMock(SalesInvoiceService::class);
        $service->method('computePaymentStatus')
            ->with(20)
            ->willReturn('UNPAID');

        $controller = $this->buildInvoiceController($service);
        $response = $this->captureOutput(fn() => $controller->paymentStatus(20));

        $this->assertTrue($response['success']);
        $this->assertSame(20, $response['data']['id']);
        $this->assertSame('UNPAID', $response['data']['payment_status']);
    }

    /**
     * Test invoice payment status endpoint returns PAID.
     *
     * Validates: Requirements 7.5
     */
    public function testInvoicePaymentStatusEndpointReturnsPaid(): void
    {
        $service = $this->createMock(SalesInvoiceService::class);
        $service->method('computePaymentStatus')
            ->with(25)
            ->willReturn('PAID');

        $controller = $this->buildInvoiceController($service);
        $response = $this->captureOutput(fn() => $controller->paymentStatus(25));

        $this->assertTrue($response['success']);
        $this->assertSame(25, $response['data']['id']);
        $this->assertSame('PAID', $response['data']['payment_status']);
    }

    /**
     * Test invoice payment status endpoint handles not found invoice.
     *
     * Validates: Requirements 7.5
     */
    public function testInvoicePaymentStatusEndpointHandlesNotFound(): void
    {
        $service = $this->createMock(SalesInvoiceService::class);
        $service->method('computePaymentStatus')
            ->willThrowException(new \RuntimeException('Sales invoice not found'));

        $controller = $this->buildInvoiceController($service);
        $response = $this->captureOutput(fn() => $controller->paymentStatus(999));

        $this->assertFalse($response['success']);
        $this->assertSame('Sales invoice not found', $response['message']);
    }

    // ─── Balance Recalculation Endpoints ────────────────────────────────────

    /**
     * Test balance recalculation requires admin permission.
     *
     * Validates: Requirements 12.5
     */
    public function testBalanceRecalculationRequiresAdminPermission(): void
    {
        // Set auth context without admin permission
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 100,
            'permissions' => ['po.view', 'invoices.view'],
        ]);

        $balanceRecalculator = $this->createMock(BalanceRecalculator::class);
        $balanceRecalculator->expects($this->never())->method('recalculate');

        $audit = $this->createMock(AuditService::class);

        $controller = $this->buildAdminController($balanceRecalculator, $audit);
        $response = $this->captureOutput(fn() => $controller->recalculateBalances());

        $this->assertFalse($response['success']);
        $this->assertSame('Missing permission: admin', $response['message']);
    }

    /**
     * Test balance recalculation returns report format.
     *
     * Validates: Requirements 12.4
     */
    public function testBalanceRecalculationReturnsReportFormat(): void
    {
        $report = [
            [
                'account_id'      => 1,
                'prev_balance'    => 1000.00,
                'new_balance'     => 800.00,
                'prev_balance_fc' => 1000.00,
                'new_balance_fc'  => 800.00,
            ],
            [
                'account_id'      => 2,
                'prev_balance'    => 500.00,
                'new_balance'     => 600.00,
                'prev_balance_fc' => 500.00,
                'new_balance_fc'  => 600.00,
            ],
        ];

        $balanceRecalculator = $this->createMock(BalanceRecalculator::class);
        $balanceRecalculator->method('recalculate')
            ->with(null)
            ->willReturn($report);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('log');

        $controller = $this->buildAdminController($balanceRecalculator, $audit);
        $response = $this->captureOutput(fn() => $controller->recalculateBalances());

        $this->assertTrue($response['success']);
        $this->assertCount(2, $response['data']['report']);
        $this->assertSame(1, $response['data']['report'][0]['account_id']);
        $this->assertEquals(1000.00, $response['data']['report'][0]['prev_balance']);
        $this->assertEquals(800.00, $response['data']['report'][0]['new_balance']);
        $this->assertEquals(1000.00, $response['data']['report'][0]['prev_balance_fc']);
        $this->assertEquals(800.00, $response['data']['report'][0]['new_balance_fc']);
    }

    /**
     * Test single account recalculation endpoint.
     *
     * Validates: Requirements 12.4
     */
    public function testSingleAccountRecalculationEndpoint(): void
    {
        $report = [
            [
                'account_id'      => 5,
                'prev_balance'    => 2000.00,
                'new_balance'     => 1500.00,
                'prev_balance_fc' => 2000.00,
                'new_balance_fc'  => 1500.00,
            ],
        ];

        $balanceRecalculator = $this->createMock(BalanceRecalculator::class);
        $balanceRecalculator->method('recalculate')
            ->with(5)
            ->willReturn($report);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('log');

        $controller = $this->buildAdminController($balanceRecalculator, $audit);
        $response = $this->captureOutput(fn() => $controller->recalculateAccountBalance(5));

        $this->assertTrue($response['success']);
        $this->assertCount(1, $response['data']['report']);
        $this->assertSame(5, $response['data']['report'][0]['account_id']);
        $this->assertEquals(2000.00, $response['data']['report'][0]['prev_balance']);
        $this->assertEquals(1500.00, $response['data']['report'][0]['new_balance']);
    }

    /**
     * Test single account recalculation also requires admin permission.
     *
     * Validates: Requirements 12.5
     */
    public function testSingleAccountRecalculationRequiresAdmin(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 100,
            'permissions' => ['po.view'],
        ]);

        $balanceRecalculator = $this->createMock(BalanceRecalculator::class);
        $balanceRecalculator->expects($this->never())->method('recalculate');

        $audit = $this->createMock(AuditService::class);

        $controller = $this->buildAdminController($balanceRecalculator, $audit);
        $response = $this->captureOutput(fn() => $controller->recalculateAccountBalance(5));

        $this->assertFalse($response['success']);
        $this->assertSame('Missing permission: admin', $response['message']);
    }

    /**
     * Test balance recalculation logs action via AuditService.
     *
     * Validates: Requirements 11.6
     */
    public function testBalanceRecalculationLogsAction(): void
    {
        $balanceRecalculator = $this->createMock(BalanceRecalculator::class);
        $balanceRecalculator->method('recalculate')->willReturn([]);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())
            ->method('log')
            ->with(
                'BALANCE_RECALCULATION',
                'business_accounts',
                0,
                'recalculate_all',
                null,
                $this->callback(fn($v) => $v['accounts_processed'] === 0),
                1,    // user_id
                1,    // tenant_id
                100   // business_id
            );

        $controller = $this->buildAdminController($balanceRecalculator, $audit);
        $this->captureOutput(fn() => $controller->recalculateBalances());
    }

    // ─── Helper Methods ─────────────────────────────────────────────────────

    private function buildPOController(PurchaseOrderService $service): PurchaseOrdersController
    {
        $ref = new ReflectionClass(PurchaseOrdersController::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('service');
        $prop->setAccessible(true);
        $prop->setValue($controller, $service);

        return $controller;
    }

    private function buildInvoiceController(SalesInvoiceService $service): SalesInvoicesController
    {
        $ref = new ReflectionClass(SalesInvoicesController::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('service');
        $prop->setAccessible(true);
        $prop->setValue($controller, $service);

        return $controller;
    }

    private function buildAdminController(BalanceRecalculator $balanceRecalculator, AuditService $audit): AdminController
    {
        $ref = new ReflectionClass(AdminController::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('balanceRecalculator');
        $prop->setAccessible(true);
        $prop->setValue($controller, $balanceRecalculator);

        $prop2 = $ref->getProperty('audit');
        $prop2->setAccessible(true);
        $prop2->setValue($controller, $audit);

        return $controller;
    }

    private function captureOutput(callable $fn): array
    {
        ob_start();
        $fn();
        $output = ob_get_clean();

        return json_decode($output, true) ?? [];
    }
}
