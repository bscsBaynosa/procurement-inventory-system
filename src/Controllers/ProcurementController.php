<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\RequestService;
use App\Services\InventoryService;
use App\Services\PDFService;

class ProcurementController extends BaseController
{
    private ?AuthService $auth = null;
    private ?RequestService $requests = null;
    private ?InventoryService $inventory = null;
    private ?PDFService $pdf = null;

    public function __construct(?AuthService $auth = null, ?RequestService $requests = null, ?InventoryService $inventory = null, ?PDFService $pdf = null)
    {
        // Lazy init services
        $this->auth = $auth;
        $this->requests = $requests;
        $this->inventory = $inventory;
        $this->pdf = $pdf;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    private function auth(): AuthService { return $this->auth ??= new AuthService(); }
    private function requests(): RequestService { return $this->requests ??= new RequestService(); }
    private function inventory(): InventoryService { return $this->inventory ??= new InventoryService(); }
    private function pdf(): PDFService { return $this->pdf ??= new PDFService(); }

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
        $branchStats = $this->inventory()->getStatsPerBranch();
        $this->render('dashboard/manager.php', compact('requests', 'branchStats'));
    }

    /**
     * POST: Update a request status (for_approval | waiting_for_release | disapproved)
     */
    public function updateRequestStatus(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
        $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : null;
        $allowed = ['for_approval','waiting_for_release','disapproved'];
        if ($requestId <= 0 || !in_array($status, $allowed, true)) {
            header('Location: /dashboard');
            return;
        }

        $this->requests()->updateRequestStatus($requestId, $status, (int)($_SESSION['user_id'] ?? 0), $notes);
        header('Location: /dashboard');
    }

    /**
     * GET: Generate a simple Purchase Order PDF for a given request.
     */
    public function generatePO(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
        if ($requestId <= 0) {
            header('Location: /dashboard');
            return;
        }

        // Gather request details and related labels
        $req = $this->requests()->getRequestById($requestId);
        if (!$req) {
            header('Location: /dashboard');
            return;
        }

        // Look up labels (branch, item, requester name)
        $pdo = \App\Database\Connection::resolve();
        $labels = [
            'branch_name' => null,
            'item_name' => null,
            'requested_by_name' => null,
        ];
        if (!empty($req['branch_id'])) {
            $stmt = $pdo->prepare('SELECT name FROM branches WHERE branch_id = :id');
            $stmt->execute(['id' => $req['branch_id']]);
            $labels['branch_name'] = (string)($stmt->fetchColumn() ?: '');
        }
        if (!empty($req['item_id'])) {
            $stmt = $pdo->prepare('SELECT name FROM inventory_items WHERE item_id = :id');
            $stmt->execute(['id' => $req['item_id']]);
            $labels['item_name'] = (string)($stmt->fetchColumn() ?: '');
        }
        if (!empty($req['requested_by'])) {
            $stmt = $pdo->prepare('SELECT full_name FROM users WHERE user_id = :id');
            $stmt->execute(['id' => $req['requested_by']]);
            $labels['requested_by_name'] = (string)($stmt->fetchColumn() ?: '');
        }

        $poData = [
            'PO Number' => 'PO-' . date('Ymd') . '-' . $requestId,
            'Request ID' => $requestId,
            'Branch' => $labels['branch_name'] ?? 'N/A',
            'Item' => $labels['item_name'] ?? 'N/A',
            'Quantity' => (string)($req['quantity'] ?? ''),
            'Unit' => (string)($req['unit'] ?? ''),
            'Priority' => (string)($req['priority'] ?? ''),
            'Needed By' => (string)($req['needed_by'] ?? ''),
            'Requested By' => $labels['requested_by_name'] ?? 'N/A',
            'Status' => (string)($req['status'] ?? ''),
            'Prepared By' => (string)($_SESSION['full_name'] ?? ''),
            'Prepared At' => date('Y-m-d H:i'),
        ];

        $this->pdf()->generatePurchaseOrderPDF($poData);
    }
}
 
