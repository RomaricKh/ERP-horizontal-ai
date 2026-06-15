<?php

namespace Tests\Property;

use App\Repository\BusinessRepository;
use App\Repository\ExchangeRateRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: auto-exchange-rate, Property 1: Latest rate lookup returns the most recent entry
 *
 * For any business with a set of exchange rate entries for a given currency,
 * calling `getLatestForLookup` with that currency SHALL return the rate whose
 * `rate_date` is the most recent (maximum) among all entries for that currency
 * and business.
 *
 * **Validates: Requirements 1.1, 3.2**
 */
class ExchangeRateLookupPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random sets of exchange rate rows.
     *
     * Each case: [businessId, businessCurrency, foreignCurrency, rateRows]
     * where rateRows is an array of ['rate_date' => ..., 'rate' => ...] entries.
     */
    public static function latestRateLookupProvider(): array
    {
        $cases = [];
        $currencies = ['EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'BRL', 'INR', 'MXN'];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $businessId = mt_rand(1, 10000);
            // Base currency is always USD for this test to ensure foreign != base
            $businessCurrency = 'USD';
            // Pick a random foreign currency (never USD)
            $foreignCurrency = $currencies[array_rand($currencies)];

            // Generate between 2 and 10 rate rows with varying dates and rates
            $numRows = mt_rand(2, 10);
            $rateRows = [];
            for ($j = 0; $j < $numRows; $j++) {
                // Random date within the last 2 years
                $daysAgo = mt_rand(0, 730);
                $rateDate = date('Y-m-d', strtotime("-{$daysAgo} days"));
                // Random rate between 0.01 and 999.99
                $rate = round(mt_rand(1, 99999) / 100, 2);
                $rateRows[] = [
                    'rate_date' => $rateDate,
                    'rate'      => $rate,
                ];
            }

            $cases["biz_{$businessId}_{$foreignCurrency}_{$numRows}rows_iter_{$i}"] = [
                $businessId,
                $businessCurrency,
                $foreignCurrency,
                $rateRows,
            ];
        }

        return $cases;
    }

    /**
     * Property 1: Latest rate lookup returns the most recent entry
     *
     * **Validates: Requirements 1.1, 3.2**
     *
     * @dataProvider latestRateLookupProvider
     */
    public function testLatestRateLookupReturnsTheMostRecentEntry(
        int $businessId,
        string $businessCurrency,
        string $foreignCurrency,
        array $rateRows
    ): void {
        // Determine the expected result: the row with the maximum rate_date
        $expectedRow = null;
        foreach ($rateRows as $row) {
            if ($expectedRow === null || $row['rate_date'] > $expectedRow['rate_date']) {
                $expectedRow = $row;
            }
        }

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Build BusinessRepository stub that returns the business with its base currency
        $businessRepo = new class($businessId, $businessCurrency) extends BusinessRepository {
            private int $bizId;
            private string $bizCurrency;

            public function __construct(int $bizId, string $bizCurrency)
            {
                // Skip parent constructor (needs PDO)
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

        // Build ExchangeRateRepository stub that simulates getLatestRate
        // It should return the row with the maximum rate_date for the given currency
        $latestRow = $expectedRow;
        $exchangeRateRepo = new class($foreignCurrency, $businessId, $latestRow) extends ExchangeRateRepository {
            private string $expectedCurrency;
            private int $expectedBusinessId;
            private ?array $latestRow;

            public function __construct(string $expectedCurrency, int $expectedBusinessId, ?array $latestRow)
            {
                // Skip parent constructor (needs PDO)
                $this->expectedCurrency = $expectedCurrency;
                $this->expectedBusinessId = $expectedBusinessId;
                $this->latestRow = $latestRow;
            }

            public function getLatestRate(string $currency, int $businessId): ?array
            {
                // Simulate the DB behavior: return the row with the max rate_date
                if ($currency === $this->expectedCurrency && $businessId === $this->expectedBusinessId) {
                    return [
                        'id'          => 1,
                        'tenant_id'   => 1,
                        'business_id' => $this->expectedBusinessId,
                        'currency'    => $this->expectedCurrency,
                        'rate_date'   => $this->latestRow['rate_date'],
                        'rate'        => $this->latestRow['rate'],
                        'source'      => null,
                        'created_at'  => '2025-01-01 00:00:00',
                    ];
                }
                return null;
            }
        };

        // Build the service with stubbed dependencies via reflection
        $service = $this->buildServiceWithStubs($businessRepo, $exchangeRateRepo);

        // Call getLatestForLookup
        $result = $service->getLatestForLookup($foreignCurrency);

        // Assert the returned rate matches the row with the maximum rate_date
        $this->assertSame(false, $result['is_base'], 'Foreign currency should not be flagged as base');
        $this->assertNotNull($result['rate'], 'Rate should not be null when rows exist');
        $this->assertEqualsWithDelta(
            (float) $expectedRow['rate'],
            $result['rate'],
            0.001,
            sprintf(
                'Expected rate %.2f (from date %s) but got %.2f. '
                . 'Currency: %s, Business: %d, Rows: %d',
                $expectedRow['rate'],
                $expectedRow['rate_date'],
                $result['rate'],
                $foreignCurrency,
                $businessId,
                count($rateRows)
            )
        );
        $this->assertSame(
            $expectedRow['rate_date'],
            $result['rate_date'],
            sprintf(
                'Expected rate_date %s but got %s. Currency: %s, Business: %d',
                $expectedRow['rate_date'],
                $result['rate_date'] ?? 'null',
                $foreignCurrency,
                $businessId
            )
        );
    }

    /**
     * Data provider generating 120 random business currency values for Property 2.
     *
     * Each case: [businessId, baseCurrency]
     * The test will call getLatestForLookup with the same currency as the business base currency.
     */
    public static function baseCurrencyResolvesToOneProvider(): array
    {
        $cases = [];
        $currencies = [
            'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'BRL', 'INR',
            'MXN', 'KRW', 'SGD', 'HKD', 'NOK', 'SEK', 'DKK', 'NZD', 'ZAR', 'TRY',
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $businessId = mt_rand(1, 10000);
            $baseCurrency = $currencies[array_rand($currencies)];

            $cases["base_{$baseCurrency}_biz_{$businessId}_iter_{$i}"] = [
                $businessId,
                $baseCurrency,
            ];
        }

        return $cases;
    }

    /**
     * Property 2: Base currency always resolves to rate 1
     *
     * For any business with any base currency value, calling `getLatestForLookup`
     * with a currency code equal to the business's base currency SHALL return a
     * rate of exactly 1, regardless of what entries exist in the exchange rates table.
     *
     * **Validates: Requirements 1.3, 3.3**
     *
     * @dataProvider baseCurrencyResolvesToOneProvider
     */
    public function testBaseCurrencyAlwaysResolvesToRateOne(
        int $businessId,
        string $baseCurrency
    ): void {
        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Build BusinessRepository stub that returns the business with the given base currency
        $businessRepo = new class($businessId, $baseCurrency) extends BusinessRepository {
            private int $bizId;
            private string $bizCurrency;

            public function __construct(int $bizId, string $bizCurrency)
            {
                // Skip parent constructor (needs PDO)
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

        // Build ExchangeRateRepository stub - it should NOT be called for base currency
        // but we provide one that returns a non-1 rate to prove the short-circuit works
        $exchangeRateRepo = new class($baseCurrency, $businessId) extends ExchangeRateRepository {
            private string $currency;
            private int $businessId;

            public function __construct(string $currency, int $businessId)
            {
                // Skip parent constructor (needs PDO)
                $this->currency = $currency;
                $this->businessId = $businessId;
            }

            public function getLatestRate(string $currency, int $businessId): ?array
            {
                // If this is called for the base currency, it means the short-circuit failed.
                // Return a non-1 rate to make the test fail if the short-circuit is broken.
                return [
                    'id'          => 99,
                    'tenant_id'   => 1,
                    'business_id' => $this->businessId,
                    'currency'    => $this->currency,
                    'rate_date'   => '2025-01-01',
                    'rate'        => 99.99,
                    'source'      => null,
                    'created_at'  => '2025-01-01 00:00:00',
                ];
            }
        };

        // Build the service with stubbed dependencies via reflection
        $service = $this->buildServiceWithStubs($businessRepo, $exchangeRateRepo);

        // Call getLatestForLookup with the base currency
        $result = $service->getLatestForLookup($baseCurrency);

        // Assert rate === 1 and is_base === true
        $this->assertSame(
            1,
            $result['rate'],
            sprintf(
                'Base currency %s should always resolve to rate 1, got %s. Business: %d',
                $baseCurrency,
                var_export($result['rate'], true),
                $businessId
            )
        );
        $this->assertNull(
            $result['rate_date'],
            sprintf(
                'Base currency %s should have null rate_date, got %s. Business: %d',
                $baseCurrency,
                var_export($result['rate_date'], true),
                $businessId
            )
        );
        $this->assertTrue(
            $result['is_base'],
            sprintf(
                'Base currency %s should have is_base === true. Business: %d',
                $baseCurrency,
                $businessId
            )
        );
    }

    /**
     * Data provider generating 120 random scenarios for Property 3.
     *
     * Each case: [businessId, baseCurrency, foreignCurrency, providedRate, rateTableEntry]
     * where:
     * - providedRate is float|null (user-provided rate or null)
     * - rateTableEntry is array|null (latest rate row from table, or null if empty table)
     *
     * We generate three scenario types per iteration cycle:
     * - Scenario A: providedRate is non-null (user provided a rate)
     * - Scenario B: providedRate is null, rateTableEntry is non-null (fallback to table)
     * - Scenario C: providedRate is null, rateTableEntry is null (empty table, should throw)
     */
    public static function resolveRateProvider(): array
    {
        $cases = [];
        $currencies = ['EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'BRL', 'INR', 'MXN'];

        // Generate 40 iterations of each scenario type = 120 total
        for ($i = 0; $i < 40; $i++) {
            $businessId = mt_rand(1, 10000);
            $baseCurrency = 'USD';
            $foreignCurrency = $currencies[array_rand($currencies)];

            // Scenario A: User provides a rate (should be preserved)
            $providedRate = round(mt_rand(1, 99999) / 100, 2);
            // Table may or may not have a rate - doesn't matter when user provides one
            $tableRate = mt_rand(0, 1) === 1
                ? ['rate' => round(mt_rand(1, 99999) / 100, 2), 'rate_date' => date('Y-m-d', strtotime('-' . mt_rand(0, 730) . ' days'))]
                : null;

            $cases["scenarioA_provided_rate_biz_{$businessId}_{$foreignCurrency}_iter_{$i}"] = [
                'scenarioType'    => 'A',
                'businessId'      => $businessId,
                'baseCurrency'    => $baseCurrency,
                'foreignCurrency' => $foreignCurrency,
                'providedRate'    => $providedRate,
                'rateTableEntry'  => $tableRate,
            ];

            // Scenario B: No user rate, table has a rate (should fall back to table)
            $businessId = mt_rand(1, 10000);
            $foreignCurrency = $currencies[array_rand($currencies)];
            $tableRate = [
                'rate'      => round(mt_rand(1, 99999) / 100, 2),
                'rate_date' => date('Y-m-d', strtotime('-' . mt_rand(0, 730) . ' days')),
            ];

            $cases["scenarioB_fallback_to_table_biz_{$businessId}_{$foreignCurrency}_iter_{$i}"] = [
                'scenarioType'    => 'B',
                'businessId'      => $businessId,
                'baseCurrency'    => $baseCurrency,
                'foreignCurrency' => $foreignCurrency,
                'providedRate'    => null,
                'rateTableEntry'  => $tableRate,
            ];

            // Scenario C: No user rate, empty table (should throw)
            $businessId = mt_rand(1, 10000);
            $foreignCurrency = $currencies[array_rand($currencies)];

            $cases["scenarioC_empty_table_throws_biz_{$businessId}_{$foreignCurrency}_iter_{$i}"] = [
                'scenarioType'    => 'C',
                'businessId'      => $businessId,
                'baseCurrency'    => $baseCurrency,
                'foreignCurrency' => $foreignCurrency,
                'providedRate'    => null,
                'rateTableEntry'  => null,
            ];
        }

        return $cases;
    }

    /**
     * Property 3: Backend rate resolution preserves user-provided rates and falls back correctly
     *
     * For any financial document submission, the resolved exchange rate SHALL equal the
     * user-provided rate when one is given, and SHALL equal the latest rate from the
     * exchange rates table when no rate is provided. If no rate is provided and no rate
     * exists in the table, the submission SHALL be rejected.
     *
     * **Validates: Requirements 7.1, 7.3**
     *
     * @dataProvider resolveRateProvider
     */
    public function testResolveRatePreservesUserProvidedRatesAndFallsBackCorrectly(
        string $scenarioType,
        int $businessId,
        string $baseCurrency,
        string $foreignCurrency,
        ?float $providedRate,
        ?array $rateTableEntry
    ): void {
        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Build BusinessRepository stub
        $businessRepo = new class($businessId, $baseCurrency) extends BusinessRepository {
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

        // Build ExchangeRateRepository stub based on rateTableEntry
        $exchangeRateRepo = new class($foreignCurrency, $businessId, $rateTableEntry) extends ExchangeRateRepository {
            private string $expectedCurrency;
            private int $expectedBusinessId;
            private ?array $rateTableEntry;

            public function __construct(string $expectedCurrency, int $expectedBusinessId, ?array $rateTableEntry)
            {
                $this->expectedCurrency = $expectedCurrency;
                $this->expectedBusinessId = $expectedBusinessId;
                $this->rateTableEntry = $rateTableEntry;
            }

            public function getLatestRate(string $currency, int $businessId): ?array
            {
                if ($currency === $this->expectedCurrency && $businessId === $this->expectedBusinessId) {
                    if ($this->rateTableEntry === null) {
                        return null;
                    }
                    return [
                        'id'          => 1,
                        'tenant_id'   => 1,
                        'business_id' => $this->expectedBusinessId,
                        'currency'    => $this->expectedCurrency,
                        'rate_date'   => $this->rateTableEntry['rate_date'],
                        'rate'        => $this->rateTableEntry['rate'],
                        'source'      => null,
                        'created_at'  => '2025-01-01 00:00:00',
                    ];
                }
                return null;
            }
        };

        // Build the service with stubbed dependencies via reflection
        $service = $this->buildServiceWithStubs($businessRepo, $exchangeRateRepo);

        switch ($scenarioType) {
            case 'A':
                // Scenario A: User provides a rate - it should be preserved exactly
                $result = $service->resolveRate($foreignCurrency, $providedRate);
                $this->assertSame(
                    $providedRate,
                    $result,
                    sprintf(
                        'Provided rate %.2f should be preserved, got %.2f. Currency: %s, Business: %d',
                        $providedRate,
                        $result,
                        $foreignCurrency,
                        $businessId
                    )
                );
                break;

            case 'B':
                // Scenario B: No user rate, table has a rate - should fall back to table rate
                $result = $service->resolveRate($foreignCurrency, null);
                $expectedRate = (float) $rateTableEntry['rate'];
                $this->assertEqualsWithDelta(
                    $expectedRate,
                    $result,
                    0.001,
                    sprintf(
                        'Expected fallback rate %.2f from table, got %.2f. Currency: %s, Business: %d',
                        $expectedRate,
                        $result,
                        $foreignCurrency,
                        $businessId
                    )
                );
                break;

            case 'C':
                // Scenario C: No user rate, empty table - should throw InvalidArgumentException
                $this->expectException(\InvalidArgumentException::class);
                $this->expectExceptionMessage("Exchange rate is required for currency {$foreignCurrency}");
                $service->resolveRate($foreignCurrency, null);
                break;
        }
    }

    /**
     * Data provider generating 120 random business currency scenarios for Property 4.
     *
     * Each case: [businessId, baseCurrency, foreignCurrency, testBaseCurrencyMatch]
     * We generate two scenario types per iteration cycle:
     * - Scenario A (60 cases): foreign currency != base currency → should query repo, is_base === false
     * - Scenario B (60 cases): currency === base currency → should short-circuit to rate 1
     */
    public static function baseCurrencySourcedFromBusinessProvider(): array
    {
        $cases = [];
        $currencies = [
            'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'BRL', 'INR',
            'MXN', 'KRW', 'SGD', 'HKD', 'NOK', 'SEK', 'DKK', 'NZD', 'ZAR', 'TRY',
        ];

        // Generate 60 iterations of each scenario type = 120 total
        for ($i = 0; $i < 60; $i++) {
            $businessId = mt_rand(1, 10000);
            // Pick a random base currency for the business
            $baseCurrency = $currencies[array_rand($currencies)];

            // Scenario A: Pick a foreign currency that is DIFFERENT from the base
            $foreignCurrency = $baseCurrency;
            while ($foreignCurrency === $baseCurrency) {
                $foreignCurrency = $currencies[array_rand($currencies)];
            }

            $cases["scenarioA_foreign_{$foreignCurrency}_base_{$baseCurrency}_biz_{$businessId}_iter_{$i}"] = [
                'scenarioType'    => 'A',
                'businessId'      => $businessId,
                'baseCurrency'    => $baseCurrency,
                'lookupCurrency'  => $foreignCurrency,
            ];

            // Scenario B: Lookup currency matches the business's base currency
            $businessId = mt_rand(1, 10000);
            $baseCurrency = $currencies[array_rand($currencies)];

            $cases["scenarioB_base_match_{$baseCurrency}_biz_{$businessId}_iter_{$i}"] = [
                'scenarioType'    => 'B',
                'businessId'      => $businessId,
                'baseCurrency'    => $baseCurrency,
                'lookupCurrency'  => $baseCurrency,
            ];
        }

        return $cases;
    }

    /**
     * Property 4: Base currency is always sourced from the business table
     *
     * For any business, the base currency used by `getLatestForLookup` for the
     * short-circuit comparison SHALL be the value of the `currency` field from the
     * `businesses` table for the current `business_id`, not a hardcoded or cached value.
     *
     * **Validates: Requirements 2.1**
     *
     * @dataProvider baseCurrencySourcedFromBusinessProvider
     */
    public function testBaseCurrencyIsAlwaysSourcedFromTheBusinessTable(
        string $scenarioType,
        int $businessId,
        string $baseCurrency,
        string $lookupCurrency
    ): void {
        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Build BusinessRepository stub that returns the business with the given random base currency
        $businessRepo = new class($businessId, $baseCurrency) extends BusinessRepository {
            private int $bizId;
            private string $bizCurrency;

            public function __construct(int $bizId, string $bizCurrency)
            {
                // Skip parent constructor (needs PDO)
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

        // Build ExchangeRateRepository stub that tracks whether it was called
        $repoWasCalled = false;
        $exchangeRateRepo = new class($lookupCurrency, $businessId, $repoWasCalled) extends ExchangeRateRepository {
            private string $expectedCurrency;
            private int $expectedBusinessId;
            public bool $wasCalled = false;

            public function __construct(string $expectedCurrency, int $expectedBusinessId, bool &$wasCalled)
            {
                // Skip parent constructor (needs PDO)
                $this->expectedCurrency = $expectedCurrency;
                $this->expectedBusinessId = $expectedBusinessId;
            }

            public function getLatestRate(string $currency, int $businessId): ?array
            {
                $this->wasCalled = true;
                // Return a rate to prove the repo was queried
                return [
                    'id'          => 1,
                    'tenant_id'   => 1,
                    'business_id' => $this->expectedBusinessId,
                    'currency'    => $currency,
                    'rate_date'   => '2025-01-15',
                    'rate'        => 1.5,
                    'source'      => null,
                    'created_at'  => '2025-01-01 00:00:00',
                ];
            }
        };

        // Build the service with stubbed dependencies via reflection
        $service = $this->buildServiceWithStubs($businessRepo, $exchangeRateRepo);

        // Call getLatestForLookup with the lookup currency
        $result = $service->getLatestForLookup($lookupCurrency);

        switch ($scenarioType) {
            case 'A':
                // Foreign currency: the service should NOT short-circuit, should query the repo
                $this->assertFalse(
                    $result['is_base'],
                    sprintf(
                        'Currency %s should NOT be identified as base when business base is %s. Business: %d',
                        $lookupCurrency,
                        $baseCurrency,
                        $businessId
                    )
                );
                $this->assertTrue(
                    $exchangeRateRepo->wasCalled,
                    sprintf(
                        'ExchangeRateRepository::getLatestRate should be called for foreign currency %s '
                        . '(business base: %s, business: %d)',
                        $lookupCurrency,
                        $baseCurrency,
                        $businessId
                    )
                );
                // The rate should come from the repo (1.5 in our stub)
                $this->assertEqualsWithDelta(
                    1.5,
                    $result['rate'],
                    0.001,
                    sprintf(
                        'Rate for foreign currency %s should come from repo, got %s. Business base: %s',
                        $lookupCurrency,
                        var_export($result['rate'], true),
                        $baseCurrency
                    )
                );
                break;

            case 'B':
                // Base currency match: the service should short-circuit to rate 1
                $this->assertTrue(
                    $result['is_base'],
                    sprintf(
                        'Currency %s should be identified as base when business base is %s. Business: %d',
                        $lookupCurrency,
                        $baseCurrency,
                        $businessId
                    )
                );
                $this->assertSame(
                    1,
                    $result['rate'],
                    sprintf(
                        'Base currency %s should resolve to rate 1, got %s. Business: %d',
                        $lookupCurrency,
                        var_export($result['rate'], true),
                        $businessId
                    )
                );
                $this->assertNull(
                    $result['rate_date'],
                    sprintf(
                        'Base currency %s should have null rate_date. Business: %d',
                        $lookupCurrency,
                        $businessId
                    )
                );
                $this->assertFalse(
                    $exchangeRateRepo->wasCalled,
                    sprintf(
                        'ExchangeRateRepository::getLatestRate should NOT be called when currency %s '
                        . 'matches business base %s (business: %d)',
                        $lookupCurrency,
                        $baseCurrency,
                        $businessId
                    )
                );
                break;
        }
    }

    /**
     * Build an ExchangeRateService with stubbed dependencies injected via reflection.
     */
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
}
