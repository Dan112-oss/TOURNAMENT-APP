<?php
declare(strict_types=1);

/**
 * Database
 *
 * Singleton PDO connection. Every core class and API endpoint
 * should get its connection via Database::getConnection() rather
 * than instantiating PDO directly, so the whole app shares one
 * connection per request.
 */
class Database
{
    private static ?PDO $instance = null;

    // Prevent direct instantiation and cloning — this is a singleton.
    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';

            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $config['db_host'],
                $config['db_name'],
                $config['db_charset']
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    $config['db_user'],
                    $config['db_pass'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        // Use real prepared statements, not client-side emulation.
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                // Never leak connection details (host/user/pass) to the client.
                error_log('Database connection failed: ' . $e->getMessage());
                throw new RuntimeException('Database connection failed.');
            }
        }

        return self::$instance;
    }
}
