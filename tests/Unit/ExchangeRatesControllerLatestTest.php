<?php

namespace Tests\Unit;

use App\Controllers\ExchangeRatesController;
use App\Services\ExchangeRateService;
use App\Support\AuthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ExchangeRatesController::latest() endpoint.
 *
 * Validates: Requirements 3.1, 3.2, 3.3, 3.4
 */
class ExchangeRatesControllerLatestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up auth context so $this->auth() doesn't throw
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up $_GET
        unset($_GET['currency']);
        parent::tearDown();
    }

    /**
     * Build a controller with a stubbed ExchangeRateService injected via reflection.
     */
    private function buildControllerWithService(ExchangeRateService $service): ExchangeRatesController
    {
        $ref = new ReflectionClass(ExchangeRatesController::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('service');
        $prop->setAccessible(true);
        $prop->setValue($controller, $service);

        return $controller;
    }

    /**
     * Create a mock ExchangeRateService that returns the given result from getLatestForLookup.
     */
    private function makeServiceStub(array $lookupResult): ExchangeRateService
    {
        $service = $this->createMock(ExchangeRateService::class);
        $service->method('getLatestForLookup')
            ->willReturn($lookupResult);

        return $service;
    }

    /**
     * Create a mock ExchangeRateService that throws InvalidArgumentException from getLatestForLookup.
     */
    private function makeServiceStubThatThrows(string $message): ExchangeRateService
    {
        $service = $this->createMock(ExchangeRateService::class);
        $service->method('getLatestForLookup')
            ->willThrowException(new \InvalidArgumentException($message));

        return $service;
    }

    /**
     * Call the latest() method and capture the JSON output.
     */
    private function callLatest(ExchangeRatesController $controller): array
    {
        ob_start();
        $controller->latest();
        $output = ob_get_clean();

        return json_decode($output, true);
    }

    // ─── Tests ──────────────────────────────────────────────────────────────────

    /**
     * Test that a valid foreign currency returns rate, rate_date, and is_base.
     *
     * Validates: Requirements 3.1, 3.2
     */
    public function testValidCurrencyReturnsRateAndMetadata(): void
    {
        $_GET['currency'] = 'EUR';

        $service = $this->makeServiceStub([
            'rate'      => 1.08,
            'rate_date' => '2025-01-15',
            'is_base'   => false,
        ]);

        $controller = $this->buildControllerWithService($service);
        $response = $this->callLatest($controller);

        $this->assertTrue($response['success']);
        $this->assertSame(1.08, $response['data']['rate']);
        $this->assertSame('2025-01-15', $response['data']['rate_date']);
        $this->assertFalse($response['data']['is_base']);
    }

    /**
     * Test that base currency returns rate: 1 and is_base: true.
     *
     * Validates: Requirements 3.3
     */
    public function testBaseCurrencyReturnsRateOneAndIsBaseTrue(): void
    {
        $_GET['currency'] = 'USD';

        $service = $this->makeServiceStub([
            'rate'      => 1,
            'rate_date' => null,
            'is_base'   => true,
        ]);

        $controller = $this->buildControllerWithService($service);
        $response = $this->callLatest($controller);

        $this->assertTrue($response['success']);
        $this->assertSame(1, $response['data']['rate']);
        $this->assertNull($response['data']['rate_date']);
        $this->assertTrue($response['data']['is_base']);
    }

    /**
     * Test that empty currency returns 422 error with "Currency is required" message.
     *
     * Validates: Requirements 3.4
     */
    public function testEmptyCurrencyReturns422Error(): void
    {
        $_GET['currency'] = '';

        // Service should not be called, but provide a stub anyway
        $service = $this->makeServiceStub([
            'rate'      => null,
            'rate_date' => null,
            'is_base'   => false,
        ]);

        $controller = $this->buildControllerWithService($service);
        $response = $this->callLatest($controller);

        $this->assertFalse($response['success']);
        $this->assertSame('Currency is required', $response['message']);
    }

    /**
     * Test that missing currency parameter returns 422 error.
     *
     * Validates: Requirements 3.4
     */
    public function testMissingCurrencyParameterReturns422Error(): void
    {
        // Don't set $_GET['currency'] at all
        unset($_GET['currency']);

        $service = $this->makeServiceStub([
            'rate'      => null,
            'rate_date' => null,
            'is_base'   => false,
        ]);

        $controller = $this->buildControllerWithService($service);
        $response = $this->callLatest($controller);

        $this->assertFalse($response['success']);
        $this->assertSame('Currency is required', $response['message']);
    }

    /**
     * Test that a currency with no rates returns rate: null.
     *
     * Validates: Requirements 3.2, 3.3
     */
    public function testCurrencyWithNoRatesReturnsNullRate(): void
    {
        $_GET['currency'] = 'JPY';

        $service = $this->makeServiceStub([
            'rate'      => null,
            'rate_date' => null,
            'is_base'   => false,
        ]);

        $controller = $this->buildControllerWithService($service);
        $response = $this->callLatest($controller);

        $this->assertTrue($response['success']);
        $this->assertNull($response['data']['rate']);
        $this->assertNull($response['data']['rate_date']);
        $this->assertFalse($response['data']['is_base']);
    }
}
