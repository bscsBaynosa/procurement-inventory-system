<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\RequestService;

class ProcurementController extends BaseController
{
    private ?AuthService $auth = null;
    private ?RequestService $requests = null;

    public function __construct(?AuthService $auth = null, ?RequestService $requests = null)
    {
        // Lazy init services
        $this->auth = $auth;
        $this->requests = $requests;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    private function auth(): AuthService { return $this->auth ??= new AuthService(); }
    private function requests(): RequestService { return $this->requests ??= new RequestService(); }

    public function index(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $branchId = $_SESSION['branch_id'] ?? null;
        $requests = $this->requests()->getAllRequests([
            'branch_id' => $branchId ? (int)$branchId : null,
        ]);
        $this->render('dashboard/manager.php', compact('requests'));
    }
}
 
