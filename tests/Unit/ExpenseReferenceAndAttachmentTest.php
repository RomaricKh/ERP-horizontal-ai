<?php

namespace Tests\Unit;

use App\Repository\ExpenseRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ExpenseService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for expense vendor invoice reference and attachment fields.
 *
 * Validates: REQ-030 — expenses should support reference_number, reference_date,
 * and attachment_path fields in create/update/select operations.
 */
class ExpenseReferenceAndAttachmentTest extends TestCase
{
    private int $businessId = 1;
    private int $expenseId  = 200;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * Test: create an expense with vendor invoice reference number and date.
     *
     * When creating an expense with reference_number and reference_date,
     * those values must be passed to the repository create method.
     */
    public function testCreateExpenseWithVendorInvoiceReference(): void
    {
        $capturedData = null;

        $expenseRepo = new class($this->expenseId, $capturedData) extends ExpenseRepository {
            private int $returnId;
            private $captured;

            public function __construct(int $returnId, &$captured)
            {
                $this->returnId = $returnId;
                $this->captured = &$captured;
            }

            public function create(array $data): int
            {
                $this->captured = $data;
                return $this->returnId;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return null;
            }
        };

        $service = $this->buildService($expenseRepo);

        $id = $service->create([
            'payee_id'         => 10,
            'expense_date'     => '2025-03-15',
            'currency'         => 'USD',
            'exchange_rate'    => 1.0,
            'amount_fc'        => 1500.00,
            'reference_number' => 'INV-2025-0042',
            'reference_date'   => '2025-03-10',
        ]);

        $this->assertSame($this->expenseId, $id);
        $this->assertNotNull($capturedData);
        $this->assertSame('INV-2025-0042', $capturedData['reference_number']);
        $this->assertSame('2025-03-10', $capturedData['reference_date']);
    }

    /**
     * Test: create an expense with an attachment path.
     *
     * When creating an expense with attachment_path,
     * the path must be stored in the repository.
     */
    public function testCreateExpenseWithAttachmentPath(): void
    {
        $capturedData = null;

        $expenseRepo = new class($this->expenseId, $capturedData) extends ExpenseRepository {
            private int $returnId;
            private $captured;

            public function __construct(int $returnId, &$captured)
            {
                $this->returnId = $returnId;
                $this->captured = &$captured;
            }

            public function create(array $data): int
            {
                $this->captured = $data;
                return $this->returnId;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return null;
            }
        };

        $service = $this->buildService($expenseRepo);

        $id = $service->create([
            'payee_id'        => 10,
            'expense_date'    => '2025-03-15',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'amount_fc'       => 2000.00,
            'attachment_path' => '/uploads/expenses/exp_abc123_1700000000.pdf',
        ]);

        $this->assertSame($this->expenseId, $id);
        $this->assertNotNull($capturedData);
        $this->assertSame('/uploads/expenses/exp_abc123_1700000000.pdf', $capturedData['attachment_path']);
    }

