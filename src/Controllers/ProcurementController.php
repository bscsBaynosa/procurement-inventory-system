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
    // Gate: allow canvassing only when group is Approved or Canvassing Rejected (to re-canvass)
    $statuses = [];
    foreach ($rows as $r) { $statuses[(string)($r['status'] ?? '')] = true; }
    $allowedStates = ['approved','canvassing_rejected'];
    $isAllowed = false;
    foreach ($allowedStates as $as) { if (isset($statuses[$as])) { $isAllowed = true; break; } }
    if (!$isAllowed) { header('Location: /manager/requests?error=' . rawurlencode('Canvassing allowed only for Approved PRs')); return; }
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
                    // Fuzzy fallback: for items not found by exact match, try token-based ILIKE queries
                    $foundNames = [];
                    foreach ($prices as $sid0 => $map0) { foreach ($map0 as $nm0 => $_) { $foundNames[$nm0] = true; } }
                    $toFind = array_values(array_filter($names, static fn($nm) => !isset($foundNames[$nm])));
                    foreach ($toFind as $nm) {
                        // Build up to 3 significant tokens (length>=3)
                        $tokens = preg_split('/[^a-z0-9]+/i', (string)$nm) ?: [];
                        $tokens = array_values(array_filter(array_map('strtolower', $tokens), static fn($t) => strlen($t) >= 3));
                        if (!$tokens) { continue; }
                        $tokens = array_slice($tokens, 0, 3);
                        $cond = [];
                        $params2 = [];
                        // supplier filter first
                        $cond[] = 'supplier_id IN (' . $inSup . ')';
                        foreach ($suppliers as $s) { $params2[] = (int)$s['user_id']; }
                        foreach ($tokens as $t) { $cond[] = 'LOWER(name) ILIKE ?'; $params2[] = '%' . $t . '%'; }
                        $sql2 = 'SELECT id, supplier_id, LOWER(name) AS lname, price, unit, package_label, pieces_per_package FROM supplier_items WHERE ' . implode(' AND ', $cond);
                        $st2 = $pdo->prepare($sql2);
                        $st2->execute($params2);
                        foreach ($st2->fetchAll() as $it) {
                            $sid = (int)$it['supplier_id'];
                            $basePrice = (float)($it['price'] ?? 0);
                            $piecesPerPkg = max(1, (int)($it['pieces_per_package'] ?? 1));
                            $neededPieces = max(0, (int)($byNameQty[$nm] ?? 0));
                            $neededPkgs = $piecesPerPkg > 0 ? (int)ceil($neededPieces / $piecesPerPkg) : 0;
                            $best = $basePrice;
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
                                } catch (\Throwable $e) {}
                            }
                            if (!isset($prices[$sid])) { $prices[$sid] = []; }
                            // Map this fuzzy match under the canonical item name key ($nm)
                            if (!isset($prices[$sid][$nm]) || $best < $prices[$sid][$nm]) { $prices[$sid][$nm] = $best; }
                        }
                    }
            }
        } catch (\Throwable $ignored) { /* supplier_items may not exist yet; ignore */ }
        $this->render('procurement/canvass_form.php', [ 'pr' => $pr, 'rows' => $rows, 'suppliers' => $suppliers, 'prices' => $prices ]);
    }

    /** POST: Submit canvassing selection and generate a PDF, then redirect to compose with PR + Canvass attachments */
    public function canvassSubmit(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        // Require preview first for UX integrity
        if (!isset($_SESSION['canvass_previewed']) || !is_array($_SESSION['canvass_previewed']) || empty($_SESSION['canvass_previewed'][$pr])) {
            header('Location: /manager/requests/canvass?pr=' . urlencode($pr) . '&error=' . rawurlencode('Please generate the preview first'));
            return;
        }
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
        // Optional awarded vendor (must be among selected)
        $awardedId = isset($_POST['awarded_to']) && $_POST['awarded_to'] !== '' ? (int)$_POST['awarded_to'] : null;
        $awardedName = null;
        if ($awardedId && in_array($awardedId, $chosen, true) && isset($map[$awardedId])) {
            $awardedName = $map[$awardedId];
        }
        // Totals per supplier (used for justification row in PDFs) — always compute to mirror the canvassing page
        $totals = [];
        try {
            $byNameQty = [];
            foreach ($rows as $r) {
                $n = strtolower(trim((string)($r['item_name'] ?? '')));
                if ($n === '') { continue; }
                $qty = (int)($r['quantity'] ?? 0);
                $byNameQty[$n] = ($byNameQty[$n] ?? 0) + max(0, $qty);
            }
            $names = array_keys($byNameQty);
            if ($names && $chosen) {
                $inSup = implode(',', array_fill(0, count($chosen), '?'));
                $inNames = implode(',', array_fill(0, count($names), '?'));
                $params = [];
                foreach ($chosen as $sid) { $params[] = (int)$sid; }
                foreach ($names as $nm) { $params[] = $nm; }
                $sql = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE supplier_id IN (' . $inSup . ') AND LOWER(name) IN (' . $inNames . ')';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $prices = [];
                foreach ($st->fetchAll() as $it) {
                    $sid = (int)$it['supplier_id'];
                    $lname = (string)$it['lname'];
                    $base = (float)($it['price'] ?? 0);
                    $ppp = max(1, (int)($it['pieces_per_package'] ?? 1));
                    $needPk = $ppp > 0 ? (int)ceil((int)($byNameQty[$lname] ?? 0) / $ppp) : 0;
                    $best = $base;
                    if ($needPk > 0) {
                        try {
                            $tq = $pdo->prepare('SELECT min_packages, max_packages, price_per_package FROM supplier_item_price_tiers WHERE supplier_item_id = :id ORDER BY min_packages ASC');
                            $tq->execute(['id' => (int)$it['id']]);
                            foreach ($tq->fetchAll() as $t) {
                                $min = (int)$t['min_packages'];
                                $max = $t['max_packages'] !== null ? (int)$t['max_packages'] : null;
                                if ($needPk >= $min && ($max === null || $needPk <= $max)) { $best = min($best, (float)$t['price_per_package']); }
                            }
                        } catch (\Throwable $e) {}
                    }
                    if (!isset($prices[$sid])) { $prices[$sid] = []; }
                    if (!isset($prices[$sid][$lname]) || $best < $prices[$sid][$lname]) { $prices[$sid][$lname] = $best; }
                }
                // Fuzzy tokens for missing
                $found = [];
                foreach ($prices as $sid0 => $m0) { foreach ($m0 as $nm0 => $_) { $found[$nm0] = true; } }
                $toFind = array_values(array_filter($names, static fn($nm) => !isset($found[$nm])));
                foreach ($toFind as $nm) {
                    $tokens = preg_split('/[^a-z0-9]+/i', (string)$nm) ?: [];
                    $tokens = array_values(array_filter(array_map('strtolower', $tokens), static fn($t) => strlen($t) >= 3));
                    if (!$tokens) { continue; }
                    $tokens = array_slice($tokens, 0, 3);
                    $cond = [];
                    $p2 = [];
                    $cond[] = 'supplier_id IN (' . $inSup . ')';
                    foreach ($chosen as $sid) { $p2[] = (int)$sid; }
                    foreach ($tokens as $t) { $cond[] = 'LOWER(name) ILIKE ?'; $p2[] = '%' . $t . '%'; }
                    $sql2 = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE ' . implode(' AND ', $cond);
                    $st2 = $pdo->prepare($sql2);
                    $st2->execute($p2);
                    foreach ($st2->fetchAll() as $it) {
                        $sid = (int)$it['supplier_id'];
                        $base = (float)($it['price'] ?? 0);
                        $ppp = max(1, (int)($it['pieces_per_package'] ?? 1));
                        $needPk = $ppp > 0 ? (int)ceil((int)($byNameQty[$nm] ?? 0) / $ppp) : 0;
                        $best = $base;
                        if ($needPk > 0) {
                            try {
                                $tq = $pdo->prepare('SELECT min_packages, max_packages, price_per_package FROM supplier_item_price_tiers WHERE supplier_item_id = :id ORDER BY min_packages ASC');
                                $tq->execute(['id' => (int)$it['id']]);
                                foreach ($tq->fetchAll() as $t) {
                                    $min = (int)$t['min_packages'];
                                    $max = $t['max_packages'] !== null ? (int)$t['max_packages'] : null;
                                    if ($needPk >= $min && ($max === null || $needPk <= $max)) { $best = min($best, (float)$t['price_per_package']); }
                                }
                            } catch (\Throwable $e) {}
                        }
                        if (!isset($prices[$sid])) { $prices[$sid] = []; }
                        if (!isset($prices[$sid][$nm]) || $best < $prices[$sid][$nm]) { $prices[$sid][$nm] = $best; }
                    }
                }
                // Sum totals per supplier
                foreach ($prices as $sid => $pm) {
                    $sum = 0.0;
                    foreach ($byNameQty as $iname => $_qty) {
                        if (isset($pm[$iname])) { $sum += (float)$pm[$iname]; }
                    }
                    if ($sum > 0) { $totals[$sid] = $sum; }
                }
            }
        } catch (\Throwable $e) { /* best-effort: totals may remain empty */ }

        // If no manual award selected, auto-pick the cheapest supplier using computed totals
        if (!$awardedId && $totals) {
            asort($totals);
            $sid = (int)array_key_first($totals);
            if ($sid && isset($map[$sid])) { $awardedId = $sid; $awardedName = $map[$sid]; }
        }
        // If still no awarded vendor, try saved awarded_to on pr_canvassing; else default to first chosen supplier to avoid blank
        if (!$awardedName) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                    pr_number VARCHAR(32) PRIMARY KEY,
                    supplier1 VARCHAR(255),
                    supplier2 VARCHAR(255),
                    supplier3 VARCHAR(255),
                    awarded_to VARCHAR(255),
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )");
                $stA = $pdo->prepare('SELECT awarded_to FROM pr_canvassing WHERE pr_number = :pr');
                $stA->execute(['pr' => $pr]);
                $saved = (string)($stA->fetchColumn() ?: '');
                if ($saved !== '') { $awardedName = $saved; }
            } catch (\Throwable $ignored) {}
        }
        if (!$awardedName && $chosen) {
            $first = $chosen[0]; if (isset($map[$first])) { $awardedName = $map[$first]; }
        }
        // Build PR‑style PR-Canvass PDF (PR layout + Canvassing table inserted before Attachments)
        $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . DIRECTORY_SEPARATOR . 'PR-Canvass-' . preg_replace('/[^A-Za-z0-9_-]/','_', $pr) . '.pdf';
        // PR meta
        $justification = '';
        $neededBy = '';
        foreach ($rows as $r) {
            if ($justification === '' && isset($r['justification']) && $r['justification'] !== null && $r['justification'] !== '') {
                $raw = (string)$r['justification'];
                $pt = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . $pr);
                if ($pt === null || $pt === $raw || str_starts_with((string)$pt, 'v1:')) {
                    $pt2 = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . (string)($r['request_id'] ?? ''));
                    if ($pt2 !== null && $pt2 !== '' && !str_starts_with((string)$pt2, 'v1:')) { $pt = $pt2; }
                }
                $justification = (string)($pt ?? $raw);
            }
            if ($neededBy === '' && !empty($r['needed_by'])) { $neededBy = date('Y-m-d', strtotime((string)$r['needed_by'])); }
        }
        $notedBy = '';
        try {
            $u = $pdo->query("SELECT full_name FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement') ORDER BY (role='procurement_manager') DESC, full_name ASC LIMIT 1");
            $nb = $u ? $u->fetchColumn() : null; if ($nb) { $notedBy = (string)$nb; }
        } catch (\Throwable $ignored) {}
        $dateReceived = '';
        try {
            $stR = $pdo->prepare("SELECT m.created_at FROM messages m JOIN users u ON u.user_id = m.recipient_id WHERE u.role IN ('procurement_manager','procurement') AND (m.subject ILIKE :s OR m.attachment_name ILIKE :a) ORDER BY m.created_at ASC LIMIT 1");
            $stR->execute(['s' => '%PR ' . $pr . '%', 'a' => 'pr_' . $pr . '%']);
            $dr = $stR->fetchColumn(); if ($dr) { $dateReceived = date('Y-m-d', strtotime((string)$dr)); }
        } catch (\Throwable $ignored) {}
        // Optional canvassing approval fields (blank until admin approves)
        $purchaseApprovedBy = '';
        $purchaseApprovedAt = '';
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                pr_number VARCHAR(32) PRIMARY KEY,
                supplier1 VARCHAR(255),
                supplier2 VARCHAR(255),
                supplier3 VARCHAR(255),
                awarded_to VARCHAR(255),
                approved_by VARCHAR(255),
                approved_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $stC0 = $pdo->prepare('SELECT approved_by, approved_at FROM pr_canvassing WHERE pr_number = :pr');
            $stC0->execute(['pr' => $pr]);
            if ($cv0 = $stC0->fetch()) {
                $purchaseApprovedBy = (string)($cv0['approved_by'] ?? '');
                if (!empty($cv0['approved_at'])) { $purchaseApprovedAt = date('Y-m-d', strtotime((string)$cv0['approved_at'])); }
            }
        } catch (\Throwable $ignored) {}
        // Ensure supplier names are in the same order as chosen
        $supNamesOrdered = [];
        foreach ($chosen as $sid) { if (isset($map[$sid])) { $supNamesOrdered[] = $map[$sid]; } }
        $metaCan = [
            'pr_number' => $pr,
            'branch_name' => (string)($rows[0]['branch_name'] ?? 'N/A'),
            'requested_by' => (string)($rows[0]['requested_by_name'] ?? ''),
            'prepared_at' => date('Y-m-d', strtotime((string)($rows[0]['created_at'] ?? date('Y-m-d')))),
            'effective_date' => date('Y-m-d'),
            'justification' => $justification,
            'needed_by' => $neededBy,
            'date_received' => $dateReceived,
            'noted_by' => $notedBy,
            'canvassed_suppliers' => $supNamesOrdered ?: array_values($map),
            'awarded_to' => (string)($awardedName ?? ''),
            'canvass_totals' => (function() use ($totals, $chosen) {
                $out = [];
                for ($i=0;$i<3;$i++) { $sid = $chosen[$i] ?? 0; $out[$i] = ($sid && isset($totals[$sid])) ? (float)$totals[$sid] : null; }
                return $out;
            })(),
            'signature_variant' => 'purchase_approval',
            'purchase_approved_by' => $purchaseApprovedBy,
            'purchase_approved_at' => $purchaseApprovedAt,
        ];
        $itemsCan = [];
        foreach ($rows as $r) {
            $itemsCan[] = [
                'description' => (string)($r['item_name'] ?? 'Item'),
                'unit' => (string)($r['unit'] ?? ''),
                'qty' => (int)($r['quantity'] ?? 0),
                'stock_on_hand' => isset($r['stock_on_hand']) ? (int)$r['stock_on_hand'] : null,
            ];
        }
        $this->pdf()->generatePurchaseRequisitionToFile($metaCan, $itemsCan, $file);
        // Persist selected suppliers for PR (for inclusion in PR PDF later)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                pr_number VARCHAR(32) PRIMARY KEY,
                supplier1 VARCHAR(255),
                supplier2 VARCHAR(255),
                supplier3 VARCHAR(255),
                awarded_to VARCHAR(255),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $names = array_values($map);
            $s1 = $names[0] ?? null; $s2 = $names[1] ?? null; $s3 = $names[2] ?? null;
            // Upsert
            $pdo->prepare('INSERT INTO pr_canvassing (pr_number, supplier1, supplier2, supplier3, awarded_to) VALUES (:pr,:s1,:s2,:s3,:aw)
                           ON CONFLICT (pr_number) DO UPDATE SET supplier1 = EXCLUDED.supplier1, supplier2 = EXCLUDED.supplier2, supplier3 = EXCLUDED.supplier3, awarded_to = EXCLUDED.awarded_to, updated_at = NOW()')
                ->execute(['pr' => $pr, 's1' => $s1, 's2' => $s2, 's3' => $s3, 'aw' => $awardedName]);
        } catch (\Throwable $ignored) {}

        // Build PR PDF as second attachment (final PR with approval/canvassing info)
        $justification = '';
        $neededBy = '';
        $approvedBy = '';
        $approvedAt = '';
        foreach ($rows as $r) {
            if ($justification === '' && isset($r['justification']) && $r['justification'] !== null && $r['justification'] !== '') {
                $raw = (string)$r['justification'];
                $pt = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . $pr);
                if ($pt === null || $pt === $raw || str_starts_with((string)$pt, 'v1:')) {
                    $pt2 = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . (string)($r['request_id'] ?? ''));
                    if ($pt2 !== null && $pt2 !== '' && !str_starts_with((string)$pt2, 'v1:')) { $pt = $pt2; }
                }
                $justification = (string)($pt ?? $raw);
            }
            if ($neededBy === '' && !empty($r['needed_by'])) { $neededBy = date('Y-m-d', strtotime((string)$r['needed_by'])); }
            if ($approvedBy === '' && !empty($r['approved_by'])) { $approvedBy = (string)$r['approved_by']; }
            if ($approvedAt === '' && !empty($r['approved_at'])) { $approvedAt = date('Y-m-d', strtotime((string)$r['approved_at'])); }
        }
        $notedBy = '';
        try {
            $u = $pdo->query("SELECT full_name FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement') ORDER BY (role='procurement_manager') DESC, full_name ASC LIMIT 1");
            $nb = $u ? $u->fetchColumn() : null; if ($nb) { $notedBy = (string)$nb; }
        } catch (\Throwable $ignored) {}
        $dateReceived = '';
        try {
            $stR = $pdo->prepare("SELECT m.created_at FROM messages m JOIN users u ON u.user_id = m.recipient_id WHERE u.role IN ('procurement_manager','procurement') AND (m.subject ILIKE :s OR m.attachment_name ILIKE :a) ORDER BY m.created_at ASC LIMIT 1");
            $stR->execute(['s' => '%PR ' . $pr . '%', 'a' => 'pr_' . $pr . '%']);
            $dr = $stR->fetchColumn(); if ($dr) { $dateReceived = date('Y-m-d', strtotime((string)$dr)); }
        } catch (\Throwable $ignored) {}
        // Keep supplier display order aligned with the chosen IDs
        $supNamesOrdered2 = [];
        foreach ($chosen as $sid) { if (isset($map[$sid])) { $supNamesOrdered2[] = $map[$sid]; } }
        $meta = [
            'pr_number' => $pr,
            'branch_name' => (string)($rows[0]['branch_name'] ?? 'N/A'),
            'requested_by' => (string)($rows[0]['requested_by_name'] ?? ''),
            'prepared_at' => date('Y-m-d', strtotime((string)($rows[0]['created_at'] ?? date('Y-m-d')))),
            'effective_date' => date('Y-m-d'),
            'justification' => $justification,
            'needed_by' => $neededBy,
            'date_received' => $dateReceived,
            'noted_by' => $notedBy,
            // If PR approval fields are blank, fallback to canvassing approval (so PR shows Admin name/date post-canvassing)
            'approved_by' => (function() use ($pdo, $pr, $approvedBy) {
                if ($approvedBy !== '') { return $approvedBy; }
                try {
                    $st = $pdo->prepare('SELECT approved_by FROM pr_canvassing WHERE pr_number = :pr');
                    $st->execute(['pr' => $pr]);
                    $v = (string)($st->fetchColumn() ?: '');
                    return $v;
                } catch (\Throwable $e) { return ''; }
            })(),
            'approved_at' => (function() use ($pdo, $pr, $approvedAt) {
                if ($approvedAt !== '') { return $approvedAt; }
                try {
                    $st = $pdo->prepare('SELECT approved_at FROM pr_canvassing WHERE pr_number = :pr');
                    $st->execute(['pr' => $pr]);
                    $v = $st->fetchColumn();
                    return $v ? date('Y-m-d', strtotime((string)$v)) : '';
                } catch (\Throwable $e) { return ''; }
            })(),
            'canvassed_suppliers' => $supNamesOrdered2 ?: array_values($map),
            'awarded_to' => (string)($awardedName ?? ''),
            'canvass_totals' => (function() use ($totals, $chosen) {
                $out = [];
                for ($i=0;$i<3;$i++) { $sid = $chosen[$i] ?? 0; $out[$i] = ($sid && isset($totals[$sid])) ? (float)$totals[$sid] : null; }
                return $out;
            })(),
        ];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'description' => (string)($r['item_name'] ?? 'Item'),
                'unit' => (string)($r['unit'] ?? ''),
                'qty' => (int)($r['quantity'] ?? 0),
                'stock_on_hand' => isset($r['stock_on_hand']) ? (int)$r['stock_on_hand'] : null,
            ];
        }
        $root = realpath(__DIR__ . '/../../..');
        $candidates = [];
        if ($root) { $candidates[] = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf'; }
        $candidates[] = sys_get_temp_dir();
        $prPdf = null; $written = false;
        foreach ($candidates as $base) {
            if (!is_dir($base)) { @mkdir($base, 0777, true); }
            if (!is_dir($base) || !is_writable($base)) { continue; }
            $path = $base . DIRECTORY_SEPARATOR . 'PR-' . preg_replace('/[^A-Za-z0-9_-]/','_', $pr) . '.pdf';
            try {
                $this->pdf()->generatePurchaseRequisitionToFile($meta, $items, $path);
                if (@is_file($path) && (@filesize($path) ?: 0) > 0) { $prPdf = $path; $written = true; break; }
            } catch (\Throwable $ignored) {}
        }
        if (!$written || $prPdf === null) { header('Location: /manager/requests?error=' . rawurlencode('PR+PDF+generation+failed')); return; }

        // Mark the PR group as canvassing_submitted (awaiting admin approval)
        try { $this->requests()->updateGroupStatus($pr, 'canvassing_submitted', (int)($_SESSION['user_id'] ?? 0), 'Canvassing submitted for admin approval'); } catch (\Throwable $ignored) {}

        // Redirect to compose with Admin prefilled, subject, and two attachments (Canvass + PR)
        // Ensure message columns exist (safety)
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}
        $to = 0;
        try {
            $stAdm = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role = 'admin' ORDER BY user_id ASC LIMIT 1");
            $to = (int)($stAdm ? ($stAdm->fetchColumn() ?: 0) : 0);
        } catch (\Throwable $ignored) {}
        $subject = 'PR ' . $pr . ' • PR - Canvass';
        $qs = http_build_query([
            'to' => $to > 0 ? $to : null,
            'subject' => $subject,
            // Primary attach: Canvassing PDF
            'attach_name' => basename($file),
            'attach_path' => $file,
            // Second attach: PR PDF
            'attach_name2' => basename($prPdf),
            'attach_path2' => $prPdf,
        ]);
        header('Location: /admin/messages' . ($qs !== '' ? ('?' . $qs) : ''));
        // Clear preview flag for this PR after successful flow
        unset($_SESSION['canvass_previewed'][$pr]);
        return;
    }

    /** POST: Preview Canvassing PDF in a new tab (no DB status change). Sets a session flag to allow sending later. */
    public function canvassPreview(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $chosen = isset($_POST['suppliers']) && is_array($_POST['suppliers']) ? array_values(array_unique(array_map('intval', $_POST['suppliers']))) : [];
        if ($pr === '' || count($chosen) < 3) { header('Location: /manager/requests/canvass?pr=' . urlencode($pr) . '&error=Select+3%E2%80%935+suppliers+to+preview'); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { header('Location: /manager/requests'); return; }
        $pdo = \App\Database\Connection::resolve();
        // Map supplier ids to names for preview headers
        $inParams = implode(',', array_fill(0, count($chosen), '?'));
        $st = $pdo->prepare('SELECT user_id, full_name FROM users WHERE user_id IN (' . $inParams . ')');
        $st->execute($chosen);
        $map = [];
        foreach ($st->fetchAll() as $s) { $map[(int)$s['user_id']] = (string)$s['full_name']; }
        // Build PR-style meta to generate a PR-Canvass PDF (PR layout + Canvassing table before Attachments)
        $pdo = \App\Database\Connection::resolve();
        $justification = '';
        $neededBy = '';
        foreach ($rows as $r) {
            if ($justification === '' && isset($r['justification']) && $r['justification'] !== null && $r['justification'] !== '') {
                $raw = (string)$r['justification'];
                $pt = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . $pr);
                if ($pt === null || $pt === $raw || str_starts_with((string)$pt, 'v1:')) {
                    $pt2 = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . (string)($r['request_id'] ?? ''));
                    if ($pt2 !== null && $pt2 !== '' && !str_starts_with((string)$pt2, 'v1:')) { $pt = $pt2; }
                }
                $justification = (string)($pt ?? $raw);
            }
            if ($neededBy === '' && !empty($r['needed_by'])) { $neededBy = date('Y-m-d', strtotime((string)$r['needed_by'])); }
        }
        // Noted by and date received for header completeness
        $notedBy = '';
        try {
            $u = $pdo->query("SELECT full_name FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement') ORDER BY (role='procurement_manager') DESC, full_name ASC LIMIT 1");
            $nb = $u ? $u->fetchColumn() : null; if ($nb) { $notedBy = (string)$nb; }
        } catch (\Throwable $ignored) {}
        $dateReceived = '';
        try {
            $stR = $pdo->prepare("SELECT m.created_at FROM messages m JOIN users u ON u.user_id = m.recipient_id WHERE u.role IN ('procurement_manager','procurement') AND (m.subject ILIKE :s OR m.attachment_name ILIKE :a) ORDER BY m.created_at ASC LIMIT 1");
            $stR->execute(['s' => '%PR ' . $pr . '%', 'a' => 'pr_' . $pr . '%']);
            $dr = $stR->fetchColumn(); if ($dr) { $dateReceived = date('Y-m-d', strtotime((string)$dr)); }
        } catch (\Throwable $ignored) {}
        // Compute cheapest totals per supplier for preview (to prefill AWARDED TO and totals)
        $awardedPreview = '';
        $totalsPreview = [];
        try {
            // Build name->qty map
            $byNameQty = [];
            $names = [];
            foreach ($rows as $r) {
                $nm = strtolower(trim((string)($r['item_name'] ?? '')));
                $names[] = $nm;
                $byNameQty[$nm] = (int)($byNameQty[$nm] ?? 0) + (int)($r['quantity'] ?? 0);
            }
            $names = array_values(array_unique($names));
            if ($names && $chosen) {
                $inSup = implode(',', array_fill(0, count($chosen), '?'));
                $inNames = implode(',', array_fill(0, count($names), '?'));
                $params = [];
                foreach ($chosen as $sid) { $params[] = (int)$sid; }
                foreach ($names as $nm) { $params[] = $nm; }
                $sql = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE supplier_id IN (' . $inSup . ') AND LOWER(name) IN (' . $inNames . ')';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $prices = [];
                foreach ($st->fetchAll() as $it) {
                    $sid = (int)$it['supplier_id'];
                    $lname = (string)$it['lname'];
                    $base = (float)($it['price'] ?? 0);
                    $ppp = max(1, (int)($it['pieces_per_package'] ?? 1));
                    $needPk = $ppp > 0 ? (int)ceil((int)($byNameQty[$lname] ?? 0) / $ppp) : 0;
                    $best = $base;
                    if ($needPk > 0) {
                        try {
                            $tq = $pdo->prepare('SELECT min_packages, max_packages, price_per_package FROM supplier_item_price_tiers WHERE supplier_item_id = :id ORDER BY min_packages ASC');
                            $tq->execute(['id' => (int)$it['id']]);
                            foreach ($tq->fetchAll() as $t) {
                                $min = (int)$t['min_packages'];
                                $max = $t['max_packages'] !== null ? (int)$t['max_packages'] : null;
                                if ($needPk >= $min && ($max === null || $needPk <= $max)) { $best = min($best, (float)$t['price_per_package']); }
                            }
                        } catch (\Throwable $e) {}
                    }
                    if (!isset($prices[$sid])) { $prices[$sid] = []; }
                    if (!isset($prices[$sid][$lname]) || $best < $prices[$sid][$lname]) { $prices[$sid][$lname] = $best; }
                }
                // Sum totals per supplier and pick cheapest
                $totals = [];
                foreach ($prices as $sid => $pm) {
                    $sum = 0.0;
                    foreach ($byNameQty as $iname => $_q) { if (isset($pm[$iname])) { $sum += (float)$pm[$iname]; } }
                    if ($sum > 0) { $totals[$sid] = $sum; }
                }
                if ($totals) {
                    asort($totals);
                    $sidMin = (int)array_key_first($totals);
                    if ($sidMin && isset($map[$sidMin])) { $awardedPreview = (string)$map[$sidMin]; }
                    // Build totals in the same supplier order provided
                    for ($i=0;$i<3;$i++) { $sid = $chosen[$i] ?? 0; $totalsPreview[$i] = ($sid && isset($totals[$sid])) ? (float)$totals[$sid] : null; }
                }
            }
        } catch (\Throwable $ignored) {}

        // Optional canvassing approval fields if already approved (preview after approval)
        $purchaseApprovedBy = '';
        $purchaseApprovedAt = '';
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                pr_number VARCHAR(32) PRIMARY KEY,
                supplier1 VARCHAR(255),
                supplier2 VARCHAR(255),
                supplier3 VARCHAR(255),
                awarded_to VARCHAR(255),
                approved_by VARCHAR(255),
                approved_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $stC = $pdo->prepare('SELECT approved_by, approved_at FROM pr_canvassing WHERE pr_number = :pr');
            $stC->execute(['pr' => $pr]);
            if ($row = $stC->fetch()) {
                $purchaseApprovedBy = (string)($row['approved_by'] ?? '');
                if (!empty($row['approved_at'])) { $purchaseApprovedAt = date('Y-m-d', strtotime((string)$row['approved_at'])); }
            }
        } catch (\Throwable $ignored) {}
        $meta = [
            'pr_number' => $pr,
            'branch_name' => (string)($rows[0]['branch_name'] ?? 'N/A'),
            'requested_by' => (string)($rows[0]['requested_by_name'] ?? ''),
            'prepared_at' => date('Y-m-d', strtotime((string)($rows[0]['created_at'] ?? date('Y-m-d')))),
            'effective_date' => date('Y-m-d'),
            'justification' => $justification,
            'needed_by' => $neededBy,
            'date_received' => $dateReceived,
            'noted_by' => $notedBy,
            'canvassed_suppliers' => array_values($map),
            // Prefer existing awarded_to if saved; else use computed cheapest for preview
            'awarded_to' => (function() use ($pdo, $pr, $awardedPreview) {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                        pr_number VARCHAR(32) PRIMARY KEY,
                        supplier1 VARCHAR(255),
                        supplier2 VARCHAR(255),
                        supplier3 VARCHAR(255),
                        awarded_to VARCHAR(255),
                        approved_by VARCHAR(255),
                        approved_at TIMESTAMPTZ,
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )");
                    $st = $pdo->prepare('SELECT awarded_to FROM pr_canvassing WHERE pr_number = :pr');
                    $st->execute(['pr' => $pr]);
                    $v = $st->fetchColumn();
                    return $v ? (string)$v : $awardedPreview;
                } catch (\Throwable $e) { return ''; }
            })(),
            'canvass_totals' => $totalsPreview,
            'signature_variant' => 'purchase_approval',
            'purchase_approved_by' => $purchaseApprovedBy,
            'purchase_approved_at' => $purchaseApprovedAt,
        ];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'description' => (string)($r['item_name'] ?? 'Item'),
                'unit' => (string)($r['unit'] ?? ''),
                'qty' => (int)($r['quantity'] ?? 0),
                'stock_on_hand' => isset($r['stock_on_hand']) ? (int)$r['stock_on_hand'] : null,
            ];
        }
        // Generate to temp and stream inline
        $dir = sys_get_temp_dir();
        $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'PR-Canvass-' . preg_replace('/[^A-Za-z0-9_-]/','_', $pr) . '-preview.pdf';
        $this->pdf()->generatePurchaseRequisitionToFile($meta, $items, $file);
        if (!is_file($file)) { header('Location: /manager/requests/canvass?pr=' . urlencode($pr) . '&error=Failed+to+generate+preview'); return; }
        // Set session flag to allow final send later
        if (!isset($_SESSION['canvass_previewed']) || !is_array($_SESSION['canvass_previewed'])) { $_SESSION['canvass_previewed'] = []; }
        $_SESSION['canvass_previewed'][$pr] = time();
        $size = @filesize($file) ?: null;
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode('Canvassing-PR-' . $pr . '.pdf') . '"');
        if ($size !== null) { header('Content-Length: ' . (string)$size); }
        @readfile($file);
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
        $pdo = \App\Database\Connection::resolve();
        $justification = '';
        $neededBy = '';
        $approvedBy = '';
        $approvedAt = '';
        // Try to derive justification (decrypt if needed) and earliest needed_by
        foreach ($rows as $r) {
            if ($justification === '' && isset($r['justification']) && $r['justification'] !== null && $r['justification'] !== '') {
                $raw = (string)$r['justification'];
                // Attempt decrypt with PR-number AAD, then request-id AAD
                $pt = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . $pr);
                if ($pt === null || $pt === $raw || str_starts_with((string)$pt, 'v1:')) {
                    $pt2 = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . (string)($r['request_id'] ?? ''));
                    if ($pt2 !== null && $pt2 !== '' && !str_starts_with((string)$pt2, 'v1:')) { $pt = $pt2; }
                }
                $justification = (string)($pt ?? $raw);
            }
            if ($neededBy === '' && !empty($r['needed_by'])) { $neededBy = date('Y-m-d', strtotime((string)$r['needed_by'])); }
            if ($approvedBy === '' && !empty($r['approved_by'])) { $approvedBy = (string)$r['approved_by']; }
            if ($approvedAt === '' && !empty($r['approved_at'])) { $approvedAt = date('Y-m-d', strtotime((string)$r['approved_at'])); }
        }
        // Noted by: pick a procurement manager (fallback any procurement)
        $notedBy = '';
        try {
            $u = $pdo->query("SELECT full_name FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement') ORDER BY (role='procurement_manager') DESC, full_name ASC LIMIT 1");
            $nb = $u ? $u->fetchColumn() : null; if ($nb) { $notedBy = (string)$nb; }
        } catch (\Throwable $ignored) {}
        // Date received: earliest message to a procurement user referencing this PR
        $dateReceived = '';
        try {
            $stR = $pdo->prepare("SELECT m.created_at FROM messages m JOIN users u ON u.user_id = m.recipient_id WHERE u.role IN ('procurement_manager','procurement') AND (m.subject ILIKE :s OR m.attachment_name ILIKE :a) ORDER BY m.created_at ASC LIMIT 1");
            $stR->execute(['s' => '%PR ' . $pr . '%', 'a' => 'pr_' . $pr . '%']);
            $dr = $stR->fetchColumn(); if ($dr) { $dateReceived = date('Y-m-d', strtotime((string)$dr)); }
        } catch (\Throwable $ignored) {}

        // Optional canvassing info (persisted by canvassSubmit)
        $canvasSup = [];
        $awardedTo = '';
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                pr_number VARCHAR(32) PRIMARY KEY,
                supplier1 VARCHAR(255),
                supplier2 VARCHAR(255),
                supplier3 VARCHAR(255),
                awarded_to VARCHAR(255),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $stC = $pdo->prepare('SELECT supplier1, supplier2, supplier3, awarded_to FROM pr_canvassing WHERE pr_number = :pr');
            $stC->execute(['pr' => $pr]);
            if ($cv = $stC->fetch()) {
                foreach (['supplier1','supplier2','supplier3'] as $k) { if (!empty($cv[$k])) { $canvasSup[] = (string)$cv[$k]; } }
                $awardedTo = (string)($cv['awarded_to'] ?? '');
            }
        } catch (\Throwable $ignored) {}

        // Compute supplier totals aligned to stored supplier order to mirror the Canvassing page
        $canvassTotals = [];
        if (!empty($canvasSup)) {
            try {
                // Map supplier names to IDs
                $names = array_slice(array_values($canvasSup), 0, 3);
                $lowers = array_map(static fn($s) => strtolower(trim((string)$s)), $names);
                $inNames = implode(',', array_fill(0, count($lowers), '?'));
                $stS = $pdo->prepare('SELECT user_id, LOWER(full_name) AS lname FROM users WHERE LOWER(full_name) IN (' . $inNames . ')');
                $stS->execute($lowers);
                $idByName = [];
                foreach ($stS->fetchAll() as $row) { $idByName[(string)$row['lname']] = (int)$row['user_id']; }
                $supplierIds = [];
                foreach ($lowers as $ln) { $supplierIds[] = $idByName[$ln] ?? 0; }
                // Build item name->qty map
                $byNameQty = [];
                foreach ($rows as $r) {
                    $n = strtolower(trim((string)($r['item_name'] ?? '')));
                    if ($n === '') { continue; }
                    $qty = (int)($r['quantity'] ?? 0);
                    $byNameQty[$n] = ($byNameQty[$n] ?? 0) + max(0, $qty);
                }
                $itemNames = array_keys($byNameQty);
                if ($itemNames && array_filter($supplierIds)) {
                    $inSup = implode(',', array_fill(0, count($supplierIds), '?'));
                    $inItems = implode(',', array_fill(0, count($itemNames), '?'));
                    $params = [];
                    foreach ($supplierIds as $sid) { $params[] = (int)$sid; }
                    foreach ($itemNames as $nm) { $params[] = $nm; }
                    $sql = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE supplier_id IN (' . $inSup . ') AND LOWER(name) IN (' . $inItems . ')';
                    $stI = $pdo->prepare($sql);
                    $stI->execute($params);
                    $prices = [];
                    foreach ($stI->fetchAll() as $it) {
                        $sid = (int)$it['supplier_id'];
                        $lname = (string)$it['lname'];
                        $base = (float)($it['price'] ?? 0);
                        $ppp = max(1, (int)($it['pieces_per_package'] ?? 1));
                        $needPk = $ppp > 0 ? (int)ceil((int)($byNameQty[$lname] ?? 0) / $ppp) : 0;
                        $best = $base;
                        if ($needPk > 0) {
                            try {
                                $tq = $pdo->prepare('SELECT min_packages, max_packages, price_per_package FROM supplier_item_price_tiers WHERE supplier_item_id = :id ORDER BY min_packages ASC');
                                $tq->execute(['id' => (int)$it['id']]);
                                foreach ($tq->fetchAll() as $t) {
                                    $min = (int)$t['min_packages'];
                                    $max = $t['max_packages'] !== null ? (int)$t['max_packages'] : null;
                                    if ($needPk >= $min && ($max === null || $needPk <= $max)) { $best = min($best, (float)$t['price_per_package']); }
                                }
                            } catch (\Throwable $e) {}
                        }
                        if (!isset($prices[$sid])) { $prices[$sid] = []; }
                        if (!isset($prices[$sid][$lname]) || $best < $prices[$sid][$lname]) { $prices[$sid][$lname] = $best; }
                    }
                    // Sum totals by supplier id
                    $bySidTotal = [];
                    foreach ($prices as $sid => $pm) {
                        $sum = 0.0;
                        foreach ($byNameQty as $iname => $_qty) { if (isset($pm[$iname])) { $sum += (float)$pm[$iname]; } }
                        if ($sum > 0) { $bySidTotal[$sid] = $sum; }
                    }
                    // Align totals to name order; also auto-fill awarded if missing
                    $canvassTotals = [];
                    for ($i=0; $i<3; $i++) {
                        $sid = $supplierIds[$i] ?? 0;
                        $canvassTotals[$i] = ($sid && isset($bySidTotal[$sid])) ? (float)$bySidTotal[$sid] : null;
                    }
                    if ($awardedTo === '' && $bySidTotal) {
                        asort($bySidTotal);
                        $sidMin = (int)array_key_first($bySidTotal);
                        // Find the name corresponding to sidMin in our order
                        $idx = array_search($sidMin, $supplierIds, true);
                        if ($idx !== false && isset($names[$idx])) { $awardedTo = (string)$names[$idx]; }
                    }
                }
            } catch (\Throwable $ignored) {}
        }

        // If PR approval is blank, fallback to canvassing approvals (so PR shows Admin name/date after canvassing approval)
        if ($approvedBy === '' || $approvedAt === '') {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                    pr_number VARCHAR(32) PRIMARY KEY,
                    supplier1 VARCHAR(255),
                    supplier2 VARCHAR(255),
                    supplier3 VARCHAR(255),
                    awarded_to VARCHAR(255),
                    approved_by VARCHAR(255),
                    approved_at TIMESTAMPTZ,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )");
                $stAp = $pdo->prepare('SELECT approved_by, approved_at FROM pr_canvassing WHERE pr_number = :pr');
                $stAp->execute(['pr' => $pr]);
                if ($rowAp = $stAp->fetch()) {
                    if ($approvedBy === '' && !empty($rowAp['approved_by'])) { $approvedBy = (string)$rowAp['approved_by']; }
                    if ($approvedAt === '' && !empty($rowAp['approved_at'])) { $approvedAt = date('Y-m-d', strtotime((string)$rowAp['approved_at'])); }
                }
            } catch (\Throwable $ignored) {}
        }

        // Final fallback: if still blank, backfill from events history for the initial Admin approval
        if ($approvedBy === '' || $approvedAt === '') {
            try {
                $stEv = $pdo->prepare(
                    "SELECT e.performed_at, u.full_name AS who
                     FROM purchase_request_events e
                     JOIN purchase_requests pr2 ON pr2.request_id = e.request_id
                     LEFT JOIN users u ON u.user_id = e.performed_by
                     WHERE pr2.pr_number = :pr AND e.new_status = 'approved'
                     ORDER BY e.performed_at DESC
                     LIMIT 1"
                );
                $stEv->execute(['pr' => $pr]);
                if ($ev = $stEv->fetch()) {
                    if ($approvedBy === '' && !empty($ev['who'])) { $approvedBy = (string)$ev['who']; }
                    if ($approvedAt === '' && !empty($ev['performed_at'])) { $approvedAt = date('Y-m-d', strtotime((string)$ev['performed_at'])); }
                }
            } catch (\Throwable $ignored) {}
        }

        $meta = [
            'pr_number' => $pr,
            'branch_name' => (string)($rows[0]['branch_name'] ?? 'N/A'),
            'requested_by' => (string)($rows[0]['requested_by_name'] ?? ''),
            'prepared_at' => date('Y-m-d', strtotime((string)($rows[0]['created_at'] ?? date('Y-m-d')))),
            'effective_date' => date('Y-m-d'),
            'justification' => $justification,
            'needed_by' => $neededBy,
            'date_received' => $dateReceived,
            'noted_by' => $notedBy,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
            'canvassed_suppliers' => $canvasSup,
            'awarded_to' => $awardedTo,
            'canvass_totals' => $canvassTotals,
        ];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'description' => (string)($r['item_name'] ?? 'Item'),
                'unit' => (string)($r['unit'] ?? ''),
                'qty' => (int)($r['quantity'] ?? 0),
                'stock_on_hand' => isset($r['stock_on_hand']) ? (int)$r['stock_on_hand'] : null,
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
        try {
            if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager', 'procurement', 'admin'], true)) { header('Location: /login'); return; }
            $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
            if ($pr === '') { header('Location: /manager/requests?error=No+PR+number'); return; }
            $rows = $this->requests()->getGroupDetails($pr);
            if (!$rows) { header('Location: /manager/requests?error=PR+not+found'); return; }
        // Build canonical PR PDF (same layout as Admin Assistant), include procurement meta
        $pdo = \App\Database\Connection::resolve();
        $justification = '';
        $neededBy = '';
        foreach ($rows as $r) {
            if ($justification === '' && isset($r['justification']) && $r['justification'] !== null && $r['justification'] !== '') {
                $raw = (string)$r['justification'];
                $pt = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . $pr);
                if ($pt === null || $pt === $raw || str_starts_with((string)$pt, 'v1:')) {
                    $pt2 = \App\Services\CryptoService::maybeDecrypt($raw, 'pr:' . (string)($r['request_id'] ?? ''));
                    if ($pt2 !== null && $pt2 !== '' && !str_starts_with((string)$pt2, 'v1:')) { $pt = $pt2; }
                }
                $justification = (string)($pt ?? $raw);
            }
            if ($neededBy === '' && !empty($r['needed_by'])) { $neededBy = date('Y-m-d', strtotime((string)$r['needed_by'])); }
        }
        $notedBy = '';
        try {
            $u = $pdo->query("SELECT full_name FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement') ORDER BY (role='procurement_manager') DESC, full_name ASC LIMIT 1");
            $nb = $u ? $u->fetchColumn() : null; if ($nb) { $notedBy = (string)$nb; }
        } catch (\Throwable $ignored) {}
        $dateReceived = '';
        try {
            $stR = $pdo->prepare("SELECT m.created_at FROM messages m JOIN users u ON u.user_id = m.recipient_id WHERE u.role IN ('procurement_manager','procurement') AND (m.subject ILIKE :s OR m.attachment_name ILIKE :a) ORDER BY m.created_at ASC LIMIT 1");
            $stR->execute(['s' => '%PR ' . $pr . '%', 'a' => 'pr_' . $pr . '%']);
            $dr = $stR->fetchColumn(); if ($dr) { $dateReceived = date('Y-m-d', strtotime((string)$dr)); }
        } catch (\Throwable $ignored) {}

        $meta = [
            'pr_number' => $pr,
            'branch_name' => (string)($rows[0]['branch_name'] ?? 'N/A'),
            'requested_by' => (string)($rows[0]['requested_by_name'] ?? ''),
            'prepared_at' => date('Y-m-d', strtotime((string)($rows[0]['created_at'] ?? date('Y-m-d')))),
            'effective_date' => date('Y-m-d'),
            'justification' => $justification,
            'needed_by' => $neededBy,
            'date_received' => $dateReceived,
            'noted_by' => $notedBy,
        ];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'description' => (string)($r['item_name'] ?? 'Item'),
                'unit' => (string)($r['unit'] ?? ''),
                'qty' => (int)($r['quantity'] ?? 0),
            ];
        }
        // Render PDF to a writable folder with fallback to system temp (Heroku-compatible)
        $root = realpath(__DIR__ . '/../../..');
        $candidates = [];
        if ($root) { $candidates[] = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf'; }
        $candidates[] = sys_get_temp_dir();
        $tmpFile = null; $written = false;
        foreach ($candidates as $base) {
            if (!is_dir($base)) { @mkdir($base, 0777, true); }
            if (!is_dir($base) || !is_writable($base)) { continue; }
            $path = $base . DIRECTORY_SEPARATOR . 'PR-' . preg_replace('/[^A-Za-z0-9_-]/','_', $pr) . '.pdf';
            try {
                $this->pdf()->generatePurchaseRequisitionToFile($meta, $items, $path);
                if (@is_file($path) && (@filesize($path) ?: 0) > 0) { $tmpFile = $path; $written = true; break; }
            } catch (\Throwable $ignored) { /* try next candidate */ }
        }
        if (!$written || $tmpFile === null) { throw new \RuntimeException('Failed to generate PR PDF'); }
        // Ensure message attachments columns (for prefill safety)
        $pdo = \App\Database\Connection::resolve();
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}

        // Update PR group status to mark forwarded for admin approval immediately
        try { $this->requests()->updateGroupStatus($pr, 'for_admin_approval', (int)($_SESSION['user_id'] ?? 0), 'Sent to Admin for Approval'); } catch (\Throwable $ignored) {}

        // Redirect user to the compose screen with prefilled recipient, subject, and auto-attachment
        $to = 0;
        try {
            $stAdm = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role = 'admin' ORDER BY user_id ASC LIMIT 1");
            $to = (int)($stAdm ? ($stAdm->fetchColumn() ?: 0) : 0);
        } catch (\Throwable $ignored) {}
        $subject = 'PR ' . $pr . ' • For Approval';
        $qs = http_build_query([
            'to' => $to > 0 ? $to : null,
            'subject' => $subject,
            'attach_name' => basename($tmpFile),
            'attach_path' => $tmpFile,
        ]);
        header('Location: /admin/messages' . ($qs !== '' ? ('?' . $qs) : ''));
            exit; // Hard-exit to guarantee redirect even if any output was buffered
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /manager/requests?error=' . $msg);
            exit; // Ensure we don't fall through and risk sending stray output
        }
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
 
