<?php

namespace Tests\Property;

use App\Controllers\InsightsController;
use App\Services\InsightsService;
use App\Support\AuthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: people-module-review, Property 1: Error response never leaks internal exception messages
 *
 * For any exception thrown within the InsightsController where the exception is
 * NOT an InvalidArgumentException or RuntimeException, the HTTP response body
 * SHALL NOT contain the exception's internal message string.
 *
 * This test generates random exception messages and verifies that when
 * InsightsService throws a generic \Throwable (like \Error or \Exception),
 * the controller response body does NOT contain the exception message —
 * it should only contain a generic string like "Failed to load insights".
 *
 * **Validates: Requirements 2.4**
 */
class PeopleInsightsErrorHandlingPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Controller methods that delegate to InsightsService and handle exceptions.
     */
    private const CONTROLLER_METHODS = [
        'supplier',
        'customer',
        'general',
        'partner',
        'customerItems',
    ];

    /**
     * Exception classes that should NOT leak their message (generic Throwable).
     * These are exceptions that are NOT subclasses of InvalidArgumentException or RuntimeException.
     * The controller's tiered catch handles those two specifically (returning their message),
     * while any other Throwable gets a generic "Failed to load insights" response.
     */
    private const NON_LEAKING_EXCEPTION_CLASSES = [
        \Error::class,
        \TypeError::class,
        \LogicException::class,
        \DomainException::class,
        \BadMethodCallException::class,
        \BadFunctionCallException::class,
    ];

    /**
     * Data provider generating random exception messages with various throwable types.
     *
     * Each case yields: [string $exceptionClass, string $randomMessage, string $controllerMethod]
     */
    public static function randomExceptionMessageProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $exceptionClass = self::NON_LEAKING_EXCEPTION_CLASSES[
                array_rand(self::NON_LEAKING_EXCEPTION_CLASSES)
            ];

            $method = self::CONTROLLER_METHODS[
                array_rand(self::CONTROLLER_METHODS)
            ];

            // Generate a random message that should never appear in the response
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
     * Property 1: Error response never leaks internal exception messages (InsightsController)
     *
     * For any exception thrown within InsightsController where the exception is not
     * an InvalidArgumentException or RuntimeException, the HTTP response body SHALL
     * NOT contain the exception's internal message string.
     *
     * **Validates: Requirements 2.4**
     *
     * @dataProvider randomExceptionMessageProvider
     */
    public function testErrorResponseNeverLeaksInternalMessages(
        string $exceptionClass,
        string $randomMessage,
        string $controllerMethod
    ): void {
        // Verify precondition: the exception class is NOT one that's allowed to leak
        $exception = new $exceptionClass($randomMessage);
        $this->assertNotInstanceOf(
            \InvalidArgumentException::class,
            $exception,
            'Test invariant: exception must not be InvalidArgumentException'
        );
        $this->assertNotInstanceOf(
            \RuntimeException::class,
            $exception,
            'Test invariant: exception must not be RuntimeException'
        );

        // Create a mock InsightsService that throws the given exception
        $mockService = $this->createMock(InsightsService::class);

        // Map controller method to service method
        $serviceMethod = $this->mapControllerToServiceMethod($controllerMethod);

        $mockService->method($serviceMethod)
            ->willThrowException($exception);

        // Create a testable controller that captures output instead of calling exit()
        $controller = $this->createTestableController($mockService);

        // Set up AuthContext for the controller's auth() call
        $this->setUpAuthContext();

        // Call the controller method — our testable subclass captures output
        $controller->$controllerMethod(999);

        $output = $controller->getCapturedOutput();
        $statusCode = $controller->getCapturedStatusCode();

        // THE PROPERTY: The random exception message must NOT appear in the response body
        $this->assertStringNotContainsString(
            $randomMessage,
            $output,
            sprintf(
                'PROPERTY VIOLATION: Internal exception message "%s" leaked into the HTTP response '
                . 'body when InsightsController::%s() caught a %s. The response should contain only '
                . 'a generic error message like "Failed to load insights".',
                $randomMessage,
                $controllerMethod,
                $exceptionClass
            )
        );

        // Additional assertions: verify correct generic behavior
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded, 'Response must be valid JSON');
        $this->assertFalse($decoded['success'], 'Response success must be false for errors');
        $this->assertSame(
            'Failed to load insights',
            $decoded['message'],
            'Response must contain the generic error message, not the internal exception message'
        );
        $this->assertSame(500, $statusCode, 'HTTP status must be 500 for generic Throwable');
    }

    /**
     * Map controller method name to the corresponding InsightsService method name.
     */
    private function mapControllerToServiceMethod(string $controllerMethod): string
    {
        $map = [
            'supplier'      => 'getSupplierInsights',
            'customer'      => 'getCustomerInsights',
            'general'       => 'getGeneralInsights',
            'partner'       => 'getPartnerInsights',
            'customerItems' => 'getCustomerItems',
        ];

        return $map[$controllerMethod];
    }

    /**
     * Create a testable InsightsController subclass that does not call exit()
     * and captures output/status code for assertion.
     */
    private function createTestableController(InsightsService $mockService): object
    {
        // Create anonymous class extending InsightsController that overrides
        // the json() method to capture output instead of echoing + exit
        $controller = new class extends InsightsController {
            private string $capturedOutput = '';
            private int $capturedStatusCode = 200;
            private ?InsightsService $mockService = null;

            // Skip parent constructor (avoids DB connection)
            public function __construct()
            {
                // Intentionally empty — we inject service via setMockService
            }

            public function setMockService(InsightsService $service): void
            {
                $this->mockService = $service;
                // Inject into the parent's private property
                $ref = new \ReflectionClass(InsightsController::class);
                $prop = $ref->getProperty('service');
                $prop->setAccessible(true);
                $prop->setValue($this, $service);
            }

            // Override json() to capture output instead of echo + potentially exit
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
                    'message' => $message
                ]);
            }

            // Override serverError to avoid exit()
            protected function serverError(string $message = 'Internal server error'): void
            {
                $this->json(false, null, $message, 500);
                // Do NOT call exit() — this is the key difference for testing
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
     * This ensures we're testing that truly random internal messages don't leak.
     */
    private static function generateRandomMessage(int $seed): string
    {
        // Create varied, random-looking messages that would be recognizable if leaked
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
        ];

        mt_srand($seed * 7919); // Use a prime multiplier for variation

        $template = $templates[array_rand($templates)];

        // Count format specifiers
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
