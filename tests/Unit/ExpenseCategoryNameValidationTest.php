<?php

namespace Tests\Unit;

use App\Repository\ExpenseRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ExpenseService;
use App\Repository\PeopleRepository;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for category name validation in ExpenseService.
 *
 * Validates: Requirements 10.1, 10.2, 10.3
 */
class ExpenseCategoryNameValidationTest extends TestCase
{
    private ExpenseService $service;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 1,
        ]);

        $this->service = $this->buildService();
    }

    // -------------------------------------------------------------------
    // createCategory — length validation
    // -------------------------------------------------------------------

    public function testCreateCategoryRejectsNameOver100CharsAfterTrimming(): void
    {
        $longName = str_repeat('a', 101);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name must not exceed 100 characters');

        $this->service->createCategory($longName, false);
    }

    public function testCreateCategoryAcceptsNameExactly100Chars(): void
    {
        $name = str_repeat('a', 100);

        // Should not throw — will reach the repo call and return the stub ID
        $id = $this->service->createCategory($name, false);
        $this->assertSame(1, $id);
    }

    public function testCreateCategoryTrimsBeforeLengthCheck(): void
    {
        // 99 chars + surrounding spaces = over 100 total chars, but 99 after trim
        $name = '  ' . str_repeat('b', 99) . '  ';

        $id = $this->service->createCategory($name, false);
        $this->assertSame(1, $id);
    }

    public function testCreateCategoryRejectsPaddedNameOver100AfterTrim(): void
    {
        // 101 chars of content + spaces = trim to 101
        $name = '  ' . str_repeat('c', 101) . '  ';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name must not exceed 100 characters');

        $this->service->createCategory($name, false);
    }

    // -------------------------------------------------------------------
    // createCategory — control character validation
    // -------------------------------------------------------------------

    public function testCreateCategoryRejectsNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name contains invalid characters');

        $this->service->createCategory("Office\x00Supplies", false);
    }

    public function testCreateCategoryRejectsTab(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name contains invalid characters');

        $this->service->createCategory("Office\tSupplies", false);
    }

    public function testCreateCategoryRejectsNewline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name contains invalid characters');

        $this->service->createCategory("Office\nSupplies", false);
    }

    public function testCreateCategoryRejectsDeleteChar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name contains invalid characters');

        $this->service->createCategory("Office\x7FSupplies", false);
    }

    public function testCreateCategoryRejectsBellChar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name contains invalid characters');

        $this->service->createCategory("Office\x07Supplies", false);
    }

    public function testCreateCategoryAcceptsNormalPrintableChars(): void
    {
        $id = $this->service->createCategory('Office Supplies & Equipment (2025)', false);
        $this->assertSame(1, $id);
    }

    // -------------------------------------------------------------------
    // updateCategory — length validation
    // -------------------------------------------------------------------

    public function testUpdateCategoryRejectsNameOver100Chars(): void
    {
        $longName = str_repeat('x', 101);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name must not exceed 100 characters');

        $this->service->updateCategory(1, $longName, false);
    }

    public function testUpdateCategoryAcceptsNameExactly100Chars(): void
    {
        $name = str_repeat('x', 100);

        // Should not throw
        $this->service->updateCategory(1, $name, false);
        $this->assertTrue(true); // no exception = pass
    }

    // -------------------------------------------------------------------
    // updateCategory — control character validation
    // -------------------------------------------------------------------

    public function testUpdateCategoryRejectsControlChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name contains invalid characters');

        $this->service->updateCategory(1, "Rent\x01Payment", false);
    }

    public function testUpdateCategoryRejectsEscapeChar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name contains invalid characters');

        $this->service->updateCategory(1, "Rent\x1BPayment", false);
    }

    public function testUpdateCategoryAcceptsValidUnicodeChars(): void
    {
        // Unicode characters above 0x7F (non-ASCII printable) should be accepted
        $this->service->updateCategory(1, 'مصاريف المكتب', false);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function buildService(): ExpenseService
    {
        $expenseRepo = new class extends ExpenseRepository {
            public function __construct() {}

            public function createCategory(array $data): int
            {
                return 1;
            }

            public function findCategoryById(int $id, int $tenantId): ?array
            {
                return [
                    'id'          => $id,
                    'tenant_id'   => $tenantId,
                    'business_id' => 1,
                    'name'        => 'Old Name',
                    'is_fixed'    => false,
                ];
            }

            public function updateCategory(int $id, int $tenantId, array $data): void
            {
                // no-op
            }
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
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
        };

        $pdo = $this->createMock(PDO::class);

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
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
