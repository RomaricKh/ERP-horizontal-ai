<?php
namespace App\Config;

use PDO;
use PDOException;

class Database
{
    /**
     * @var PDO|null The single PDO instance
     */
    private static ?PDO $pdo = null;

    /**
     * Connect to the database
     *
     * @param array $dbConfig ['host'=>..., 'dbname'=>..., 'user'=>..., 'pass'=>..., 'charset'=>...]
     * @return PDO
     */
    public static function connect(array $dbConfig): PDO
    {
        if (self::$pdo === null) {
            $host    = $dbConfig['host'] ?? 'localhost';
            $dbname  = $dbConfig['dbname'] ?? '';
            $user    = $dbConfig['user'] ?? 'root';
            $pass    = $dbConfig['pass'] ?? '';
            $charset = $dbConfig['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

            try {
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,           // throw exceptions on errors
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // fetch as associative arrays
                    PDO::ATTR_EMULATE_PREPARES => false,                 // real prepared statements
                ]);
            } catch (PDOException $e) {
                // Optional: log to file instead of echoing in production
                error_log("[DB ERROR] " . $e->getMessage());
                die("Database connection failed. Check logs.");
            }
        }

        return self::$pdo;
    }

    /**
     * Get the existing PDO connection
     *
     * @return PDO
     */
    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            throw new \Exception("Database not connected. Call Database::connect() first.");
        }
        return self::$pdo;
    }
}
