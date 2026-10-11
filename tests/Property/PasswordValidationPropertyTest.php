<?php

namespace Tests\Property;

use App\Services\TenantOwnerService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Feature: tenant-admin-review-fixes, Property 1: Password length validation
 *
 * For any string of length less than 8 characters, calling validatePassword()
 * SHALL throw an InvalidArgumentException; and for any string of length 8 or
 * more characters, calling validatePassword() SHALL NOT throw an exception.
 *
 * Uses PHP Reflection to access the private static method.
 * Generates random strings of varying lengths (0-100+) across many iterations
 * and verifies the property holds universally.
 *
 * **Validates: Requirements 1.1, 1.2, 1.3**
 */
class PasswordValidationPropertyTest extends TestCase
{
    private const ITERATIONS = 150;

    private ReflectionMethod $validatePassword;

    protected function setUp(): void
    {
        $this->validatePassword = new ReflectionMethod(TenantOwnerService::class, 'validatePassword');
        $this->validatePassword->setAccessible(true);
    }

    // ── Generators ──────────────────────────────────────────────────────────

    /**
     * Generate a random string of the specified length using a broad character set.
     */
    private function generateRandomString(int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
            . '!@#$%^&*()-_=+[]{}|;:,.<>?/~`\'" ';
        $result = '';
        $charsLen = strlen($chars);

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[mt_rand(0, $charsLen - 1)];
        }

        return $result;
    }

    // ── Property Tests ──────────────────────────────────────────────────────

    /**
     * Property 1: For ANY string with length < 8, validatePassword() SHALL throw
     * InvalidArgumentException.
     *
     * Generates random strings with lengths 0-7 and verifies each throws.
     *
     * **Validates: Requirements 1.1, 1.2, 1.3**
     */
    public function testShortPasswordsAlwaysThrow(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate a random length from 0 to 7
            $length = mt_rand(0, 7);
            $password = $this->generateRandomString($length);

            $this->assertSame(
                $length,
                strlen($password),
                "Generator invariant: generated string should have length {$length}"
            );

            $threw = false;
            $exceptionMessage = '';

            try {
                $this->validatePassword->invoke(null, $password);
            } catch (\InvalidArgumentException $e) {
                $threw = true;
                $exceptionMessage = $e->getMessage();
            }

            $this->assertTrue(
                $threw,
                sprintf(
                    'Iteration %d: validatePassword() should throw InvalidArgumentException '
                    . 'for password of length %d (value: "%s")',
                    $i,
                    $length,
                    $password
                )
            );

            $this->assertSame(
                'Password must be at least 8 characters',
                $exceptionMessage,
                sprintf(
                    'Iteration %d: Exception message should indicate minimum length requirement. '
                    . 'Got: "%s"',
                    $i,
                    $exceptionMessage
                )
            );
        }
    }

    /**
     * Property 1: For ANY string with length >= 8, validatePassword() SHALL NOT
     * throw an exception.
     *
     * Generates random strings with lengths 8-120 and verifies none throw.
     *
     * **Validates: Requirements 1.1, 1.2, 1.3**
     */
    public function testValidPasswordsNeverThrow(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate a random length from 8 to 120
            $length = mt_rand(8, 120);
            $password = $this->generateRandomString($length);

            $this->assertSame(
                $length,
                strlen($password),
                "Generator invariant: generated string should have length {$length}"
            );

            $threw = false;
            $exceptionClass = '';
            $exceptionMessage = '';

            try {
                $this->validatePassword->invoke(null, $password);
            } catch (\Throwable $e) {
                $threw = true;
                $exceptionClass = get_class($e);
                $exceptionMessage = $e->getMessage();
            }

            $this->assertFalse(
                $threw,
                sprintf(
                    'Iteration %d: validatePassword() should NOT throw for password of length %d '
                    . '(value: "%s"). Got %s: "%s"',
                    $i,
                    $length,
                    $password,
                    $exceptionClass,
                    $exceptionMessage
                )
            );
        }
    }

    /**
     * Property 1: Boundary test — length exactly 8 should NOT throw,
     * length exactly 7 should throw.
     *
     * Generates many random strings of exactly 7 and 8 characters to stress
     * the boundary condition across various character compositions.
     *
     * **Validates: Requirements 1.1, 1.2, 1.3**
     */
    public function testBoundaryLengthProperty(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Length 7 — must throw
            $short = $this->generateRandomString(7);
            $threwForShort = false;

            try {
                $this->validatePassword->invoke(null, $short);
            } catch (\InvalidArgumentException $e) {
                $threwForShort = true;
            }

            $this->assertTrue(
                $threwForShort,
                sprintf(
                    'Iteration %d: validatePassword() should throw for 7-char password: "%s"',
                    $i,
                    $short
                )
            );

            // Length 8 — must NOT throw
            $valid = $this->generateRandomString(8);
            $threwForValid = false;
            $exInfo = '';

            try {
                $this->validatePassword->invoke(null, $valid);
            } catch (\Throwable $e) {
                $threwForValid = true;
                $exInfo = get_class($e) . ': ' . $e->getMessage();
            }

            $this->assertFalse(
                $threwForValid,
                sprintf(
                    'Iteration %d: validatePassword() should NOT throw for 8-char password: "%s". Got: %s',
                    $i,
                    $valid,
                    $exInfo
                )
            );
        }
    }
}
