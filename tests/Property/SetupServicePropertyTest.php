<?php

namespace Tests\Property;

use App\Services\SetupService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Feature: first-installation-flow
 *
 * Property 6: Password Hash Round-Trip
 *   For any password string of 8 or more characters provided during setup,
 *   `password_verify($password, $stored_hash)` SHALL return true for the
 *   hash computed by the SetupService and bound to the `password_hash`
 *   placeholder on the users INSERT statement.
 *   **Validates: Requirements 6.1, 6.3**
 *
 * Property 4: Security Lock Rejects When Configured
 *   For any valid setup payload, when the tenants table contains one or
 *   more rows (revealed by the `SELECT COUNT(*) FROM tenants FOR UPDATE`
 *   lock query), the `SetupService::initialize()` method SHALL return a
 *   failure result with status 409 and SHALL NOT modify the database (no
 *   INSERT statements prepared, transaction rolled back).
 *   **Validates: Requirements 3.1, 13.2**
 */
class SetupServicePropertyTest extends TestCase
{
    private const ITERATIONS = 110;

    /**
     * Data provider generating random passwords >= 8 chars, <= 72 bytes.
     *
     * Bcrypt only consumes the first 72 BYTES of its input, so we cap byte
     * length (not char length) at 72 to ensure the round-trip is valid for
     * the entire generated string. Each case yields: [string $password].
     *
     * The generated set covers four flavors so the property exercises a
     * wide range of inputs that a real administrator might submit:
     *   1. ASCII alphanumeric                (the common case)
     *   2. ASCII printable incl. specials    (passphrase / strong PW case)
     *   3. Strings containing spaces         (passphrase case)
     *   4. Short UTF-8 multi-byte sequences  (international case)
     */
    public static function passwordProvider(): array
    {
        $cases = [];

        // Boundary: minimum allowed length (Requirement 6.3)
        $cases['boundary_exactly_8_chars'] = ['abcdefgh'];

        // Boundary: maximum bcrypt input length (72 bytes of ASCII)
        $cases['boundary_exactly_72_bytes_ascii'] = [str_repeat('A1b2C3d4', 9)];

        // Edge case: all-space-padded password (Requirements 6.1, 6.3)
        $cases['edge_password_with_leading_trailing_spaces'] = ['   pass word   '];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $flavor = $i % 4;
            $length = mt_rand(8, 72);

            switch ($flavor) {
                case 0:
                    $pw = self::randomAsciiAlphanumeric($length);
                    $label = "alnum_len{$length}_iter{$i}";
                    break;

                case 1:
                    $pw = self::randomAsciiPrintable($length);
                    $label = "printable_len{$length}_iter{$i}";
                    break;

                case 2:
                    $pw = self::randomWithSpaces($length);
                    $label = "withSpaces_len{$length}_iter{$i}";
                    break;

                case 3:
                default:
                    // Build a unicode-flavored string then trim to <=72 bytes
                    $pw = self::randomUtf8WithinByteBudget($length);
                    $byteLen = strlen($pw);
                    $label = "utf8_bytes{$byteLen}_iter{$i}";
                    break;
            }

            $cases[$label] = [$pw];
        }

