<?php

namespace App\Controllers;

use App\Services\InventoryService;
use App\Services\RequestService;
use App\Database\Connection;
use PDO;

class AdminController extends BaseController
{
    private ?PDO $pdo = null;
    private ?InventoryService $inventory = null;
    private ?RequestService $requests = null;

    public function __construct(?PDO $pdo = null)
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $this->pdo = $pdo ?? Connection::resolve();
    }

    private function inventory(): InventoryService
    {
        if (!$this->inventory) { $this->inventory = new InventoryService($this->pdo); }
        return $this->inventory;
    }

    private function requests(): RequestService
    {
        if (!$this->requests) { $this->requests = new RequestService($this->pdo); }
        return $this->requests;
    }

    public function dashboard(): void
    {
        try {
            // Summary counts
            $counts = [
                'users_total' => 0,
                'users_active' => 0,
                'branches' => 0,
                'requests' => [
                    'pending' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                    'in_review' => 0,
                ],
                'inventory' => $this->inventory()->getStatsByBranch(null),
            ];

            // Users
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
            $counts['users_total'] = (int)$stmt->fetchColumn();
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM users WHERE is_active = TRUE');
            $counts['users_active'] = (int)$stmt->fetchColumn();
            // Branches
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM branches');
            $counts['branches'] = (int)$stmt->fetchColumn();
            // Requests by status
            $rs = $this->pdo->query('SELECT status, COUNT(*) AS c FROM purchase_requests GROUP BY status');
            foreach ($rs->fetchAll() as $row) {
                $status = (string)$row['status'];
                $c = (int)$row['c'];
                if (!isset($counts['requests'][$status])) { $counts['requests'][$status] = 0; }
                $counts['requests'][$status] += $c;
            }

            // Recent activity: last 6 requests
            $recent = $this->pdo->query(
                'SELECT pr.request_id, pr.status, pr.created_at, i.name AS item_name, b.name AS branch_name
                 FROM purchase_requests pr
                 LEFT JOIN inventory_items i ON i.item_id = pr.item_id
                 LEFT JOIN branches b ON b.branch_id = pr.branch_id
                 ORDER BY pr.created_at DESC
                 LIMIT 6'
            )->fetchAll();

            // Top inventory items for the table (limit 10)
            $items = $this->pdo->query(
                'SELECT item_id, name, category, status, quantity, unit, branch_id
                 FROM inventory_items
                 ORDER BY name ASC
                 LIMIT 10'
            )->fetchAll();

            $this->render('dashboard/admin.php', [
                'counts' => $counts,
                'recent' => $recent,
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            // Friendly guidance if DB not initialized or migrations not run
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            $scheme = $forwarded !== '' ? explode(',', $forwarded)[0] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $setupUrl = $scheme . '://' . $host . '/setup?token=YOUR_TOKEN';
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Setup required</title>';
            echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px;background:#0b0b0b;color:#e5e7eb} .box{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:18px;max-width:800px} a{color:#22c55e;text-decoration:none} .dim{color:#94a3b8} pre{white-space:pre-wrap;background:#0b0b0b;padding:12px;border-radius:8px;overflow:auto}</style>';
            echo '</head><body><div class="box">';
            echo '<h2>Database setup required</h2>';
            echo '<p class="dim">The database may not be initialized yet. Run the one-time setup route to apply the schema and seed the admin user.</p>';
            echo '<ol><li>Set the <code>SETUP_TOKEN</code> config var in Heroku.</li><li>Visit <code>' . htmlspecialchars($setupUrl, ENT_QUOTES, 'UTF-8') . '</code> on this app.</li></ol>';
            echo '<p class="dim">After that, reload this page.</p>';
            echo '<details><summary>Error details</summary><pre>' . $msg . '</pre></details>';
            echo '</div></body></html>';
        }
    }
}
