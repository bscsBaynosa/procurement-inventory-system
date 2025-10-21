<?php

namespace App\Database;

use Dotenv\Dotenv;
use PDO;
use PDOException;
use RuntimeException;

class Connection
{
    private static ?PDO $pdo = null;

    /**
     * Resolve a shared PDO connection using configuration from environment variables.
     */
    public static function resolve(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        self::bootstrapDotEnv();

        $config = self::parseConfigFromEnvironment();

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['sslmode']
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to connect to the PostgreSQL database: ' . $exception->getMessage(), 0, $exception);
        }

        return self::$pdo;
    }

    /**
     * Load environment variables from a .env file when available.
     */
    private static function bootstrapDotEnv(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $dotenvPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($dotenvPath)) {
            return;
        }

        // Avoid loading the same .env file multiple times in long-running processes.
        static $hasBootstrapped = false;
        if ($hasBootstrapped) {
            return;
        }

        $dotenv = Dotenv::createImmutable($projectRoot);
        $dotenv->safeLoad();
        $hasBootstrapped = true;
    }

    /**
     * Extract connection details from typical Heroku or custom environment variables.
     */
    private static function parseConfigFromEnvironment(): array
    {
        $url = getenv('DATABASE_URL');
        if ($url) {
            return self::parseDatabaseUrl($url);
        }

        $host = getenv('PGHOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('PGPORT') ?: getenv('DB_PORT') ?: '5432';
        $database = getenv('PGDATABASE') ?: getenv('DB_DATABASE') ?: 'postgres';
        $username = getenv('PGUSER') ?: getenv('DB_USERNAME') ?: 'postgres';
        $password = getenv('PGPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
        $sslmode = getenv('PGSSLMODE') ?: getenv('DB_SSLMODE') ?: 'prefer';

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'sslmode' => $sslmode,
        ];
    }

    /**
     * Parse a DATABASE_URL string into discrete configuration values.
     */
    private static function parseDatabaseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            throw new RuntimeException('Invalid DATABASE_URL format.');
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return [
            'host' => $parts['host'],
            'port' => (string)($parts['port'] ?? '5432'),
            'database' => ltrim($parts['path'], '/'),
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
            'sslmode' => $query['sslmode'] ?? getenv('PGSSLMODE') ?? 'require',
        ];
    }
}
