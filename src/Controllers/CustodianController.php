<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\InventoryService;
use App\Services\RequestService;

class CustodianController extends BaseController
{
    private ?AuthService $auth = null;
    private ?InventoryService $inventory = null;
    private ?RequestService $requests = null;

    public function __construct(?AuthService $auth = null, ?InventoryService $inventory = null, ?RequestService $requests = null)
    {
        // Lazy init services
        $this->auth = $auth;
        $this->inventory = $inventory;
        $this->requests = $requests;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    private function auth(): AuthService { return $this->auth ??= new AuthService(); }
    private function inventory(): InventoryService { return $this->inventory ??= new InventoryService(); }
    private function requests(): RequestService { return $this->requests ??= new RequestService(); }

    public function dashboard(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['custodian', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $branchId = $_SESSION['branch_id'] ?? null;
        $inventoryStats = $this->inventory()->getStatsByBranch($branchId ? (int)$branchId : null);
        $pendingRequests = $this->requests()->getPendingRequests($branchId ? (int)$branchId : null);

        $this->render('dashboard/custodian.php', compact('inventoryStats', 'pendingRequests'));
    }
}
 