        return $cases;
    }

    /**
     * Property 6: Password Hash Round-Trip
     *
     * For any password string of 8 or more characters (and <= 72 bytes —
     * bcrypt's documented input limit), the hash produced and bound by
     * `SetupService::initialize()` SHALL satisfy
     * `password_verify($password, $hash) === true` and SHALL be a valid
     * bcrypt hash that does not embed the plain-text password.
     *
     * **Validates: Requirements 6.1, 6.3**
     *
     * @dataProvider passwordProvider
     */
    public function testPasswordHashRoundTripVerifiesTrue(string $password): void
    {
        // Generator invariants — protect against accidental provider bugs.
        $this->assertGreaterThanOrEqual(
            8,
            mb_strlen($password),
            'Generator invariant: password must be >= 8 chars (Requirement 6.3)'
        );
        $this->assertLessThanOrEqual(
            72,
            strlen($password),
            "Generator invariant: bcrypt only consumes first 72 bytes — keep generated passwords within that budget"
        );

        // Reference to be mutated by the mock when the users INSERT runs.
        $captured = ['password_hash' => null];

        $pdo = $this->buildMockPdoCapturingPasswordHash($captured);
        $service = new SetupService('test-jwt-secret-not-used-by-property-6', $pdo);

        $result = $service->initialize([
            'tenant_name' => 'Property 6 Tenant',
            'username'    => 'p6user',
            'email'       => 'p6@example.com',
            'password'    => $password,
            'first_name'  => 'Prop',
            'last_name'   => 'Six',
        ]);

        // The transaction itself must have committed successfully so that
        // we know the captured hash was the one the service actually
        // intended to persist.
        $this->assertTrue(
            $result['success'],
            'SetupService::initialize() must succeed for valid input; got: '
                . ($result['message'] ?? '(no message)')
        );

        $hash = $captured['password_hash'];

        $this->assertNotNull(
            $hash,
            'SetupService did not bind the password_hash parameter on the users INSERT'
        );
        $this->assertIsString($hash);
        $this->assertNotSame(
            $password,
            $hash,
            'Stored value must be a hash, not the plain-text password (Requirement 6.2)'
        );

        // Bcrypt hashes always start with $2y$ (PHP's PASSWORD_BCRYPT prefix).
        $this->assertMatchesRegularExpression(
            '/^\$2y\$\d{2}\$.{53}$/',
            $hash,
            'Hash must be a well-formed bcrypt hash (PASSWORD_BCRYPT, $2y$ prefix, 60 chars)'
        );

        // Core property assertion: the round-trip must verify.
        $this->assertTrue(
            password_verify($password, $hash),
            sprintf(
                'password_verify FAILED for password (len=%d bytes, mb_len=%d): %s',
                strlen($password),
                mb_strlen($password),
                $this->safeRender($password)
            )
        );

        // Belt-and-suspenders: a different password must NOT verify against
        // the same hash. This guards against a degenerate hash function
        // that returns true for everything (would otherwise mask a real bug).
        //
        // Bcrypt only consumes the first 72 BYTES of its input, so we must
        // mutate inside that window — appending past 72 bytes would produce
        // an identical hash. We flip the first byte to a different value
        // (deterministically), which is always inside the consumed window.
        $firstByte = $password[0];
        $altByte = ($firstByte === 'A') ? 'B' : 'A';
        $mutated = $altByte . substr($password, 1);

        $this->assertNotSame(
            $password,
            $mutated,
            'Test invariant: the mutated password must differ from the original'
        );

        $this->assertFalse(
            password_verify($mutated, $hash),
            'A modified password must not verify against the original hash'
        );
    }

    // ------------------------------------------------------------------
    // Generators
    // ------------------------------------------------------------------

    private static function randomAsciiAlphanumeric(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        return self::randomFromAlphabet($alphabet, $length);
    }

    private static function randomAsciiPrintable(int $length): string
    {
        // ASCII printable range 0x21..0x7E (excludes space — handled separately)
        $alphabet = '';
        for ($c = 0x21; $c <= 0x7E; $c++) {
            $alphabet .= chr($c);
        }
        return self::randomFromAlphabet($alphabet, $length);
    }

    private static function randomWithSpaces(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789      '; // weighted spaces
        return self::randomFromAlphabet($alphabet, $length);
    }

    /**
     * Build a UTF-8 string with at least 8 CHARACTERS (mb_strlen) and at
     * most 72 BYTES (bcrypt's input limit). The string mixes multi-byte
     * code points with ASCII letters.
     *
     * `$targetBytes` is a soft hint — the loop stops as soon as adding
     * another code point would exceed 72 bytes, then pads with ASCII to
     * reach the 8-character minimum if needed.
     */
    private static function randomUtf8WithinByteBudget(int $targetBytes): string
    {
        $targetBytes = min(72, max(16, $targetBytes));

        // Pool of multi-byte code points: Latin-1 supplement, Greek, Cyrillic,
        // currency symbols, and a few common symbols (all 2-3 byte UTF-8
        // sequences — kept short to fit the 72-byte budget).
        $codePoints = [
            'é', 'ñ', 'ü', 'ç', 'å', 'ø', // Latin-1 supplement (2 bytes)
            'α', 'β', 'γ', 'Δ',             // Greek (2 bytes)
            'ж', 'я', 'Ц',                  // Cyrillic (2 bytes)
            '€', '£', '¥',                  // currency (2-3 bytes)
            '☃', '✓', '★',                  // misc symbols (3 bytes)
        ];

        $out = '';
        $safety = 0;
        while (strlen($out) < $targetBytes && $safety < 200) {
            $safety++;
            $candidate = (mt_rand(0, 1) === 0)
                ? $codePoints[array_rand($codePoints)]
                : chr(mt_rand(0x61, 0x7A)); // ascii lowercase

            if (strlen($out) + strlen($candidate) > $targetBytes) {
                break;
            }
            $out .= $candidate;
        }

        // Ensure the >= 8 character minimum (Requirement 6.3 talks in
        // characters, not bytes). Pad with ASCII while we still have byte
        // budget.
        while (mb_strlen($out) < 8 && strlen($out) < 72) {
            $out .= 'a';
        }

        return $out;
    }

    private static function randomFromAlphabet(string $alphabet, int $length): string
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[mt_rand(0, $max)];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // PDO mock plumbing
    // ------------------------------------------------------------------

    /**
     * Build a mock PDO that:
     *   - Accepts beginTransaction() / commit() / rollBack() calls
     *   - Returns a "FOR UPDATE" statement whose fetchColumn() yields 0
     *     (so the lock check passes and we reach the inserts)
     *   - For each `prepare()` call, returns a PDOStatement whose
     *     `execute()` captures bound parameters. The users-INSERT
     *     statement's `password_hash` parameter is written into
     *     `$captured['password_hash']`.
     *   - Returns sequential lastInsertId() values so tenant_id and user_id
     *     resolve to integers as the service expects.
     */
    private function buildMockPdoCapturingPasswordHash(array &$captured): PDO
    {
        $pdo = $this->createMock(PDO::class);

        // --- query() : SELECT COUNT(*) FROM tenants FOR UPDATE returns 0 ---
        $lockStmt = $this->createMock(PDOStatement::class);
        $lockStmt->method('fetchColumn')->willReturn(0);
        $pdo->method('query')->willReturn($lockStmt);

        // --- prepare() : route by SQL fragment ---
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$captured) {
                $stmt = $this->createMock(PDOStatement::class);

                if (stripos($sql, 'INSERT INTO users') !== false) {
                    // Capture the password_hash bound parameter so the
                    // round-trip can be verified by the test body.
                    $stmt->method('execute')->willReturnCallback(
                        function (array $params) use (&$captured) {
                            if (array_key_exists('password_hash', $params)) {
                                $captured['password_hash'] = $params['password_hash'];
                            }
                            return true;
                        }
                    );
                } else {
                    // tenants / tenant_owners INSERTs — accept anything.
                    $stmt->method('execute')->willReturn(true);
                }

                return $stmt;
            }
        );

        // --- transaction lifecycle ---
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // --- lastInsertId() : sequential integers (1, 2, 3, ...) ---
        $idCounter = 0;
        $pdo->method('lastInsertId')->willReturnCallback(
            function () use (&$idCounter) {
                return (string) (++$idCounter);
            }
        );

        return $pdo;
    }

    /**
     * Render a (potentially binary / multi-byte) password safely for use
     * in assertion failure messages.
     */
    private function safeRender(string $password): string
    {
        if (preg_match('//u', $password)) {
            return $password;
        }
        return '0x' . bin2hex($password);
    }

    // ==================================================================
    // Property 4: Security Lock Rejects When Configured
    // ==================================================================

    /**
     * Data provider yielding random VALID setup payloads paired with a
     * random positive `existing_tenants_count` (1..N) to be returned by
     * the FOR UPDATE lock query.
     *
     * Each row: [array $payload, int $existingCount]
     *
     * The payloads cover the same valid input space the real Setup
     * Controller would forward to the service after passing validation:
     *   - tenant_name : non-empty string
     *   - username    : >= 3 chars
     *   - email       : valid email format
     *   - password    : >= 8 chars
     *   - first_name  : non-empty string
     *   - last_name   : non-empty string
     */
    public static function validPayloadWithExistingTenantsProvider(): array
    {
        $cases = [];

        // Boundary: exactly one existing tenant — the smallest "configured"
        // state that should still reject (Requirements 3.1, 13.2).
        $cases['boundary_exactly_one_existing_tenant'] = [
            [
                'tenant_name' => 'Acme Corp',
                'username'    => 'alice',
                'email'       => 'alice@acme.test',
                'password'    => 'correct horse battery staple',
                'first_name'  => 'Alice',
                'last_name'   => 'Anderson',
            ],
            1,
        ];

        // Edge: very large existing tenant count (saturated system).
        $cases['edge_large_existing_tenant_count'] = [
            [
                'tenant_name' => 'Globex',
                'username'    => 'bob_smith',
                'email'       => 'bob@globex.test',
                'password'    => 'p@ssw0rd!12345',
                'first_name'  => 'Bob',
                'last_name'   => 'Smith',
            ],
            PHP_INT_MAX - 1,
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payload = [
                'tenant_name' => self::randomNonEmptyAscii(mt_rand(1, 64), 'Tenant'),
                'username'    => self::randomUsername(mt_rand(3, 32)),
                'email'       => self::randomEmail(),
                'password'    => self::randomPassword(mt_rand(8, 64)),
                'first_name'  => self::randomNonEmptyAscii(mt_rand(1, 32), 'First'),
                'last_name'   => self::randomNonEmptyAscii(mt_rand(1, 32), 'Last'),
            ];

            // Random positive integer for the count returned by the lock
            // query. The contract is "one or more rows" (Requirement 13.2),
            // so any positive integer should trigger the 409 path.
            $existingCount = mt_rand(1, 1_000_000);

            $cases["random_iter{$i}_existing{$existingCount}"] = [
                $payload,
                $existingCount,
            ];
        }

        return $cases;
    }

    /**
     * Property 4: Security Lock Rejects When Configured
     *
     * For any valid setup payload, when the tenants table contains one or
     * more rows (revealed by the FOR UPDATE lock query inside the
     * transaction), `SetupService::initialize()` SHALL:
     *
     *   1. Return ['success' => false, 'status' => 409, 'message' => '...']
     *   2. NOT prepare any INSERT statement (no tenant/user/owner row
     *      written) — i.e. the database is left untouched.
     *   3. Roll back the transaction (no commit).
     *
     * **Validates: Requirements 3.1, 13.2**
     *
     * @dataProvider validPayloadWithExistingTenantsProvider
     */
    public function testInitializeRejectsWith409WhenTenantsTableNotEmpty(
        array $payload,
        int $existingCount
    ): void {
        // Generator invariants — protect against accidental provider bugs.
        $this->assertGreaterThan(
            0,
            $existingCount,
            'Generator invariant: existing tenant count must be positive to exercise the 409 path'
        );
        $this->assertGreaterThanOrEqual(
            8,
            strlen((string) $payload['password']),
            'Generator invariant: password must be >= 8 characters (Requirement 6.3)'
        );
        $this->assertNotSame('', trim((string) $payload['tenant_name']));
        $this->assertNotSame('', trim((string) $payload['username']));
        $this->assertNotFalse(
            filter_var($payload['email'], FILTER_VALIDATE_EMAIL),
            'Generator invariant: email must be valid (Requirement 4.4)'
        );

        // Track what the service did so we can assert "no INSERTs" and
        // "rollBack called" after the call returns.
        $observed = [
            'prepare_calls'         => [],
            'rollback_called'       => false,
            'commit_called'         => false,
            'beginTransaction_called' => false,
        ];

        $pdo = $this->buildMockPdoWithExistingTenants($existingCount, $observed);
        $service = new SetupService('test-jwt-secret-not-used-by-property-4', $pdo);

        $result = $service->initialize($payload);

        // ---- Assertion 1: 409 failure response shape (Requirement 13.2) ----
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse(
            $result['success'],
            sprintf(
                'initialize() must return success=false when %d tenant(s) already exist; got: %s',
                $existingCount,
                var_export($result, true)
            )
        );

        $this->assertArrayHasKey('status', $result);
        $this->assertSame(
            409,
            $result['status'],
            sprintf(
                'initialize() must return HTTP status 409 when tenants table is non-empty (Requirement 13.2); got %s',
                var_export($result['status'], true)
            )
        );

        $this->assertArrayHasKey('message', $result);
        $this->assertIsString($result['message']);
        $this->assertNotSame(
            '',
            trim($result['message']),
            'A non-empty message must accompany the 409 response (Requirement 13.2)'
        );

        // The service must NOT leak the plain-text password into the
        // response message (Requirement 6.2).
        $this->assertStringNotContainsString(
            (string) $payload['password'],
            $result['message'],
            'Plain-text password must never appear in any response message'
        );

        // ---- Assertion 2: NO INSERT statements were prepared ----
        // (the database is not modified — Requirements 3.1, 13.2)
        foreach ($observed['prepare_calls'] as $sql) {
            $this->assertStringNotContainsStringIgnoringCase(
                'INSERT',
                $sql,
                sprintf(
                    'No INSERT statement must be prepared when setup is locked; saw: %s',
                    $sql
                )
            );
        }

        // ---- Assertion 3: transaction was rolled back, not committed ----
        $this->assertTrue(
            $observed['beginTransaction_called'],
            'beginTransaction() must be called so the lock query runs inside a transaction'
        );
        $this->assertTrue(
            $observed['rollback_called'],
            'rollBack() must be called when the lock reveals existing tenants (Requirement 13.2)'
        );
        $this->assertFalse(
            $observed['commit_called'],
            'commit() must NOT be called when setup is rejected — the database must be untouched'
        );
    }

    // ------------------------------------------------------------------
    // Property 4 generators
    // ------------------------------------------------------------------

    private static function randomNonEmptyAscii(int $length, string $prefix = ''): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ';
        $body = self::randomFromAlphabet($alphabet, max(1, $length));
        // Guarantee non-empty after trim() by anchoring with a letter.
        return $prefix . 'X' . $body;
    }

    private static function randomUsername(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789_';
        $length = max(3, $length);
        // First char: letter, to avoid leading underscore/digit edge cases.
        $first = chr(mt_rand(0x61, 0x7A));
        return $first . self::randomFromAlphabet($alphabet, $length - 1);
    }

    private static function randomEmail(): string
    {
        $local = self::randomFromAlphabet(
            'abcdefghijklmnopqrstuvwxyz0123456789',
            mt_rand(3, 12)
        );
        $domain = self::randomFromAlphabet(
            'abcdefghijklmnopqrstuvwxyz',
            mt_rand(3, 10)
        );
        $tlds = ['com', 'net', 'org', 'test', 'example'];
        return $local . '@' . $domain . '.' . $tlds[array_rand($tlds)];
    }

    private static function randomPassword(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        return self::randomFromAlphabet($alphabet, max(8, $length));
    }

    // ------------------------------------------------------------------
    // Property 4 PDO mock plumbing
    // ------------------------------------------------------------------

    /**
     * Build a mock PDO that simulates an "already configured" system:
     *
     *   - `query('SELECT COUNT(*) FROM tenants FOR UPDATE')` returns a
     *     statement whose `fetchColumn()` yields `$existingCount` (>=1).
     *   - `prepare()` is recorded into `$observed['prepare_calls']` and
     *     returns a statement whose `execute()` always succeeds — but per
     *     the property, no INSERT prepare should ever happen, and the
     *     test asserts that explicitly.
     *   - `beginTransaction()`, `commit()`, `rollBack()` set boolean
     *     flags in `$observed` so the test can verify the rollback path
     *     was taken.
     */
    private function buildMockPdoWithExistingTenants(int $existingCount, array &$observed): PDO
    {
        $pdo = $this->createMock(PDO::class);

        // --- query() : the FOR UPDATE lock query returns $existingCount ---
        $lockStmt = $this->createMock(PDOStatement::class);
        $lockStmt->method('fetchColumn')->willReturn($existingCount);
        $pdo->method('query')->willReturn($lockStmt);

        // --- prepare() : record SQL and return a benign statement ---
        // If the service ever prepares an INSERT despite the lock failure
        // that's a property violation; the test body verifies this.
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$observed) {
                $observed['prepare_calls'][] = $sql;

                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);
                return $stmt;
            }
        );

        // --- transaction lifecycle: record which methods were called ---
        $pdo->method('beginTransaction')->willReturnCallback(
            function () use (&$observed) {
                $observed['beginTransaction_called'] = true;
                return true;
            }
        );
        $pdo->method('commit')->willReturnCallback(
            function () use (&$observed) {
                $observed['commit_called'] = true;
                return true;
            }
        );
        $pdo->method('rollBack')->willReturnCallback(
            function () use (&$observed) {
                $observed['rollback_called'] = true;
                return true;
            }
        );
        // After rollBack(), the service may call inTransaction() in the
        // catch block; return false then so it doesn't try to roll back
        // again. We track state via the rollback flag.
        $pdo->method('inTransaction')->willReturnCallback(
            function () use (&$observed) {
                return !$observed['rollback_called'];
            }
        );

        // --- lastInsertId() : should never be called on the rejection
        //     path, but provide a sane stub anyway ---
        $pdo->method('lastInsertId')->willReturn('0');

        return $pdo;
    }
}
