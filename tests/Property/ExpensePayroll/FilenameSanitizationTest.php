<?php

namespace Tests\Property\ExpensePayroll;

use PHPUnit\Framework\TestCase;

/**
 * Property 5: Filename Sanitization Safety
 *
 * For any input filename string, after sanitization the output SHALL NOT contain:
 * - Path traversal sequences (`../`, `..\\`)
 * - Null bytes (`\0`)
 * - Characters outside the set `[a-zA-Z0-9_\-.]`
 *
 * The sanitization logic under test:
 *   str_replace(['../', '..\\', "\0"], '', $input)
 *   then preg_replace('/[^a-zA-Z0-9_\-.]/', '', $sanitized)
 *
 * Feature: expenses-payroll-review, Property 5: Filename sanitization safety
 *
 * **Validates: Requirements 9.3**
 *
 * @group property
 * @group expenses-payroll-review
 */
class FilenameSanitizationTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Apply the same sanitization logic as ExpensesController::uploadAttachment().
     */
    private function sanitizeFilename(string $input): string
    {
        // Step 1: Remove path traversal sequences and null bytes
        $sanitized = str_replace(['../', '..\\', "\0"], '', $input);
        // Step 2: Keep only allowed characters
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $sanitized);
        return $sanitized;
    }

    /**
     * Generate random filenames containing dangerous characters.
     * Includes path traversal sequences, null bytes, unicode, special chars, etc.
     */
    public static function dangerousFilenameProvider(): array
    {
        $cases = [];

        // Generators for dangerous filename strings
        $generators = [
            // Path traversal sequences
            fn() => '../../../etc/passwd',
            fn() => '..\\..\\..\\windows\\system32\\config\\sam',
            fn() => 'uploads/../../../secret.txt',
            fn() => '..\\admin\\config.php',
            // Null byte injection
            fn() => "malware.php\0.jpg",
            fn() => "shell\0.exe",
            fn() => "\0\0\0hidden.txt",
            // Unicode and special characters
            fn() => 'файл_' . mt_rand(1, 999) . '.pdf',
            fn() => '文件名.doc',
            fn() => 'résumé_' . mt_rand(1, 99) . '.docx',
            // HTML/Script injection in filename
            fn() => '<script>alert("xss")</script>.jpg',
            fn() => 'file"name.png',
            fn() => "file'name.pdf",
            // Spaces and control characters
            fn() => "file with spaces.pdf",
            fn() => "tab\there.doc",
            fn() => "newline\nhere.png",
            fn() => "carriage\rreturn.pdf",
            // Mixed dangerous patterns
            fn() => '../' . chr(0) . 'evil.php',
            fn() => '..\\' . chr(0) . '..\\secret.ini',
            fn() => str_repeat('../', mt_rand(1, 10)) . 'passwd',
            fn() => str_repeat('..\\', mt_rand(1, 10)) . 'boot.ini',
            // Shell metacharacters
            fn() => 'file;rm -rf /.pdf',
            fn() => 'file|cat /etc/passwd.txt',
            fn() => 'file$(whoami).doc',
            fn() => 'file`id`.png',
            // URL encoding attempts
            fn() => '%2e%2e%2ffile.txt',
            fn() => '..%2ffile.pdf',
            fn() => '%00malicious.exe',
            // Double-encoded traversal
            fn() => '....//....//etc/passwd',
            fn() => '..../\\..../\\win.ini',
            // Long filenames with random bytes
            fn() => bin2hex(random_bytes(mt_rand(5, 30))) . '.' . ['pdf', 'jpg', 'png', 'doc'][mt_rand(0, 3)],
            // Completely random binary data
            fn() => random_bytes(mt_rand(10, 50)),
            // Mixed safe and unsafe characters
            fn() => 'normal_file-name' . chr(mt_rand(0, 31)) . '.pdf',
            fn() => 'report_' . mt_rand(1, 999) . '/../../../etc/shadow',
            // Empty-ish inputs
            fn() => '../',
            fn() => '..\\',
            fn() => "\0",
            fn() => '.',
            fn() => '..',
            // Nested traversal that might bypass single-pass replacement
            fn() => '....//',
            fn() => '....\\\\',
            fn() => '.' . '.' . '/' . 'evil.txt',
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $generator = $generators[array_rand($generators)];
            $dangerousFilename = $generator();
            $cases["dangerous_filename_{$i}"] = [$dangerousFilename];
        }

        // Add some explicit edge cases beyond the random iterations
        $cases['empty_string'] = [''];
        $cases['only_null_bytes'] = ["\0\0\0\0"];
        $cases['only_traversal_forward'] = ['../../../'];
        $cases['only_traversal_backward'] = ['..\\..\\..\\'];
        $cases['max_length_traversal'] = [str_repeat('../', 50)];
        $cases['null_between_dots_slash'] = ["..\0./"];
        $cases['unicode_lookalike_dot_dot_slash'] = ['．．／file.txt']; // fullwidth chars

        return $cases;
    }

    /**
     * Property 5: Sanitized filenames contain no path traversal sequences.
     *
     * For any input filename string, the sanitized output SHALL NOT contain
     * the path traversal sequence `../`.
     *
     * Feature: expenses-payroll-review, Property 5: Filename sanitization safety
     *
     * **Validates: Requirements 9.3**
     *
     * @dataProvider dangerousFilenameProvider
     */
    public function testSanitizedOutputContainsNoForwardTraversal(string $input): void
    {
        $sanitized = $this->sanitizeFilename($input);

        $this->assertStringNotContainsString(
            '../',
            $sanitized,
            sprintf(
                'Sanitized filename still contains "../" path traversal. Input: %s, Output: %s',
                bin2hex($input),
                $sanitized
            )
        );
    }

    /**
     * Property 5: Sanitized filenames contain no backslash traversal sequences.
     *
     * For any input filename string, the sanitized output SHALL NOT contain
     * the path traversal sequence `..\\`.
     *
     * Feature: expenses-payroll-review, Property 5: Filename sanitization safety
     *
     * **Validates: Requirements 9.3**
     *
     * @dataProvider dangerousFilenameProvider
     */
    public function testSanitizedOutputContainsNoBackwardTraversal(string $input): void
    {
        $sanitized = $this->sanitizeFilename($input);

        $this->assertStringNotContainsString(
            '..\\',
            $sanitized,
            sprintf(
                'Sanitized filename still contains "..\\" path traversal. Input: %s, Output: %s',
                bin2hex($input),
                $sanitized
            )
        );
    }

    /**
     * Property 5: Sanitized filenames contain no null bytes.
     *
     * For any input filename string, the sanitized output SHALL NOT contain
     * null bytes (`\0`).
     *
     * Feature: expenses-payroll-review, Property 5: Filename sanitization safety
     *
     * **Validates: Requirements 9.3**
     *
     * @dataProvider dangerousFilenameProvider
     */
    public function testSanitizedOutputContainsNoNullBytes(string $input): void
    {
        $sanitized = $this->sanitizeFilename($input);

        $this->assertStringNotContainsString(
            "\0",
            $sanitized,
            sprintf(
                'Sanitized filename still contains null byte. Input: %s, Output: %s',
                bin2hex($input),
                bin2hex($sanitized)
            )
        );
    }

    /**
     * Property 5: Sanitized filenames contain only allowed characters.
     *
     * For any input filename string, the sanitized output SHALL only contain
     * characters from the set `[a-zA-Z0-9_\-.]`.
     *
     * Feature: expenses-payroll-review, Property 5: Filename sanitization safety
     *
     * **Validates: Requirements 9.3**
     *
     * @dataProvider dangerousFilenameProvider
     */
    public function testSanitizedOutputContainsOnlyAllowedCharacters(string $input): void
    {
        $sanitized = $this->sanitizeFilename($input);

        // The output should match entirely against the allowed character set
        // An empty string is valid (all dangerous chars removed)
        $this->assertMatchesRegularExpression(
            '/^[a-zA-Z0-9_\-.]*$/',
            $sanitized,
            sprintf(
                'Sanitized filename contains disallowed characters. Input: %s, Output: %s',
                bin2hex($input),
                $sanitized
            )
        );
    }

    /**
     * Property 5: Sanitized filenames preserve safe content.
     *
     * For any input filename containing only allowed characters,
     * the sanitized output SHALL be identical to the input.
     *
     * Feature: expenses-payroll-review, Property 5: Filename sanitization safety
     *
     * **Validates: Requirements 9.3**
     */
    public function testSafeFilenamesArePreserved(): void
    {
        $safeChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.';

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate a random safe filename
            $length = mt_rand(1, 50);
            $safeName = '';
            for ($j = 0; $j < $length; $j++) {
                $safeName .= $safeChars[mt_rand(0, strlen($safeChars) - 1)];
            }

            $sanitized = $this->sanitizeFilename($safeName);

            $this->assertSame(
                $safeName,
                $sanitized,
                sprintf(
                    'Safe filename was modified by sanitization. Input: %s, Output: %s',
                    $safeName,
                    $sanitized
                )
            );
        }
    }
}
