<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for environment variable loading in config.php (REQ-020).
 *
 * Tests:
 * - Config loads values from a .env file when it exists
 * - Config falls back to hardcoded defaults when no .env file exists
 * - The .env parser handles comments, blank lines, and quoted values correctly
 *
 * Validates: Requirements REQ-020
 */
class EnvConfigLoadingTest extends TestCase
{
    private string $tempDir;
    private string $configDir;
    private string $configFile;
    private string $envFile;

    protected function setUp(): void
    {
        // Create a temporary directory structure that mirrors the project layout
        $this->tempDir = sys_get_temp_dir() . '/erp_env_test_' . uniqid();
        $this->configDir = $this->tempDir . '/src/Config';
        mkdir($this->configDir, 0777, true);

        $this->envFile = $this->tempDir . '/.env';
        $this->configFile = $this->configDir . '/config.php';

        // Copy the config.php content but adjust the .env path for testing
        $configContent = file_get_contents(__DIR__ . '/../../src/Config/config.php');
        // Replace the relative path to .env with our temp directory path
        $configContent = str_replace(
            "\$envFile = __DIR__ . '/../../.env';",
            "\$envFile = '" . str_replace('\\', '/', $this->tempDir) . "/.env';",
            $configContent
        );
        file_put_contents($this->configFile, $configContent);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        if (file_exists($this->envFile)) {
            unlink($this->envFile);
        }
        if (file_exists($this->configFile)) {
            unlink($this->configFile);
        }
        if (is_dir($this->configDir)) {
            rmdir($this->configDir);
        }
        // Remove src directory
        $srcDir = $this->tempDir . '/src';
        if (is_dir($srcDir)) {
            rmdir($srcDir);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        // Clean up $_ENV entries we may have set
        unset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
        unset($_ENV['JWT_SECRET'], $_ENV['CRON_SECRET']);
        putenv('DB_HOST');
        putenv('DB_NAME');
        putenv('DB_USER');
        putenv('DB_PASS');
        putenv('JWT_SECRET');
        putenv('CRON_SECRET');
    }

    /**
     * Test: config loads values from .env file when it exists.
     */
    public function testConfigLoadsFromEnvFile(): void
    {
        // Create a .env file with custom values
        $envContent = <<<'ENV'
DB_HOST=192.168.1.100
DB_NAME=erp_production
DB_USER=erp_admin
DB_PASS=s3cur3P@ss!
JWT_SECRET=my-custom-jwt-secret-key-12345
CRON_SECRET=my-custom-cron-secret
ENV;
        file_put_contents($this->envFile, $envContent);

        // Load the config
        $config = $this->loadConfig();

        $this->assertEquals('192.168.1.100', $config['db']['host']);
        $this->assertEquals('erp_production', $config['db']['dbname']);
        $this->assertEquals('erp_admin', $config['db']['user']);
        $this->assertEquals('s3cur3P@ss!', $config['db']['pass']);
        $this->assertEquals('my-custom-jwt-secret-key-12345', $config['jwt_secret']);
        $this->assertEquals('my-custom-cron-secret', $config['cron_secret']);
    }

    /**
     * Test: config falls back to hardcoded defaults when .env does not exist.
     */
    public function testConfigFallsBackToDefaultsWithoutEnvFile(): void
    {
        // Ensure no .env file exists
        if (file_exists($this->envFile)) {
            unlink($this->envFile);
        }

        // Clear any previously set env vars
        unset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
        unset($_ENV['JWT_SECRET'], $_ENV['CRON_SECRET']);
        putenv('DB_HOST');
        putenv('DB_NAME');
        putenv('DB_USER');
        putenv('DB_PASS');
        putenv('JWT_SECRET');
        putenv('CRON_SECRET');

        $config = $this->loadConfig();

        $this->assertEquals('localhost', $config['db']['host']);
        $this->assertEquals('erp', $config['db']['dbname']);
        $this->assertEquals('root', $config['db']['user']);
        $this->assertEquals('', $config['db']['pass']);
        $this->assertEquals('4hG7!k9xPq2@b8ZrF6sL0mN1vYcT5wE3', $config['jwt_secret']);
        $this->assertEquals('cron-8Xp2Qz7Lm4Nv9Ks1Yw6Hd3Tf0Rj5Bg', $config['cron_secret']);
    }

    /**
     * Test: .env parser ignores comments and blank lines.
     */
    public function testEnvParserIgnoresCommentsAndBlankLines(): void
    {
        $envContent = <<<'ENV'
# This is a comment
DB_HOST=myhost

# Another comment

DB_NAME=mydb
ENV;
        file_put_contents($this->envFile, $envContent);

        $config = $this->loadConfig();

        $this->assertEquals('myhost', $config['db']['host']);
        $this->assertEquals('mydb', $config['db']['dbname']);
    }

    /**
     * Test: .env parser handles quoted values (double and single quotes).
     */
    public function testEnvParserHandlesQuotedValues(): void
    {
        $envContent = <<<'ENV'
DB_HOST="quoted-host"
DB_NAME='single-quoted-db'
DB_USER=unquoted-user
DB_PASS="pass with spaces"
ENV;
        file_put_contents($this->envFile, $envContent);

        $config = $this->loadConfig();

        $this->assertEquals('quoted-host', $config['db']['host']);
        $this->assertEquals('single-quoted-db', $config['db']['dbname']);
        $this->assertEquals('unquoted-user', $config['db']['user']);
        $this->assertEquals('pass with spaces', $config['db']['pass']);
    }

    /**
     * Test: .env parser handles values with equals signs in them.
     */
    public function testEnvParserHandlesEqualsInValue(): void
    {
        $envContent = <<<'ENV'
JWT_SECRET=abc=def=ghi
ENV;
        file_put_contents($this->envFile, $envContent);

        $config = $this->loadConfig();

        $this->assertEquals('abc=def=ghi', $config['jwt_secret']);
    }

    /**
     * Test: .env parser handles empty values.
     */
    public function testEnvParserHandlesEmptyValues(): void
    {
        $envContent = <<<'ENV'
DB_HOST=localhost
DB_PASS=
ENV;
        file_put_contents($this->envFile, $envContent);

        $config = $this->loadConfig();

        $this->assertEquals('localhost', $config['db']['host']);
        $this->assertEquals('', $config['db']['pass']);
    }

    /**
     * Helper: loads the config file and returns the $config array.
     * Uses an isolated include to avoid variable pollution between tests.
     */
    private function loadConfig(): array
    {
        // Clear $_ENV before loading to ensure clean state
        unset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
        unset($_ENV['JWT_SECRET'], $_ENV['CRON_SECRET']);
        putenv('DB_HOST');
        putenv('DB_NAME');
        putenv('DB_USER');
        putenv('DB_PASS');
        putenv('JWT_SECRET');
        putenv('CRON_SECRET');

        // Include the config file in an isolated scope
        $loadConfigFn = function (string $file): array {
            include $file;
            return $config ?? [];
        };

        return $loadConfigFn($this->configFile);
    }
}