    /**
     * Test: update an expense to add vendor invoice reference.
     *
     * When updating an expense to include reference_number and reference_date,
     * those values must be passed to the repository update method.
     */
    public function testUpdateExpenseWithVendorInvoiceReference(): void
    {
        $capturedUpdateData = null;

        $beforeRecord = [
            'id'                   => $this->expenseId,
            'business_id'          => $this->businessId,
            'payee_id'             => 10,
            'payee_name'           => 'Test Supplier',
            'expense_category_id'  => 1,
            'category_name'        => 'Utilities',
            'expense_date'         => '2025-03-15',
            'currency'             => 'USD',
            'exchange_rate'        => '1.000000',
            'amount_fc'            => '1500.00',
            'amount_bc'            => '1500.00',
            'notes'                => null,
            'reference_number'     => null,
            'reference_date'       => null,
            'attachment_path'      => null,
            'created_at'           => '2025-03-15',
            'updated_at'           => '2025-03-15',
        ];

        $expenseRepo = new class($beforeRecord, $capturedUpdateData) extends ExpenseRepository {
            private array $record;
            private $captured;

            public function __construct(array $record, &$captured)
            {
                $this->record = $record;
                $this->captured = &$captured;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return $this->record;
            }

            public function update(int $id, int $businessId, array $data): void
            {
                $this->captured = $data;
            }
        };

        $service = $this->buildService($expenseRepo);

        $service->update($this->expenseId, [
            'payee_id'         => 10,
            'expense_date'     => '2025-03-15',
            'currency'         => 'USD',
            'exchange_rate'    => 1.0,
            'amount_fc'        => 1500.00,
            'reference_number' => 'VENDOR-INV-99',
            'reference_date'   => '2025-03-12',
            'attachment_path'  => '/uploads/expenses/exp_xyz_1700000001.jpg',
        ]);

        $this->assertNotNull($capturedUpdateData);
        $this->assertSame('VENDOR-INV-99', $capturedUpdateData['reference_number']);
        $this->assertSame('2025-03-12', $capturedUpdateData['reference_date']);
        $this->assertSame('/uploads/expenses/exp_xyz_1700000001.jpg', $capturedUpdateData['attachment_path']);
    }

    /**
     * Test: findAllForBusiness returns reference and attachment fields.
     *
     * The repository SELECT query must include reference_number, reference_date,
     * and attachment_path in the result set.
     */
    public function testFindAllForBusinessIncludesReferenceFields(): void
    {
        // Use a real PDO mock to verify the SQL includes the new columns
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);

        $expectedRow = [
            'id'                   => 1,
            'business_id'          => $this->businessId,
            'payee_id'             => 10,
            'payee_name'           => 'Supplier A',
            'expense_category_id'  => 2,
            'category_name'        => 'Rent',
            'expense_date'         => '2025-03-15',
            'currency'             => 'USD',
            'exchange_rate'        => '1.000000',
            'amount_fc'            => '3000.00',
            'amount_bc'            => '3000.00',
            'notes'                => null,
            'reference_number'     => 'INV-2025-100',
            'reference_date'       => '2025-03-10',
            'attachment_path'      => '/uploads/expenses/exp_test.pdf',
            'created_at'           => '2025-03-15 10:00:00',
            'updated_at'           => '2025-03-15 10:00:00',
        ];

        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([$expectedRow]);

        $pdo->method('prepare')->willReturnCallback(function ($sql) use ($stmt) {
            // Verify the SQL includes the new columns
            $this->assertStringContainsString('reference_number', $sql);
            $this->assertStringContainsString('reference_date', $sql);
            $this->assertStringContainsString('attachment_path', $sql);
            return $stmt;
        });

        $repo = new ExpenseRepository($pdo);
        $results = $repo->findAllForBusiness($this->businessId);

        $this->assertCount(1, $results);
        $this->assertSame('INV-2025-100', $results[0]['reference_number']);
        $this->assertSame('2025-03-10', $results[0]['reference_date']);
        $this->assertSame('/uploads/expenses/exp_test.pdf', $results[0]['attachment_path']);
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    /**
     * Build an ExpenseService with mocked dependencies injected via reflection.
     */
    private function buildService(ExpenseRepository $expenseRepo): ExpenseService
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $audit = new class extends AuditService {
            public function __construct() {}
            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null,
                                bool $force = false, bool $tenantScope = false): void {}
        };

        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                if ($providedRate !== null) {
                    return $providedRate;
                }
                return 1.0;
            }
        };

        $ref     = new ReflectionClass(ExpenseService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $expenseRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
        ];

        foreach ($props as $name => $value) {
            if ($ref->hasProperty($name)) {
                $prop = $ref->getProperty($name);
                $prop->setAccessible(true);
                $prop->setValue($service, $value);
            }
        }

        return $service;
    }
}
