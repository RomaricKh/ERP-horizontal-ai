<?php

namespace Tests\Unit;

use App\Services\AuthService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for password reset token flow (REQ-017).
 *
 * Tests:
 * - Requesting a password reset creates a token in the database
 * - A valid token can be used to reset the password
 * - An expired token is rejected
 * - A used token (used_at is non-null) is rejected
 * - An invalid token is rejected
 *
 * Validates: Requirements REQ-017, Correctness Property 6
 */
class PasswordResetTokenTest extends TestCase
{
    /**
     * Test: requestPasswordReset creates a token record for a valid email.
     */
    public function testRequestPasswordResetCreatesToken(): void
    {
        $executedQueries = [];
        $executedParams = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$executedQueries, &$executedParams) {
                $stmt = $this->createMock(PDOStatement::class);
                $capturedParams = [];

                $stmt->method('execute')->willReturnCallback(
                    function ($params = null) use (&$capturedParams, &$executedQueries, &$executedParams, $sql) {
                        $capturedParams = $params;
                        $executedQueries[] = $sql;
                        $executedParams[] = $params;
                        return true;
                    }
                );

                // SELECT user by email — return a user
                if (stripos($sql, 'FROM users') !== false && stripos($sql, 'email') !== false) {
                    $stmt->method('fetch')->willReturn([
                        'id' => 1,
                        'email' => 'test@example.com',
                        'first_name' => 'Test'
                    ]);
                }

                // SELECT business for SMTP — return null (no SMTP configured)
                if (stripos($sql, 'FROM businesses') !== false) {
                    $stmt->method('fetchColumn')->willReturn(false);
                }

                return $stmt;
            }
        );

        $service = new AuthService('test-secret', $pdo);
        $service->requestPasswordReset('test@example.com');

        // Verify an INSERT into password_reset_tokens was executed
        $insertQueries = array_filter($executedQueries, function ($sql) {
            return stripos($sql, 'INSERT INTO password_reset_tokens') !== false;
        });

        $this->assertNotEmpty($insertQueries, 'A token should be inserted into password_reset_tokens');

        // Verify the insert params contain user_id, token_hash, and expires_at
        $insertIdx = array_key_first($insertQueries);
        $insertParams = $executedParams[$insertIdx];
        $this->assertEquals(1, $insertParams['user_id']);
        $this->assertNotEmpty($insertParams['token_hash']);
        $this->assertEquals(64, strlen($insertParams['token_hash']), 'Token hash should be 64 chars (SHA-256)');
        $this->assertNotEmpty($insertParams['expires_at']);
    }

    /**
     * Test: requestPasswordReset does nothing for non-existent email (no enumeration).
     */
    public function testRequestPasswordResetSilentForUnknownEmail(): void
    {
        $executedQueries = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$executedQueries) {
                $stmt = $this->createMock(PDOStatement::class);

                $stmt->method('execute')->willReturnCallback(
                    function () use (&$executedQueries, $sql) {
                        $executedQueries[] = $sql;
                        return true;
                    }
                );

                // SELECT user by email — return null (not found)
                if (stripos($sql, 'FROM users') !== false) {
                    $stmt->method('fetch')->willReturn(false);
                }

                return $stmt;
            }
        );

        $service = new AuthService('test-secret', $pdo);
        $service->requestPasswordReset('nonexistent@example.com');

        // Verify NO insert was executed
        $insertQueries = array_filter($executedQueries, function ($sql) {
            return stripos($sql, 'INSERT INTO password_reset_tokens') !== false;
        });

        $this->assertEmpty($insertQueries, 'No token should be created for unknown email');
    }

    /**
     * Test: resetPassword succeeds with a valid, unexpired, unused token.
     */
    public function testResetPasswordSucceedsWithValidToken(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $executedQueries = [];
        $executedParams = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$executedQueries, &$executedParams, $tokenHash, $expiresAt) {
                $stmt = $this->createMock(PDOStatement::class);

                $stmt->method('execute')->willReturnCallback(
                    function ($params = null) use (&$executedQueries, &$executedParams, $sql) {
                        $executedQueries[] = $sql;
                        $executedParams[] = $params;
                        return true;
                    }
                );

                // SELECT token record
                if (stripos($sql, 'FROM password_reset_tokens') !== false) {
                    $stmt->method('fetch')->willReturn([
                        'id' => 1,
                        'user_id' => 42,
                        'expires_at' => $expiresAt,
                        'used_at' => null,
                    ]);
                }

                return $stmt;
            }
        );

        $service = new AuthService('test-secret', $pdo);
        $service->resetPassword($rawToken, 'newPassword123');

        // Verify password was updated
        $updatePasswordQueries = array_filter($executedQueries, function ($sql) {
            return stripos($sql, 'UPDATE users') !== false && stripos($sql, 'password_hash') !== false;
        });
        $this->assertNotEmpty($updatePasswordQueries, 'Password should be updated');

        // Verify token was marked as used
        $markUsedQueries = array_filter($executedQueries, function ($sql) {
            return stripos($sql, 'UPDATE password_reset_tokens') !== false && stripos($sql, 'used_at') !== false;
        });
        $this->assertNotEmpty($markUsedQueries, 'Token should be marked as used');
    }

    /**
     * Test: resetPassword rejects an expired token.
     * Validates Correctness Property 6: token SHALL be rejected if expires_at is in the past.
     */
    public function testResetPasswordRejectsExpiredToken(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        // Token expired 1 hour ago
        $expiresAt = (new \DateTime('-1 hour'))->format('Y-m-d H:i:s');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($expiresAt) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);

                if (stripos($sql, 'FROM password_reset_tokens') !== false) {
                    $stmt->method('fetch')->willReturn([
                        'id' => 1,
                        'user_id' => 42,
                        'expires_at' => $expiresAt,
                        'used_at' => null,
                    ]);
                }

                return $stmt;
            }
        );

        $service = new AuthService('test-secret', $pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expired');

        $service->resetPassword($rawToken, 'newPassword123');
    }

    /**
     * Test: resetPassword rejects a token that has already been used.
     * Validates Correctness Property 6: token SHALL be rejected if used_at is non-null.
     */
    public function testResetPasswordRejectsUsedToken(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');
        $usedAt = (new \DateTime('-30 minutes'))->format('Y-m-d H:i:s');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($expiresAt, $usedAt) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);

                if (stripos($sql, 'FROM password_reset_tokens') !== false) {
                    $stmt->method('fetch')->willReturn([
                        'id' => 1,
                        'user_id' => 42,
                        'expires_at' => $expiresAt,
                        'used_at' => $usedAt, // Already used!
                    ]);
                }

                return $stmt;
            }
        );

        $service = new AuthService('test-secret', $pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already been used');

        $service->resetPassword($rawToken, 'newPassword123');
    }

    /**
     * Test: resetPassword rejects an invalid (non-existent) token.
     */
    public function testResetPasswordRejectsInvalidToken(): void
    {
        $rawToken = bin2hex(random_bytes(32));

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);

                if (stripos($sql, 'FROM password_reset_tokens') !== false) {
                    $stmt->method('fetch')->willReturn(false); // Not found
                }

                return $stmt;
            }
        );

        $service = new AuthService('test-secret', $pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired');

        $service->resetPassword($rawToken, 'newPassword123');
    }

    /**
     * Test: resetPassword rejects a password that is too short.
     */
    public function testResetPasswordRejectsShortPassword(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($expiresAt) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);

                if (stripos($sql, 'FROM password_reset_tokens') !== false) {
                    $stmt->method('fetch')->willReturn([
                        'id' => 1,
                        'user_id' => 42,
                        'expires_at' => $expiresAt,
                        'used_at' => null,
                    ]);
                }

                return $stmt;
            }
        );

        $service = new AuthService('test-secret', $pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 6 characters');

        $service->resetPassword($rawToken, 'abc');
    }

    /**
     * Test: A successful reset sets used_at, making the token single-use.
     * Validates Correctness Property 6: A successful reset SHALL set used_at to current timestamp.
     */
    public function testSuccessfulResetSetsUsedAt(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $executedQueries = [];
        $executedParams = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$executedQueries, &$executedParams, $expiresAt) {
                $stmt = $this->createMock(PDOStatement::class);

                $stmt->method('execute')->willReturnCallback(
                    function ($params = null) use (&$executedQueries, &$executedParams, $sql) {
                        $executedQueries[] = $sql;
                        $executedParams[] = $params;
                        return true;
                    }
                );

                if (stripos($sql, 'FROM password_reset_tokens') !== false) {
                    $stmt->method('fetch')->willReturn([
                        'id' => 5,
                        'user_id' => 42,
                        'expires_at' => $expiresAt,
                        'used_at' => null,
                    ]);
                }

                return $stmt;
            }
        );

        $service = new AuthService('test-secret', $pdo);
        $service->resetPassword($rawToken, 'validPassword123');

        // Find the UPDATE that sets used_at
        $markUsedQueries = array_filter($executedQueries, function ($sql) {
            return stripos($sql, 'UPDATE password_reset_tokens') !== false
                && stripos($sql, 'used_at') !== false;
        });

        $this->assertNotEmpty($markUsedQueries, 'Token used_at should be set after successful reset');

        // Verify the correct token ID was updated
        $markIdx = array_key_first($markUsedQueries);
        $markParams = $executedParams[$markIdx];
        $this->assertEquals(5, $markParams['id']);
    }
}
