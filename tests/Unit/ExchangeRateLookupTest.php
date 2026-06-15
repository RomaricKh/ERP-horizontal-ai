<?php

namespace Tests\Unit;

use App\Repository\BusinessRepository;
use App\Repository\ExchangeRateRepository;
use App\Services\ExchangeRateService;
use App\Support\AuthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ExchangeRateService::getLatestForLookup and resolveRate.
 *
 * Validates: Requirements 1.1, 1.3, 1.4, 3.3, 3.4, 7.1, 7.2, 7.3
 */
class ExchangeRateLookupTest extends TestCase
{
    private function buildServiceWithStubs(
        BusinessRepository $businessRepo,
        ExchangeRateRepository $exchangeRateRepo
    ): ExchangeRateService {
        $ref     = new ReflectionClass(ExchangeRateService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'businessRepo' => $businessRepo,
            'repo'         => $exchangeRateRepo,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    private function makeBusinessRepo(int $businessId, string $currency): BusinessRepository
    {
        return new class($businessId, $currency) extends BusinessRepository {
            private int $bizId;
            private string $bizCurrency;

            public function __construct(int $bizId, string $bizCurrency)
            {
                $this->bizId = $bizId;
                $this->bizCurrency = $bizCurrency;
            }

            public function getBusinessByID(int $business_id): ?array
            {
                return [
                    'id'       => $this->bizId,
                    'currency' => $this->bizCurrency,
                ];
            }
        };
    }

    private function makeExchangeRateRepo(?array $rateRow): ExchangeRateRepository
    {
        return new class($rateRow) extends ExchangeRateRepository {
            private ?array $rateRow;

            public function __construct(?array $rateRow)
            {
                // Skip parent constructor (needs PDO)
                $this->rateRow = $rateRow;
            }

            public function getLatestRate(string $currency, int $businessId): ?array
            {
                return $this->rateRow;
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 100,
        ]);
    }

    // ─── getLatestForLookup tests ───────────────────────────────────────────────

    /**
     * Test that getLatestForLookup with empty string throws InvalidArgumentException.
     *
     * Validates: Requirements 1.1, 3.4
     */
    public function testGetLatestForLookupWithEmptyStringThrowsException(): void
    {
        $service = $this->buildServiceWithStubs(
            $this->makeBusinessRepo(100, 'USD'),
            $this->makeExchangeRateRepo(null)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency is required');

        $service->getLatestForLookup('');
    }

    /**
     * Test that getLatestForLookup with whitespace-only string throws InvalidArgumentException.
     *
     * Validates: Requirements 1.1, 3.4
     */
    public function testGetLatestForLookupWithWhitespaceOnlyThrowsException(): void
    {
        $service = $this->buildServiceWithStubs(
            $this->makeBusinessRepo(100, 'USD'),
            $this->makeExchangeRateRepo(null)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency is required');

        $service->getLatestForLookup('   ');
    }

    /**
     * Test that getLatestForLookup with a currency that has no rates returns rate: null.
     *
     * Validates: Requirements 1.4, 3.3
     */
    public function testGetLatestForLookupWithNoRatesReturnsNull(): void
    {
        $service = $this->buildServiceWithStubs(
            $this->makeBusinessRepo(100, 'USD'),
            $this->makeExchangeRateRepo(null) // No rate found
        );

        $result = $service->getLatestForLookup('EUR');

        $this->assertNull($result['rate']);
        $this->assertNull($result['rate_date']);
        $this->assertFalse($result['is_base']);
    }

    /**
     * Test that getLatestForLookup with base currency returns rate: 1, is_base: true.
     *
     * Validates: Requirements 1.3, 3.3
     */
    public function testGetLatestForLookupWithBaseCurrencyReturnsOne(): void
    {
        $service = $this->buildServiceWithStubs(
            $this->makeBusinessRepo(100, 'USD'),
            $this->makeExchangeRateRepo(null)
        );

        $result = $service->getLatestForLookup('USD');

        $this->assertSame(1, $result['rate']);
        $this->assertNull($result['rate_date']);
        $this->assertTrue($result['is_base']);
    }

    /**
     * Test that getLatestForLookup with a foreign currency that has a rate returns it.
     *
     * Validates: Requirements 1.1, 3.2
     */
    public function testGetLatestForLookupWithExistingRateReturnsIt(): void
    {
        $rateRow = [
            'id'          => 5,
            'tenant_id'   => 1,
            'business_id' => 100,
            'currency'    => 'EUR',
            'rate_date'   => '2025-01-15',
            'rate'        => 1.08,
            'source'      => null,
            'created_at'  => '2025-01-15 10:00:00',
        ];

        $service = $this->buildServiceWithStubs(
            $this->makeBusinessRepo(100, 'USD'),
            $this->makeExchangeRateRepo($rateRow)
        );

        $result = $service->getLatestForLookup('EUR');

        $this->assertSame(1.08, $result['rate']);
        $this->assertSame('2025-01-15', $result['rate_date']);
        $this->assertFalse($result['is_base']);
    }

    // ─── resolveRate tests ──────────────────────────────────────────────────────

    /**
     * Test that resolveRate with provided rate returns that rate unchanged.
     *
     * Validates: Requirements 7.1, 7.3
     */
    public function testResolveRateWithProvidedRateReturnsItUnchanged(): void
    {
        $service = $this->buildServiceWithStubs(
            $this->makeBusinessRepo(100, 'USD'),
            $this->makeExchangeRateRepo(null) // Repo should not matter
        );

        $result = $service->resolveRate('EUR', 1.25);

        $this->assertSame(1.25, $result);
    }

    /**
     * Test that resolveRate with null rate and existing table rate returns the table rate.
     *
     * Validates: Requirements 7.1, 7.2
     */
    public function testResolveRateWithNullRateAndExistingTableRateReturnsTableRate(): void
    {
        $rateRow = [
            'id'          => 10,
            'tenant_id'   => 1,
            'business_id' => 100,
            'currency'    => 'GBP',
            'rate_date'   => '2025-02-01',
            'rate'        => 0.79,
            'source'      => null,
            'created_at'  => '2025-02-01 08:00:00',
        ];

        $service = $this->buildServiceWithStubs(
            $this->makeBusinessRepo(100, 'USD'),
            $this->makeExchangeRateRepo($rateRow)
        );

        $result = $service->resolveRate('GBP', null);

        $this->assertEqualsWithDelta(0.79, $result, 0.001);
    }

    /**
     * Test that resolveRate with null rate and no table rate throws exception.
     *
     * Validates: Requirements 7.2, 7.3
     */
    public function testResolveRateWithNullRateAndNoTableRateThrowsException(): void
    {
        $service = $this->buildServiceWithStubs(
            $this->makeBusinessRepo(100, 'USD'),
            $this->makeExchangeRateRepo(null) // No rate in table
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exchange rate is required for currency JPY');

        $service->resolveRate('JPY', null);
    }
}
