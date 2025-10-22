<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\InventoryService;
use App\Services\RequestService;

class CustodianController extends BaseController
{
    private AuthService $auth;
    private InventoryService $inventory;
    private RequestService $requests;

    public function __construct(?AuthService $auth = null, ?InventoryService $inventory = null, ?RequestService $requests = null)
    {
        $this->auth = $auth ?? new AuthService();
        $this->inventory = $inventory ?? new InventoryService();
        $this->requests = $requests ?? new RequestService();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    public function dashboard(): void
    {
        if (!$this->auth->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['custodian', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $branchId = $_SESSION['branch_id'] ?? null;
        $inventoryStats = $this->inventory->getStatsByBranch($branchId ? (int)$branchId : null);
        $pendingRequests = $this->requests->getPendingRequests($branchId ? (int)$branchId : null);

        $this->render('dashboard/custodian.php', compact('inventoryStats', 'pendingRequests'));
    }
}
 
