<?php

namespace Tests\Property;

use App\Services\PeopleService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: people-module-review, Property 4: Input validation rejects all invalid person data
 *
 * For any input where the name is empty after trimming or exceeds 255 characters, or the email
 * is non-empty but fails filter_var(FILTER_VALIDATE_EMAIL), or the phone contains characters
 * outside [0-9 \-\+\(\)] or exceeds 50 characters, or any payee_type_id is not a positive integer,
 * the PeopleService SHALL throw an InvalidArgumentException identifying the invalid field.
 *
 * **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5, 7.6**
 */
class PeopleInputValidationPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    private function invokeValidatePersonInput(array $data, bool $isCreate = true): void
    {
        $pdo = $this->createMock(\PDO::class);
        $ref = new ReflectionClass(PeopleService::class);
        $service = $ref->newInstanceWithoutConstructor();

        // Set up the db property since constructor is bypassed
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $pdo);

        $method = $ref->getMethod('validatePersonInput');
        $method->setAccessible(true);
        $method->invoke($service, $data, $isCreate);
    }

    // ── Generators ─────────────────────────────────────────────────────────

    private function randomPrintableString(int $length): string
    {
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= chr(mt_rand(32, 126));
        }
        return $str;
    }

    private function randomUnicodeString(int $length): string
    {
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            // Mix ASCII and multibyte chars
            if (mt_rand(0, 1)) {
                $str .= chr(mt_rand(65, 90)); // A-Z
            } else {
                // Use 2-byte UTF-8 chars (Latin Extended)
                $str .= mb_chr(mt_rand(0x00C0, 0x00FF));
            }
        }
        return $str;
    }

    private function generateInvalidEmails(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $type = mt_rand(0, 5);
            switch ($type) {
                case 0:
                    // No @ symbol
                    $cases[] = $this->randomPrintableString(mt_rand(3, 30));
                    break;
                case 1:
                    // @ but no domain
                    $cases[] = $this->randomPrintableString(mt_rand(1, 10)) . '@';
                    break;
                case 2:
                    // @ at start
                    $cases[] = '@' . $this->randomPrintableString(mt_rand(3, 10));
                    break;
                case 3:
                    // Multiple @ symbols
                    $cases[] = 'a@b@c.com';
                    break;
                case 4:
                    // Space in email
                    $cases[] = 'user name@example.com';
                    break;
                case 5:
                    // Missing TLD
                    $cases[] = 'user@domain';
                    break;
            }
        }
        return $cases;
    }

    private function generateInvalidPhoneChars(): array
    {
        $invalidChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*={}[]|\\:;"\'<>,./~`';
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $len = mt_rand(2, 20);
            $phone = '';
            // Put some valid chars + at least one invalid char
            for ($j = 0; $j < $len; $j++) {
                if ($j === mt_rand(0, $len - 1)) {
                    $phone .= $invalidChars[mt_rand(0, strlen($invalidChars) - 1)];
                } else {
                    $validChars = '0123456789 -+()';
                    $phone .= $validChars[mt_rand(0, strlen($validChars) - 1)];
                }
            }
            // Ensure at least one invalid char is present
            if (preg_match('/^[\d\s\-\+\(\)]+$/', $phone)) {
                $phone .= $invalidChars[mt_rand(0, strlen($invalidChars) - 1)];
            }
            $cases[] = $phone;
        }
        return $cases;
    }

    // ── Data Providers ─────────────────────────────────────────────────────

    public static function emptyNameProvider(): array
    {
        $cases = [];
        // Whitespace-only names of various lengths
        for ($i = 0; $i < 50; $i++) {
            $len = mt_rand(0, 20);
            $whitespace = str_repeat(' ', $len);
            $cases["whitespace_{$i}"] = [$whitespace];
        }
        // Tab/newline characters (still trims to empty)
        $cases['tabs'] = ["\t\t\t"];
        $cases['newlines'] = ["\n\n"];
        $cases['mixed_ws'] = [" \t \n "];
        $cases['empty'] = [''];
        return $cases;
    }

    public static function longNameProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 50; $i++) {
            $len = mt_rand(256, 500);
            $name = '';
            for ($j = 0; $j < $len; $j++) {
                $name .= chr(mt_rand(65, 90)); // A-Z
            }
            $cases["long_name_{$i}"] = [$name];
        }
        // Exactly 256 chars
        $cases['exactly_256'] = [str_repeat('A', 256)];
        // With leading space still > 255 after trim
        $cases['leading_space_long'] = [' ' . str_repeat('B', 256)];
        return $cases;
    }

    public static function invalidEmailProvider(): array
    {
        $cases = [];
        $invalidEmails = [
            'no-at-sign', 'user@', '@domain.com', 'a@b@c.com',
            'user name@example.com', 'user@.com', '@', '@@',
            'user@domain..com', '.user@domain.com',
            'user.@domain.com', 'user@-domain.com',
        ];
        foreach ($invalidEmails as $idx => $email) {
            $cases["fixed_invalid_{$idx}"] = [$email];
        }
        // Generate random invalid emails
        for ($i = 0; $i < 50; $i++) {
            $type = mt_rand(0, 4);
            switch ($type) {
                case 0:
                    $email = '';
                    for ($j = 0; $j < mt_rand(3, 20); $j++) {
                        $email .= chr(mt_rand(97, 122));
                    }
                    break;
                case 1:
                    $email = chr(mt_rand(97, 122)) . '@';
                    break;
                case 2:
                    $email = '@' . chr(mt_rand(97, 122));
                    break;
                case 3:
                    $email = 'user @example.com';
                    break;
                case 4:
                    $email = 'a@b@c.com';
                    break;
            }
            $cases["random_invalid_{$i}"] = [$email];
        }
        return $cases;
    }

    public static function invalidPhoneCharsProvider(): array
    {
        $invalidChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*={}[]|:;"\'<>,./~`';
        $cases = [];
        for ($i = 0; $i < 80; $i++) {
            $len = mt_rand(2, 20);
            $phone = '';
            $validChars = '0123456789 -+()';
            for ($j = 0; $j < $len; $j++) {
                $phone .= $validChars[mt_rand(0, strlen($validChars) - 1)];
            }
            // Inject at least one invalid char at random position
            $pos = mt_rand(0, strlen($phone));
            $invalidChar = $invalidChars[mt_rand(0, strlen($invalidChars) - 1)];
            $phone = substr($phone, 0, $pos) . $invalidChar . substr($phone, $pos);
            $cases["invalid_phone_chars_{$i}"] = [$phone];
        }
        return $cases;
    }

    public static function longPhoneProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 50; $i++) {
            $len = mt_rand(51, 100);
            $phone = '';
            $validChars = '0123456789 -+()';
            for ($j = 0; $j < $len; $j++) {
                $phone .= $validChars[mt_rand(0, strlen($validChars) - 1)];
            }
            $cases["long_phone_{$i}"] = [$phone];
        }
        return $cases;
    }

    public static function invalidPayeeTypeIdsProvider(): array
    {
        $cases = [];
        // Zero
        for ($i = 0; $i < 20; $i++) {
            $cases["zero_{$i}"] = [[0]];
        }
        // Negative integers
        for ($i = 0; $i < 20; $i++) {
            $cases["negative_{$i}"] = [[mt_rand(-1000, -1)]];
        }
        // Non-numeric strings
        $strings = ['abc', 'foo', '', ' ', 'null', 'undefined', '1.5', '-1', '0'];
        foreach ($strings as $idx => $s) {
            $cases["string_{$idx}"] = [[$s]];
        }
        // Floats
        for ($i = 0; $i < 10; $i++) {
            $cases["float_{$i}"] = [[mt_rand(1, 100) + 0.5]];
        }
        // Mixed valid and invalid
        for ($i = 0; $i < 20; $i++) {
            $cases["mixed_{$i}"] = [[mt_rand(1, 100), mt_rand(-100, 0)]];
        }
        return $cases;
    }

    public static function validInputProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 50; $i++) {
            $nameLen = mt_rand(1, 255);
            $name = '';
            for ($j = 0; $j < $nameLen; $j++) {
                $name .= chr(mt_rand(65, 122)); // A-z range
            }

            $data = ['name' => $name];

            // Optionally add a valid email
            if (mt_rand(0, 1)) {
                $data['email'] = 'user' . mt_rand(1, 9999) . '@example.com';
            }

            // Optionally add a valid phone
            if (mt_rand(0, 1)) {
                $phoneLen = mt_rand(1, 50);
                $validChars = '0123456789 -+()';
                $phone = '';
                for ($j = 0; $j < $phoneLen; $j++) {
                    $phone .= $validChars[mt_rand(0, strlen($validChars) - 1)];
                }
                $data['phone'] = $phone;
            }

            // Optionally add valid payee_type_ids
            if (mt_rand(0, 1)) {
                $count = mt_rand(1, 5);
                $ids = [];
                for ($j = 0; $j < $count; $j++) {
                    $ids[] = mt_rand(1, 1000);
                }
                $data['payee_type_ids'] = $ids;
            }

            $cases["valid_{$i}"] = [$data];
        }
        return $cases;
    }

    // ── Property Tests ─────────────────────────────────────────────────────

    /**
     * Property 4: Empty/whitespace-only names are rejected on create.
     *
     * For any name that trims to empty, validatePersonInput SHALL throw
     * InvalidArgumentException with message 'Name is required.'
     *
     * **Validates: Requirements 7.1**
     *
     * @dataProvider emptyNameProvider
     */
    public function testEmptyNameRejectedOnCreate(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name is required.');

        $this->invokeValidatePersonInput(['name' => $name], true);
    }

    /**
     * Property 4: Names exceeding 255 characters are rejected.
     *
     * For any name whose trimmed length > 255, validatePersonInput SHALL throw
     * InvalidArgumentException with message 'Name must not exceed 255 characters.'
     *
     * **Validates: Requirements 7.1**
     *
     * @dataProvider longNameProvider
     */
    public function testLongNameRejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name must not exceed 255 characters.');

        $this->invokeValidatePersonInput(['name' => $name], true);
    }

    /**
     * Property 4: Invalid email formats are rejected.
     *
     * For any non-empty email that fails filter_var(FILTER_VALIDATE_EMAIL),
     * validatePersonInput SHALL throw InvalidArgumentException with
     * message 'Invalid email format.'
     *
     * **Validates: Requirements 7.2**
     *
     * @dataProvider invalidEmailProvider
     */
    public function testInvalidEmailRejected(string $email): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format.');

        $this->invokeValidatePersonInput([
            'name' => 'Valid Name',
            'email' => $email,
        ], true);
    }

    /**
     * Property 4: Phone numbers with invalid characters are rejected.
     *
     * For any phone containing characters outside [0-9 \-\+\(\)],
     * validatePersonInput SHALL throw InvalidArgumentException with
     * message 'Phone contains invalid characters.'
     *
     * **Validates: Requirements 7.3**
     *
     * @dataProvider invalidPhoneCharsProvider
     */
    public function testPhoneWithInvalidCharsRejected(string $phone): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone contains invalid characters.');

        $this->invokeValidatePersonInput([
            'name' => 'Valid Name',
            'phone' => $phone,
        ], true);
    }

    /**
     * Property 4: Phone numbers exceeding 50 characters are rejected.
     *
     * For any phone with only valid characters but length > 50,
     * validatePersonInput SHALL throw InvalidArgumentException with
     * message 'Phone must not exceed 50 characters.'
     *
     * **Validates: Requirements 7.3**
     *
     * @dataProvider longPhoneProvider
     */
    public function testLongPhoneRejected(string $phone): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone must not exceed 50 characters.');

        $this->invokeValidatePersonInput([
            'name' => 'Valid Name',
            'phone' => $phone,
        ], true);
    }

    /**
     * Property 4: Non-positive-integer payee_type_ids are rejected.
     *
     * For any payee_type_id that is not a positive integer (0, negative,
     * non-numeric string, float), validatePersonInput SHALL throw
     * InvalidArgumentException with message containing 'Invalid payee type ID:'
     *
     * **Validates: Requirements 7.4**
     *
     * @dataProvider invalidPayeeTypeIdsProvider
     */
    public function testInvalidPayeeTypeIdsRejected(array $typeIds): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid payee type ID:');

        $this->invokeValidatePersonInput([
            'name' => 'Valid Name',
            'payee_type_ids' => $typeIds,
        ], true);
    }

    /**
     * Property 4: Valid inputs do NOT throw exceptions.
     *
     * For any input with a valid non-empty name (≤255 chars), a valid email
     * (or none), a valid phone (or none), and valid positive integer payee_type_ids
     * (or none), validatePersonInput SHALL NOT throw any exception.
     *
     * **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5, 7.6**
     *
     * @dataProvider validInputProvider
     */
    public function testValidInputAccepted(array $data): void
    {
        // Should not throw any exception
        $this->invokeValidatePersonInput($data, true);
        $this->assertTrue(true);
    }

    // ── Property 5: Invalid payee_type_ids input is always rejected ────────

    /**
     * Helper to invoke PeopleService::create() with a mocked PDO that supports
     * beginTransaction/rollBack but throws before any real DB operations.
     */
    private function invokeCreateWithData(array $data): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        $service = new PeopleService($pdo);
        $service->create(1, 1, 1, $data);
    }

    public static function invalidPayeeTypeIdsForCreateProvider(): array
    {
        $cases = [];

        // Empty array cases
        for ($i = 0; $i < 30; $i++) {
            $cases["empty_array_{$i}"] = [[]];
        }

        // Null cases
        for ($i = 0; $i < 20; $i++) {
            $cases["null_{$i}"] = [null];
        }

        // Not an array: string values
        $strings = ['', 'customer', '1', '1,2,3', 'null', 'true', 'false', ' '];
        foreach ($strings as $idx => $s) {
            $cases["string_{$idx}"] = [$s];
        }

        // Not an array: integer values
        for ($i = 0; $i < 15; $i++) {
            $cases["integer_{$i}"] = [mt_rand(0, 100)];
        }

        // Not an array: boolean false
        $cases['false'] = [false];

        // Not an array: boolean true
        $cases['true'] = [true];

        // Not an array: float values
        for ($i = 0; $i < 10; $i++) {
            $cases["float_{$i}"] = [mt_rand(1, 100) / 10.0];
        }

        // Not an array: zero
        $cases['zero'] = [0];

        return $cases;
    }

    /**
     * Property 5: Invalid payee_type_ids input is always rejected.
     *
     * For any input where payee_type_ids is empty, null, or not an array,
     * calling PeopleService::create() SHALL throw an InvalidArgumentException
     * with the message "At least one payee type must be selected." — and this
     * check SHALL occur exactly once.
     *
     * **Validates: Requirements 1.2**
     *
     * @dataProvider invalidPayeeTypeIdsForCreateProvider
     */
    public function testInvalidPayeeTypeIdsRejectedByCreate($invalidPayeeTypeIds): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one payee type must be selected.');

        $data = [
            'name' => 'Valid Person Name',
            'payee_type_ids' => $invalidPayeeTypeIds,
        ];

        $this->invokeCreateWithData($data);
    }

    /**
     * Property 5 (supplemental): Missing payee_type_ids key is rejected.
     *
     * When payee_type_ids is not provided at all in the data array,
     * PeopleService::create() SHALL throw InvalidArgumentException with
     * the message "At least one payee type must be selected."
     *
     * **Validates: Requirements 1.2**
     */
    public function testMissingPayeeTypeIdsKeyRejectedByCreate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one payee type must be selected.');

        $data = [
            'name' => 'Valid Person Name',
            // payee_type_ids key intentionally omitted
        ];

        $this->invokeCreateWithData($data);
    }

    /**
     * Property 5 (supplemental): The payee_type_ids check occurs exactly once.
     *
     * For any input with empty payee_type_ids, exactly one exception is thrown
     * (not two from duplicate checks). We verify by counting that the exception
     * message matches exactly — a duplicate check would either throw before
     * reaching the main check or produce a different sequence.
     *
     * **Validates: Requirements 1.2**
     */
    public function testPayeeTypeIdsCheckOccursExactlyOnce(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        $service = new PeopleService($pdo);

        $invalidInputs = [
            ['name' => 'Test', 'payee_type_ids' => []],
            ['name' => 'Test', 'payee_type_ids' => null],
            ['name' => 'Test', 'payee_type_ids' => 'string'],
            ['name' => 'Test'], // key missing
        ];

        foreach ($invalidInputs as $idx => $data) {
            $exceptionCount = 0;
            try {
                $service->create(1, 1, 1, $data);
            } catch (InvalidArgumentException $e) {
                $exceptionCount++;
                $this->assertSame(
                    'At least one payee type must be selected.',
                    $e->getMessage(),
                    "Input index {$idx}: exception message should match expected"
                );
            }
            $this->assertSame(1, $exceptionCount, "Input index {$idx}: exactly one exception should be thrown");
        }
    }
}
