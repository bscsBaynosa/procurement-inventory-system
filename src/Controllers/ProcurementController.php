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
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $branchId = $_SESSION['branch_id'] ?? null;
        // If there are approved requests, send Procurement to the PO flow
        $approved = $this->requests()->getAllRequests([
            'branch_id' => $branchId ? (int)$branchId : null,
            'status' => 'approved',
        ]);
        if (!empty($approved)) {
            header('Location: /procurement/po');
            return;
        }
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
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
    $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : null;
    // Align with request_status enum
    $allowed = ['pending','approved','rejected','in_progress','completed','cancelled'];
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

    /**
     * GET: Procurement PO list (approved requests awaiting PO).
     */
    public function po(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $branchId = $_SESSION['branch_id'] ?? null;
        $approved = $this->requests()->getAllRequests([
            'branch_id' => $branchId ? (int)$branchId : null,
            'status' => 'approved',
        ]);
        // Load existing POs
        $pdo = \App\Database\Connection::resolve();
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
                po_id BIGSERIAL PRIMARY KEY,
                po_number VARCHAR(64) NOT NULL UNIQUE,
                request_id BIGINT NOT NULL REFERENCES purchase_requests(request_id) ON DELETE CASCADE,
                status VARCHAR(32) NOT NULL DEFAULT 'issued',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL
            )");
        } catch (\Throwable $e) {}
        $pos = [];
        try {
            $sql = 'SELECT po.po_id, po.po_number, po.status AS po_status, po.created_at, pr.request_id, pr.quantity, pr.unit,
                           i.name AS item_name, b.name AS branch_name
                    FROM purchase_orders po
                    JOIN purchase_requests pr ON pr.request_id = po.request_id
                    LEFT JOIN inventory_items i ON i.item_id = pr.item_id
                    LEFT JOIN branches b ON b.branch_id = pr.branch_id
                    ORDER BY po.created_at DESC';
            $st = $pdo->query($sql);
            $pos = $st ? $st->fetchAll() : [];
        } catch (\Throwable $e) {}

        $this->render('procurement/po_list.php', [ 'approved' => $approved, 'pos' => $pos ]);
    }

    /**
     * GET: Create a PO record for an approved request then stream the PO PDF.
     */
    public function createPO(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
        if ($requestId <= 0) { header('Location: /procurement/po'); return; }

        // Ensure the request exists and is approved
        $req = $this->requests()->getRequestById($requestId);
        if (!$req || (string)($req['status'] ?? '') !== 'approved') {
            header('Location: /procurement/po');
            return;
        }

        // Create purchase_orders table if missing and insert a PO record (idempotent by request)
        $pdo = \App\Database\Connection::resolve();
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
                po_id BIGSERIAL PRIMARY KEY,
                po_number VARCHAR(64) NOT NULL UNIQUE,
                request_id BIGINT NOT NULL REFERENCES purchase_requests(request_id) ON DELETE CASCADE,
                status VARCHAR(32) NOT NULL DEFAULT 'issued',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL
            )");
        } catch (\Throwable $e) {
            // Continue even if creation fails; we'll still attempt to generate PDF for UX
        }

        // If a PO already exists for this request, reuse its number; else create one
        $poNumber = null;
        try {
            $st = $pdo->prepare('SELECT po_number FROM purchase_orders WHERE request_id = :rid LIMIT 1');
            $st->execute(['rid' => $requestId]);
            $poNumber = $st->fetchColumn() ?: null;
            if (!$poNumber) {
                $poNumber = 'PO-' . date('Ymd') . '-' . $requestId;
                $ins = $pdo->prepare("INSERT INTO purchase_orders (po_number, request_id, status, created_by) VALUES (:n,:r, 'issued', :by)");
                $ins->execute(['n' => $poNumber, 'r' => $requestId, 'by' => $_SESSION['user_id'] ?? null]);
                // Optionally move request to in_progress state now that a PO exists
                $this->requests()->updateRequestStatus($requestId, 'in_progress', (int)($_SESSION['user_id'] ?? 0), 'PO created');
            }
        } catch (\Throwable $e) {
            // Ignore DB write issues and fallback to a computed PO number for the PDF
            if (!$poNumber) { $poNumber = 'PO-' . date('Ymd') . '-' . $requestId; }
        }

        // Delegate to PDF generation (will stream the PO)
        $_GET['request_id'] = (string)$requestId; // keep generatePO flow simple
        $this->generatePO();
    }
}
 
