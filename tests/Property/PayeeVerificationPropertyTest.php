<?php

namespace Tests\Property;

use App\Repository\InsightsRepository;
use App\Repository\PayeeStatementRepository;
use App\Repository\PeopleRepository;
use App\Services\InsightsService;
use App\Services\PayeeStatementService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Property 8: Payee business verification rejects cross-business access
 *
 * For any payee ID that does not belong to the specified business (no row in `payees`
 * with matching `id` and `business_id`), both InsightsService and PayeeStatementService
 * SHALL throw a RuntimeException with message "Payee not found" before executing any data query.
 *
 * **Validates: Requirements 11.1, 11.2, 11.4**
 */
class PayeeVerificationPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Generate a random payee ID (positive integer).
     */
    private function generateRandomPayeeId(): int
    {
        return mt_rand(1, 999999);
    }

    /**
     * Generate a random business ID (positive integer).
     */
    private function generateRandomBusinessId(): int
    {
        return mt_rand(1, 99999);
    }

    /**
     * Build an InsightsService with mocked dependencies injected via reflection.
     */
    private function buildInsightsService(PeopleRepository $peopleRepo, InsightsRepository $insightsRepo): InsightsService
    {
        $ref = new ReflectionClass(InsightsService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $peopleRepoProp = $ref->getProperty('peopleRepo');
        $peopleRepoProp->setAccessible(true);
        $peopleRepoProp->setValue($service, $peopleRepo);

        $insightsRepoProp = $ref->getProperty('insightsRepo');
        $insightsRepoProp->setAccessible(true);
        $insightsRepoProp->setValue($service, $insightsRepo);

        return $service;
    }

    /**
     * Build a PayeeStatementService with mocked dependencies injected via reflection.
     */
    private function buildStatementService(PeopleRepository $peopleRepo, PayeeStatementRepository $statementRepo): PayeeStatementService
    {
        $ref = new ReflectionClass(PayeeStatementService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $peopleRepoProp = $ref->getProperty('peopleRepo');
        $peopleRepoProp->setAccessible(true);
        $peopleRepoProp->setValue($service, $peopleRepo);

        $statementRepoProp = $ref->getProperty('statementRepo');
        $statementRepoProp->setAccessible(true);
        $statementRepoProp->setValue($service, $statementRepo);

        return $service;
    }

    /**
     * Property 8: InsightsService::getSupplierInsights throws RuntimeException
     * when payee does not belong to business.
     *
     * **Validates: Requirements 11.1, 11.4**
     */
    public function testInsightsSupplierThrowsWhenPayeeNotInBusiness(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId = $this->generateRandomPayeeId();
            $businessId = $this->generateRandomBusinessId();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->expects($this->once())
                ->method('verifyPayeeBelongsToBusiness')
                ->with($payeeId, $businessId)
                ->willReturn(null);

            $insightsRepo = $this->createMock(InsightsRepository::class);
            // Ensure no data query is executed
            $insightsRepo->expects($this->never())
                ->method('getSupplierItemInsights');

            $service = $this->buildInsightsService($peopleRepo, $insightsRepo);

            try {
                $service->getSupplierInsights($payeeId, $businessId);
                $this->fail(sprintf(
                    'Iteration %d: getSupplierInsights(%d, %d) should throw RuntimeException.',
                    $i, $payeeId, $businessId
                ));
            } catch (\RuntimeException $e) {
                $this->assertSame('Payee not found', $e->getMessage());
            }
        }
    }

    /**
     * Property 8: InsightsService::getCustomerInsights throws RuntimeException
     * when payee does not belong to business.
     *
     * **Validates: Requirements 11.1, 11.4**
     */
    public function testInsightsCustomerThrowsWhenPayeeNotInBusiness(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId = $this->generateRandomPayeeId();
            $businessId = $this->generateRandomBusinessId();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->expects($this->once())
                ->method('verifyPayeeBelongsToBusiness')
                ->with($payeeId, $businessId)
                ->willReturn(null);

            $insightsRepo = $this->createMock(InsightsRepository::class);
            // Ensure no data query is executed
            $insightsRepo->expects($this->never())
                ->method('getCustomerItemInsights');
            $insightsRepo->expects($this->never())
                ->method('getCustomerInvoiceFallback');

            $service = $this->buildInsightsService($peopleRepo, $insightsRepo);

            try {
                $service->getCustomerInsights($payeeId, $businessId);
                $this->fail(sprintf(
                    'Iteration %d: getCustomerInsights(%d, %d) should throw RuntimeException.',
                    $i, $payeeId, $businessId
                ));
            } catch (\RuntimeException $e) {
                $this->assertSame('Payee not found', $e->getMessage());
            }
        }
    }

    /**
     * Property 8: InsightsService::getGeneralInsights throws RuntimeException
     * when payee does not belong to business.
     *
     * **Validates: Requirements 11.1, 11.4**
     */
    public function testInsightsGeneralThrowsWhenPayeeNotInBusiness(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId = $this->generateRandomPayeeId();
            $businessId = $this->generateRandomBusinessId();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->expects($this->once())
                ->method('verifyPayeeBelongsToBusiness')
                ->with($payeeId, $businessId)
                ->willReturn(null);

            $insightsRepo = $this->createMock(InsightsRepository::class);
            $insightsRepo->expects($this->never())
                ->method('getGeneralInsights');

            $service = $this->buildInsightsService($peopleRepo, $insightsRepo);

            try {
                $service->getGeneralInsights($payeeId, $businessId);
                $this->fail(sprintf(
                    'Iteration %d: getGeneralInsights(%d, %d) should throw RuntimeException.',
                    $i, $payeeId, $businessId
                ));
            } catch (\RuntimeException $e) {
                $this->assertSame('Payee not found', $e->getMessage());
            }
        }
    }

    /**
     * Property 8: InsightsService::getPartnerInsights throws RuntimeException
     * when payee does not belong to business.
     *
     * **Validates: Requirements 11.1, 11.4**
     */
    public function testInsightsPartnerThrowsWhenPayeeNotInBusiness(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId = $this->generateRandomPayeeId();
            $businessId = $this->generateRandomBusinessId();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->expects($this->once())
                ->method('verifyPayeeBelongsToBusiness')
                ->with($payeeId, $businessId)
                ->willReturn(null);

            $insightsRepo = $this->createMock(InsightsRepository::class);
            $insightsRepo->expects($this->never())
                ->method('getPartnerInsights');

            $service = $this->buildInsightsService($peopleRepo, $insightsRepo);

            try {
                $service->getPartnerInsights($payeeId, $businessId);
                $this->fail(sprintf(
                    'Iteration %d: getPartnerInsights(%d, %d) should throw RuntimeException.',
                    $i, $payeeId, $businessId
                ));
            } catch (\RuntimeException $e) {
                $this->assertSame('Payee not found', $e->getMessage());
            }
        }
    }

    /**
     * Property 8: InsightsService::getCustomerItems throws RuntimeException
     * when payee does not belong to business.
     *
     * **Validates: Requirements 11.1, 11.4**
     */
    public function testInsightsCustomerItemsThrowsWhenPayeeNotInBusiness(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId = $this->generateRandomPayeeId();
            $businessId = $this->generateRandomBusinessId();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->expects($this->once())
                ->method('verifyPayeeBelongsToBusiness')
                ->with($payeeId, $businessId)
                ->willReturn(null);

            $insightsRepo = $this->createMock(InsightsRepository::class);
            $insightsRepo->expects($this->never())
                ->method('getCustomerItems');

            $service = $this->buildInsightsService($peopleRepo, $insightsRepo);

            try {
                $service->getCustomerItems($payeeId, $businessId);
                $this->fail(sprintf(
                    'Iteration %d: getCustomerItems(%d, %d) should throw RuntimeException.',
                    $i, $payeeId, $businessId
                ));
            } catch (\RuntimeException $e) {
                $this->assertSame('Payee not found', $e->getMessage());
            }
        }
    }

    /**
     * Property 8: PayeeStatementService::getStatement throws RuntimeException
     * when payee does not belong to business.
     *
     * **Validates: Requirements 11.2, 11.4**
     */
    public function testStatementServiceThrowsWhenPayeeNotInBusiness(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId = $this->generateRandomPayeeId();
            $businessId = $this->generateRandomBusinessId();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->expects($this->once())
                ->method('verifyPayeeBelongsToBusiness')
                ->with($payeeId, $businessId)
                ->willReturn(null);

            $statementRepo = $this->createMock(PayeeStatementRepository::class);
            // Ensure no data query is executed before verification
            $statementRepo->expects($this->never())
                ->method('getTransactions');
            $statementRepo->expects($this->never())
                ->method('calculateOpeningBalance');

            $service = $this->buildStatementService($peopleRepo, $statementRepo);

            try {
                $service->getStatement($payeeId, $businessId, '2024-01-01', '2024-12-31');
                $this->fail(sprintf(
                    'Iteration %d: getStatement(%d, %d) should throw RuntimeException.',
                    $i, $payeeId, $businessId
                ));
            } catch (\RuntimeException $e) {
                $this->assertSame('Payee not found', $e->getMessage());
            }
        }
    }

    /**
     * Property 8: Verification occurs BEFORE any data query in InsightsService.
     *
     * For any random payee/business pair, even when the insights repo would return data,
     * the service SHALL throw before reaching the data query when verification fails.
     *
     * **Validates: Requirements 11.1, 11.4**
     */
    public function testVerificationPrecedesDataQueryInInsightsService(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId = $this->generateRandomPayeeId();
            $businessId = $this->generateRandomBusinessId();

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->method('verifyPayeeBelongsToBusiness')
                ->willReturn(null);

            $insightsRepo = $this->createMock(InsightsRepository::class);
            // These should never be called — if they are, verification is not happening first
            $insightsRepo->expects($this->never())->method('getSupplierItemInsights');
            $insightsRepo->expects($this->never())->method('getCustomerItemInsights');
            $insightsRepo->expects($this->never())->method('getCustomerInvoiceFallback');
            $insightsRepo->expects($this->never())->method('getGeneralInsights');
            $insightsRepo->expects($this->never())->method('getPartnerInsights');
            $insightsRepo->expects($this->never())->method('getCustomerItems');

            $service = $this->buildInsightsService($peopleRepo, $insightsRepo);

            // Pick a random method to call
            $methods = ['getSupplierInsights', 'getCustomerInsights', 'getGeneralInsights', 'getPartnerInsights', 'getCustomerItems'];
            $method = $methods[array_rand($methods)];

            try {
                $service->$method($payeeId, $businessId);
                $this->fail(sprintf(
                    'Iteration %d: %s(%d, %d) should throw RuntimeException.',
                    $i, $method, $payeeId, $businessId
                ));
            } catch (\RuntimeException $e) {
                $this->assertSame('Payee not found', $e->getMessage());
            }
        }
    }

    /**
     * Property 8: Verification occurs BEFORE any data query in PayeeStatementService.
     *
     * For any random payee/business pair with valid dates, the service SHALL throw
     * before reaching any statement repository query when verification fails.
     *
     * **Validates: Requirements 11.2, 11.4**
     */
    public function testVerificationPrecedesDataQueryInStatementService(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId = $this->generateRandomPayeeId();
            $businessId = $this->generateRandomBusinessId();

            // Generate valid date range so we don't trip date validation
            $year = mt_rand(2020, 2025);
            $month = mt_rand(1, 12);
            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $endDate = sprintf('%04d-%02d-28', $year, $month);

            $peopleRepo = $this->createMock(PeopleRepository::class);
            $peopleRepo->expects($this->once())
                ->method('verifyPayeeBelongsToBusiness')
                ->with($payeeId, $businessId)
                ->willReturn(null);

            $statementRepo = $this->createMock(PayeeStatementRepository::class);
            $statementRepo->expects($this->never())->method('getTransactions');
            $statementRepo->expects($this->never())->method('calculateOpeningBalance');

            $service = $this->buildStatementService($peopleRepo, $statementRepo);

            try {
                $service->getStatement($payeeId, $businessId, $startDate, $endDate);
                $this->fail(sprintf(
                    'Iteration %d: getStatement(%d, %d, %s, %s) should throw RuntimeException.',
                    $i, $payeeId, $businessId, $startDate, $endDate
                ));
            } catch (\RuntimeException $e) {
                $this->assertSame('Payee not found', $e->getMessage());
            }
        }
    }
}
