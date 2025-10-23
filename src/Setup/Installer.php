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

        // Ensure users table has first_name/last_name columns; migrate legacy full_name data
        try {
            $colCheck = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='users'")
                ->fetchAll(PDO::FETCH_COLUMN);
            $hasFirst = in_array('first_name', $colCheck, true);
            $hasLast = in_array('last_name', $colCheck, true);
            if (!$hasFirst) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(120) NOT NULL DEFAULT ''");
                $logs[] = 'Added users.first_name column';
            }
            if (!$hasLast) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(120) NOT NULL DEFAULT ''");
                $logs[] = 'Added users.last_name column';
            }
            // Backfill first_name/last_name from full_name when empty
            $this->pdo->exec(<<<SQL
                UPDATE users SET
                    first_name = CASE
                        WHEN first_name IS NULL OR first_name = '' THEN COALESCE(split_part(full_name, ' ', 1), full_name)
                        ELSE first_name
                    END,
                    last_name = CASE
                        WHEN last_name IS NULL OR last_name = '' THEN
                            CASE
                                WHEN strpos(full_name, ' ') > 0 THEN split_part(full_name, ' ', array_length(regexp_split_to_array(full_name, '\\s+'), 1))
                                ELSE full_name
                            END
                        ELSE last_name
                    END
                WHERE (first_name IS NULL OR first_name = '') OR (last_name IS NULL OR last_name = '');
            SQL);
            // Ensure full_name stays consistent where possible
            $this->pdo->exec("UPDATE users SET full_name = TRIM(CONCAT_WS(' ', first_name, last_name)) WHERE TRIM(full_name) = '' OR full_name IS NULL");
        } catch (\Throwable $ignored) {
            // Non-fatal during setup; columns may already exist in some environments
        }

        // Seed/ensure POCC branches with addresses (idempotent)
        $branches = [
            [
                'code' => 'QC',
                'name' => 'QUEZON CITY',
                'address' => "POCC Centralized Services Office (CSO)\nTel: (2) 8283-2232\nMobile: +63 917-122-6186\nEmail: pocc.cares@gmail.com\n\nPOCC Fairview Cancer Center\nBasement Marian Medical Arts Bldg., Dahlia Ave, West Fairview, Quezon City 1118\nTel: +63 (2) 8429-5179\nMobile: +63 917-705-6057\nEmail: info.pocc@gmail.com\nWeb: https://www.philippineoncologycenter.com",
            ],
            [
                'code' => 'MNL',
                'name' => 'MANILA',
                'address' => "POCC-MCM Radiation Therapy Unit\nYWCA Annex Bldg., G/F, UN Ave Ermita, Manila 1000\nTel: +63 (2) 9975-6533, 523-8131 loc 2647\nMobile: +63 917-706-7301\nEmail: info.RT.manilamed@gmail.com",
            ],
            [
                'code' => 'STB',
                'name' => 'STO. TOMAS BATANGAS',
                'address' => "St. Frances Cabrini Medical Center & Cancer Institute, Radiotherapy Section\nSt. Frances Cabrini Medical Tourism Park, Maharlika Highway, Poblacion 2, Sto. Tomas, Batangas 4234\nTel: +63 (43) 7784-8411 loc 8832 or 728-0216\nMobile: +63 917-715-5283\nEmail: pca@gmail.com",
            ],
            [
                'code' => 'SFLU',
                'name' => 'SAN FERNANDO CITY LA UNION',
                'address' => "Bethany Cancer Center\nBasement, Northwing, Bethany Hospital, Inc.\nWiddoes St., Brgy 2, San Fernando, La Union 2500\nTel: +63 (72) 888-2930 loc 172\nMobile: +63 917-715-5273\nEmail: info.RT.bethany.pocc@gmail.com",
            ],
            [
                'code' => 'DASC',
                'name' => 'DASMARINAS CAVITE',
                'address' => "DLSUMC Cancer Center Radiotherapy Dept.\nDe La Salle University Medical Center (DLSUMC)\nJose Sotto Tantiansu (JST) Cancer Center G/F\nGov D. Mangubat Ave., Dasmarinas City, Cavite 4114\nTel: +63 (46) 8416-0686\nMobile: +63 917-705-6508\nEmail: info.RT.lasalle@mail.com",
            ],
        ];
        foreach ($branches as $b) {
            $exists = $this->pdo->prepare('SELECT branch_id, address FROM branches WHERE code = :c OR name = :n LIMIT 1');
            $exists->execute(['c' => $b['code'], 'n' => $b['name']]);
            $row = $exists->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $ins = $this->pdo->prepare('INSERT INTO branches (code, name, address, is_active) VALUES (:c,:n,:a, TRUE)');
                $ins->execute(['c' => $b['code'], 'n' => $b['name'], 'a' => $b['address']]);
                $logs[] = 'Seeded branch ' . $b['name'];
            } else {
                // If address is empty, update it; otherwise keep existing value
                $addr = (string)($row['address'] ?? '');
                if (trim($addr) === '') {
                    $up = $this->pdo->prepare('UPDATE branches SET address = :a WHERE (code = :c OR name = :n)');
                    $up->execute(['a' => $b['address'], 'c' => $b['code'], 'n' => $b['name']]);
                    $logs[] = 'Updated address for branch ' . $b['name'];
                }
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
            $ins = $this->pdo->prepare('INSERT INTO users (username, password_hash, first_name, last_name, full_name, email, role, branch_id, is_active) VALUES (:u, :p, :fn, :ln, :n, :e, :r, :b, TRUE)');
            $ins->execute([
                'u' => $username,
                'p' => $hash,
                'fn' => 'System',
                'ln' => 'Administrator',
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
