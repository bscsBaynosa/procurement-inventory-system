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
        // Enforce unique PO numbers to avoid collisions (best-effort; ignore if duplicates currently exist)
        try {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_purchase_orders_po_number_unique ON purchase_orders (po_number)");
        } catch (\Throwable $ignored) {}
        // Determine the correct reference column for purchase_orders (legacy installs may use po_id instead of id)
        $poRefCol = 'id';
        try {
            $hasId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'purchase_orders' AND column_name = 'id'")->fetchColumn();
            if (!$hasId) {
                $hasPoId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'purchase_orders' AND column_name = 'po_id'")->fetchColumn();
                if ($hasPoId) { $poRefCol = 'po_id'; }
            }
        } catch (\Throwable $e) { /* ignore and keep default 'id' */ }
        // purchase_order_items lines (reference detected column to avoid FK errors on legacy schemas)
        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
            id BIGSERIAL PRIMARY KEY,
            po_id BIGINT NOT NULL REFERENCES purchase_orders($poRefCol) ON DELETE CASCADE,
            description TEXT NOT NULL,
            unit VARCHAR(32) NOT NULL,
            qty INTEGER NOT NULL DEFAULT 0,
            unit_price NUMERIC(12,2) NOT NULL DEFAULT 0,
            line_total NUMERIC(12,2) NOT NULL DEFAULT 0
        )");
        // Add-on columns required by new PO module (idempotent).
        try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS pr_id BIGINT REFERENCES purchase_requests(request_id) ON DELETE SET NULL"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS prepared_by VARCHAR(255)"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS finance_officer VARCHAR(255)"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_name VARCHAR(255)"); } catch (\Throwable $e) {}
        // Optional mirror columns for signatures
        try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(255)"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS approved_by VARCHAR(255)"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS discount NUMERIC(12,2) DEFAULT 0"); } catch (\Throwable $e) {}
        // Legacy installs may predate pdf_path; add if missing (SELECT clauses must not break)
        try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS pdf_path TEXT"); } catch (\Throwable $e) {}
        // Legacy installs may also predate vendor_name; add silently for fallback supplier naming
        try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS vendor_name VARCHAR(255)"); } catch (\Throwable $e) {}
    }

    /** GET: Create PO form for a canvassing-approved PR */
    public function poCreate(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_GET['pr']) ? trim((string)$_GET['pr']) : '';
        if ($pr === '') { header('Location: /manager/requests'); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { header('Location: /manager/requests'); return; }
        // Require canvassing_approved (or allow PO retry after po_rejected)
        // Determine the most recent group status to avoid relying on an arbitrary row
        try {
            $pdo = \App\Database\Connection::resolve();
            $st = $pdo->prepare("SELECT status FROM purchase_requests WHERE pr_number = :pr ORDER BY updated_at DESC LIMIT 1");
            $st->execute(['pr' => $pr]);
            $groupStatus = (string)($st->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            // Fallback: use the first row's status
            $groupStatus = (string)($rows[0]['status'] ?? '');
        }
        // Broaden gating: allow after canvassing_submitted as well (awaiting admin approval) for draft PO preparation
        if (!in_array($groupStatus, ['canvassing_submitted','canvassing_approved','po_rejected'], true)) {
            header('Location: /manager/requests?error=' . rawurlencode('PO allowed only after canvassing has been submitted or approved'));
            return;
        }
        $this->ensurePoTables();
        // Load suppliers list (optional: narrow to previously selected suppliers)
        $pdo = \App\Database\Connection::resolve();
        $suppliers = $pdo->query("SELECT user_id, full_name FROM users WHERE is_active=TRUE AND role='supplier' ORDER BY full_name ASC")->fetchAll();
        // Prefill logic from PR + any previous PO attempt for this PR
        $poNext = '';
        try { $poNext = $this->requests()->getNextPoNumberPreview(); } catch (\Throwable $ignored) { $poNext = ''; }
        $prefillSupplierId = null; $prefillVendorName = ''; $prefillCenter = ''; $prefillTerms = '30 days'; $prefillAdminName='';
        // Center inferred from branch name if present
        $prefillCenter = (string)($rows[0]['branch_name'] ?? '');
        // Auto-pick lone active admin for admin_name prefill
        try {
            $prefillAdminName = (string)($pdo->query("SELECT full_name FROM users WHERE role='admin' AND is_active=TRUE ORDER BY created_at ASC LIMIT 1")->fetchColumn() ?: '');
        } catch (\Throwable $e) { $prefillAdminName=''; }
        // If a previous PO exists for this PR number, reuse its supplier/vendor meta to streamline retries
        try {
            $stPrev = $pdo->prepare("SELECT supplier_id, vendor_name, center, terms FROM purchase_orders WHERE pr_number = :pr ORDER BY created_at DESC LIMIT 1");
            $stPrev->execute(['pr' => $pr]);
            if ($prev = $stPrev->fetch()) {
                $prefillSupplierId = (int)($prev['supplier_id'] ?? 0) ?: null;
                $prefillVendorName = (string)($prev['vendor_name'] ?? '');
                if (!empty($prev['center'])) { $prefillCenter = (string)$prev['center']; }
                if (!empty($prev['terms'])) { $prefillTerms = (string)$prev['terms']; }
            }
        } catch (\Throwable $e) { /* ignore */ }
        // If no previous PO, optionally pre-select first supplier (if exactly one supplier active) and use its name as vendor_name
        if ($prefillSupplierId === null && count($suppliers) === 1) {
            $prefillSupplierId = (int)$suppliers[0]['user_id'];
            $prefillVendorName = (string)$suppliers[0]['full_name'];
        }
        // Attempt to detect canvass/quoted price columns dynamically for pre-filling item unit prices
        $candidatePriceCols = ['quoted_price','canvass_price','price','unit_price','approved_price'];
        $detectedPriceCol = null;
        foreach ($candidatePriceCols as $c) {
            try {
                $hasCol = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_requests' AND column_name=:c");
                $hasCol->execute(['c'=>$c]);
                if ($hasCol->fetchColumn()) { $detectedPriceCol = $c; break; }
            } catch (\Throwable $e) { /* ignore */ }
        }
        // Enrich rows with prefill_price if column exists and value numeric
        if ($detectedPriceCol) {
            foreach ($rows as &$r) {
                $val = $r[$detectedPriceCol] ?? null;
                $r['prefill_price'] = is_numeric($val) ? (float)$val : 0.0;
            }
            unset($r);
        }
        $this->render('procurement/po_create.php', [
            'pr' => $pr,
            'rows' => $rows,
            'suppliers' => $suppliers,
            'po_next' => $poNext,
            'prefill' => [
                'supplier_id' => $prefillSupplierId,
                'vendor_name' => $prefillVendorName,
                'center' => $prefillCenter,
                'terms' => $prefillTerms,
                'admin_name' => $prefillAdminName,
            ],
        ]);
    }

    /** POST: Create PO, generate PDF, and send to Admin for approval */
    public function poSubmit(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); return; }
        $this->ensurePoTables();
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $poNumber = isset($_POST['po_number']) ? trim((string)$_POST['po_number']) : '';
        if ($pr === '' || $supplierId <= 0) {
            $_SESSION['flash_error'] = 'Missing required fields (PR or Supplier).';
            header('Location: /procurement/po/create?pr=' . urlencode($pr) . '&error=Missing+fields'); return; }
        // If no PO number provided, generate one; else validate format
        if ($poNumber === '') {
            try { $poNumber = $this->requests()->generateNewPoNumber(); } catch (\Throwable $e) { $poNumber = ''; }
        } else {
            if (!preg_match('/^\d{7}$/', $poNumber)) { // Expect YYYY + 3-digit sequence e.g., 2025001
                $_SESSION['flash_error'] = 'Invalid PO number format. Expected YYYYNNN.';
                header('Location: /procurement/po/create?pr=' . urlencode($pr) . '&error=Invalid+PO+number+format+(expected+YYYYNNN)'); return;
            }
        }
        $vendorName = trim((string)($_POST['vendor_name'] ?? ''));
        $vendorAddress = trim((string)($_POST['vendor_address'] ?? ''));
        $vendorTin = trim((string)($_POST['vendor_tin'] ?? ''));
        $center = trim((string)($_POST['center'] ?? ''));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $terms = trim((string)($_POST['terms'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $discount = isset($_POST['discount']) ? (float)$_POST['discount'] : 0.0;
        $deliverTo = trim((string)($_POST['deliver_to'] ?? 'MHI Bldg., New York St., Brgy. Immaculate Concepcion, Cubao, Quezon City'));
        $lookFor = trim((string)($_POST['look_for'] ?? (string)($_SESSION['full_name'] ?? '')));
        $date = date('Y-m-d');
        // New personnel fields (required)
        $financeOfficer = trim((string)($_POST['finance_officer'] ?? ''));
        $adminName = trim((string)($_POST['admin_name'] ?? ''));
        if ($center === '' || $terms === '' || $financeOfficer === '' || $adminName === '') {
            $_SESSION['flash_error'] = 'Required fields missing (Center, Terms, Finance Officer, Admin Name).';
            header('Location: /procurement/po/create?pr=' . urlencode($pr) . '&error=Required+fields+missing'); return;
        }
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
    if (empty($items)) { $_SESSION['flash_error'] = 'Add at least one item.'; header('Location: /procurement/po/create?pr=' . urlencode($pr) . '&error=Add+at+least+one+item'); return; }
        $pdo = \App\Database\Connection::resolve();
        // Resolve pr_id for linkage
        $prId = null;
        try {
            $stPid = $pdo->prepare('SELECT request_id FROM purchase_requests WHERE pr_number = :pr LIMIT 1');
            $stPid->execute(['pr' => $pr]);
            $prId = $stPid->fetchColumn();
        } catch (\Throwable $ignored) {}
        // Insert PO
        // Guard against duplicate PO numbers race by retrying once if unique violation occurs
                $sqlH = 'SELECT po.*, ' . $selectPr . ', COALESCE(po.vendor_name, \'Unknown Supplier\') AS supplier_name FROM purchase_orders po' . $joinPrSql . ' WHERE po.' . $idCol . ' = :id';
        try {
            $ins->execute([
                'pr' => $pr,
                'prid' => $prId ?: null,
                'po' => $poNumber,
                'sid' => $supplierId,
                'vn' => $vendorName ?: null,
                'va' => $vendorAddress ?: null,
                'vt' => $vendorTin ?: null,
                'ce' => $center ?: null,
                'ref' => $reference ?: null,
                'te' => $terms ?: null,
                'no' => $notes ?: null,
                'dt' => $deliverTo ?: null,
                'lf' => $lookFor ?: null,
                'tot' => max(0.0, $total - $discount),
                'disc' => $discount,
                'uid' => (int)($_SESSION['user_id'] ?? 0),
                'prep' => (string)($_SESSION['full_name'] ?? ''),
                'fo' => $financeOfficer,
                'an' => $adminName,
                'rev' => $financeOfficer,
                'app' => $adminName,
            ]);
        } catch (\Throwable $e) {
            // If duplicate key on po_number, generate a fresh one and retry once
            if (stripos((string)$e->getMessage(), 'duplicate') !== false) {
                try { $poNumber = $this->requests()->generateNewPoNumber(); } catch (\Throwable $ignored) {}
                $ins->execute([
                    'pr' => $pr,
                    'prid' => $prId ?: null,
                    'po' => $poNumber,
                    'sid' => $supplierId,
                    'vn' => $vendorName ?: null,
                    'va' => $vendorAddress ?: null,
                    'vt' => $vendorTin ?: null,
                    'ce' => $center ?: null,
                    'ref' => $reference ?: null,
                    'te' => $terms ?: null,
                    'no' => $notes ?: null,
                    'dt' => $deliverTo ?: null,
                    'lf' => $lookFor ?: null,
                    'tot' => max(0.0, $total - $discount),
                    'disc' => $discount,
                    'uid' => (int)($_SESSION['user_id'] ?? 0),
                    'prep' => (string)($_SESSION['full_name'] ?? ''),
                    'fo' => $financeOfficer,
                    'an' => $adminName,
                    'rev' => $financeOfficer,
                    'app' => $adminName,
                ]);
            } else { throw $e; }
        }
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
            'discount' => $discount,
            'deliver_to' => $deliverTo,
            'look_for' => $lookFor,
            'prepared_by' => (string)($_SESSION['full_name'] ?? ''),
            'reviewed_by' => $financeOfficer,
            'approved_by' => $adminName,
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
        $_SESSION['flash_success'] = 'Purchase Order ' . htmlspecialchars($poNumber, ENT_QUOTES, 'UTF-8') . ' created and sent for Admin approval.';
        header('Location: /manager/requests?success=po_created');
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
        // Detect legacy purchase_orders schema differences (supplier column or primary key naming)
        $supplierCol = 'supplier_id';
        try {
            $hasSupplierId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='supplier_id'")->fetchColumn();
            if (!$hasSupplierId) {
                $hasSupplier = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='supplier'")->fetchColumn();
                if ($hasSupplier) { $supplierCol = 'supplier'; }
            }
        } catch (\Throwable $e) { /* ignore */ }
        $idCol = 'id';
        try {
            $hasId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='id'")->fetchColumn();
            if (!$hasId) {
                $hasPoId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='po_id'")->fetchColumn();
                if ($hasPoId) { $idCol = 'po_id'; }
            }
        } catch (\Throwable $e) { /* ignore */ }
        // Optional filters
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;
        $supplier = isset($_GET['supplier']) && $_GET['supplier'] !== '' ? (int)$_GET['supplier'] : null;
        $where = [];
        $params = [];
        if ($status !== null) { $where[] = 'po.status = :status'; $params['status'] = $status; }
        if ($supplier !== null) { $where[] = 'po.' . $supplierCol . ' = :sid'; $params['sid'] = $supplier; }
        // If legacy schema lacks supplier column entirely, skip join and show placeholder supplier name
        $joinUsers = true;
        try {
            $existsSupplierCol = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='" . $supplierCol . "'")->fetchColumn();
            if (!$existsSupplierCol) { $joinUsers = false; }
        } catch (\Throwable $e) { /* ignore */ }
        // Determine how to provide pr_number
        $selectPr = 'po.pr_number';
        $canJoinPR = false;
        try {
            $hasPoPrNumber = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='pr_number'")->fetchColumn();
            if (!$hasPoPrNumber) {
                $hasPoPrId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='pr_id'")->fetchColumn();
                $hasReqId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_requests' AND column_name='request_id'")->fetchColumn();
                $hasReqPrNumber = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_requests' AND column_name='pr_number'")->fetchColumn();
                if ($hasPoPrId && $hasReqId && $hasReqPrNumber) { $canJoinPR = true; $selectPr = 'pr.pr_number'; }
                else { $selectPr = "'N/A'"; }
            }
        } catch (\Throwable $e) { $selectPr = "'N/A'"; }
        // Determine total expression:
        // - If purchase_orders.total exists, prefer it but fall back to items sum
        // - If it doesn't exist, use items sum only (do NOT reference po.total)
        $itemsSumExpr = '(SELECT COALESCE(SUM(i.line_total),0) FROM purchase_order_items i WHERE i.po_id = po.' . $idCol . ')';
        $totalExpr = $itemsSumExpr; // default when po.total is absent
        try {
            $hasPoTotal = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='total'")->fetchColumn();
            if ($hasPoTotal) { $totalExpr = 'COALESCE(po.total, ' . $itemsSumExpr . ')'; }
        } catch (\Throwable $e) { /* keep default itemsSumExpr */ }
        // pdf_path may be absent on legacy schema; if so, expose NULL AS pdf_path to keep templates working
        $pdfPathSelect = 'po.pdf_path';
        try {
            $hasPdfPath = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='pdf_path'")->fetchColumn();
            if (!$hasPdfPath) { $pdfPathSelect = 'NULL AS pdf_path'; }
        } catch (\Throwable $e) { $pdfPathSelect = 'NULL AS pdf_path'; }
        // Build dynamic SELECT mapping legacy column names to expected aliases
        $sqlJoin = 'SELECT po.' . $idCol . ' AS id, ' . $selectPr . ' AS pr_number, po.po_number, po.status, ' . $totalExpr . ' AS total, ' . $pdfPathSelect . ', po.created_at, u.full_name AS supplier_name'
            . ' FROM purchase_orders po'
            . ' JOIN users u ON u.user_id = po.' . $supplierCol
            . ($canJoinPR ? ' LEFT JOIN purchase_requests pr ON pr.request_id = po.pr_id' : '');
        // For no-join path, avoid touching vendor_name entirely (legacy DBs may not have it). Use a constant placeholder.
        $vendorNameExpr = "'Unknown Supplier'";
        $sqlNoJoin = 'SELECT po.' . $idCol . ' AS id, ' . $selectPr . ' AS pr_number, po.po_number, po.status, ' . $totalExpr . ' AS total, ' . $pdfPathSelect . ', po.created_at, ' . $vendorNameExpr . ' AS supplier_name'
            . ' FROM purchase_orders po'
            . ($canJoinPR ? ' LEFT JOIN purchase_requests pr ON pr.request_id = po.pr_id' : '');
        // Apply filters
        $sqlJ = $sqlJoin; $sqlN = $sqlNoJoin;
        if ($where) { $sqlJ .= ' WHERE ' . implode(' AND ', $where); }
        // For no-join path, only keep status filter (supplier filter requires users join/column)
        $whereNoJoin = [];
        if ($status !== null) { $whereNoJoin[] = 'po.status = :status'; }
        if ($whereNoJoin) { $sqlN .= ' WHERE ' . implode(' AND ', $whereNoJoin); }
        $sqlJ .= ' ORDER BY po.created_at DESC';
        $sqlN .= ' ORDER BY po.created_at DESC';
        // Try join first; if it fails due to missing column, fallback to no-join
        try {
        $st = $pdo->prepare($sqlJ);
        $st->execute($params);
        $pos = $st->fetchAll();
        } catch (\Throwable $e) {
            $st = $pdo->prepare($sqlN);
            $params2 = [];
            if ($status !== null) { $params2['status'] = $status; }
            $st->execute($params2);
            $pos = $st->fetchAll();
        }
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
        // Detect legacy schema for id / supplier column mapping
        $supplierCol = 'supplier_id'; $idCol = 'id';
        try {
            $hasSupplierId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='supplier_id'")->fetchColumn();
            if (!$hasSupplierId) {
                $hasSupplier = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='supplier'")->fetchColumn();
                if ($hasSupplier) { $supplierCol = 'supplier'; }
            }
            $hasId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='id'")->fetchColumn();
            if (!$hasId) {
                $hasPoId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='po_id'")->fetchColumn();
                if ($hasPoId) { $idCol = 'po_id'; }
            }
        } catch (\Throwable $e) { /* ignore */ }
        // Prepare joins to also provide pr_number if missing
        $canJoinPR = false;
        try {
            $hasPoPrNumber = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='pr_number'")->fetchColumn();
            if (!$hasPoPrNumber) {
                $hasPoPrId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='pr_id'")->fetchColumn();
                $hasReqId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_requests' AND column_name='request_id'")->fetchColumn();
                $hasReqPrNumber = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_requests' AND column_name='pr_number'")->fetchColumn();
                if ($hasPoPrId && $hasReqId && $hasReqPrNumber) { $canJoinPR = true; }
            }
        } catch (\Throwable $e) { /* ignore */ }
        $selectPr = "COALESCE(po.pr_number, pr.pr_number) AS pr_number";
        if (!$canJoinPR) { $selectPr = "COALESCE(po.pr_number, 'N/A') AS pr_number"; }
        $joinPrSql = $canJoinPR ? ' LEFT JOIN purchase_requests pr ON pr.request_id = po.pr_id' : '';
        // Load header with supplier name
        $h = null;
        try {
            $sqlH = 'SELECT po.*, ' . $selectPr . ', u.full_name AS supplier_name FROM purchase_orders po JOIN users u ON u.user_id = po.' . $supplierCol . $joinPrSql . ' WHERE po.' . $idCol . ' = :id';
            $st = $pdo->prepare($sqlH);
            $st->execute(['id' => $id]);
            $h = $st->fetch();
        } catch (\Throwable $e) {
            // Fallback for legacy schemas without supplier column: no users join
                $sqlH = 'SELECT po.*, ' . $selectPr . ', COALESCE(po.vendor_name, \'Unknown Supplier\') AS supplier_name FROM purchase_orders po' . $joinPrSql . ' WHERE po.' . $idCol . ' = :id';
            $st = $pdo->prepare($sqlH);
            $st->execute(['id' => $id]);
            $h = $st->fetch();
        }
        if (!$h) { header('Location: /procurement/pos'); return; }
        // Load lines
        $lt = $pdo->prepare('SELECT description, unit, qty, unit_price, line_total FROM purchase_order_items WHERE po_id = :id ORDER BY id ASC');
        $lt->execute(['id' => $id]);
        $lines = $lt->fetchAll();
        $this->render('procurement/po_view.php', ['po' => $h, 'items' => $lines]);
    }

    /** GET: Regenerate PO PDF and stream/download (export) */
    public function poExport(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); return; }
        $this->ensurePoTables();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) { header('Location: /procurement/pos'); return; }
        $pdo = \App\Database\Connection::resolve();
        // Detect legacy schema for id / supplier column mapping
        $supplierCol = 'supplier_id'; $idCol = 'id';
        try {
            $hasSupplierId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='supplier_id'")->fetchColumn();
            if (!$hasSupplierId) {
                $hasSupplier = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='supplier'")->fetchColumn();
                if ($hasSupplier) { $supplierCol = 'supplier'; }
            }
            $hasId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='id'")->fetchColumn();
            if (!$hasId) {
                $hasPoId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='po_id'")->fetchColumn();
                if ($hasPoId) { $idCol = 'po_id'; }
            }
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $sqlH = 'SELECT po.*, ' . $selectPr . ', u.full_name AS supplier_name FROM purchase_orders po JOIN users u ON u.user_id = po.' . $supplierCol . $joinPrSql . ' WHERE po.' . $idCol . ' = :id';
            $st = $pdo->prepare($sqlH);
            $st->execute(['id' => $id]);
            $po = $st->fetch();
        } catch (\Throwable $e) {
            $sqlH = 'SELECT po.*, ' . $selectPr . ', COALESCE(po.vendor_name, \'Unknown Supplier\') AS supplier_name FROM purchase_orders po' . $joinPrSql . ' WHERE po.' . $idCol . ' = :id';
            $st = $pdo->prepare($sqlH);
            $st->execute(['id' => $id]);
            $po = $st->fetch();
        }
        if (!$po) { header('Location: /procurement/pos'); return; }
        $it = $pdo->prepare('SELECT description, unit, qty, unit_price, line_total FROM purchase_order_items WHERE po_id = :id ORDER BY id ASC');
        $it->execute(['id' => $id]);
        $rows = [];
        foreach ($it->fetchAll() as $r) {
            $rows[] = [
                'description' => (string)$r['description'],
                'unit' => (string)$r['unit'],
                'qty' => (int)$r['qty'],
                'unit_price' => (float)$r['unit_price'],
                'total' => (float)$r['line_total'],
            ];
        }
        $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . DIRECTORY_SEPARATOR . 'PO-' . preg_replace('/[^A-Za-z0-9_-]/','_', (string)$po['po_number']) . '.pdf';
        $this->pdf()->generatePurchaseOrderPDFToFile([
            'po_number' => (string)$po['po_number'],
            'date' => date('Y-m-d', strtotime((string)($po['created_at'] ?? date('Y-m-d')))),
            'vendor_name' => (string)($po['vendor_name'] ?? $po['supplier_name'] ?? ''),
            'vendor_address' => (string)($po['vendor_address'] ?? ''),
            'vendor_tin' => (string)($po['vendor_tin'] ?? ''),
            'reference' => (string)($po['reference'] ?? ''),
            'terms' => (string)($po['terms'] ?? ''),
            'center' => (string)($po['center'] ?? ''),
            'notes' => (string)($po['notes'] ?? ''),
            'discount' => (float)($po['discount'] ?? 0),
            'deliver_to' => (string)($po['deliver_to'] ?? ''),
            'look_for' => (string)($po['look_for'] ?? ''),
            'prepared_by' => (string)($po['prepared_by'] ?? ''),
            'reviewed_by' => (string)($po['reviewed_by'] ?? ''),
            'approved_by' => (string)($po['approved_by'] ?? ''),
            'items' => $rows,
        ], $file);
        // Update stored path (best-effort)
        try {
            $stmtUp = $pdo->prepare('UPDATE purchase_orders SET pdf_path = :p, updated_at = NOW() WHERE ' . $idCol . ' = :id');
            $stmtUp->execute(['p' => $file, 'id' => $id]);
        } catch (\Throwable $e) {}
        if (!is_file($file)) { $_SESSION['flash_error'] = 'Failed to generate PO PDF.'; header('Location: /procurement/po/view?id=' . $id); return; }
        $size = @filesize($file) ?: null;
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode('PO-' . (string)$po['po_number'] . '.pdf') . '"');
        if ($size !== null) { header('Content-Length: ' . (string)$size); }
        @readfile($file);
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
        // Build supplier category map (from supplier_items.category)
        $supCats = [];
        try {
            $stc = $pdo->query("SELECT DISTINCT supplier_id, COALESCE(NULLIF(TRIM(LOWER(category)), ''), 'uncategorized') AS cat FROM supplier_items");
            foreach ($stc->fetchAll() as $row) {
                $sid = (int)$row['supplier_id'];
                $cat = (string)$row['cat'];
                if (!isset($supCats[$sid])) { $supCats[$sid] = []; }
                $supCats[$sid][$cat] = true;
            }
        } catch (\Throwable $e) { $supCats = []; }
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
        // Prepare eligible suppliers per item_id based on inventory_items.category
        $eligibleByItem = [];
        foreach ($rows as $r) {
            $iid = (int)($r['item_id'] ?? 0); if ($iid <= 0) continue;
            $cat = strtolower(trim((string)($r['item_category'] ?? '')));
            if ($cat === '') $cat = 'uncategorized';
            $eligible = [];
            foreach ($suppliers as $s) {
                $sid = (int)$s['user_id'];
                if (isset($supCats[$sid])) {
                    $has = false; foreach ($supCats[$sid] as $c => $_) { if ($c === $cat) { $has = true; break; } }
                    if ($has) { $eligible[] = $sid; }
                }
            }
            // If no eligible based on mapping, fallback to all suppliers
            if (!$eligible) { foreach ($suppliers as $s) { $eligible[] = (int)$s['user_id']; } }
            $eligibleByItem[$iid] = $eligible;
        }
        $this->render('procurement/canvass_form.php', [ 'pr' => $pr, 'rows' => $rows, 'suppliers' => $suppliers, 'prices' => $prices, 'eligible' => $eligibleByItem ]);
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
        // Parse per-item supplier overrides (each item can have its own supplier subset)
        $itemSupMap = [];
        if (isset($_POST['item_suppliers']) && is_array($_POST['item_suppliers'])) {
            // Build a mapping from lower(item_name) and item_id to use for resolving numeric keys
            $nameByIdLower = [];
            foreach ($rows as $rr) { $iid0 = (int)($rr['item_id'] ?? 0); $nm0 = strtolower(trim((string)($rr['item_name'] ?? ''))); if ($iid0>0 && $nm0!=='') { $nameByIdLower[$iid0] = $nm0; } }
            foreach ($_POST['item_suppliers'] as $k => $arr) {
                if (!is_array($arr)) { continue; }
                $keyLower = null;
                if (ctype_digit((string)$k)) {
                    $iidK = (int)$k;
                    $keyLower = $nameByIdLower[$iidK] ?? null;
                } else {
                    $keyLower = strtolower(trim((string)$k));
                }
                if (!$keyLower) { continue; }
                $sids = array_values(array_unique(array_map('intval', $arr)));
                // Enforce between 3-5 when provided
                if (count($sids) >= 3) { $itemSupMap[$keyLower] = array_slice($sids, 0, 5); }
            }
        }
        // Fetch supplier names for union of global chosen and all per-item overrides
        $allSupIds = $chosen;
        foreach ($itemSupMap as $arr) { foreach ($arr as $sid) { if (!in_array($sid, $allSupIds, true)) { $allSupIds[] = $sid; } } }
        $allSupIds = array_values(array_unique(array_filter(array_map('intval', $allSupIds), static fn($v)=>$v>0)));
        if (empty($allSupIds)) { $allSupIds = $chosen; }
        // Fetch supplier names
        $inParams = implode(',', array_fill(0, count($allSupIds), '?'));
        $st = $pdo->prepare('SELECT user_id, full_name FROM users WHERE user_id IN (' . $inParams . ')');
        $st->execute($allSupIds);
        $map = [];
        foreach ($st->fetchAll() as $s) { $map[(int)$s['user_id']] = (string)$s['full_name']; }
        // Optional awarded vendor (must be among selected) — overall award (legacy/global)
        $awardedId = isset($_POST['awarded_to']) && $_POST['awarded_to'] !== '' ? (int)$_POST['awarded_to'] : null;
        $awardedName = null;
        if ($awardedId && in_array($awardedId, $chosen, true) && isset($map[$awardedId])) {
            $awardedName = $map[$awardedId];
        }
        // Per-item award map: item_key (lowercase) => supplier_id
        $awardMap = [];
        if (isset($_POST['item_award']) && is_array($_POST['item_award'])) {
            $nameByIdLower2 = [];
            foreach ($rows as $rr) { $iid0 = (int)($rr['item_id'] ?? 0); $nm0 = strtolower(trim((string)($rr['item_name'] ?? ''))); if ($iid0>0 && $nm0!=='') { $nameByIdLower2[$iid0] = $nm0; } }
            foreach ($_POST['item_award'] as $k => $sidVal) {
                $sid = (int)$sidVal; if ($sid <= 0) { continue; }
                $keyLower = null;
                if (ctype_digit((string)$k)) { $keyLower = $nameByIdLower2[(int)$k] ?? null; }
                else { $keyLower = strtolower(trim((string)$k)); }
                if (!$keyLower) { continue; }
                $awardMap[$keyLower] = $sid;
            }
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
            if ($names && $allSupIds) {
                $inSup = implode(',', array_fill(0, count($allSupIds), '?'));
                $inNames = implode(',', array_fill(0, count($names), '?'));
                $params = [];
                foreach ($allSupIds as $sid) { $params[] = (int)$sid; }
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
                    foreach ($allSupIds as $sid) { $p2[] = (int)$sid; }
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
                // Sum totals per supplier (based on global chosen only, to preserve legacy totals display)
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
        // Choose a writable directory (storage/pdf if available, else system temp) to avoid //storage paths on hosts without realpath
        $root = @realpath(__DIR__ . '/../../..') ?: null;
        $dirCandidates = [];
        if ($root) { $dirCandidates[] = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf'; }
        $dirCandidates[] = sys_get_temp_dir();
        $dir = null;
        foreach ($dirCandidates as $cand) {
            if (!is_dir($cand)) { @mkdir($cand, 0777, true); }
            if (is_dir($cand) && is_writable($cand)) { $dir = $cand; break; }
        }
        if ($dir === null) { $dir = '.'; }
        $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'PR-Canvass-' . preg_replace('/[^A-Za-z0-9_-]/','_', $pr) . '.pdf';
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
        // Build per-item canvassing matrix for 5-column table (Item | S1 | S2 | S3 | Awarded To)
        // If per-item supplier overrides exist, use those per row; else fall back to global $chosen.
        $canvassMatrix = (function() use ($rows, $chosen, $map, $prices, $awardMap, $itemSupMap) {
            $matrix = [];
            foreach ($rows as $r) {
                $nm = strtolower(trim((string)($r['item_name'] ?? '')));
                if ($nm === '') { continue; }
                $qty = (int)($r['quantity'] ?? 0);
                $unit = (string)($r['unit'] ?? '');
                $label = (string)($r['item_name'] ?? 'Item');
                if ($qty > 0) { $label .= ' × ' . $qty . ($unit !== '' ? (' ' . $unit) : ''); }
                // Determine per-row supplier ids (override or global), then derive names and prices aligned to 3 columns
                $rowSupIds = isset($itemSupMap[$nm]) && is_array($itemSupMap[$nm]) && count($itemSupMap[$nm]) >= 3
                    ? array_slice(array_values($itemSupMap[$nm]), 0, 3)
                    : array_slice($chosen, 0, 3);
                $rowSupNames = [];
                foreach ($rowSupIds as $sid) { $rowSupNames[] = isset($map[$sid]) ? (string)$map[$sid] : ''; }
                $rowPrices = [];
                for ($i=0;$i<3;$i++) {
                    $sid = $rowSupIds[$i] ?? 0;
                    $p = ($sid && isset($prices[$sid]) && isset($prices[$sid][$nm])) ? (float)$prices[$sid][$nm] : null;
                    $rowPrices[$i] = $p;
                }
                // Honor per-item manual award if provided and valid; else pick cheapest non-null index
                $awardIdx = -1;
                if (isset($awardMap[$nm])) {
                    $manualSid = (int)$awardMap[$nm];
                    for ($i=0;$i<3;$i++) {
                        if (($rowSupIds[$i] ?? 0) === $manualSid) {
                            if ($rowPrices[$i] !== null) { $awardIdx = $i; }
                            break;
                        }
                    }
                }
                if ($awardIdx < 0) {
                    $minVal = null;
                    for ($i=0;$i<3;$i++) { if ($rowPrices[$i] !== null) { $v=(float)$rowPrices[$i]; if ($minVal===null || $v<$minVal){$minVal=$v;$awardIdx=$i;} } }
                }
                $matrix[] = ['item' => $label, 'prices' => $rowPrices, 'award_index' => $awardIdx, 'supplier_names' => $rowSupNames];
            }
            return $matrix;
        })();

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
            'canvass_matrix' => $canvassMatrix,
            'signature_variant' => 'purchase_approval',
            'purchase_approved_by' => $purchaseApprovedBy,
            'purchase_approved_at' => $purchaseApprovedAt,
            // Explicitly render canvassing in the PR-Canvass PDF only
            'render_canvass' => true,
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
        if (!@is_file($file) || ((int)@filesize($file) <= 0)) {
            header('Location: /manager/requests/canvass?pr=' . urlencode($pr) . '&error=' . rawurlencode('Failed to write PR-Canvass PDF'));
            return;
        }
        // Persist selected suppliers, their IDs and computed totals for PR (for inclusion in PR PDF later)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                pr_number VARCHAR(32) PRIMARY KEY,
                supplier1 VARCHAR(255),
                supplier2 VARCHAR(255),
                supplier3 VARCHAR(255),
                supplier1_id BIGINT,
                supplier2_id BIGINT,
                supplier3_id BIGINT,
                total1 NUMERIC(14,2),
                total2 NUMERIC(14,2),
                total3 NUMERIC(14,2),
                awarded_to VARCHAR(255),
                approved_by VARCHAR(255),
                approved_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            // Ensure new columns exist in older installs
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS supplier1_id BIGINT"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS supplier2_id BIGINT"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS supplier3_id BIGINT"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS total1 NUMERIC(14,2)"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS total2 NUMERIC(14,2)"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS total3 NUMERIC(14,2)"); } catch (\Throwable $e) {}
            $names = array_values($map);
            $s1 = $names[0] ?? null; $s2 = $names[1] ?? null; $s3 = $names[2] ?? null;
            $sid1 = $chosen[0] ?? null; $sid2 = $chosen[1] ?? null; $sid3 = $chosen[2] ?? null;
            $t1 = ($sid1 && isset($totals[$sid1])) ? (float)$totals[$sid1] : null;
            $t2 = ($sid2 && isset($totals[$sid2])) ? (float)$totals[$sid2] : null;
            $t3 = ($sid3 && isset($totals[$sid3])) ? (float)$totals[$sid3] : null;
            // Upsert
            $pdo->prepare('INSERT INTO pr_canvassing (pr_number, supplier1, supplier2, supplier3, supplier1_id, supplier2_id, supplier3_id, total1, total2, total3, awarded_to)
                           VALUES (:pr,:s1,:s2,:s3,:sid1,:sid2,:sid3,:t1,:t2,:t3,:aw)
                           ON CONFLICT (pr_number) DO UPDATE SET supplier1 = EXCLUDED.supplier1, supplier2 = EXCLUDED.supplier2, supplier3 = EXCLUDED.supplier3,
                               supplier1_id = EXCLUDED.supplier1_id, supplier2_id = EXCLUDED.supplier2_id, supplier3_id = EXCLUDED.supplier3_id,
                               total1 = EXCLUDED.total1, total2 = EXCLUDED.total2, total3 = EXCLUDED.total3,
                               awarded_to = EXCLUDED.awarded_to, updated_at = NOW()')
                ->execute(['pr' => $pr, 's1' => $s1, 's2' => $s2, 's3' => $s3, 'sid1' => $sid1, 'sid2' => $sid2, 'sid3' => $sid3, 't1' => $t1, 't2' => $t2, 't3' => $t3, 'aw' => $awardedName]);
        } catch (\Throwable $ignored) {}

        // Respect user's explicit Awarded selection if provided and valid
        $awardedSelected = isset($_POST['awarded_to']) && $_POST['awarded_to'] !== '' ? (int)$_POST['awarded_to'] : 0;
        if ($awardedSelected && in_array($awardedSelected, $chosen, true) && isset($map[$awardedSelected])) {
            $awardedPreview = (string)$map[$awardedSelected];
        }
        // Final fallback for preview: if still blank, default to first chosen supplier to avoid an empty cell
        if ($awardedPreview === '' && !empty($chosen)) {
            $first = $chosen[0]; if (isset($map[$first])) { $awardedPreview = (string)$map[$first]; }
        }

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
        // (removed stray preview fallback block that referenced undefined variables; totals and awards are handled above)

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
            'canvass_matrix' => $canvassMatrix,
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
        // Prefill all Admin users as recipients
        $toList = [];
        try {
            $stAdm = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role = 'admin' ORDER BY user_id ASC");
            foreach ($stAdm->fetchAll() as $row) { $id = (int)$row['user_id']; if ($id > 0) { $toList[] = $id; } }
        } catch (\Throwable $ignored) {}
        $subject = 'PR ' . $pr . ' • PR - Canvass';
        $qs = http_build_query([
            // multiple recipients like to[]=1&to[]=2
            'to' => $toList ?: null,
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
    $prices = [];
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
                // Fuzzy fallback for items not matched by exact LOWER(name)
                $foundNames = [];
                foreach ($prices as $sid0 => $map0) { foreach ($map0 as $nm0 => $_) { $foundNames[$nm0] = true; } }
                $toFind = array_values(array_filter($names, static fn($nm) => !isset($foundNames[$nm])));
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

        // Build per-item canvassing matrix for the new 5-column table
        // Per-item award map from preview form (accepts item_id or name keys)
        $awardMap = [];
        if (isset($_POST['item_award']) && is_array($_POST['item_award'])) {
            $nameByIdLower = [];
            foreach ($rows as $rr) { $iid0 = (int)($rr['item_id'] ?? 0); $nm0 = strtolower(trim((string)($rr['item_name'] ?? ''))); if ($iid0>0 && $nm0!=='') { $nameByIdLower[$iid0] = $nm0; } }
            foreach ($_POST['item_award'] as $k => $sidVal) {
                $sid = (int)$sidVal; if ($sid <= 0) { continue; }
                $k2 = null;
                if (ctype_digit((string)$k)) { $k2 = $nameByIdLower[(int)$k] ?? null; }
                else { $k2 = strtolower(trim((string)$k)); }
                if (!$k2) { continue; }
                $awardMap[$k2] = $sid;
            }
        }
        // Include per-item supplier overrides in preview too
        $itemSupMap = [];
        if (isset($_POST['item_suppliers']) && is_array($_POST['item_suppliers'])) {
            $nameByIdLower = [];
            foreach ($rows as $rr) { $iid0 = (int)($rr['item_id'] ?? 0); $nm0 = strtolower(trim((string)($rr['item_name'] ?? ''))); if ($iid0>0 && $nm0!=='') { $nameByIdLower[$iid0] = $nm0; } }
            foreach ($_POST['item_suppliers'] as $k => $arr) {
                if (!is_array($arr)) { continue; }
                $key = null;
                if (ctype_digit((string)$k)) { $key = $nameByIdLower[(int)$k] ?? null; }
                else { $key = strtolower(trim((string)$k)); }
                if ($key === null || $key === '') { continue; }
                $sids = array_values(array_unique(array_map('intval', $arr)));
                if (count($sids) >= 3) { $itemSupMap[$key] = array_slice($sids, 0, 5); }
            }
        }
        $canvassMatrix = (function() use ($rows, $chosen, $prices, $awardMap, $itemSupMap, $map) {
            $matrix = [];
            foreach ($rows as $r) {
                $nm = strtolower(trim((string)($r['item_name'] ?? '')));
                if ($nm === '') { continue; }
                $qty = (int)($r['quantity'] ?? 0);
                $unit = (string)($r['unit'] ?? '');
                $label = (string)($r['item_name'] ?? 'Item');
                if ($qty > 0) { $label .= ' × ' . $qty . ($unit !== '' ? (' ' . $unit) : ''); }
                $rowSupIds = isset($itemSupMap[$nm]) && is_array($itemSupMap[$nm]) && count($itemSupMap[$nm]) >= 3
                    ? array_slice(array_values($itemSupMap[$nm]), 0, 3)
                    : array_slice($chosen, 0, 3);
                $rowSupNames = [];
                foreach ($rowSupIds as $sid) { $rowSupNames[] = isset($map[$sid]) ? (string)$map[$sid] : ''; }
                $rowPrices = [];
                for ($i=0;$i<3;$i++) {
                    $sid = $rowSupIds[$i] ?? 0;
                    $p = ($sid && isset($prices[$sid]) && isset($prices[$sid][$nm])) ? (float)$prices[$sid][$nm] : null;
                    $rowPrices[$i] = $p;
                }
                // If a manual per-item selection was provided and it matches one of the chosen suppliers with a price, honor it
                $awardIdx = -1;
                if (isset($awardMap[$nm])) {
                    $manualSid = (int)$awardMap[$nm];
                    for ($i=0;$i<3;$i++) {
                        if (($rowSupIds[$i] ?? 0) === $manualSid) {
                            if ($rowPrices[$i] !== null) { $awardIdx = $i; }
                            break;
                        }
                    }
                }
                if ($awardIdx < 0) {
                    $minVal = null;
                    for ($i=0;$i<3;$i++) { if ($rowPrices[$i] !== null) { $v=(float)$rowPrices[$i]; if ($minVal===null || $v<$minVal){$minVal=$v;$awardIdx=$i;} } }
                }
                $matrix[] = ['item' => $label, 'prices' => $rowPrices, 'award_index' => $awardIdx, 'supplier_names' => $rowSupNames];
            }
            return $matrix;
        })();

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
            // Prefer preview value (user selection/cheapest); else DB; else first chosen
            'awarded_to' => (function() use ($pdo, $pr, $awardedPreview, $chosen, $map) {
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
                    if ($awardedPreview !== '') { return (string)$awardedPreview; }
                    if ($v) { return (string)$v; }
                    if (!empty($chosen)) { $first = $chosen[0]; if (isset($map[$first])) { return (string)$map[$first]; } }
                    return '';
                } catch (\Throwable $e) { return ''; }
            })(),
            'canvass_totals' => $totalsPreview,
            'canvass_matrix' => $canvassMatrix,
            'signature_variant' => 'purchase_approval',
            'purchase_approved_by' => $purchaseApprovedBy,
            'purchase_approved_at' => $purchaseApprovedAt,
            // Explicitly render canvassing in the preview PDF only
            'render_canvass' => true,
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
        // Store preview selection in session so PR download can mirror the same suppliers/totals before final submit
        if (!isset($_SESSION['canvass_preview_data']) || !is_array($_SESSION['canvass_preview_data'])) { $_SESSION['canvass_preview_data'] = []; }
        $_SESSION['canvass_preview_data'][$pr] = [
            'at' => time(),
            // Supplier names in the same order as the chosen ids
            'supplier_names' => (function() use ($chosen, $map) {
                $out = [];
                foreach ($chosen as $sid) { if (isset($map[$sid])) { $out[] = $map[$sid]; } }
                return array_slice($out, 0, 3);
            })(),
            'totals' => $totalsPreview,
            'awarded_to' => $awardedPreview,
            // Matrix may include per-row supplier_names overriding the global 3; PDF will respect this
            'matrix' => $canvassMatrix,
        ];

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

    /**
     * POST (AJAX): Return live supplier quotes for each requested item.
     * Input JSON: { pr_number: string, item_keys: [string], suppliers: [int] }
     * Response JSON: { prices: { supplier_id: { item_key: price } }, awarded: { item_key: [supplier_ids_with_min] } }
     */
    public function canvassQuotesApi(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { http_response_code(403); echo json_encode(['error'=>'forbidden']); return; }
        header('Content-Type: application/json');
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) { http_response_code(400); echo json_encode(['error'=>'invalid_json']); return; }
        $pr = isset($payload['pr_number']) ? trim((string)$payload['pr_number']) : '';
        $itemKeys = isset($payload['item_keys']) && is_array($payload['item_keys']) ? array_values(array_filter(array_map(static fn($v)=>trim(strtolower((string)$v)), $payload['item_keys']), static fn($v)=>$v!=='')) : [];
        $supplierIds = isset($payload['suppliers']) && is_array($payload['suppliers']) ? array_values(array_unique(array_map('intval', $payload['suppliers']))) : [];
        if ($pr === '' || empty($itemKeys) || count($supplierIds) < 1) { http_response_code(400); echo json_encode(['error'=>'missing_fields']); return; }
        // Load PR rows for quantity context
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { http_response_code(404); echo json_encode(['error'=>'pr_not_found']); return; }
        // Build quantity map per normalized item key
        $qtyMap = [];
        foreach ($rows as $r) {
            $k = strtolower(trim((string)($r['item_name'] ?? '')));
            if ($k==='') { continue; }
            $qtyMap[$k] = ($qtyMap[$k] ?? 0) + (int)($r['quantity'] ?? 0);
        }
        // Fetch direct matches first
        $pdo = \App\Database\Connection::resolve();
        $prices = [];
        try {
            $inSup = implode(',', array_fill(0, count($supplierIds), '?'));
            $inItems = implode(',', array_fill(0, count($itemKeys), '?'));
            $params = [];
            foreach ($supplierIds as $sid) { $params[] = (int)$sid; }
            foreach ($itemKeys as $nm) { $params[] = $nm; }
            $sql = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE supplier_id IN (' . $inSup . ') AND LOWER(name) IN (' . $inItems . ')';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rowsMatch = $st->fetchAll();
            foreach ($rowsMatch as $it) {
                $sid = (int)$it['supplier_id'];
                $lname = (string)$it['lname'];
                $base = (float)($it['price'] ?? 0);
                $ppp = max(1, (int)($it['pieces_per_package'] ?? 1));
                $neededPk = $ppp > 0 ? (int)ceil((int)($qtyMap[$lname] ?? 0) / $ppp) : 0;
                $best = $base;
                if ($neededPk > 0) {
                    try {
                        $tq = $pdo->prepare('SELECT min_packages, max_packages, price_per_package FROM supplier_item_price_tiers WHERE supplier_item_id = :id ORDER BY min_packages ASC');
                        $tq->execute(['id' => (int)$it['id']]);
                        foreach ($tq->fetchAll() as $t) {
                            $min = (int)$t['min_packages'];
                            $max = $t['max_packages'] !== null ? (int)$t['max_packages'] : null;
                            if ($neededPk >= $min && ($max === null || $neededPk <= $max)) { $best = min($best, (float)$t['price_per_package']); }
                        }
                    } catch (\Throwable $e) {}
                }
                if (!isset($prices[$sid])) { $prices[$sid] = []; }
                if (!isset($prices[$sid][$lname]) || $best < $prices[$sid][$lname]) { $prices[$sid][$lname] = $best; }
            }
            // Fuzzy fallback per missing item using token ILIKE matching
            $found = [];
            foreach ($prices as $sid0=>$m0){ foreach ($m0 as $nm0=>$_){ $found[$nm0]=true; } }
            $missing = array_values(array_filter($itemKeys, static fn($k)=>!isset($found[$k])));
            foreach ($missing as $miss) {
                $tokens = preg_split('/[^a-z0-9]+/i', (string)$miss) ?: [];
                $tokens = array_values(array_filter(array_map('strtolower', $tokens), static fn($t)=>strlen($t)>=3));
                // Expand composite words like "bondpaper" to increase match likelihood
                $extra = [];
                foreach ($tokens as $tk) {
                    if (strpos($tk, 'bondpaper') !== false) { $extra[] = 'bond'; $extra[] = 'paper'; }
                }
                $tokens = array_values(array_unique(array_merge($tokens, $extra)));
                if (!$tokens) { continue; }
                $tokens = array_slice($tokens, 0, 4);
                $params2 = [];
                foreach ($supplierIds as $sid) { $params2[] = (int)$sid; }
                $likeConds = [];
                foreach ($tokens as $tk) { $likeConds[] = 'LOWER(name) ILIKE ?'; $params2[] = '%' . $tk . '%'; }
                $conds = ['supplier_id IN (' . $inSup . ')', '(' . implode(' OR ', $likeConds) . ')'];
                $sql2 = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE ' . implode(' AND ', $conds);
                $st2 = $pdo->prepare($sql2);
                $st2->execute($params2);
                foreach ($st2->fetchAll() as $it) {
                    $sid = (int)$it['supplier_id'];
                    $base = (float)($it['price'] ?? 0);
                    $lnameCanonical = $miss; // map under requested key
                    $ppp = max(1, (int)($it['pieces_per_package'] ?? 1));
                    $neededPk = $ppp > 0 ? (int)ceil((int)($qtyMap[$lnameCanonical] ?? 0) / $ppp) : 0;
                    $best = $base;
                    if ($neededPk > 0) {
                        try {
                            $tq = $pdo->prepare('SELECT min_packages, max_packages, price_per_package FROM supplier_item_price_tiers WHERE supplier_item_id = :id ORDER BY min_packages ASC');
                            $tq->execute(['id' => (int)$it['id']]);
                            foreach ($tq->fetchAll() as $t) {
                                $min = (int)$t['min_packages'];
                                $max = $t['max_packages'] !== null ? (int)$t['max_packages'] : null;
                                if ($neededPk >= $min && ($max === null || $neededPk <= $max)) { $best = min($best, (float)$t['price_per_package']); }
                            }
                        } catch (\Throwable $e) {}
                    }
                    if (!isset($prices[$sid])) { $prices[$sid] = []; }
                    if (!isset($prices[$sid][$lnameCanonical]) || $best < $prices[$sid][$lnameCanonical]) { $prices[$sid][$lnameCanonical] = $best; }
                }
            }
        } catch (\Throwable $e) { /* ignore, best-effort */ }
        // Build awarded map: for each item key, list supplier IDs with min price
        $awarded = [];
        foreach ($itemKeys as $k) {
            $min = null; $ids = [];
            foreach ($supplierIds as $sid) {
                if (isset($prices[$sid]) && isset($prices[$sid][$k])) {
                    $p = (float)$prices[$sid][$k];
                    if ($min === null || $p < $min - 1e-9) { $min = $p; $ids = [$sid]; }
                    elseif ($min !== null && abs($p - $min) < 1e-9) { $ids[] = $sid; }
                }
            }
            if ($min !== null) { $awarded[$k] = $ids; }
        }
        echo json_encode(['prices'=>$prices,'awarded'=>$awarded]);
    }

    /**
     * POST (AJAX): Return live supplier quotes by item_id (preferred, unambiguous per-row addressing).
     * Input JSON: { pr_number: string, item_ids: [int], suppliers: [int] }
     * Response JSON: { prices: { supplier_id: { item_id: price } }, awarded: { item_id: [supplier_ids_with_min] } }
     */
    public function canvassQuotesByIdApi(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error'=>'forbidden']); return; }
        header('Content-Type: application/json');
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) { http_response_code(400); echo json_encode(['error'=>'invalid_json']); return; }
        $pr = isset($payload['pr_number']) ? trim((string)$payload['pr_number']) : '';
        $itemIds = isset($payload['item_ids']) && is_array($payload['item_ids']) ? array_values(array_unique(array_map('intval', $payload['item_ids']))) : [];
        $supplierIds = isset($payload['suppliers']) && is_array($payload['suppliers']) ? array_values(array_unique(array_map('intval', $payload['suppliers']))) : [];
        if ($pr === '' || empty($itemIds) || empty($supplierIds)) { http_response_code(400); echo json_encode(['error'=>'missing_fields']); return; }
        // Load PR rows to map item_id -> name (lowercased) and quantities
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { http_response_code(404); echo json_encode(['error'=>'pr_not_found']); return; }
        $idToName = []; $qtyById = [];
        foreach ($rows as $r) {
            $iid = (int)($r['item_id'] ?? 0); if ($iid <= 0) continue;
            $nm = strtolower(trim((string)($r['item_name'] ?? '')));
            if ($nm === '') continue;
            if (!isset($idToName[$iid])) { $idToName[$iid] = $nm; }
            $qtyById[$iid] = ($qtyById[$iid] ?? 0) + (int)($r['quantity'] ?? 0);
        }
        // Prepare lookups by canonical LOWER(name), but return keyed by item_id
        $names = [];
        foreach ($itemIds as $iid) { if (isset($idToName[$iid])) { $names[$iid] = $idToName[$iid]; } }
        if (empty($names)) { echo json_encode(['prices'=>[], 'awarded'=>[]]); return; }
        $pdo = \App\Database\Connection::resolve();
        $prices = [];
        try {
            $inSup = implode(',', array_fill(0, count($supplierIds), '?'));
            $inNames = implode(',', array_fill(0, count(array_values(array_unique(array_values($names)))), '?'));
            $params = [];
            foreach ($supplierIds as $sid) { $params[] = (int)$sid; }
            foreach (array_values(array_unique(array_values($names))) as $nm) { $params[] = $nm; }
            $sql = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE supplier_id IN (' . $inSup . ') AND LOWER(name) IN (' . $inNames . ')';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $byName = [];
            foreach ($st->fetchAll() as $it) {
                $sid = (int)$it['supplier_id'];
                $lname = (string)$it['lname'];
                $base = (float)($it['price'] ?? 0);
                $ppp = max(1, (int)($it['pieces_per_package'] ?? 1));
                // For each item_id with this name, compute needed packages and best tier price
                foreach ($names as $iid => $nm) {
                    if ($nm !== $lname) continue;
                    $needPk = $ppp > 0 ? (int)ceil((int)($qtyById[$iid] ?? 0) / $ppp) : 0;
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
                    if (!isset($prices[$sid][$iid]) || $best < $prices[$sid][$iid]) { $prices[$sid][$iid] = $best; }
                }
            }
            // Fuzzy fallback per item for missing name matches
            $haveForId = [];
            foreach ($prices as $sid0 => $pm0) { foreach ($pm0 as $iid0 => $_) { $haveForId[$iid0] = true; } }
            $missingIids = array_values(array_filter($itemIds, static fn($id)=>!isset($haveForId[$id]) && isset($names[$id])));
            foreach ($missingIids as $iid) {
                $nm = $names[$iid];
                $tokens = preg_split('/[^a-z0-9]+/i', (string)$nm) ?: [];
                $tokens = array_values(array_filter(array_map('strtolower', $tokens), static fn($t)=>strlen($t)>=3));
                // Expand composite words like "bondpaper" to increase match likelihood
                $extra = [];
                foreach ($tokens as $tk) {
                    if (strpos($tk, 'bondpaper') !== false) { $extra[] = 'bond'; $extra[] = 'paper'; }
                }
                $tokens = array_values(array_unique(array_merge($tokens, $extra)));
                if (!$tokens) { continue; }
                $tokens = array_slice($tokens, 0, 4);
                $p2 = [];
                foreach ($supplierIds as $sid) { $p2[] = (int)$sid; }
                $likeConds = [];
                foreach ($tokens as $tk) { $likeConds[] = 'LOWER(name) ILIKE ?'; $p2[] = '%' . $tk . '%'; }
                $conds = ['supplier_id IN (' . $inSup . ')', '(' . implode(' OR ', $likeConds) . ')'];
                $sql2 = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE ' . implode(' AND ', $conds);
                $st2 = $pdo->prepare($sql2);
                $st2->execute($p2);
                foreach ($st2->fetchAll() as $it) {
                    $sid = (int)$it['supplier_id'];
                    $base = (float)($it['price'] ?? 0);
                    $ppp = max(1, (int)($it['pieces_per_package'] ?? 1));
                    $needPk = $ppp > 0 ? (int)ceil((int)($qtyById[$iid] ?? 0) / $ppp) : 0;
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
                    if (!isset($prices[$sid][$iid]) || $best < $prices[$sid][$iid]) { $prices[$sid][$iid] = $best; }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }
        // Build awarded map keyed by item_id
        $awarded = [];
        foreach ($itemIds as $iid) {
            $min = null; $ids = [];
            foreach ($supplierIds as $sid) {
                if (isset($prices[$sid]) && isset($prices[$sid][$iid])) {
                    $p = (float)$prices[$sid][$iid];
                    if ($min === null || $p < $min - 1e-9) { $min = $p; $ids = [$sid]; }
                    elseif ($min !== null && abs($p - $min) < 1e-9) { $ids[] = $sid; }
                }
            }
            if ($min !== null) { $awarded[$iid] = $ids; }
        }
        echo json_encode(['prices' => $prices, 'awarded' => $awarded]);
    }

    /**
     * POST (AJAX): Return quotes for a single item_id from supplier_quotes table.
     * Input JSON: { item_id: int, supplier_ids: [int] }
     * Response JSON: { prices: { supplier_id: price }, awarded: supplier_id|null }
     */
    public function canvassItemQuotesApi(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error'=>'forbidden']); return; }
        header('Content-Type: application/json');
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) { http_response_code(400); echo json_encode(['error'=>'invalid_json']); return; }
        $itemId = isset($payload['item_id']) ? (int)$payload['item_id'] : 0;
        $supplierIds = isset($payload['supplier_ids']) && is_array($payload['supplier_ids']) ? array_values(array_unique(array_map('intval', $payload['supplier_ids']))) : [];
        if ($itemId <= 0 || empty($supplierIds)) { http_response_code(400); echo json_encode(['error'=>'missing_fields']); return; }
        $pdo = \App\Database\Connection::resolve();
        // Ensure table exists
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_quotes (
                id BIGSERIAL PRIMARY KEY,
                item_id BIGINT NOT NULL REFERENCES inventory_items(item_id) ON DELETE CASCADE,
                supplier_id BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
                price NUMERIC(14,2) NOT NULL,
                currency VARCHAR(8),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            // Convenience index for filtering
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_supplier_quotes_item_supplier ON supplier_quotes(item_id, supplier_id)");
        } catch (\Throwable $e) {}
        $in = implode(',', array_fill(0, count($supplierIds), '?'));
        $params = [$itemId];
        foreach ($supplierIds as $sid) { $params[] = (int)$sid; }
        $sql = "SELECT supplier_id, price FROM supplier_quotes WHERE item_id = ? AND supplier_id IN ($in)";
        $prices = [];
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll() as $r) { $prices[(int)$r['supplier_id']] = (float)$r['price']; }
        } catch (\Throwable $e) { /* ignore */ }
        // Fallback: if some suppliers have no explicit quotes, try supplier_items fuzzy by inventory item name
        $missing = array_values(array_filter($supplierIds, static fn($sid)=>!isset($prices[$sid])));
        if ($missing) {
            try {
                // Get inventory item name and quantity context for potential tiering
                $nm = (string)$pdo->prepare('SELECT LOWER(name) FROM inventory_items WHERE item_id = :id')->execute(['id'=>$itemId]) && ($tmp=$pdo->query('SELECT LOWER(name) FROM inventory_items WHERE item_id = '.(int)$itemId.' LIMIT 1')->fetchColumn()) ? (string)$tmp : '';
                $nm = trim($nm);
                if ($nm !== '') {
                    $tokens = preg_split('/[^a-z0-9]+/i', $nm) ?: [];
                    $tokens = array_values(array_filter(array_map('strtolower',$tokens), static fn($t)=>strlen($t)>=3));
                    if ($tokens) {
                        $likeConds = [];
                        $p2 = [];
                        foreach ($missing as $sid) { $p2[] = (int)$sid; }
                        foreach ($tokens as $tk) { $likeConds[] = 'LOWER(name) ILIKE ?'; $p2[] = '%'.$tk.'%'; }
                        $sql2 = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE supplier_id IN ('.implode(',', array_fill(0, count($missing), '?')).') AND (' . implode(' OR ', $likeConds) . ')';
                        $st2 = $pdo->prepare($sql2);
                        $st2->execute($p2);
                        foreach ($st2->fetchAll() as $it) {
                            $sid = (int)$it['supplier_id'];
                            if (!in_array($sid, $missing, true)) { continue; }
                            $base = (float)($it['price'] ?? 0);
                            if (!isset($prices[$sid]) || $base < $prices[$sid]) { $prices[$sid] = $base; }
                        }
                    }
                }
            } catch (\Throwable $e) { /* ignore */ }
        }
        // Pick cheapest among provided suppliers
        $awarded = null; $min = null;
        foreach ($supplierIds as $sid) {
            if (!isset($prices[$sid])) { continue; }
            $p = (float)$prices[$sid];
            if ($min === null || $p < $min - 1e-9) { $min = $p; $awarded = $sid; }
        }
        echo json_encode(['prices' => $prices, 'awarded' => $awarded]);
    }

    /**
     * POST (AJAX): Persist a generated canvass sheet with an auto ID CYYYYNNN and awarded suppliers per item.
     * Input JSON: { pr_number: string, selections: { item_key: [supplier_ids] }, awards: { item_key: supplier_id }, suppliers: [int] }
     * Response JSON: { canvass_id: string, status: 'ok' }
     */
    public function canvassStore(): void
    {
        if (!$this->auth()->isAuthenticated() || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { http_response_code(403); echo json_encode(['error'=>'forbidden']); return; }
        header('Content-Type: application/json');
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) { http_response_code(400); echo json_encode(['error'=>'invalid_json']); return; }
        $pr = isset($payload['pr_number']) ? trim((string)$payload['pr_number']) : '';
        $selections = isset($payload['selections']) && is_array($payload['selections']) ? $payload['selections'] : [];
        $awards = isset($payload['awards']) && is_array($payload['awards']) ? $payload['awards'] : [];
        $suppliers = isset($payload['suppliers']) && is_array($payload['suppliers']) ? array_values(array_unique(array_map('intval',$payload['suppliers']))) : [];
        if ($pr === '' || count($suppliers) < 3) { http_response_code(400); echo json_encode(['error'=>'missing_fields']); return; }
        // Generate canvass id CYYYYNNN (sequence table)
        $pdo = \App\Database\Connection::resolve();
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS canvass_sequences (calendar_year INTEGER PRIMARY KEY, last_value INTEGER NOT NULL DEFAULT 0, updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())"); } catch (\Throwable $e) {}
        $year = (int)date('Y');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO canvass_sequences (calendar_year,last_value) VALUES (:y,0) ON CONFLICT (calendar_year) DO NOTHING')->execute(['y'=>$year]);
            $st = $pdo->prepare('UPDATE canvass_sequences SET last_value = last_value + 1, updated_at = NOW() WHERE calendar_year = :y RETURNING last_value');
            $st->execute(['y'=>$year]);
            $seq = (int)$st->fetchColumn();
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); $seq = 1; }
        $canvassId = 'C' . sprintf('%04d%03d', $year, $seq);
        // Create storage table
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing_details (\n                canvass_id VARCHAR(16) PRIMARY KEY,\n                pr_number VARCHAR(32) NOT NULL,\n                suppliers JSONB NOT NULL,\n                selections JSONB,\n                awards JSONB,\n                created_by BIGINT,\n                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()\n            )");
        } catch (\Throwable $e) {}
        // Upsert (allow re-generation) into pr_canvassing_details
        try {
            $stmt = $pdo->prepare('INSERT INTO pr_canvassing_details (canvass_id, pr_number, suppliers, selections, awards, created_by) VALUES (:cid,:pr,:sup,:sel,:awd,:uid)\n                ON CONFLICT (canvass_id) DO UPDATE SET suppliers=EXCLUDED.suppliers, selections=EXCLUDED.selections, awards=EXCLUDED.awards, created_at=NOW()');
            $stmt->execute([
                'cid'=>$canvassId,
                'pr'=>$pr,
                'sup'=>json_encode($suppliers),
                'sel'=>json_encode($selections),
                'awd'=>json_encode($awards),
                'uid'=>(int)($_SESSION['user_id'] ?? 0)
            ]);
            // Also persist normalized per-item rows in pr_canvassing_items for analytics and PO prefill
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing_items (
                    id BIGSERIAL PRIMARY KEY,
                    canvass_id VARCHAR(16) NOT NULL,
                    pr_number VARCHAR(32) NOT NULL,
                    item_id BIGINT NOT NULL,
                    item_name VARCHAR(255),
                    suppliers JSONB,          -- global supplier_ids used
                    selected_suppliers JSONB, -- per-item supplier_ids (override), if any
                    quotes JSONB,             -- { supplier_id: price }
                    awarded_supplier_id BIGINT,
                    awarded_price NUMERIC(14,2),
                    created_by BIGINT,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )");
            } catch (\Throwable $e) {}
            // Load PR rows to map item_id and names/quantities
            $rows = $this->requests()->getGroupDetails($pr);
            $itemIds = [];
            $idToName = []; $qtyById = [];
            foreach ($rows as $r) {
                $iid = (int)($r['item_id'] ?? 0); if ($iid <= 0) continue;
                $itemIds[] = $iid;
                $nm = (string)($r['item_name'] ?? '');
                if (!isset($idToName[$iid])) { $idToName[$iid] = $nm; }
                $qtyById[$iid] = ($qtyById[$iid] ?? 0) + (int)($r['quantity'] ?? 0);
            }
            $itemIds = array_values(array_unique($itemIds));
            // Normalize selections/awards: keys may be item_id (numeric) or item_key (name)
            $selById = [];
            foreach ($selections as $k => $arr) {
                $kid = ctype_digit((string)$k) ? (int)$k : null;
                if ($kid === null) {
                    // map name to id
                    $lower = strtolower(trim((string)$k));
                    foreach ($idToName as $iid => $nm) { if (strtolower(trim($nm)) === $lower) { $kid = $iid; break; } }
                }
                if ($kid) { $selById[$kid] = array_values(array_unique(array_map('intval', (array)$arr))); }
            }
            $awardById = [];
            foreach ($awards as $k => $sid) {
                $kid = ctype_digit((string)$k) ? (int)$k : null;
                if ($kid === null) {
                    $lower = strtolower(trim((string)$k));
                    foreach ($idToName as $iid => $nm) { if (strtolower(trim($nm)) === $lower) { $kid = $iid; break; } }
                }
                if ($kid) { $awardById[$kid] = (int)$sid; }
            }
            // Compute quotes using the same logic as canvassQuotesByIdApi for the union of global suppliers and per-item overrides
            $pdo->beginTransaction();
            try {
                // Clear previous rows for this canvass_id to keep latest state
                $pdo->prepare('DELETE FROM pr_canvassing_items WHERE canvass_id = :cid')->execute(['cid' => $canvassId]);
                // Build name map lower->ids for quick matching
                $lowerById = [];
                foreach ($idToName as $iid => $nm) { $lowerById[$iid] = strtolower(trim($nm)); }
                if ($itemIds && $suppliers) {
                    $inSup = implode(',', array_fill(0, count($suppliers), '?'));
                    $uniqueNames = array_values(array_unique(array_values($lowerById)));
                    if ($uniqueNames) {
                        $inNames = implode(',', array_fill(0, count($uniqueNames), '?'));
                        $params = [];
                        foreach ($suppliers as $sid) { $params[] = (int)$sid; }
                        foreach ($uniqueNames as $nm) { $params[] = $nm; }
                        $sql = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE supplier_id IN (' . $inSup . ') AND LOWER(name) IN (' . $inNames . ')';
                        $st = $pdo->prepare($sql);
                        $st->execute($params);
                        // Index rows by name for use per item
                        $byName = [];
                        foreach ($st->fetchAll() as $it) { $byName[(string)$it['lname']][] = $it; }
                        $ins = $pdo->prepare('INSERT INTO pr_canvassing_items (canvass_id, pr_number, item_id, item_name, suppliers, selected_suppliers, quotes, awarded_supplier_id, awarded_price, created_by) VALUES (:cid,:pr,:iid,:nm,:sup,:sel,:qt,:aw,:ap,:uid)');
                        foreach ($itemIds as $iid) {
                            $lname = $lowerById[$iid] ?? null; if (!$lname) continue;
                            $needQty = (int)($qtyById[$iid] ?? 0);
                            $pp = [];
                            if (isset($byName[$lname])) {
                                foreach ($byName[$lname] as $it) {
                                    $sid = (int)$it['supplier_id'];
                                    $base = (float)($it['price'] ?? 0);
                                    $ppp = max(1, (int)($it['pieces_per_package'] ?? 1));
                                    $needPk = $ppp > 0 ? (int)ceil($needQty / $ppp) : 0;
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
                                    // Only keep for suppliers in our chosen list
                                    if (in_array($sid, $suppliers, true)) {
                                        if (!isset($pp[$sid]) || $best < $pp[$sid]) { $pp[$sid] = $best; }
                                    }
                                }
                            }
                            // Determine per-item selected suppliers and awarded
                            $sel = $selById[$iid] ?? [];
                            $awSid = $awardById[$iid] ?? null;
                            // If no explicit award, pick cheapest among either per-item selected or global
                            $activeSet = !empty($sel) ? $sel : $suppliers;
                            $minVal = null; $minSid = null;
                            foreach ($activeSet as $sid) {
                                if (!isset($pp[$sid])) continue;
                                $v = (float)$pp[$sid];
                                if ($minVal === null || $v < $minVal) { $minVal = $v; $minSid = $sid; }
                            }
                            if (!$awSid && $minSid) { $awSid = $minSid; }
                            $awPrice = ($awSid && isset($pp[$awSid])) ? (float)$pp[$awSid] : null;
                            $ins->execute([
                                'cid' => $canvassId,
                                'pr' => $pr,
                                'iid' => $iid,
                                'nm' => $idToName[$iid] ?? 'Item',
                                'sup' => json_encode($suppliers),
                                'sel' => json_encode($sel ?: null),
                                'qt' => json_encode($pp),
                                'aw' => $awSid ?: null,
                                'ap' => $awPrice,
                                'uid' => (int)($_SESSION['user_id'] ?? 0),
                            ]);
                        }
                    }
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                // Non-fatal: continue with success response for base details row
            }
            echo json_encode(['canvass_id'=>$canvassId,'status'=>'ok']);
        } catch (\Throwable $e) {
            http_response_code(500); echo json_encode(['error'=>'store_failed','detail'=>$e->getMessage()]);
        }
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

        // Optional canvassing info
        // Priority: 1) session preview (mirror canvassing page), 2) persisted pr_canvassing, 3) recompute fallback
    $canvasSup = [];
    $awardedTo = '';
    $canvassTotals = [];
    $canvassMatrix = [];
        // 1) Always try to seed from preview first so PR mirrors the Canvassing page exactly
        if (isset($_SESSION['canvass_preview_data'][$pr]) && is_array($_SESSION['canvass_preview_data'][$pr])) {
            $pv0 = $_SESSION['canvass_preview_data'][$pr];
            $canvasSup = array_slice((array)($pv0['supplier_names'] ?? []), 0, 3);
            $awardedTo = (string)($pv0['awarded_to'] ?? '');
            $canvassTotals = array_slice((array)($pv0['totals'] ?? []), 0, 3);
            if (isset($pv0['matrix']) && is_array($pv0['matrix'])) { $canvassMatrix = $pv0['matrix']; }
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                pr_number VARCHAR(32) PRIMARY KEY,
                supplier1 VARCHAR(255),
                supplier2 VARCHAR(255),
                supplier3 VARCHAR(255),
                supplier1_id BIGINT,
                supplier2_id BIGINT,
                supplier3_id BIGINT,
                total1 NUMERIC(14,2),
                total2 NUMERIC(14,2),
                total3 NUMERIC(14,2),
                awarded_to VARCHAR(255),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            // Evolve schema for existing deployments
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS supplier1_id BIGINT"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS supplier2_id BIGINT"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS supplier3_id BIGINT"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS total1 NUMERIC(14,2)"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS total2 NUMERIC(14,2)"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE pr_canvassing ADD COLUMN IF NOT EXISTS total3 NUMERIC(14,2)"); } catch (\Throwable $e) {}
            $stC = $pdo->prepare('SELECT supplier1, supplier2, supplier3, total1, total2, total3, awarded_to FROM pr_canvassing WHERE pr_number = :pr');
            $stC->execute(['pr' => $pr]);
            if ($cv = $stC->fetch()) {
                // Only overlay values that are still missing to keep preview as primary source of truth
                if (empty($canvasSup)) {
                    foreach (['supplier1','supplier2','supplier3'] as $k) { if (!empty($cv[$k])) { $canvasSup[] = (string)$cv[$k]; } }
                }
                if (empty(array_filter((array)$canvassTotals, static fn($v)=>$v!==null && $v!==''))) {
                    $canvassTotals = [ isset($cv['total1']) ? (float)$cv['total1'] : null,
                                       isset($cv['total2']) ? (float)$cv['total2'] : null,
                                       isset($cv['total3']) ? (float)$cv['total3'] : null, ];
                }
                if ($awardedTo === '' && !empty($cv['awarded_to'])) { $awardedTo = (string)$cv['awarded_to']; }
            }
        } catch (\Throwable $ignored) {}
        // If we have supplier names but totals are all nulls, try session preview totals/award as well
        if (!empty($canvasSup) && isset($_SESSION['canvass_preview_data'][$pr]) && is_array($_SESSION['canvass_preview_data'][$pr])) {
            $nonNull = 0; foreach ((array)$canvassTotals as $v) { if ($v !== null && $v !== '') { $nonNull++; } }
            if ($nonNull === 0) {
                $pv = $_SESSION['canvass_preview_data'][$pr];
                $tmp = array_slice((array)($pv['totals'] ?? []), 0, 3);
                $tmpHas = 0; foreach ($tmp as $t) { if ($t !== null && $t !== '') { $tmpHas++; } }
                if ($tmpHas > 0) { $canvassTotals = $tmp; }
                if ($awardedTo === '' && !empty($pv['awarded_to'])) { $awardedTo = (string)$pv['awarded_to']; }
            }
        }

    // Compute supplier totals aligned to supplier order to mirror the Canvassing page (if not already from preview)
    $hasNumericTotals = 0; foreach ((array)$canvassTotals as $v) { if ($v !== null && $v !== '') { $hasNumericTotals++; } }
    if ($hasNumericTotals === 0 && !empty($canvasSup)) {
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
                // Fuzzy fallback: if any supplier id not found by exact match, try token-based ILIKE search
                if (in_array(0, $supplierIds, true)) {
                    for ($i=0; $i<count($supplierIds); $i++) {
                        if ($supplierIds[$i] !== 0) { continue; }
                        $nm = $names[$i] ?? '';
                        $tokens = preg_split('/[^a-z0-9]+/i', (string)$nm) ?: [];
                        $tokens = array_values(array_filter(array_map('strtolower', $tokens), static fn($t) => strlen($t) >= 2));
                        if (!$tokens) { continue; }
                        $conds = ["role='supplier'", 'is_active = TRUE'];
                        $paramsTok = [];
                        foreach ($tokens as $t) { $conds[] = 'full_name ILIKE ?'; $paramsTok[] = '%' . $t . '%'; }
                        $sqlTok = 'SELECT user_id FROM users WHERE ' . implode(' AND ', $conds) . ' ORDER BY user_id ASC LIMIT 1';
                        $stTok = $pdo->prepare($sqlTok);
                        $stTok->execute($paramsTok);
                        $got = (int)($stTok->fetchColumn() ?: 0);
                        if ($got > 0) { $supplierIds[$i] = $got; }
                    }
                }
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
                    // Fuzzy fallback for item names not matched by exact LOWER(name) IN (...) using token-based ILIKE
                    $foundNames = [];
                    foreach ($prices as $sid0 => $map0) { foreach ($map0 as $nm0 => $_) { $foundNames[$nm0] = true; } }
                    $toFind = array_values(array_filter($itemNames, static fn($nm) => !isset($foundNames[$nm])));
                    foreach ($toFind as $nm) {
                        $tokens = preg_split('/[^a-z0-9]+/i', (string)$nm) ?: [];
                        $tokens = array_values(array_filter(array_map('strtolower', $tokens), static fn($t) => strlen($t) >= 3));
                        if (!$tokens) { continue; }
                        $tokens = array_slice($tokens, 0, 3);
                        $conds = [];
                        $params2 = [];
                        $conds[] = 'supplier_id IN (' . $inSup . ')';
                        foreach ($supplierIds as $sid) { $params2[] = (int)$sid; }
                        foreach ($tokens as $t) { $conds[] = 'LOWER(name) ILIKE ?'; $params2[] = '%' . $t . '%'; }
                        $sql2 = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE ' . implode(' AND ', $conds);
                        $st2 = $pdo->prepare($sql2);
                        $st2->execute($params2);
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
                    // Build per-item canvassing matrix
                    $canvassMatrix = (function() use ($rows, $supplierIds, $prices) {
                        $matrix = [];
                        foreach ($rows as $r) {
                            $nm = strtolower(trim((string)($r['item_name'] ?? '')));
                            if ($nm === '') { continue; }
                            $qty = (int)($r['quantity'] ?? 0);
                            $unit = (string)($r['unit'] ?? '');
                            $label = (string)($r['item_name'] ?? 'Item');
                            if ($qty > 0) { $label .= ' × ' . $qty . ($unit !== '' ? (' ' . $unit) : ''); }
                            $rowPrices = [];
                            for ($i=0;$i<3;$i++) {
                                $sid = $supplierIds[$i] ?? 0;
                                $p = ($sid && isset($prices[$sid]) && isset($prices[$sid][$nm])) ? (float)$prices[$sid][$nm] : null;
                                $rowPrices[$i] = $p;
                            }
                            $awardIdx = -1; $minVal = null;
                            for ($i=0;$i<3;$i++) { if ($rowPrices[$i] !== null) { $v=(float)$rowPrices[$i]; if ($minVal===null || $v<$minVal){$minVal=$v;$awardIdx=$i;} } }
                            $matrix[] = ['item' => $label, 'prices' => $rowPrices, 'award_index' => $awardIdx];
                        }
                        return $matrix;
                    })();
                    // If award still blank, use preview session award first; else compute from cheapest
                    if ($awardedTo === '' && isset($_SESSION['canvass_preview_data'][$pr]['awarded_to']) && $_SESSION['canvass_preview_data'][$pr]['awarded_to'] !== '') {
                        $awardedTo = (string)$_SESSION['canvass_preview_data'][$pr]['awarded_to'];
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

        // Do not auto-default Awarded To here; leave selection to preview/DB or computed cheapest later in PDF layer

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
            'canvass_matrix' => $canvassMatrix,
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
        // Prefill all Admin users as recipients
        $toList = [];
        try {
            $stAdm = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role = 'admin' ORDER BY user_id ASC");
            foreach ($stAdm->fetchAll() as $row) { $id = (int)$row['user_id']; if ($id > 0) { $toList[] = $id; } }
        } catch (\Throwable $ignored) {}
        $subject = 'PR ' . $pr . ' • For Approval';
        $qs = http_build_query([
            'to' => $toList ?: null,
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
 
