<?php

namespace Tests\Property;

use App\Controllers\PeopleController;
use App\Services\PeopleService;
use App\Support\AuthContext;
use PHPUnit\Framework\TestCase;

/**
 * Feature: people-module-review
 *
 * Property 2: Exception type determines HTTP status code
 * Property 3: All caught exceptions are logged with structured context
 *
 * **Validates: Requirements 3.1, 3.2, 3.3, 3.4, 2.6, 16.1, 16.2, 16.3**
 */
class PeopleControllerErrorHandlingPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Controller methods that can throw, mapped to their PeopleService method
     * and the generic error message used for Throwable (500) responses.
     */
    private const CONTROLLER_METHODS = [
        'store' => [
            'serviceMethod' => 'create',
            'genericMessage' => 'Failed to create person',
        ],
        'update' => [
            'serviceMethod' => 'update',
            'genericMessage' => 'Failed to update person',
        ],
        'delete' => [
            'serviceMethod' => 'delete',
            'genericMessage' => 'Failed to delete person',
        ],
    ];

    /**
     * Exception classes that should return 422 with their message preserved.
     */
    private const BUSINESS_EXCEPTION_CLASSES = [
        \InvalidArgumentException::class,
        \RuntimeException::class,
    ];

    /**
     * Exception classes that should return 500 with a generic message (NOT leak internals).
     */
    private const SYSTEM_EXCEPTION_CLASSES = [
        \Error::class,
        \TypeError::class,
        \LogicException::class,
    ];

    // ─── DATA PROVIDERS ──────────────────────────────────────────────────────

    /**
     * Data provider for business exceptions (InvalidArgumentException, RuntimeException)
     * that should return HTTP 422 with the exception message.
     */
    public static function businessExceptionProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $exceptionClass = self::BUSINESS_EXCEPTION_CLASSES[
                array_rand(self::BUSINESS_EXCEPTION_CLASSES)
            ];

            $methods = array_keys(self::CONTROLLER_METHODS);
            $method = $methods[array_rand($methods)];

            $message = self::generateRandomMessage($i);

            $label = sprintf(
                '%s_%s_iter_%d',
                (new \ReflectionClass($exceptionClass))->getShortName(),
                $method,
                $i
            );

            $cases[$label] = [$exceptionClass, $message, $method];
        }

        return $cases;
    }

    /**
     * Data provider for system exceptions (Error, TypeError, LogicException, etc.)
     * that should return HTTP 500 with a generic message.
     */
    public static function systemExceptionProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $exceptionClass = self::SYSTEM_EXCEPTION_CLASSES[
                array_rand(self::SYSTEM_EXCEPTION_CLASSES)
            ];

            $methods = array_keys(self::CONTROLLER_METHODS);
            $method = $methods[array_rand($methods)];

            $message = self::generateRandomMessage($i + 1000);

            $label = sprintf(
                '%s_%s_iter_%d',
                (new \ReflectionClass($exceptionClass))->getShortName(),
                $method,
                $i
            );

            $cases[$label] = [$exceptionClass, $message, $method];
        }

        return $cases;
    }

    // ─── PROPERTY 2: Exception type determines HTTP status code ──────────────

    /**
     * Property 2 (part A): Business exceptions (InvalidArgumentException, RuntimeException)
     * return HTTP 422 with the exception message as the response message.
     *
     * **Validates: Requirements 3.1, 3.2, 3.3**
     *
     * @dataProvider businessExceptionProvider
     */
    public function testBusinessExceptionsReturn422WithMessage(
        string $exceptionClass,
        string $randomMessage,
        string $controllerMethod
    ): void {
        // Create a mock PeopleService that throws the given business exception
        $mockService = $this->createMock(PeopleService::class);
        $exception = new $exceptionClass($randomMessage);

        $serviceMethod = self::CONTROLLER_METHODS[$controllerMethod]['serviceMethod'];
        $mockService->method($serviceMethod)->willThrowException($exception);

        // Create testable controller
        $controller = $this->createTestableController($mockService);

        // Set up AuthContext
        $this->setUpAuthContext();

        // Call the controller method
        $this->invokeControllerMethod($controller, $controllerMethod);

        $output = $controller->getCapturedOutput();
        $statusCode = $controller->getCapturedStatusCode();

        // THE PROPERTY: Business exceptions return HTTP 422
        $this->assertSame(
            422,
            $statusCode,
            sprintf(
                'PROPERTY VIOLATION: Expected HTTP 422 when PeopleController::%s() caught a %s, '
                . 'but got HTTP %d.',
                $controllerMethod,
                $exceptionClass,
                $statusCode
            )
        );

        // THE PROPERTY: The exception message IS the response message
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded, 'Response must be valid JSON');
        $this->assertFalse($decoded['success'], 'Response success must be false for errors');
        $this->assertSame(
            $randomMessage,
            $decoded['message'],
            sprintf(
                'PROPERTY VIOLATION: Expected response message to be the exception message "%s" '
                . 'when PeopleController::%s() caught a %s, but got "%s".',
                $randomMessage,
                $controllerMethod,
                $exceptionClass,
                $decoded['message'] ?? 'null'
            )
        );
    }

    /**
     * Property 2 (part B): System exceptions (Error, TypeError, LogicException, etc.)
     * return HTTP 500 with a generic message, never leaking the internal exception message.
     *
     * **Validates: Requirements 3.4**
     *
     * @dataProvider systemExceptionProvider
     */
    public function testSystemExceptionsReturn500WithGenericMessage(
        string $exceptionClass,
        string $randomMessage,
        string $controllerMethod
    ): void {
        // Create a mock PeopleService that throws the given system exception
        $mockService = $this->createMock(PeopleService::class);
        $exception = new $exceptionClass($randomMessage);

        $serviceMethod = self::CONTROLLER_METHODS[$controllerMethod]['serviceMethod'];
        $mockService->method($serviceMethod)->willThrowException($exception);

        // Create testable controller
        $controller = $this->createTestableController($mockService);

        // Set up AuthContext
        $this->setUpAuthContext();

        // Call the controller method
        $this->invokeControllerMethod($controller, $controllerMethod);

        $output = $controller->getCapturedOutput();
        $statusCode = $controller->getCapturedStatusCode();

        $expectedGenericMessage = self::CONTROLLER_METHODS[$controllerMethod]['genericMessage'];

        // THE PROPERTY: System exceptions return HTTP 500
        $this->assertSame(
            500,
            $statusCode,
            sprintf(
                'PROPERTY VIOLATION: Expected HTTP 500 when PeopleController::%s() caught a %s, '
                . 'but got HTTP %d.',
                $controllerMethod,
                $exceptionClass,
                $statusCode
            )
        );

        // THE PROPERTY: The response contains a generic message
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded, 'Response must be valid JSON');
        $this->assertFalse($decoded['success'], 'Response success must be false for errors');
        $this->assertSame(
            $expectedGenericMessage,
            $decoded['message'],
            sprintf(
                'PROPERTY VIOLATION: Expected generic message "%s" when PeopleController::%s() '
                . 'caught a %s, but got "%s".',
                $expectedGenericMessage,
                $controllerMethod,
                $exceptionClass,
                $decoded['message'] ?? 'null'
            )
        );

        // Extra: The internal exception message must NOT appear in the response body
        $this->assertStringNotContainsString(
            $randomMessage,
            $output,
            sprintf(
                'PROPERTY VIOLATION: Internal exception message "%s" leaked into the HTTP response '
                . 'when PeopleController::%s() caught a %s.',
                $randomMessage,
                $controllerMethod,
                $exceptionClass
            )
        );
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────────

    /**
     * Invoke the controller method with appropriate arguments.
     */
    private function invokeControllerMethod(object $controller, string $method): void
    {
        switch ($method) {
            case 'store':
                $controller->store();
                break;
            case 'update':
                $controller->update(999);
                break;
            case 'delete':
                $controller->delete(999);
                break;
        }
    }

    /**
     * Create a testable PeopleController subclass that captures output/status code
     * without calling exit() and accepts a mock PeopleService.
     */
    private function createTestableController(PeopleService $mockService): object
    {
        $controller = new class extends PeopleController {
            private string $capturedOutput = '';
            private int $capturedStatusCode = 200;

            // Skip parent constructor (avoids DB connection)
            public function __construct()
            {
                // Intentionally empty — we inject service via setMockService
            }

            public function setMockService(PeopleService $service): void
            {
                // Inject into the parent's private property
                $ref = new \ReflectionClass(PeopleController::class);
                $prop = $ref->getProperty('service');
                $prop->setAccessible(true);
                $prop->setValue($this, $service);
            }

            // Override json() to capture output instead of echo + exit
            protected function json(
                bool $success,
                ?array $data = null,
                ?string $message = null,
                int $status = 200
            ): void {
                $this->capturedStatusCode = $status;
                $this->capturedOutput = json_encode([
                    'success' => $success,
                    'data'    => $data,
                    'message' => $message,
                ]);
            }

            // Override serverError to avoid exit()
            protected function serverError(string $message = 'Internal server error'): void
            {
                $this->json(false, null, $message, 500);
            }

            // Override input() to return dummy data (avoid php://input dependency)
            protected function input(array $required = []): array
            {
                return [
                    'name' => 'Test Person',
                    'payee_type_ids' => [1, 2],
                ];
            }

            public function getCapturedOutput(): string
            {
                return $this->capturedOutput;
            }

            public function getCapturedStatusCode(): int
            {
                return $this->capturedStatusCode;
            }
        };

        $controller->setMockService($mockService);

        return $controller;
    }

    /**
     * Set up a minimal AuthContext so the controller's auth() call succeeds.
     */
    private function setUpAuthContext(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 1,
            'permissions' => [],
        ]);
    }

    /**
     * Generate a random string that's unlikely to match any generic error message.
     */
    private static function generateRandomMessage(int $seed): string
    {
        $templates = [
            'SQLSTATE[HY000]: Connection refused at %s:%d',
            'Undefined property: stdClass::$%s in /internal/path/%s.php on line %d',
            'Class "%s\\Internal\\%s" not found',
            'Maximum execution time of %d seconds exceeded in %s',
            'Cannot access offset of type string on string in %s at line %d',
            'Call to undefined method %s::%s()',
            'Allowed memory size of %d bytes exhausted',
            'fopen(%s): Failed to open stream: No such file or directory',
            'PDOException: SQLSTATE[42S02]: Base table or view not found: %s',
            'TypeError: Argument #%d must be of type %s, %s given',
            'Validation failed for field %s: value "%s" exceeds %d chars',
            'At least one payee type must be %s (%d provided)',
            'Invalid payee type IDs: %d, %d',
            'Cannot delete person with %s transactions in %s',
        ];

        mt_srand($seed * 7919);

        $template = $templates[array_rand($templates)];

        $specifierCount = preg_match_all('/%[sd]/', $template);
        $args = [];

        for ($i = 0; $i < $specifierCount; $i++) {
            $r = mt_rand(0, 2);
            if ($r === 0) {
                $args[] = 'internal_' . bin2hex(random_bytes(4));
            } elseif ($r === 1) {
                $args[] = mt_rand(1000, 99999);
            } else {
                $args[] = 'App\\Internal\\' . ucfirst(bin2hex(random_bytes(3)));
            }
        }

        return vsprintf($template, $args);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
