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

        // Seed POCC branches if missing
        $branches = [
            ['code' => 'QC',   'name' => 'QUEZON CITY'],
            ['code' => 'MNL',  'name' => 'MANILA'],
            ['code' => 'STB',  'name' => 'STO. TOMAS BATANGAS'],
            ['code' => 'SFLU', 'name' => 'SAN FERNANDO CITY LA UNION'],
            ['code' => 'DASC', 'name' => 'DASMARINAS CAVITE'],
        ];
        foreach ($branches as $b) {
            $exists = $this->pdo->prepare('SELECT 1 FROM branches WHERE code = :c OR name = :n LIMIT 1');
            $exists->execute(['c' => $b['code'], 'n' => $b['name']]);
            if ($exists->fetchColumn() === false) {
                $ins = $this->pdo->prepare('INSERT INTO branches (code, name, is_active) VALUES (:c,:n, TRUE)');
                $ins->execute(['c' => $b['code'], 'n' => $b['name']]);
                $logs[] = 'Seeded branch ' . $b['name'];
            }
        }
        $branchId = (int)$this->pdo->query('SELECT branch_id FROM branches ORDER BY branch_id ASC LIMIT 1')->fetchColumn();

        // Create messages table (idempotent)
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages (
            id BIGSERIAL PRIMARY KEY,
            sender_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
            recipient_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            is_read BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )');
        $logs[] = 'Messaging table ensured';

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
