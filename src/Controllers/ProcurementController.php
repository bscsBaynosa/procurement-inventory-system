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

    // --- Purchase Orders ---
    private function ensurePoTables(): void
    {
        $pdo = \App\Database\Connection::resolve();
        // purchase_orders header
        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
            id BIGSERIAL PRIMARY KEY,
            pr_number VARCHAR(64) NOT NULL,
            po_number VARCHAR(64) NOT NULL,
            supplier_id BIGINT NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
            vendor_name VARCHAR(255),
            vendor_address TEXT,
            vendor_tin VARCHAR(64),
            center VARCHAR(128),
            reference VARCHAR(255),
            terms TEXT,
            notes TEXT,
            deliver_to TEXT,
            look_for VARCHAR(255),
            status VARCHAR(64) NOT NULL DEFAULT 'draft',
            total NUMERIC(14,2) NOT NULL DEFAULT 0,
            pdf_path TEXT,
            created_by BIGINT NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )");
        // purchase_order_items lines
        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
            id BIGSERIAL PRIMARY KEY,
            po_id BIGINT NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
            description TEXT NOT NULL,
            unit VARCHAR(32) NOT NULL,
            qty INTEGER NOT NULL DEFAULT 0,
            unit_price NUMERIC(12,2) NOT NULL DEFAULT 0,
            line_total NUMERIC(12,2) NOT NULL DEFAULT 0
        )");
    }

    /** GET: Create PO form for a canvassing-approved PR */
    public function poCreate(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_GET['pr']) ? trim((string)$_GET['pr']) : '';
        if ($pr === '') { header('Location: /manager/requests'); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { header('Location: /manager/requests'); return; }
        // Require canvassing_approved to proceed
        $status = (string)($rows[0]['status'] ?? '');
        if ($status !== 'canvassing_approved' && $status !== 'po_rejected') { header('Location: /manager/requests?error=PO+allowed+only+after+canvassing+approval'); return; }
        $this->ensurePoTables();
        // Load suppliers list (optional: narrow to previously selected suppliers)
        $pdo = \App\Database\Connection::resolve();
        $suppliers = $pdo->query("SELECT user_id, full_name FROM users WHERE is_active=TRUE AND role='supplier' ORDER BY full_name ASC")->fetchAll();
        $this->render('procurement/po_create.php', [ 'pr' => $pr, 'rows' => $rows, 'suppliers' => $suppliers ]);
    }

    /** POST: Create PO, generate PDF, and send to Admin for approval */
    public function poSubmit(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); return; }
        $this->ensurePoTables();
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $poNumber = isset($_POST['po_number']) ? trim((string)$_POST['po_number']) : '';
        if ($pr === '' || $supplierId <= 0 || $poNumber === '') { header('Location: /procurement/po/create?pr=' . urlencode($pr) . '&error=Missing+fields'); return; }
        $vendorName = trim((string)($_POST['vendor_name'] ?? ''));
        $vendorAddress = trim((string)($_POST['vendor_address'] ?? ''));
        $vendorTin = trim((string)($_POST['vendor_tin'] ?? ''));
        $center = trim((string)($_POST['center'] ?? ''));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $terms = trim((string)($_POST['terms'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $deliverTo = trim((string)($_POST['deliver_to'] ?? 'MHI Bldg., New York St., Brgy. Immaculate Concepcion, Cubao, Quezon City'));
        $lookFor = trim((string)($_POST['look_for'] ?? (string)($_SESSION['full_name'] ?? '')));
        $date = date('Y-m-d');
        // Items arrays
        $descs = $_POST['item_desc'] ?? [];
        $units = $_POST['item_unit'] ?? [];
        $qtys = $_POST['item_qty'] ?? [];
        $prices = $_POST['item_price'] ?? [];
        $items = [];
        $total = 0.0;
        $n = min(count($descs), count($units), count($qtys), count($prices));
        for ($i=0; $i<$n; $i++) {
            $desc = trim((string)$descs[$i]); if ($desc === '') continue;
            $unit = trim((string)$units[$i]);
            $qty = (int)$qtys[$i];
            $price = (float)$prices[$i];
            $line = $qty * $price; $total += $line;
            $items[] = [ 'description' => $desc, 'unit' => $unit, 'qty' => $qty, 'unit_price' => $price, 'total' => $line ];
        }
        if (empty($items)) { header('Location: /procurement/po/create?pr=' . urlencode($pr) . '&error=Add+at+least+one+item'); return; }
        $pdo = \App\Database\Connection::resolve();
        // Insert PO
        $ins = $pdo->prepare('INSERT INTO purchase_orders (pr_number, po_number, supplier_id, vendor_name, vendor_address, vendor_tin, center, reference, terms, notes, deliver_to, look_for, status, total, created_by) VALUES (:pr,:po,:sid,:vn,:va,:vt,:ce,:ref,:te,:no,:dt,:lf,\'submitted\',:tot,:uid) RETURNING id');
        $ins->execute(['pr' => $pr, 'po' => $poNumber, 'sid' => $supplierId, 'vn' => $vendorName ?: null, 'va' => $vendorAddress ?: null, 'vt' => $vendorTin ?: null, 'ce' => $center ?: null, 'ref' => $reference ?: null, 'te' => $terms ?: null, 'no' => $notes ?: null, 'dt' => $deliverTo ?: null, 'lf' => $lookFor ?: null, 'tot' => $total, 'uid' => (int)($_SESSION['user_id'] ?? 0)]);
        $poId = (int)$ins->fetchColumn();
        $lineIns = $pdo->prepare('INSERT INTO purchase_order_items (po_id, description, unit, qty, unit_price, line_total) VALUES (:po,:d,:u,:q,:p,:t)');
        foreach ($items as $it) { $lineIns->execute(['po' => $poId, 'd' => $it['description'], 'u' => $it['unit'], 'q' => $it['qty'], 'p' => $it['unit_price'], 't' => $it['total']]); }
        // Generate PDF to storage
        $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . DIRECTORY_SEPARATOR . 'PO-' . preg_replace('/[^A-Za-z0-9_-]/','_', $poNumber) . '.pdf';
        $this->pdf()->generatePurchaseOrderPDFToFile([
            'po_number' => $poNumber,
            'date' => $date,
            'vendor_name' => $vendorName,
            'vendor_address' => $vendorAddress,
            'vendor_tin' => $vendorTin,
            'reference' => $reference,
            'terms' => $terms,
            'center' => $center,
            'notes' => $notes,
            'deliver_to' => $deliverTo,
            'look_for' => $lookFor,
            'prepared_by' => (string)($_SESSION['full_name'] ?? ''),
            'approved_by' => '',
            'items' => $items,
        ], $file);
        // Persist pdf path
        $pdo->prepare('UPDATE purchase_orders SET pdf_path=:p, updated_at=NOW() WHERE id=:id')->execute(['p' => $file, 'id' => $poId]);
        // Ensure messages has attachment columns
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}
        // Send to Admin for approval
        $subject = 'PO For Approval • PR ' . $pr . ' • PO ' . $poNumber;
        $body = 'Please review the attached Purchase Order for PR ' . $pr . ' and approve.';
        $recipients = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('admin')")->fetchAll();
        if ($recipients) {
            $insMsg = $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, attachment_name, attachment_path) VALUES (:s,:r,:j,:b,:an,:ap)');
            foreach ($recipients as $row) { $insMsg->execute(['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => (int)$row['user_id'], 'j' => $subject, 'b' => $body, 'an' => basename($file), 'ap' => $file]); }
        }
        // Update PR status
        try { $this->requests()->updateGroupStatus($pr, 'po_submitted', (int)($_SESSION['user_id'] ?? 0), 'PO submitted for admin approval'); } catch (\Throwable $ignored) {}
        header('Location: /manager/requests?po=1');
    }

    public function index(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $branchId = $_SESSION['branch_id'] ?? null;
        // Keep Procurement on dashboard; PO listing is now at /procurement/pos
        // Grouped view data for dashboard preview table
        $groups = $this->requests()->getRequestsGrouped([
            'branch_id' => $branchId ? (int)$branchId : null,
            'include_archived' => false,
            'sort' => 'date',
            'order' => 'desc',
        ]);
        // Supplier availability per category (distinct suppliers that have items in a category)
        $supplierCatCounts = $this->inventory()->getSupplierCountsByCategory();
        $this->render('dashboard/manager.php', [
            'groups' => $groups,
            'branchStats' => $this->inventory()->getStatsPerBranch(),
            'supplierCatCounts' => $supplierCatCounts,
        ]);
    }

    /**
     * GET: Purchase Orders list for Procurement.
     * Shows all POs with supplier, PR, totals, status, and a secure download link.
     */
    public function poList(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); return; }
        $this->ensurePoTables();
        $pdo = \App\Database\Connection::resolve();
        // Optional filters
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;
        $supplier = isset($_GET['supplier']) && $_GET['supplier'] !== '' ? (int)$_GET['supplier'] : null;
        $where = [];
        $params = [];
        if ($status !== null) { $where[] = 'po.status = :status'; $params['status'] = $status; }
        if ($supplier !== null) { $where[] = 'po.supplier_id = :sid'; $params['sid'] = $supplier; }
        $sql = 'SELECT po.id, po.pr_number, po.po_number, po.status, po.total, po.pdf_path, po.created_at, u.full_name AS supplier_name
                FROM purchase_orders po
                JOIN users u ON u.user_id = po.supplier_id';
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY po.created_at DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $pos = $st->fetchAll();
        // Load suppliers for filter dropdown
        $suppliers = $pdo->query("SELECT user_id, full_name FROM users WHERE role='supplier' AND is_active=TRUE ORDER BY full_name ASC")->fetchAll();
        $this->render('procurement/po_list.php', [ 'pos' => $pos, 'filters' => ['status' => $status, 'supplier' => $supplier], 'suppliers' => $suppliers ]);
    }

    /** GET: Single PO detail with lines and meta for Procurement */
    public function poView(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); return; }
        $this->ensurePoTables();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) { header('Location: /procurement/pos'); return; }
        $pdo = \App\Database\Connection::resolve();
        // Load header with supplier name
        $h = null;
        $st = $pdo->prepare('SELECT po.*, u.full_name AS supplier_name FROM purchase_orders po JOIN users u ON u.user_id = po.supplier_id WHERE po.id = :id');
        $st->execute(['id' => $id]);
        $h = $st->fetch();
        if (!$h) { header('Location: /procurement/pos'); return; }
        // Load lines
        $lt = $pdo->prepare('SELECT description, unit, qty, unit_price, line_total FROM purchase_order_items WHERE po_id = :id ORDER BY id ASC');
        $lt->execute(['id' => $id]);
        $lines = $lt->fetchAll();
        $this->render('procurement/po_view.php', ['po' => $h, 'items' => $lines]);
    }

    /** GET: Create RFP form (optionally prefilled from a PO id) */
    public function rfpCreate(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); return; }
        $poId = isset($_GET['po']) ? (int)$_GET['po'] : 0;
        $pr = isset($_GET['pr']) ? trim((string)$_GET['pr']) : '';
        $prefill = [
            'pr_number' => $pr,
            'po_id' => $poId,
            'po_number' => null,
            'pay_to' => '',
            'center' => '',
            'date_requested' => date('Y-m-d'),
            'date_needed' => '',
            'nature' => 'payment_to_supplier',
            'particulars' => [ ['desc' => '', 'amount' => ''] ],
            'total' => 0.00,
        ];
        if ($poId > 0) {
            $pdo = \App\Database\Connection::resolve();
            $st = $pdo->prepare('SELECT po_number, pr_number, vendor_name, total, supplier_id FROM purchase_orders WHERE id = :id');
            $st->execute(['id' => $poId]);
            if ($row = $st->fetch()) {
                $prefill['po_number'] = (string)$row['po_number'];
                $prefill['pr_number'] = $prefill['pr_number'] ?: (string)($row['pr_number'] ?? '');
                $prefill['pay_to'] = (string)($row['vendor_name'] ?? '');
                $prefill['total'] = (float)($row['total'] ?? 0);
                $prefill['particulars'] = [[ 'desc' => 'Payment for PO ' . (string)$row['po_number'], 'amount' => (string)$prefill['total'] ]];
            }
        }
        $this->render('procurement/rfp_create.php', ['rfp' => $prefill]);
    }

    /** POST: Submit RFP, generate PDF, send to Admin via message */
    public function rfpSubmit(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); return; }
        $pr = trim((string)($_POST['pr_number'] ?? ''));
        $poId = (int)($_POST['po_id'] ?? 0);
        $poNumber = trim((string)($_POST['po_number'] ?? ''));
        $payTo = trim((string)($_POST['pay_to'] ?? ''));
        $center = trim((string)($_POST['center'] ?? ''));
        $dateRequested = trim((string)($_POST['date_requested'] ?? date('Y-m-d')));
        $dateNeeded = trim((string)($_POST['date_needed'] ?? ''));
        $nature = trim((string)($_POST['nature'] ?? 'payment_to_supplier'));
        $descArr = $_POST['particular_desc'] ?? [];
        $amtArr = $_POST['particular_amount'] ?? [];
        $rows = [];
        $total = 0.0;
        $n = min(count($descArr), count($amtArr));
        for ($i=0; $i<$n; $i++) {
            $d = trim((string)$descArr[$i]); if ($d === '') continue;
            $a = (float)str_replace([','], [''], (string)$amtArr[$i]);
            $rows[] = ['desc' => $d, 'amount' => $a];
            $total += $a;
        }
        if ($payTo === '' || empty($rows)) { header('Location: /procurement/rfp/create?error=Missing+fields'); return; }
        // Generate PDF to storage
        $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $slug = $poNumber !== '' ? ('PO-' . preg_replace('/[^A-Za-z0-9_-]/','_', $poNumber)) : ($pr !== '' ? ('PR-' . preg_replace('/[^A-Za-z0-9_-]/','_', $pr)) : date('Ymd-His'));
        $file = $dir . DIRECTORY_SEPARATOR . 'RFP-' . $slug . '.pdf';
        $this->pdf()->generateRFPToFile([
            'pr_number' => $pr,
            'po_number' => $poNumber,
            'pay_to' => $payTo,
            'center' => $center,
            'date_requested' => $dateRequested,
            'date_needed' => $dateNeeded,
            'nature' => $nature,
            'particulars' => $rows,
            'total' => $total,
            'requested_by' => (string)($_SESSION['full_name'] ?? ''),
        ], $file);
        // Send to Admin for approval
        $pdo = \App\Database\Connection::resolve();
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}
        $subject = 'RFP For Approval' . ($pr !== '' ? (' • PR ' . $pr) : '') . ($poNumber !== '' ? (' • PO ' . $poNumber) : '');
        $body = 'Please review and approve the attached Request For Payment.';
        $recipients = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('admin')")->fetchAll();
        if ($recipients) {
            $ins = $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, attachment_name, attachment_path) VALUES (:s,:r,:j,:b,:an,:ap)');
            foreach ($recipients as $row) { $ins->execute(['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => (int)$row['user_id'], 'j' => $subject, 'b' => $body, 'an' => basename($file), 'ap' => $file]); }
        }
        header('Location: /procurement/pos?rfp=1');
    }

    /**
     * GET: Purchase Requests list for Procurement (grouped by PR Number) with sorting and filters.
     */
    public function viewRequests(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) {
            header('Location: /login');
            return;
        }
        $branchId = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'date';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $rows = $this->requests()->getRequestsGrouped([
            'branch_id' => $branchId,
            'status' => $status,
            'include_archived' => false,
            'sort' => $sort,
            'order' => $order,
        ]);
    $this->render('procurement/requests_list.php', [ 'groups' => $rows, 'filters' => [ 'branch' => $branchId, 'status' => $status, 'sort' => $sort, 'order' => $order ] ]);
    }

    /**
     * GET: Archived/Deleted Purchase Requests history (grouped by PR Number) with restore option.
     */
    public function requestsHistory(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) {
            header('Location: /login');
            return;
        }
        $branchId = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'date';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $rows = $this->requests()->getRequestsGrouped([
            'branch_id' => $branchId,
            'status' => $status,
            'include_archived' => true,
            'sort' => $sort,
            'order' => $order,
        ]);
        // Only archived ones
        $rows = array_values(array_filter($rows, static fn($r) => !empty($r['is_archived'])));
        $this->render('procurement/requests_history.php', [ 'groups' => $rows, 'filters' => [ 'branch' => $branchId, 'status' => $status, 'sort' => $sort, 'order' => $order ] ]);
    }

    /** GET: Completed Requisitions (status=completed) */
    public function completedRequisitions(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $branchId = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'date';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $rows = $this->requests()->getRequestsGrouped([
            'branch_id' => $branchId,
            'status' => 'completed',
            'include_archived' => false,
            'sort' => $sort,
            'order' => $order,
        ]);
        $this->render('procurement/requests_completed.php', [ 'groups' => $rows, 'filters' => [ 'branch' => $branchId, 'sort' => $sort, 'order' => $order ] ]);
    }

    /** GET: Canvassing form for an approved PR */
    public function canvass(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_GET['pr']) ? trim((string)$_GET['pr']) : '';
        if ($pr === '') { header('Location: /manager/requests'); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { header('Location: /manager/requests'); return; }
        // Load suppliers (users with role=supplier)
        $pdo = \App\Database\Connection::resolve();
        $suppliers = $pdo->query("SELECT user_id, full_name FROM users WHERE is_active = TRUE AND role = 'supplier' ORDER BY full_name ASC")->fetchAll();
        // Optional: price matrix from supplier_items matched by item name (case-insensitive)
        $prices = [];
        try {
            // Collect distinct, non-empty item names and required quantities per item
            $byNameQty = [];
            foreach ($rows as $r) {
                $n = strtolower(trim((string)($r['item_name'] ?? '')));
                if ($n === '') { continue; }
                $qty = (int)($r['quantity'] ?? 0);
                $byNameQty[$n] = ($byNameQty[$n] ?? 0) + max(0, $qty);
            }
            $names = array_keys($byNameQty);
            if ($suppliers && $names) {
                $inSup = implode(',', array_fill(0, count($suppliers), '?'));
                $inNames = implode(',', array_fill(0, count($names), '?'));
                $params = [];
                foreach ($suppliers as $s) { $params[] = (int)$s['user_id']; }
                foreach ($names as $nm) { $params[] = $nm; }
                // Fetch items with packaging info
                $sql = 'SELECT id, supplier_id, LOWER(name) AS lname, price, unit, package_label, pieces_per_package FROM supplier_items WHERE supplier_id IN (' . $inSup . ') AND LOWER(name) IN (' . $inNames . ')';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $itemsById = [];
                $ids = [];
                foreach ($st->fetchAll() as $row) {
                    $itemsById[(int)$row['id']] = $row;
                    $ids[] = (int)$row['id'];
                }
                // Default to base price per package; then evaluate tiers for needed packages
                foreach ($itemsById as $it) {
                    $sid = (int)$it['supplier_id'];
                    $lname = (string)$it['lname'];
                    $basePrice = (float)($it['price'] ?? 0);
                    $piecesPerPkg = max(1, (int)($it['pieces_per_package'] ?? 1));
                    $neededPieces = max(0, (int)($byNameQty[$lname] ?? 0));
                    $neededPkgs = $piecesPerPkg > 0 ? (int)ceil($neededPieces / $piecesPerPkg) : 0;
                    $best = $basePrice;
                    // Fetch tiers for this item and choose the price that applies to neededPkgs
                    if ($neededPkgs > 0) {
                        try {
                            $tq = $pdo->prepare('SELECT min_packages, max_packages, price_per_package FROM supplier_item_price_tiers WHERE supplier_item_id = :id ORDER BY min_packages ASC');
                            $tq->execute(['id' => (int)$it['id']]);
                            foreach ($tq->fetchAll() as $t) {
                                $min = (int)$t['min_packages'];
                                $max = $t['max_packages'] !== null ? (int)$t['max_packages'] : null;
                                if ($neededPkgs >= $min && ($max === null || $neededPkgs <= $max)) {
                                    $best = min($best, (float)$t['price_per_package']);
                                }
                            }
                        } catch (\Throwable $e) { /* tiers table may not exist yet */ }
                    }
                    if (!isset($prices[$sid])) { $prices[$sid] = []; }
                    if (!isset($prices[$sid][$lname]) || $best < $prices[$sid][$lname]) { $prices[$sid][$lname] = $best; }
                }
            }
        } catch (\Throwable $ignored) { /* supplier_items may not exist yet; ignore */ }
        $this->render('procurement/canvass_form.php', [ 'pr' => $pr, 'rows' => $rows, 'suppliers' => $suppliers, 'prices' => $prices ]);
    }

    /** POST: Submit canvassing selection and generate a PDF, then send to Admin for approval */
    public function canvassSubmit(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $chosen = isset($_POST['suppliers']) && is_array($_POST['suppliers']) ? array_slice(array_values(array_unique(array_map('intval', $_POST['suppliers']))), 0, 5) : [];
        if ($pr === '' || count($chosen) < 3) { header('Location: /manager/requests/canvass?pr=' . urlencode($pr) . '&error=Pick+at+least+3+suppliers'); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { header('Location: /manager/requests'); return; }
        $pdo = \App\Database\Connection::resolve();
        // Fetch supplier names
        $inParams = implode(',', array_fill(0, count($chosen), '?'));
        $st = $pdo->prepare('SELECT user_id, full_name FROM users WHERE user_id IN (' . $inParams . ')');
        $st->execute($chosen);
        $map = [];
        foreach ($st->fetchAll() as $s) { $map[(int)$s['user_id']] = (string)$s['full_name']; }
    // Build PDF content (structured canvassing form)
        $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . DIRECTORY_SEPARATOR . 'Canvassing-PR-' . preg_replace('/[^A-Za-z0-9_-]/','_', $pr) . '.pdf';
        $items = [];
    foreach ($rows as $r) { $items[] = ($r['item_name'] ?? 'Item') . ' × ' . (string)($r['quantity'] ?? 0) . ' ' . (string)($r['unit'] ?? ''); }
    $this->pdf()->generateCanvassingPDFToFile($pr, $items, array_values($map), $file);
        // Ensure message attachments columns
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}
        // Send to Admin for approval
        $subject = 'Canvassing For Approval • PR ' . $pr;
        $body = 'Please review the attached canvassing sheet for PR ' . $pr . ' and approve to proceed with PO.';
        $recipients = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('admin')")->fetchAll();
        if ($recipients) {
            $ins = $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, attachment_name, attachment_path) VALUES (:s,:r,:j,:b,:an,:ap)');
            foreach ($recipients as $row) {
                $ins->execute(['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => (int)$row['user_id'], 'j' => $subject, 'b' => $body, 'an' => basename($file), 'ap' => $file]);
            }
        }
    // Mark the PR group as canvassing_submitted (awaiting admin approval)
    try { $this->requests()->updateGroupStatus($pr, 'canvassing_submitted', (int)($_SESSION['user_id'] ?? 0), 'Canvassing submitted for admin approval'); } catch (\Throwable $ignored) {}
        header('Location: /manager/requests?canvass=1');
    }

    /** POST: Update status for a PR group */
    public function updateGroupStatus(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) {
            header('Location: /login');
            return;
        }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
        if ($pr === '' || $status === '') { header('Location: /manager/requests'); return; }
        $this->requests()->updateGroupStatus($pr, $status, (int)($_SESSION['user_id'] ?? 0), $_POST['notes'] ?? null);
        header('Location: /manager/requests');
    }

    /** POST: Archive a PR group */
    public function archiveGroup(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        if ($pr === '') { header('Location: /manager/requests'); return; }
        $this->requests()->archiveGroup($pr, (int)($_SESSION['user_id'] ?? 0), $_POST['reason'] ?? null);
        header('Location: /manager/requests');
    }

    /** POST: Restore an archived PR group */
    public function restoreGroup(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        if ($pr === '') { header('Location: /manager/requests/history'); return; }
        $this->requests()->restoreGroup($pr, (int)($_SESSION['user_id'] ?? 0));
        header('Location: /manager/requests/history');
    }

    /** GET: View full details for a PR group */
    public function viewGroup(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_GET['pr']) ? trim((string)$_GET['pr']) : '';
        if ($pr === '') { header('Location: /manager/requests'); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        $this->render('procurement/request_view.php', ['pr' => $pr, 'rows' => $rows]);
    }

    /** GET: Download PR PDF for a group */
    public function downloadGroup(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_GET['pr']) ? trim((string)$_GET['pr']) : '';
        if ($pr === '') { header('Location: /manager/requests'); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { header('Location: /manager/requests'); return; }
        // Build canonical PR meta and item lines for the compact PR PDF
        $meta = [
            'pr_number' => $pr,
            'branch_name' => (string)($rows[0]['branch_name'] ?? 'N/A'),
            // Requisition By should be the original preparer's full name
            'requested_by' => (string)($rows[0]['requested_by_name'] ?? ''),
            'prepared_at' => date('Y-m-d', strtotime((string)($rows[0]['created_at'] ?? date('Y-m-d')))),
            'effective_date' => date('Y-m-d'),
            'justification' => (string)($rows[0]['revision_notes'] ?? ''),
        ];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'description' => (string)($r['item_name'] ?? 'Item'),
                'unit' => (string)($r['unit'] ?? ''),
                'qty' => (int)($r['quantity'] ?? 0),
            ];
        }
        // Generate to a writable folder and stream inline (storage/pdf with fallback to system temp)
        $root = realpath(__DIR__ . '/../../..');
        $priorities = [];
        if ($root) { $priorities[] = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf'; }
        $priorities[] = sys_get_temp_dir();
        $file = null; $ok = false;
        foreach ($priorities as $d) {
            if (!is_dir($d)) { @mkdir($d, 0777, true); }
            if (is_dir($d) && is_writable($d)) {
                $candidate = $d . DIRECTORY_SEPARATOR . 'PR-' . preg_replace('/[^A-Za-z0-9_-]/','_', $pr) . '.pdf';
                try {
                    $this->pdf()->generatePurchaseRequisitionToFile($meta, $items, $candidate);
                    if (@is_file($candidate) && (@filesize($candidate) ?: 0) > 0) { $file = $candidate; $ok = true; break; }
                } catch (\Throwable $ignored) {}
            }
        }
        if (!$ok || $file === null) { header('Location: /manager/requests?error=' . rawurlencode('PDF+generation+failed')); return; }
        $size = @filesize($file) ?: null;
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode('PR-' . $pr . '.pdf') . '"');
        if ($size !== null) { header('Content-Length: ' . (string)$size); }
        @readfile($file);
    }

    /** POST: Send PR group to Admin for approval via message with PDF attachment */
    public function sendForAdminApproval(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        if ($pr === '') { header('Location: /manager/requests'); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { header('Location: /manager/requests'); return; }
        // Build canonical PR PDF (same layout as Admin Assistant)
        $meta = [
            'pr_number' => $pr,
            'branch_name' => (string)($rows[0]['branch_name'] ?? 'N/A'),
            'requested_by' => (string)($rows[0]['requested_by_name'] ?? ''),
            'prepared_at' => date('Y-m-d', strtotime((string)($rows[0]['created_at'] ?? date('Y-m-d')))),
            'effective_date' => date('Y-m-d'),
            'justification' => (string)($rows[0]['revision_notes'] ?? ''),
        ];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'description' => (string)($r['item_name'] ?? 'Item'),
                'unit' => (string)($r['unit'] ?? ''),
                'qty' => (int)($r['quantity'] ?? 0),
            ];
        }
        // Render PDF to a storage file
        $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $tmpFile = $dir . DIRECTORY_SEPARATOR . 'PR-' . preg_replace('/[^A-Za-z0-9_-]/','_', $pr) . '.pdf';
        $this->pdf()->generatePurchaseRequisitionToFile($meta, $items, $tmpFile);
        // Ensure message attachments columns
        $pdo = \App\Database\Connection::resolve();
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}

        // Send messages to Admin users
        $subject = 'For Admin Approval • PR ' . $pr;
        $body = 'Please review and approve the attached Purchase Request to begin procurement processing.';
        $recipients = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('admin')")->fetchAll();
        if ($recipients) {
            $ins = $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, attachment_name, attachment_path) VALUES (:s,:r,:j,:b,:an,:ap)');
            foreach ($recipients as $row) {
                $ins->execute([
                    's' => (int)($_SESSION['user_id'] ?? 0),
                    'r' => (int)$row['user_id'],
                    'j' => $subject,
                    'b' => $body,
                    'an' => basename($tmpFile),
                    'ap' => $tmpFile,
                ]);
            }
        }
        // Update PR group status to mark forwarded for admin approval
        try { $this->requests()->updateGroupStatus($pr, 'for_admin_approval', (int)($_SESSION['user_id'] ?? 0), 'Sent to Admin for Approval'); } catch (\Throwable $ignored) {}
        header('Location: /manager/requests?sent=1');
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
    $allowed = ['pending','approved','rejected','in_progress','completed','cancelled','canvassing_submitted','canvassing_approved','canvassing_rejected'];
        if ($requestId <= 0 || !in_array($status, $allowed, true)) {
            header('Location: /dashboard');
            return;
        }

        $this->requests()->updateRequestStatus($requestId, $status, (int)($_SESSION['user_id'] ?? 0), $notes);
        header('Location: /dashboard');
    }

}
 
