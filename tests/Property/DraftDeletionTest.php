<?php

namespace Tests\Property;

use App\Repository\AccountRepository;
use App\Repository\PeopleRepository;
use App\Repository\PurchaseOrderRepository;
use App\Repository\SalesInvoiceRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\PurchaseOrderService;
use App\Services\SalesInvoiceService;
use App\Services\StockValidator;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: operations-financial-integrity, Property 20: DRAFT Document Deletion
 *
 * For any purchase order or sales invoice, deletion succeeds if and only if the
 * document is in DRAFT status. When deletion succeeds, all associated line items
 * (po_details or si_details) are also removed, leaving no orphaned records.
 *
 * **Validates: Requirements 15.1, 15.2, 15.3, 15.4**
 */
class DraftDeletionTest extends TestCase
{
    private const ITERATIONS = 100;

    private int $tenantId = 1;
    private int $businessId = 1;
    private int $userId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * All possible PO statuses to test deletion against.
     */
    private static function allStatuses(): array
    {
        return ['DRAFT', 'APPROVED', 'RECEIVED', 'CANCELLED'];
    }

    /**
     * Data provider generating 100 POs in various statuses with line items.
     *
     * Returns: [poId, status, numLines, shouldSucceed]
     */
    public static function draftDeletionProvider(): array
    {
        $cases = [];
        $statuses = self::allStatuses();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $poId = mt_rand(1, 10000);
            $status = $statuses[array_rand($statuses)];
            $numLines = mt_rand(0, 20);
            $shouldSucceed = ($status === 'DRAFT');

            $cases["iter_{$i}_po_{$poId}_{$status}_lines_{$numLines}"] = [
                $poId,
                $status,
                $numLines,
                $shouldSucceed,
            ];
        }
        return $cases;
    }

    /**
     * Property 20: DRAFT Document Deletion — PO portion
     *
     * For any PO in any status:
     * - Deletion succeeds only for DRAFT status
     * - Non-DRAFT POs are rejected with "Only DRAFT purchase orders can be deleted"
     * - When deletion succeeds, deleteLines is called before delete (cleanup)
     *
     * **Validates: Requirements 15.1, 15.3**
     *
     * @dataProvider draftDeletionProvider
     */
    public function testDeletionSucceedsOnlyForDraftPOs(
        int $poId,
        string $status,
        int $numLines,
        bool $shouldSucceed
    ): void {
        $linesDeleted = false;
        $poDeleted = false;

        $service = $this->buildPurchaseOrderServiceForDeletion(
            $poId,
            $status,
            $numLines,
            $linesDeleted,
            $poDeleted
        );

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->delete($poId);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        if ($shouldSucceed) {
            $this->assertFalse(
                $exceptionThrown,
                sprintf(
                    'Expected deletion of DRAFT PO %d to SUCCEED but got: "%s"',
                    $poId,
                    $exceptionMessage
                )
            );

            // Verify line items were deleted
            $this->assertTrue(
                $linesDeleted,
                sprintf('Expected po_details to be deleted for PO %d', $poId)
            );

            // Verify PO header was deleted
            $this->assertTrue(
                $poDeleted,
                sprintf('Expected PO header %d to be deleted', $poId)
            );
        } else {
            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Expected deletion of %s PO %d to be REJECTED but it succeeded.',
                    $status,
                    $poId
                )
            );

            $this->assertStringContainsString(
                'Only DRAFT purchase orders can be deleted',
                $exceptionMessage,
                sprintf('Expected specific rejection message. Got: "%s"', $exceptionMessage)
            );

            // Verify nothing was deleted
            $this->assertFalse(
                $linesDeleted,
                sprintf('Lines should NOT be deleted for %s PO %d', $status, $poId)
            );
            $this->assertFalse(
                $poDeleted,
                sprintf('PO header should NOT be deleted for %s PO %d', $status, $poId)
            );
        }
    }

    /**
     * Build a PurchaseOrderService for deletion testing with tracking.
     */
    private function buildPurchaseOrderServiceForDeletion(
        int $poId,
        string $status,
        int $numLines,
        bool &$linesDeleted,
        bool &$poDeleted
    ): PurchaseOrderService {
        $businessId = $this->businessId;

        // Generate random line items
        $lines = [];
        for ($j = 0; $j < $numLines; $j++) {
            $lines[] = [
                'id'                => $j + 1,
                'purchase_order_id' => $poId,
                'item_id'           => 100 + $j,
                'item_name'         => 'Item_' . (100 + $j),
                'quantity'          => round(mt_rand(1, 10000) / 100, 2),
                'unit_price_fc'     => round(mt_rand(100, 50000) / 100, 2),
                'unit_price_bc'     => round(mt_rand(100, 50000) / 100, 2),
                'total_price_fc'    => round(mt_rand(100, 500000) / 100, 2),
                'total_price_bc'    => round(mt_rand(100, 500000) / 100, 2),
                'created_at'        => '2024-01-01',
            ];
        }

        // Mock PurchaseOrderRepository
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('findById')
            ->willReturnCallback(function ($id, $bid) use ($poId, $businessId, $status, $lines) {
                if ($id === $poId && $bid === $businessId) {
                    return [
                        'id'              => $poId,
                        'business_id'     => $businessId,
                        'supplier_id'     => 10,
                        'po_number'       => 'PO-2024-001',
                        'po_date'         => '2024-01-15',
                        'status'          => $status,
                        'currency'        => 'USD',
                        'exchange_rate'   => 1.0,
                        'total_amount_fc' => 1000.0,
                        'total_amount_bc' => 1000.0,
                        'notes'           => null,
                        'created_at'      => '2024-01-01',
                        'updated_at'      => '2024-01-01',
                        'created_by'      => 1,
                        'lines'           => $lines,
                    ];
                }
                return null;
            });

        $repo->method('deleteLines')
            ->willReturnCallback(function ($id) use ($poId, &$linesDeleted) {
                if ($id === $poId) {
                    $linesDeleted = true;
                }
            });

        $repo->method('delete')
            ->willReturnCallback(function ($id, $bid) use ($poId, $businessId, &$poDeleted) {
                if ($id === $poId && $bid === $businessId) {
                    $poDeleted = true;
                }
            });

        // Mock other dependencies
        $peopleRepo = $this->createMock(PeopleRepository::class);
        $exchangeRateService = $this->getMockBuilder(ExchangeRateService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $auditService = $this->getMockBuilder(AuditService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();
        $itemService = $this->getMockBuilder(ItemService::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock PDO
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service using reflection
        $ref = new ReflectionClass(PurchaseOrderService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $repo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $auditService,
            'itemService'         => $itemService,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    // =========================================================================
    // Invoice Deletion Tests (Property 20 — Invoice portion)
    // =========================================================================

    /**
     * All possible invoice statuses to test deletion against.
     */
    private static function allInvoiceStatuses(): array
    {
        return ['DRAFT', 'DELIVERED', 'CANCELLED'];
    }

    /**
     * Data provider generating 100 invoices in various statuses with line items.
     *
     * Returns: [invoiceId, status, numLines, shouldSucceed]
     */
    public static function invoiceDraftDeletionProvider(): array
    {
        $cases = [];
        $statuses = ['DRAFT', 'DELIVERED', 'CANCELLED'];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $invoiceId = mt_rand(1, 10000);
            $status = $statuses[array_rand($statuses)];
            $numLines = mt_rand(0, 20);
            $shouldSucceed = ($status === 'DRAFT');

            $cases["iter_{$i}_inv_{$invoiceId}_{$status}_lines_{$numLines}"] = [
                $invoiceId,
                $status,
                $numLines,
                $shouldSucceed,
            ];
        }
        return $cases;
    }

    /**
     * Property 20: DRAFT Document Deletion — Invoice portion
     *
     * For any invoice in any status:
     * - Deletion succeeds only for DRAFT status
     * - Non-DRAFT invoices are rejected with "Only DRAFT sales invoices can be deleted"
     * - When deletion succeeds, deleteLines is called before delete (cleanup)
     *
     * **Validates: Requirements 15.2, 15.4**
     *
     * @dataProvider invoiceDraftDeletionProvider
     */
    public function testDeletionSucceedsOnlyForDraftInvoices(
        int $invoiceId,
        string $status,
        int $numLines,
        bool $shouldSucceed
    ): void {
        $linesDeleted = false;
        $invoiceDeleted = false;

        $service = $this->buildSalesInvoiceServiceForDeletion(
            $invoiceId,
            $status,
            $numLines,
            $linesDeleted,
            $invoiceDeleted
        );

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->delete($invoiceId);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        if ($shouldSucceed) {
            $this->assertFalse(
                $exceptionThrown,
                sprintf(
                    'Expected deletion of DRAFT invoice %d to SUCCEED but got: "%s"',
                    $invoiceId,
                    $exceptionMessage
                )
            );

            // Verify line items were deleted
            $this->assertTrue(
                $linesDeleted,
                sprintf('Expected si_details to be deleted for invoice %d', $invoiceId)
            );

            // Verify invoice header was deleted
            $this->assertTrue(
                $invoiceDeleted,
                sprintf('Expected invoice header %d to be deleted', $invoiceId)
            );
        } else {
            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Expected deletion of %s invoice %d to be REJECTED but it succeeded.',
                    $status,
                    $invoiceId
                )
            );

            $this->assertStringContainsString(
                'Only DRAFT sales invoices can be deleted',
                $exceptionMessage,
                sprintf('Expected specific rejection message. Got: "%s"', $exceptionMessage)
            );

            // Verify nothing was deleted
            $this->assertFalse(
                $linesDeleted,
                sprintf('Lines should NOT be deleted for %s invoice %d', $status, $invoiceId)
            );
            $this->assertFalse(
                $invoiceDeleted,
                sprintf('Invoice header should NOT be deleted for %s invoice %d', $status, $invoiceId)
            );
        }
    }

    /**
     * Build a SalesInvoiceService for deletion testing with tracking.
     */
    private function buildSalesInvoiceServiceForDeletion(
        int $invoiceId,
        string $status,
        int $numLines,
        bool &$linesDeleted,
        bool &$invoiceDeleted
    ): SalesInvoiceService {
        $businessId = $this->businessId;

        // Generate random line items
        $lines = [];
        for ($j = 0; $j < $numLines; $j++) {
            $lines[] = [
                'id'              => $j + 1,
                'invoice_id'      => $invoiceId,
                'item_id'         => 100 + $j,
                'item_name'       => 'Item_' . (100 + $j),
                'quantity'        => round(mt_rand(1, 10000) / 100, 2),
                'unit_price_fc'   => round(mt_rand(100, 50000) / 100, 2),
                'unit_price_bc'   => round(mt_rand(100, 50000) / 100, 2),
                'total_price_fc'  => round(mt_rand(100, 500000) / 100, 2),
                'total_price_bc'  => round(mt_rand(100, 500000) / 100, 2),
                'created_at'      => '2024-01-01',
            ];
        }

        // Mock SalesInvoiceRepository
        $repo = $this->createMock(SalesInvoiceRepository::class);
        $repo->method('findById')
            ->willReturnCallback(function ($id, $bid) use ($invoiceId, $businessId, $status, $lines) {
                if ($id === $invoiceId && $bid === $businessId) {
                    return [
                        'id'              => $invoiceId,
                        'business_id'     => $businessId,
                        'customer_id'     => 10,
                        'invoice_number'  => 'INV-2024-001',
                        'invoice_date'    => '2024-01-15',
                        'status'          => $status,
                        'currency'        => 'USD',
                        'exchange_rate'   => 1.0,
                        'total_amount_fc' => 1000.0,
                        'total_amount_bc' => 1000.0,
                        'notes'           => null,
                        'created_at'      => '2024-01-01',
                        'updated_at'      => '2024-01-01',
                        'created_by'      => 1,
                        'lines'           => $lines,
                    ];
                }
                return null;
            });

        $repo->method('deleteLines')
            ->willReturnCallback(function ($id) use ($invoiceId, &$linesDeleted) {
                if ($id === $invoiceId) {
                    $linesDeleted = true;
                }
            });

        $repo->method('delete')
            ->willReturnCallback(function ($id, $bid) use ($invoiceId, $businessId, &$invoiceDeleted) {
                if ($id === $invoiceId && $bid === $businessId) {
                    $invoiceDeleted = true;
                }
            });

        // Mock other dependencies
        $peopleRepo = $this->createMock(PeopleRepository::class);
        $accountRepo = $this->createMock(AccountRepository::class);
        $exchangeRateService = $this->getMockBuilder(ExchangeRateService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $auditService = $this->getMockBuilder(AuditService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();
        $itemService = $this->getMockBuilder(ItemService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $stockValidator = $this->getMockBuilder(StockValidator::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock PDO
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service using reflection
        $ref = new ReflectionClass(SalesInvoiceService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $repo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $auditService,
            'itemService'         => $itemService,
            'accountRepo'         => $accountRepo,
            'stockValidator'      => $stockValidator,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
