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

    /** Inventory list & simple create form for custodian */
    public function inventoryPage(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['custodian', 'admin'], true)) {
            header('Location: /login'); return;
        }
        $branchId = $_SESSION['branch_id'] ?? null;
        $items = $this->inventory()->listInventory($branchId ? (int)$branchId : null);
        $edit = null;
        if (isset($_GET['edit'])) {
            $id = (int)$_GET['edit'];
            if ($id > 0) { $edit = $this->inventory()->getItemById($id); }
        }
        $this->render('custodian/inventory.php', [ 'items' => $items, 'edit' => $edit ]);
    }

    public function inventoryCreate(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['custodian', 'admin'], true)) { header('Location: /login'); return; }
        $name = trim((string)($_POST['name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $status = (string)($_POST['status'] ?? 'good');
        $quantity = (int)($_POST['quantity'] ?? 1);
        $unit = trim((string)($_POST['unit'] ?? 'pcs'));
        if ($name === '' || $category === '' || $quantity <= 0) { header('Location: /custodian/inventory?error=Invalid+data'); return; }
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
        $id = $this->inventory()->createItem([
            'branch_id' => $branchId ?: null,
            'name' => $name,
            'category' => $category,
            'status' => $status,
            'quantity' => $quantity,
            'unit' => $unit,
        ], (int)($_SESSION['user_id'] ?? 0));
        header('Location: /custodian/inventory?created=1');
    }

    public function inventoryUpdate(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['custodian', 'admin'], true)) { header('Location: /login'); return; }
        $id = (int)($_POST['item_id'] ?? 0);
        if ($id <= 0) { header('Location: /custodian/inventory?error=Invalid+item'); return; }
        $payload = [];
        foreach (['name','category','status','quantity','unit'] as $f) {
            if (isset($_POST[$f])) { $payload[$f] = $_POST[$f]; }
        }
        $this->inventory()->updateItem($id, $payload, (int)($_SESSION['user_id'] ?? 0));
        header('Location: /custodian/inventory?updated=1');
    }

    public function inventoryDelete(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['custodian', 'admin'], true)) { header('Location: /login'); return; }
        $id = (int)($_POST['item_id'] ?? 0);
        if ($id <= 0) { header('Location: /custodian/inventory?error=Invalid+item'); return; }
        $this->inventory()->deleteItem($id);
        header('Location: /custodian/inventory?deleted=1');
    }

    /** Purchase Request create form */
    public function newRequest(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['custodian', 'admin'], true)) { header('Location: /login'); return; }
        $branchId = $_SESSION['branch_id'] ?? null;
        $items = $this->inventory()->listInventory($branchId ? (int)$branchId : null);
        $this->render('custodian/request_create.php', [ 'items' => $items ]);
    }

    /** Handle Purchase Request submission */
    public function createRequest(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['custodian', 'admin'], true)) { header('Location: /login'); return; }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
        $payload = [
            'item_id' => isset($_POST['item_id']) ? (int)$_POST['item_id'] : null,
            'branch_id' => $branchId ?: null,
            'requested_by' => $userId,
            'request_type' => (string)($_POST['request_type'] ?? 'purchase_order'),
            'quantity' => (int)($_POST['quantity'] ?? 1),
            'unit' => (string)($_POST['unit'] ?? 'pcs'),
            'justification' => (string)($_POST['justification'] ?? ''),
            'status' => 'pending',
            'priority' => isset($_POST['priority']) ? (int)$_POST['priority'] : 3,
            'needed_by' => $_POST['needed_by'] ?? null,
        ];
        $req = $this->requests()->createPurchaseRequest($payload, $userId);
        // Optional: notify all procurement managers by message
        try {
            $pdo = \App\Database\Connection::resolve();
            $managers = $pdo->query("SELECT user_id FROM users WHERE role = 'procurement_manager' AND is_active = TRUE")->fetchAll();
            if ($managers) {
                $ins = $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
                foreach ($managers as $m) {
                    $ins->execute([
                        's' => $userId,
                        'r' => (int)$m['user_id'],
                        'j' => 'New Purchase Request #' . (int)$req['request_id'],
                        'b' => 'A new purchase request has been submitted and is pending approval.',
                    ]);
                }
            }
        } catch (\Throwable $ignored) {}

        if (isset($_POST['download_pdf']) && $_POST['download_pdf'] === '1') {
            $pdf = new \App\Services\PDFService();
            $pdf->generatePurchaseRequestPDF([
                'Request ID' => (int)$req['request_id'],
                'Request Type' => (string)$payload['request_type'],
                'Quantity' => (int)$payload['quantity'],
                'Unit' => (string)$payload['unit'],
                'Justification' => (string)$payload['justification'],
                'Branch ID' => (int)$branchId,
                'Requested By' => (int)$userId,
                'Status' => (string)($req['status'] ?? 'pending'),
            ]);
            return; // PDF sent to browser
        }
        header('Location: /custodian/requests/new?created=1');
    }
}
 
