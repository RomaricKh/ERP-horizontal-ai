<?php

namespace Tests\Unit;

use App\Services\BusinessTypeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for MODULE_TABLE_MAP correctness in BusinessTypeService.
 *
 * Validates: Requirements 6.1, 6.2, 6.3
 */
class BusinessTypeServiceTableMapTest extends TestCase
{
    private array $moduleTableMap;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(BusinessTypeService::class);
        $constant = $reflection->getReflectionConstant('MODULE_TABLE_MAP');
        $this->moduleTableMap = $constant->getValue();
    }

    /**
     * Requirement 6.1: 'payments' module key maps to table 'payment_slips'.
     */
    public function testPaymentsMapToPaymentSlips(): void
    {
        $this->assertArrayHasKey('payments', $this->moduleTableMap);
        $this->assertSame(['payment_slips'], $this->moduleTableMap['payments']);
    }

    /**
     * Requirement 6.2: 'collections' module key maps to table 'collection_slips'.
     */
    public function testCollectionsMapToCollectionSlips(): void
    {
        $this->assertArrayHasKey('collections', $this->moduleTableMap);
        $this->assertSame(['collection_slips'], $this->moduleTableMap['collections']);
    }

    /**
     * Requirement 6.3: 'salaries' module key maps to table 'salaries'.
     */
    public function testSalariesMapToSalaries(): void
    {
        $this->assertArrayHasKey('salaries', $this->moduleTableMap);
        $this->assertSame(['salaries'], $this->moduleTableMap['salaries']);
    }
}
