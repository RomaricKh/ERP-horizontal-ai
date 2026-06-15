<?php

namespace Tests\Unit;

use App\Controllers\SettingsController;
use App\Services\SettingsService;
use App\Support\AuthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration tests verifying GET /api/settings response includes the direction field.
 *
 * Validates: Requirements 11.1
 */
class SettingsControllerDirectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 100,
            'permissions' => [],
        ]);
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(AuthContext::class);
        $prop = $ref->getProperty('user');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        parent::tearDown();
    }

    // ─── GET /api/settings — Direction field present (RTL) ──────────────────

    /**
     * Test that GET /api/settings response includes the direction field with RTL value.
     *
     * Validates: Requirements 11.1
     */
    public function testGetSettingsResponseIncludesDirectionFieldRTL(): void
    {
        $settingsData = [
            'business_id'     => 100,
            'language'        => 'ar',
            'timezone'        => 'Asia/Beirut',
            'date_format'     => 'd/m/Y',
            'number_format'   => '1.234,56',
            'currency_format' => 'USD',
            'phone_format'    => '+961 ## ### ###',
            'direction'       => 'RTL',
        ];

        $service = $this->createMock(SettingsService::class);
        $service->method('get')->willReturn($settingsData);

        $controller = $this->buildController($service);
        $response = $this->captureOutput(fn() => $controller->show());

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('direction', $response['data']);
        $this->assertSame('RTL', $response['data']['direction']);
    }

    // ─── GET /api/settings — Direction field present (LTR) ──────────────────

    /**
     * Test that GET /api/settings response includes the direction field with LTR value.
     *
     * Validates: Requirements 11.1
     */
    public function testGetSettingsResponseIncludesDirectionFieldLTR(): void
    {
        $settingsData = [
            'business_id'     => 100,
            'language'        => 'en',
            'timezone'        => 'UTC',
            'date_format'     => 'Y-m-d',
            'number_format'   => '1,234.56',
            'currency_format' => 'USD',
            'phone_format'    => '+1 (###) ###-####',
            'direction'       => 'LTR',
        ];

        $service = $this->createMock(SettingsService::class);
        $service->method('get')->willReturn($settingsData);

        $controller = $this->buildController($service);
        $response = $this->captureOutput(fn() => $controller->show());

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('direction', $response['data']);
        $this->assertSame('LTR', $response['data']['direction']);
    }

    // ─── GET /api/settings — Direction is valid value ───────────────────────

    /**
     * Test that the direction field contains only valid values (LTR or RTL).
     *
     * Validates: Requirements 11.1
     */
    public function testDirectionFieldContainsValidValue(): void
    {
        $settingsData = [
            'business_id'     => 100,
            'language'        => 'ar',
            'timezone'        => 'Asia/Beirut',
            'date_format'     => 'd/m/Y',
            'number_format'   => '1.234,56',
            'currency_format' => 'USD',
            'phone_format'    => '+961 ## ### ###',
            'direction'       => 'RTL',
        ];

        $service = $this->createMock(SettingsService::class);
        $service->method('get')->willReturn($settingsData);

        $controller = $this->buildController($service);
        $response = $this->captureOutput(fn() => $controller->show());

        $this->assertTrue($response['success']);
        $this->assertContains($response['data']['direction'], ['LTR', 'RTL']);
    }

    // ─── GET /api/settings — Default fallback includes direction ────────────

    /**
     * Test that the default fallback (no DB row) also includes direction field.
     *
     * Validates: Requirements 11.1
     */
    public function testDefaultFallbackIncludesDirectionField(): void
    {
        $defaultData = [
            'business_id'     => 100,
            'language'        => 'en',
            'timezone'        => 'UTC',
            'date_format'     => 'Y-m-d',
            'number_format'   => '1,234.56',
            'currency_format' => 'USD',
            'phone_format'    => '+1 (###) ###-####',
            'direction'       => 'LTR',
        ];

        $service = $this->createMock(SettingsService::class);
        $service->method('get')->willReturn($defaultData);

        $controller = $this->buildController($service);
        $response = $this->captureOutput(fn() => $controller->show());

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('direction', $response['data']);
        $this->assertSame('LTR', $response['data']['direction']);
    }

    // ─── Helper Methods ─────────────────────────────────────────────────────

    private function buildController(SettingsService $service): SettingsController
    {
        $ref = new ReflectionClass(SettingsController::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('service');
        $prop->setAccessible(true);
        $prop->setValue($controller, $service);

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
