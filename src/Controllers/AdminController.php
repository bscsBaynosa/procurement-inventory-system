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

        $this->render('dashboard/admin.php', [
            'counts' => $counts,
            'recent' => $recent,
        ]);
    }
}
