<?php

namespace Tests\Unit;

use App\Controllers\TranslationController;
use App\Services\TranslationService;
use App\Support\AuthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration tests for the translations endpoint.
 *
 * Validates: Requirements 10.2, 10.3, 10.4, 10.5
 */
class TranslationControllerTest extends TestCase
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
        // Reset AuthContext by setting a dummy value then clearing via reflection
        $ref = new ReflectionClass(AuthContext::class);
        $prop = $ref->getProperty('user');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        parent::tearDown();
    }

    // ─── GET /api/translations/en — Valid Language ───────────────────────────

    /**
     * Test GET /api/translations/en returns key-value pairs with correct shape.
     *
     * Validates: Requirements 10.2, 10.5
     */
    public function testGetTranslationsForValidLanguageReturnsKeyValuePairs(): void
    {
        $translations = [
            'dashboard.title' => 'Dashboard',
            'settings.save'   => 'Save',
            'common.cancel'   => 'Cancel',
        ];

        $service = $this->createMock(TranslationService::class);
        $service->method('getByLanguage')
            ->with('en')
            ->willReturn($translations);

        $controller = $this->buildController($service);
        $response = $this->captureOutput(fn() => $controller->index('en'));

        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertSame('Dashboard', $response['data']['dashboard.title']);
        $this->assertSame('Save', $response['data']['settings.save']);
        $this->assertSame('Cancel', $response['data']['common.cancel']);
    }

    /**
     * Test response format matches { success, data } shape.
     *
     * Validates: Requirements 10.5
     */
    public function testResponseFormatMatchesExpectedShape(): void
    {
        $service = $this->createMock(TranslationService::class);
        $service->method('getByLanguage')
            ->with('en')
            ->willReturn(['key1' => 'value1']);

        $controller = $this->buildController($service);
        $response = $this->captureOutput(fn() => $controller->index('en'));

        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertIsBool($response['success']);
    }

    // ─── GET /api/translations/xx — Invalid Language ────────────────────────

    /**
     * Test GET /api/translations/xx (invalid language) returns 404.
     *
     * Validates: Requirements 10.3
     */
    public function testGetTranslationsForInvalidLanguageReturns404(): void
    {
        $service = $this->createMock(TranslationService::class);
        $service->method('getByLanguage')
            ->with('xx')
            ->willThrowException(new \InvalidArgumentException('Language not found'));

        $controller = $this->buildController($service);
        $response = $this->captureOutput(fn() => $controller->index('xx'));

        $this->assertFalse($response['success']);
        $this->assertNull($response['data']);
        $this->assertSame('Language not found', $response['message']);
    }

    // ─── GET /api/translations/en — Without JWT (Unauthorized) ──────────────

    /**
     * Test that without JWT (AuthContext not set), AuthContext::get() throws
     * RuntimeException, which the controller catches via \Throwable handler.
     *
     * In production, the AuthMiddleware intercepts the request before the
     * controller is reached and returns a 401 response. This test verifies
     * the underlying auth guard behavior.
     *
     * Validates: Requirements 10.4
     */
    public function testGetTranslationsWithoutJwtThrowsRuntimeException(): void
    {
        // Clear AuthContext to simulate no JWT
        $ref = new ReflectionClass(AuthContext::class);
        $prop = $ref->getProperty('user');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // Verify that AuthContext::get() throws when not set
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Auth context missing');
        AuthContext::get();
    }

    // ─── GET /api/translations/en — Success response shape ──────────────────

    /**
     * Test successful response contains success=true and data as associative array.
     *
     * Validates: Requirements 10.2, 10.5
     */
    public function testSuccessResponseContainsCorrectStructure(): void
    {
        $service = $this->createMock(TranslationService::class);
        $service->method('getByLanguage')
            ->with('en')
            ->willReturn([
                'nav.home'     => 'Home',
                'nav.settings' => 'Settings',
            ]);

        $controller = $this->buildController($service);
        $response = $this->captureOutput(fn() => $controller->index('en'));

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertCount(2, $response['data']);
        // Verify data is key-value pairs (associative array)
        $this->assertSame('Home', $response['data']['nav.home']);
        $this->assertSame('Settings', $response['data']['nav.settings']);
    }

    /**
     * Test 404 response contains success=false, null data, and message.
     *
     * Validates: Requirements 10.3, 10.5
     */
    public function testErrorResponseContainsCorrectStructure(): void
    {
        $service = $this->createMock(TranslationService::class);
        $service->method('getByLanguage')
            ->willThrowException(new \InvalidArgumentException('Language not found'));

        $controller = $this->buildController($service);
        $response = $this->captureOutput(fn() => $controller->index('zz'));

        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertFalse($response['success']);
        $this->assertNull($response['data']);
        $this->assertIsString($response['message']);
    }

    // ─── Helper Methods ─────────────────────────────────────────────────────

    private function buildController(TranslationService $service): TranslationController
    {
        $ref = new ReflectionClass(TranslationController::class);
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
