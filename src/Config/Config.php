<?php
/**
 * Application Configuration
 *
 * Loads environment variables from a .env file (if it exists) and uses them
 * to configure the application. Falls back to hardcoded development defaults
 * when no .env file is present.
 *
 * .env format: KEY=VALUE (one per line, # for comments, blank lines ignored)
 */

// Simple .env file parser — no external library required
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Split on first '=' only
        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));

        // Remove surrounding quotes if present
        if (strlen($value) >= 2) {
            if (($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
                ($value[0] === "'" && $value[strlen($value) - 1] === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Set in $_ENV and putenv for compatibility
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

$config = [
    'db' => [
        'host'   => $_ENV['DB_HOST'] ?? 'localhost',
        'dbname' => $_ENV['DB_NAME'] ?? 'erp',
        'user'   => $_ENV['DB_USER'] ?? 'root',
        'pass'   => $_ENV['DB_PASS'] ?? '',
    ],
    'jwt_secret'  => $_ENV['JWT_SECRET'] ?? '4hG7!k9xPq2@b8ZrF6sL0mN1vYcT5wE3',
    'cron_secret' => $_ENV['CRON_SECRET'] ?? 'cron-8Xp2Qz7Lm4Nv9Ks1Yw6Hd3Tf0Rj5Bg',
];
