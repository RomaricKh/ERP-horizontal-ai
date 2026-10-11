<?php

namespace Tests\Property;

use App\Services\TenantOwnerService;
use App\Services\UserService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Feature: tenant-admin-review-fixes, Property 5: Email format validation
 *
 * For any string that does not pass filter_var($string, FILTER_VALIDATE_EMAIL),
 * the designation and user-creation endpoints SHALL reject the request with an
 * InvalidArgumentException('Invalid email format'); and for any string that passes
 * filter_var, the email validation step SHALL not reject on email grounds.
 *
 * We test this by directly invoking the email validation logic in both:
 * 1. TenantOwnerService::designateFromController() - via reflection
 * 2. UserService::createWithMultipleBusinesses() - via reflection
 *
 * Since both methods perform additional logic (DB access, etc.), we isolate
 * the email validation step by checking:
 * - Invalid emails: method throws InvalidArgumentException('Invalid email format')
 * - Valid emails: method does NOT throw that specific exception at the email step
 *   (it may throw other exceptions later due to missing DB, which is acceptable)
 *
 * **Validates: Requirements 9.1, 9.2, 9.3, 9.4**
 */
class EmailValidationPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    // ── Data Providers ──────────────────────────────────────────────────────

    /**
     * Generate strings that FAIL filter_var($string, FILTER_VALIDATE_EMAIL).
     *
     * Strategies:
     * - No @ sign (random alphabetic strings)
     * - @ with no local part
     * - @ with no domain
     * - Multiple @ signs
     * - Spaces in email
     * - Domain with double dots
     * - Leading/trailing dots
     * - Empty domain after @
     * - Random binary/special characters
     *
     * Each generated string is verified to actually fail filter_var before inclusion.
     */
    public static function invalidEmailProvider(): array
    {
        $cases = [];

        // Fixed known-invalid patterns
        $knownInvalid = [
            'plaintext',
            'no-at-sign',
            'user@',
            '@domain.com',
            'a@b@c.com',
            'user name@example.com',
            'user@.com',
            '@',
            '@@',
            'user@domain..com',
            '.user@domain.com',
            'user.@domain.com',
            'user@-domain.com',
            '',
            ' ',
            'user@domain com',
            'user@@domain.com',
            "user\t@domain.com",
            "user\n@domain.com",
            '<user@domain.com>',
        ];

        foreach ($knownInvalid as $idx => $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $cases["known_invalid_{$idx}"] = [$email];
            }
        }

        // Strategy 1: Random strings without @ (guaranteed invalid)
        for ($i = 0; $i < 25; $i++) {
            $len = mt_rand(1, 30);
            $email = '';
            for ($j = 0; $j < $len; $j++) {
                $email .= chr(mt_rand(97, 122)); // lowercase letters only
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $cases["no_at_{$i}"] = [$email];
            }
        }

        // Strategy 2: @ with empty or invalid local part
        for ($i = 0; $i < 20; $i++) {
            $variants = [
                '@' . self::randomDomain(),
                ' @' . self::randomDomain(),
                '.@' . self::randomDomain(),
                '..@' . self::randomDomain(),
            ];
            $email = $variants[mt_rand(0, count($variants) - 1)];
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $cases["bad_local_{$i}"] = [$email];
            }
        }

        // Strategy 3: @ with empty or invalid domain
        for ($i = 0; $i < 20; $i++) {
            $local = self::randomLocalPart();
            $variants = [
                $local . '@',
                $local . '@ ',
                $local . '@.',
                $local . '@..',
                $local . '@-start.com',
                $local . '@domain..com',
            ];
            $email = $variants[mt_rand(0, count($variants) - 1)];
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $cases["bad_domain_{$i}"] = [$email];
            }
        }

        // Strategy 4: Strings with spaces
        for ($i = 0; $i < 15; $i++) {
            $email = self::randomLocalPart() . ' ' . self::randomLocalPart() . '@' . self::randomDomain();
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $cases["with_space_{$i}"] = [$email];
            }
        }

        // Strategy 5: Multiple @ signs
        for ($i = 0; $i < 10; $i++) {
            $email = self::randomLocalPart() . '@' . self::randomLocalPart() . '@' . self::randomDomain();
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $cases["multi_at_{$i}"] = [$email];
            }
        }

        // Strategy 6: Special/control characters
        for ($i = 0; $i < 10; $i++) {
            $special = ["\t", "\n", "\r", "\x00", "\x01", '(', ')', ',', ';', '<', '>'];
            $char = $special[mt_rand(0, count($special) - 1)];
            $email = 'user' . $char . '@example.com';
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $cases["special_char_{$i}"] = [$email];
            }
        }

        return $cases;
    }

    /**
     * Generate strings that PASS filter_var($string, FILTER_VALIDATE_EMAIL).
     *
     * Strategies:
     * - Standard emails with random local parts and domains
     * - Emails with dots in local part
     * - Emails with + addressing
     * - Emails with subdomains
     * - Various valid TLDs
     *
     * Each generated string is verified to actually pass filter_var before inclusion.
     */
    public static function validEmailProvider(): array
    {
        $cases = [];

        // Fixed known-valid patterns
        $knownValid = [
            'user@example.com',
            'test.user@domain.org',
            'first.last@sub.domain.co.uk',
            'user+tag@gmail.com',
            'a@b.co',
            'user123@test-domain.com',
            'UPPER@CASE.COM',
            '1234@5678.org',
            'very.long.local.part@equally.long.domain.example.com',
        ];

        foreach ($knownValid as $idx => $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $cases["known_valid_{$idx}"] = [$email];
            }
        }

        $tlds = ['com', 'org', 'net', 'io', 'co', 'dev', 'app', 'uk', 'de', 'fr'];

        // Strategy 1: Random simple emails
        for ($i = 0; $i < 40; $i++) {
            $local = self::randomLocalPart();
            $domain = self::randomDomainLabel() . '.' . $tlds[mt_rand(0, count($tlds) - 1)];
            $email = $local . '@' . $domain;
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $cases["random_simple_{$i}"] = [$email];
            }
        }

        // Strategy 2: Emails with dots in local part
        for ($i = 0; $i < 20; $i++) {
            $local = self::randomLocalPart() . '.' . self::randomLocalPart();
            $domain = self::randomDomainLabel() . '.' . $tlds[mt_rand(0, count($tlds) - 1)];
            $email = $local . '@' . $domain;
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $cases["with_dots_{$i}"] = [$email];
            }
        }

        // Strategy 3: Emails with + addressing
        for ($i = 0; $i < 15; $i++) {
            $local = self::randomLocalPart() . '+' . self::randomLocalPart();
            $domain = self::randomDomainLabel() . '.' . $tlds[mt_rand(0, count($tlds) - 1)];
            $email = $local . '@' . $domain;
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $cases["plus_address_{$i}"] = [$email];
            }
        }

        // Strategy 4: Emails with subdomains
        for ($i = 0; $i < 15; $i++) {
            $local = self::randomLocalPart();
            $domain = self::randomDomainLabel() . '.' . self::randomDomainLabel() . '.' . $tlds[mt_rand(0, count($tlds) - 1)];
            $email = $local . '@' . $domain;
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $cases["subdomain_{$i}"] = [$email];
            }
        }

        return $cases;
    }

    // ── Helper Methods for Generation ───────────────────────────────────────

    /**
     * Generate a random valid local part (letters, digits, dots, hyphens, underscores).
     */
    private static function randomLocalPart(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $len = mt_rand(2, 12);
        $part = '';
        for ($i = 0; $i < $len; $i++) {
            $part .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $part;
    }

    /**
     * Generate a random valid domain label (letters and digits, starts with letter).
     */
    private static function randomDomainLabel(): string
    {
        $first = chr(mt_rand(97, 122)); // letter
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $len = mt_rand(2, 10);
        $label = $first;
        for ($i = 1; $i < $len; $i++) {
            $label .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $label;
    }

    /**
     * Generate a random valid domain (label + TLD).
     */
    private static function randomDomain(): string
    {
        $tlds = ['com', 'org', 'net', 'io', 'co'];
        return self::randomDomainLabel() . '.' . $tlds[mt_rand(0, count($tlds) - 1)];
    }

    // ── Property Tests: TenantOwnerService ──────────────────────────────────

    /**
     * Property 5: Invalid emails are rejected by TenantOwnerService::designateFromController()
     *
     * For any string that filter_var($string, FILTER_VALIDATE_EMAIL) returns false,
     * designateFromController SHALL throw InvalidArgumentException('Invalid email format').
     *
     * **Validates: Requirements 9.1, 9.2**
     *
     * @dataProvider invalidEmailProvider
     */
    public function testTenantOwnerServiceRejectsInvalidEmail(string $email): void
    {
        // Skip empty/whitespace-only email - TenantOwnerService trims and triggers
        // 'Email is required' instead of 'Invalid email format'
        if (trim($email) === '') {
            $this->markTestSkipped('Empty/blank email triggers "Email is required" not "Invalid email format"');
        }

        // Create TenantOwnerService with a mock PDO (we won't reach DB calls)
        $mockPdo = $this->createMock(\PDO::class);
        $service = new TenantOwnerService($mockPdo);

        $data = [
            'email' => $email,
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'password' => 'validpass123',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');

        $service->designateFromController(1, $data);
    }

    /**
     * Property 5: Valid emails are NOT rejected on email grounds by TenantOwnerService.
     *
     * For any string that filter_var($string, FILTER_VALIDATE_EMAIL) returns truthy,
     * designateFromController SHALL NOT throw InvalidArgumentException('Invalid email format').
     *
     * Note: The method may throw other exceptions (DB errors, user not found, etc.)
     * since we're using a mock PDO — that's acceptable. We only assert that the
     * 'Invalid email format' exception is NOT thrown.
     *
     * **Validates: Requirements 9.1, 9.2**
     *
     * @dataProvider validEmailProvider
     */
    public function testTenantOwnerServiceAcceptsValidEmail(string $email): void
    {
        // Create TenantOwnerService with a mock PDO
        $mockPdo = $this->createMock(\PDO::class);
        $service = new TenantOwnerService($mockPdo);

        $data = [
            'email' => $email,
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'password' => 'validpass123',
        ];

        try {
            $service->designateFromController(1, $data);
            // If it succeeds (unlikely with mock PDO), that's fine — email was accepted
            $this->assertTrue(true);
        } catch (InvalidArgumentException $e) {
            // Must NOT be the email validation error
            $this->assertNotEquals(
                'Invalid email format',
                $e->getMessage(),
                sprintf(
                    'Valid email "%s" was rejected with "Invalid email format" — '
                    . 'filter_var considers it valid but the service rejected it.',
                    $email
                )
            );
        } catch (\Throwable $e) {
            // Any other exception (DB, runtime, etc.) is acceptable —
            // it means we passed the email validation step
            $this->assertTrue(true, sprintf(
                'Email "%s" passed validation; subsequent error: %s',
                $email,
                $e->getMessage()
            ));
        }
    }

    // ── Property Tests: UserService ─────────────────────────────────────────

    /**
     * Property 5: Invalid emails are rejected by UserService::createWithMultipleBusinesses()
     *
     * For any string that filter_var($string, FILTER_VALIDATE_EMAIL) returns false,
     * createWithMultipleBusinesses SHALL throw InvalidArgumentException('Invalid email format').
     *
     * **Validates: Requirements 9.3, 9.4**
     *
     * @dataProvider invalidEmailProvider
     */
    public function testUserServiceRejectsInvalidEmail(string $email): void
    {
        // Skip empty email - that triggers 'Field email is required' instead
        if (empty($email)) {
            $this->markTestSkipped('Empty email triggers required-field error, not format error');
        }

        // Create UserService with a mock PDO (we won't reach DB calls)
        $mockPdo = $this->createMock(\PDO::class);
        $service = new UserService($mockPdo);

        $data = [
            'email' => $email,
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'password' => 'validpass123',
            'assignments' => [],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');

        $service->createWithMultipleBusinesses(1, $data);
    }

    /**
     * Property 5: Valid emails are NOT rejected on email grounds by UserService.
     *
     * For any string that filter_var($string, FILTER_VALIDATE_EMAIL) returns truthy,
     * createWithMultipleBusinesses SHALL NOT throw InvalidArgumentException('Invalid email format').
     *
     * **Validates: Requirements 9.3, 9.4**
     *
     * @dataProvider validEmailProvider
     */
    public function testUserServiceAcceptsValidEmail(string $email): void
    {
        // Create UserService with a mock PDO
        $mockPdo = $this->createMock(\PDO::class);
        $service = new UserService($mockPdo);

        $data = [
            'email' => $email,
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'password' => 'validpass123',
            'assignments' => [],
        ];

        try {
            $service->createWithMultipleBusinesses(1, $data);
            // If it succeeds (unlikely with mock PDO), email was accepted
            $this->assertTrue(true);
        } catch (InvalidArgumentException $e) {
            // Must NOT be the email validation error
            $this->assertNotEquals(
                'Invalid email format',
                $e->getMessage(),
                sprintf(
                    'Valid email "%s" was rejected with "Invalid email format" — '
                    . 'filter_var considers it valid but the service rejected it.',
                    $email
                )
            );
        } catch (\Throwable $e) {
            // Any other exception (DB, runtime, etc.) is acceptable
            $this->assertTrue(true, sprintf(
                'Email "%s" passed validation; subsequent error: %s',
                $email,
                $e->getMessage()
            ));
        }
    }
}
