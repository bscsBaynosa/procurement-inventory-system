<?php

namespace App\Setup;

use App\Database\Connection;
use PDO;

class Installer
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::resolve();
    }

    /**
     * Apply schema and seed initial data. Returns an array of log lines.
     * Idempotent: safe to run multiple times.
     *
     * @return string[]
     */
    public function run(): array
    {
        $logs = [];

        // Apply schema
        $schemaPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
        if (!file_exists($schemaPath)) {
            return ['ERROR: schema.sql not found'];
        }

        $sql = file_get_contents($schemaPath);
        if ($sql === false) {
            return ['ERROR: failed to read schema.sql'];
        }

        $this->pdo->exec($sql);
        $logs[] = 'Schema applied successfully';

        // Seed branch if none
        $hasBranch = $this->pdo->query('SELECT 1 FROM branches LIMIT 1');
        $branchId = null;
        if ($hasBranch === false || $hasBranch->fetchColumn() === false) {
            $stmt = $this->pdo->prepare('INSERT INTO branches (code, name, address, is_active) VALUES (:code, :name, :address, TRUE) RETURNING branch_id');
            $stmt->execute(['code' => 'HQ', 'name' => 'Head Office', 'address' => 'N/A']);
            $branchId = (int)$stmt->fetchColumn();
            $logs[] = 'Seeded default branch (HQ)';
        } else {
            $branchId = (int)$this->pdo->query('SELECT branch_id FROM branches ORDER BY branch_id ASC LIMIT 1')->fetchColumn();
        }

        // Seed admin user if missing
        $username = getenv('SEED_ADMIN_USERNAME') ?: 'admin';
        $password = getenv('SEED_ADMIN_PASSWORD') ?: 'ChangeMe123!';

        $stmt = $this->pdo->prepare('SELECT user_id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);

        if ($stmt->fetchColumn() === false) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $this->pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role, branch_id, is_active) VALUES (:u, :p, :n, :e, :r, :b, TRUE)');
            $ins->execute([
                'u' => $username,
                'p' => $hash,
                'n' => 'System Administrator',
                'e' => null,
                'r' => 'admin',
                'b' => $branchId ?: null,
            ]);
            $logs[] = "Seeded admin user '{$username}' (default password set)";
        } else {
            $logs[] = "Admin user '{$username}' already exists";
        }

        return $logs;
    }
}
