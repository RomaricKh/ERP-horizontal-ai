<?php

namespace Tests\Property\ExpensePayroll;

use App\Repository\ExpenseRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ExpenseService;
use App\Support\AuthContext;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: expenses-payroll-review, Property 6: Category name validation
 *
 * For any category name that:
 * - Exceeds 100 characters after trimming, OR
 * - Contains any ASCII control character (code points 0x00–0x1F, 0x7F)
 * → ExpenseService SHALL throw InvalidArgumentException
 *
 * Minimum 100 iterations per property variant.
 *
 * **Validates: Requirements 10.1, 10.2**
 */
class CategoryNameValidationTest extends TestCase
{
    private const ITERATIONS = 100;

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
    // Data Providers — Long Names (> 100 chars after trimming)
    // -------------------------------------------------------------------

    /**
     * Generate 100 random strings with trimmed length between 101 and 500 characters.
     */
    public static function longNameProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $length = mt_rand(101, 500);
            $name = self::generatePrintableString($length);

            // Optionally add surrounding whitespace to test trim-before-check
            if (mt_rand(0, 1) === 1) {
                $padding = str_repeat(' ', mt_rand(1, 10));
                $name = $padding . $name . $padding;
            }

            $cases["long_name_iter_{$i}"] = [$name];
        }
        return $cases;
    }

    /**
     * Generate 100 random strings containing at least one ASCII control character.
     * The generated names have valid length (≤ 100 chars after trim) to isolate the control char check.
     *
     * Strategy: Always surround the control character with non-whitespace printable characters
     * so that PHP's trim() does not strip it (trim removes \x00, \x09, \x0A, \x0B, \x0D, \x20).
     * The result is a string of length 3–100 that passes the empty check and length check
     * but fails on the control character regex.
     */
    public static function controlCharNameProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate a content length between 3 and 100 chars (minimum: char + control + char)
            $totalLength = mt_rand(3, 100);

            // Pick a random position for the control character (never first or last)
            $controlPos = mt_rand(1, $totalLength - 2);

            // Pick a random control character (0x00-0x1F or 0x7F)
            $controlChar = self::randomControlChar();

            // Build the string: non-whitespace printable on edges, mixed printable elsewhere
            $name = '';
            for ($j = 0; $j < $totalLength; $j++) {
                if ($j === $controlPos) {
                    $name .= $controlChar;
                } elseif ($j === 0 || $j === $totalLength - 1) {
                    // First and last chars: non-whitespace printable (0x21-0x7E)
                    $name .= chr(mt_rand(0x21, 0x7E));
                } else {
                    // Inner chars: printable ASCII (0x20-0x7E)
                    $name .= chr(mt_rand(0x20, 0x7E));
                }
            }

            $cases["control_char_iter_{$i}"] = [$name];
        }
        return $cases;
    }

    // -------------------------------------------------------------------
    // Property Tests — createCategory
    // -------------------------------------------------------------------

    /**
     * Property 6 (length variant): Any category name exceeding 100 characters
     * after trimming SHALL cause createCategory() to throw InvalidArgumentException.
     *
     * **Validates: Requirements 10.1**
     *
     * @dataProvider longNameProvider
     */
    public function testCreateCategoryRejectsLongNames(string $name): void
    {
        $trimmedLength = mb_strlen(trim($name));

        // Precondition: the trimmed name is indeed > 100
        $this->assertGreaterThan(100, $trimmedLength, 'Test precondition: name must be > 100 chars after trim');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name must not exceed 100 characters');

        $this->service->createCategory($name, (bool) mt_rand(0, 1));
    }

    /**
     * Property 6 (control chars variant): Any category name containing ASCII
     * control characters (0x00-0x1F, 0x7F) SHALL cause createCategory() to
     * throw InvalidArgumentException.
     *
     * **Validates: Requirements 10.2**
     *
     * @dataProvider controlCharNameProvider
     */
    public function testCreateCategoryRejectsControlChars(string $name): void
    {
        // Precondition: the name does indeed contain a control character
        $this->assertMatchesRegularExpression(
            '/[\x00-\x1F\x7F]/',
            $name,
            'Test precondition: name must contain at least one control character'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name contains invalid characters');

        $this->service->createCategory($name, (bool) mt_rand(0, 1));
    }

    // -------------------------------------------------------------------
    // Property Tests — updateCategory
    // -------------------------------------------------------------------

    /**
     * Property 6 (length variant): Any category name exceeding 100 characters
     * after trimming SHALL cause updateCategory() to throw InvalidArgumentException.
     *
     * **Validates: Requirements 10.1**
     *
     * @dataProvider longNameProvider
     */
    public function testUpdateCategoryRejectsLongNames(string $name): void
    {
        $trimmedLength = mb_strlen(trim($name));

        $this->assertGreaterThan(100, $trimmedLength, 'Test precondition: name must be > 100 chars after trim');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name must not exceed 100 characters');

        $this->service->updateCategory(mt_rand(1, 1000), $name, (bool) mt_rand(0, 1));
    }

    /**
     * Property 6 (control chars variant): Any category name containing ASCII
     * control characters (0x00-0x1F, 0x7F) SHALL cause updateCategory() to
     * throw InvalidArgumentException.
     *
     * **Validates: Requirements 10.2**
     *
     * @dataProvider controlCharNameProvider
     */
    public function testUpdateCategoryRejectsControlChars(string $name): void
    {
        $this->assertMatchesRegularExpression(
            '/[\x00-\x1F\x7F]/',
            $name,
            'Test precondition: name must contain at least one control character'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name contains invalid characters');

        $this->service->updateCategory(mt_rand(1, 1000), $name, (bool) mt_rand(0, 1));
    }

    // -------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------

    /**
     * Generate a random printable ASCII string of the given length.
     * Uses characters from 0x20 (space) to 0x7E (~).
     */
    private static function generatePrintableString(int $length): string
    {
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= chr(mt_rand(0x21, 0x7E)); // Exclude space to ensure trim doesn't reduce length
        }
        return $str;
    }

    /**
     * Return a random ASCII control character (0x00-0x1F or 0x7F).
     */
    private static function randomControlChar(): string
    {
        // 33 control characters total: 0x00-0x1F (32) + 0x7F (1)
        $index = mt_rand(0, 32);
        if ($index < 32) {
            return chr($index); // 0x00-0x1F
        }
        return chr(0x7F); // DEL
    }

    /**
     * Build the ExpenseService with mocked dependencies.
     * Uses the same pattern as tests/Unit/ExpenseCategoryNameValidationTest.php.
     */
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
