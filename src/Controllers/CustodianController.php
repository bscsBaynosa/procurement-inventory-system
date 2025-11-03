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
        $role = $_SESSION['role'] ?? '';
        if ($role === 'custodian') { $role = 'admin_assistant'; }
        if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant', 'admin'], true)) {
            header('Location: /login');
            return;
        }

        $branchId = $_SESSION['branch_id'] ?? null;
    $inventoryStats = $this->inventory()->getStatsByBranch($branchId ? (int)$branchId : null);
    $categoryStats = $this->inventory()->getStatsByCategory($branchId ? (int)$branchId : null);
        $pendingRequests = $this->requests()->getPendingRequests($branchId ? (int)$branchId : null);
        // Greeting, unread, branch name, avatar
        $meId = (int)($_SESSION['user_id'] ?? 0);
        $meFirst = null; $avatarPath = null; $unread = 0; $branchName = null;
        try {
            $pdo = \App\Database\Connection::resolve();
            if ($meId > 0) {
                $st = $pdo->prepare('SELECT first_name, full_name, avatar_path, branch_id FROM users WHERE user_id = :id');
                $st->execute(['id' => $meId]);
                $u = $st->fetch();
                if ($u) { $meFirst = (string)($u['first_name'] ?? ''); $avatarPath = (string)($u['avatar_path'] ?? ''); if (!$branchId && !empty($u['branch_id'])) { $branchId = (int)$u['branch_id']; } }
                $stU = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = :me AND is_read = FALSE');
                $stU->execute(['me' => $meId]);
                $unread = (int)$stU->fetchColumn();
            }
            if ($branchId) {
                $sb = $pdo->prepare('SELECT name FROM branches WHERE branch_id = :b');
                $sb->execute(['b' => $branchId]);
                $branchName = (string)($sb->fetchColumn() ?: '');
            }
        } catch (\Throwable $ignored) {}

        $this->render('dashboard/custodian.php', [
            'inventoryStats' => $inventoryStats,
            'categoryStats' => $categoryStats,
            'pendingRequests' => $pendingRequests,
            'me_first' => $meFirst,
            'unread_count' => $unread,
            'avatar_path' => $avatarPath,
            'branch_name' => $branchName,
        ]);
    }

    /** Inventory list & simple create form for custodian */
    public function inventoryPage(): void
    {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'custodian') { $role = 'admin_assistant'; }
        if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant', 'admin'], true)) {
            header('Location: /login'); return;
        }
        $branchId = $_SESSION['branch_id'] ?? null;
    $items = $this->inventory()->listInventory($branchId ? (int)$branchId : null);
    // Items with active PRs for this branch (to disable add-to-PR)
    $activePrItems = $this->requests()->getActiveRequestItemIdsForBranch($branchId ? (int)$branchId : null);
        $categories = ['Office Supplies','Medical Equipments','Medicines','Machines','Electronics','Appliances'];
        $selectedCategory = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
        $search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $filterStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';

        // Filter in-memory for now to avoid many queries
        $filtered = [];
        foreach ($items as $it) {
            if ($selectedCategory !== '' && strcasecmp((string)$it['category'], $selectedCategory) !== 0) { continue; }
            if ($search !== '' && stripos((string)$it['name'], $search) === false) { continue; }
            if ($filterStatus !== '') {
                $qty = (int)($it['quantity'] ?? 0);
                $min = (int)($it['minimum_quantity'] ?? 0);
                $maint = (int)($it['maintaining_quantity'] ?? 0);
                $halfMaint = $maint > 0 ? (int)floor($maint * 0.5) : 0;
                $threshold = max($min, $halfMaint);
                $isLow = ($threshold > 0) ? ($qty <= $threshold) : false;
                if ($filterStatus === 'low' && !$isLow) { continue; }
                if ($filterStatus === 'ok' && $isLow) { continue; }
            }
            $filtered[] = $it;
        }
        // When no category or filters, still show all items under list
        $viewItems = ($selectedCategory !== '' || $search !== '' || $filterStatus !== '') ? $filtered : $items;
        $edit = null;
        if (isset($_GET['edit'])) {
            $id = (int)$_GET['edit'];
            if ($id > 0) { $edit = $this->inventory()->getItemById($id); }
        }
        // Cart badge (selected low-stock items to request later)
        if (!isset($_SESSION['pr_cart'])) { $_SESSION['pr_cart'] = []; }
        $cartCount = is_array($_SESSION['pr_cart']) ? count($_SESSION['pr_cart']) : 0;

        $this->render('custodian/inventory.php', [
            'items' => $viewItems,
            'edit' => $edit,
            'categories' => $categories,
            'selected_category' => $selectedCategory,
            'search' => $search,
            'filter_status' => $filterStatus,
            'cart_count' => $cartCount,
            'active_pr_items' => $activePrItems,
        ]);
    }

    public function inventoryCreate(): void
    {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'custodian') { $role = 'admin_assistant'; }
    if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant', 'admin'], true)) { header('Location: /login'); return; }
        $name = trim((string)($_POST['name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $status = (string)($_POST['status'] ?? 'good');
        $quantity = (int)($_POST['quantity'] ?? 1);
        $unitSel = trim((string)($_POST['unit'] ?? 'pcs'));
        $unitOther = trim((string)($_POST['unit_other'] ?? ''));
        $unit = ($unitSel === 'others' && $unitOther !== '') ? $unitOther : $unitSel;
        $minQty = isset($_POST['minimum_quantity']) && $_POST['minimum_quantity'] !== '' ? (int)$_POST['minimum_quantity'] : 0;
        $maintQty = isset($_POST['maintaining_quantity']) && $_POST['maintaining_quantity'] !== '' ? (int)$_POST['maintaining_quantity'] : 0;
    if ($name === '' || $category === '' || $quantity <= 0) { header('Location: /admin-assistant/inventory?error=Invalid+data'); return; }
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
        $id = $this->inventory()->createItem([
            'branch_id' => $branchId ?: null,
            'name' => $name,
            'category' => $category,
            'status' => $status,
            'quantity' => $quantity,
            'unit' => $unit,
            'minimum_quantity' => $minQty,
            'maintaining_quantity' => $maintQty,
        ], (int)($_SESSION['user_id'] ?? 0));
    header('Location: /admin-assistant/inventory?created=1');
    }

    public function inventoryUpdate(): void
    {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'custodian') { $role = 'admin_assistant'; }
    if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant', 'admin'], true)) { header('Location: /login'); return; }
    if ($role === 'admin_assistant') { header('Location: /admin-assistant/inventory?error=Not+allowed'); return; }
        $id = (int)($_POST['item_id'] ?? 0);
    if ($id <= 0) { header('Location: /admin-assistant/inventory?error=Invalid+item'); return; }
        $payload = [];
        foreach (['name','category','status','quantity','unit'] as $f) {
            if (isset($_POST[$f])) { $payload[$f] = $_POST[$f]; }
        }
        $this->inventory()->updateItem($id, $payload, (int)($_SESSION['user_id'] ?? 0));
    header('Location: /admin-assistant/inventory?updated=1');
    }

    public function inventoryDelete(): void
    {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'custodian') { $role = 'admin_assistant'; }
    if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant', 'admin'], true)) { header('Location: /login'); return; }
    if ($role === 'admin_assistant') { header('Location: /admin-assistant/inventory?error=Not+allowed'); return; }
        $id = (int)($_POST['item_id'] ?? 0);
    if ($id <= 0) { header('Location: /admin-assistant/inventory?error=Invalid+item'); return; }
        $this->inventory()->deleteItem($id);
    header('Location: /admin-assistant/inventory?deleted=1');
    }

    /** Update only the stock count for an item; record a consumption entry. */
    public function updateStock(): void
    {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'custodian') { $role = 'admin_assistant'; }
        if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant', 'admin'], true)) { header('Location: /login'); return; }
        $itemId = (int)($_POST['item_id'] ?? 0);
        $newCount = (int)($_POST['new_count'] ?? -1);
        $category = isset($_POST['category']) ? trim((string)$_POST['category']) : '';
        if ($itemId <= 0 || $newCount < 0) { header('Location: /admin-assistant/inventory'); return; }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $pdo = \App\Database\Connection::resolve();
        try {
            // Ensure table for consumption reports
            $pdo->exec("CREATE TABLE IF NOT EXISTS consumption_reports (
                id BIGSERIAL PRIMARY KEY,
                item_id BIGINT NOT NULL REFERENCES inventory_items(item_id) ON DELETE CASCADE,
                previous_count INTEGER NOT NULL,
                current_count INTEGER NOT NULL,
                delta INTEGER NOT NULL,
                changed_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                changed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            // Get current
            $st = $pdo->prepare('SELECT quantity, name, category, unit FROM inventory_items WHERE item_id = :id');
            $st->execute(['id' => $itemId]);
            $rowItem = $st->fetch(\PDO::FETCH_ASSOC) ?: ['quantity' => 0, 'name' => '', 'category' => null, 'unit' => ''];
            $prev = (int)($rowItem['quantity'] ?? 0);
            // Update
            $up = $pdo->prepare('UPDATE inventory_items SET quantity = :q, updated_by = :by, updated_at = NOW() WHERE item_id = :id');
            $up->execute(['q' => $newCount, 'by' => $userId, 'id' => $itemId]);
            // Record consumption/delivery
            $delta = $newCount - $prev; // negative -> consumed
            $ins = $pdo->prepare('INSERT INTO consumption_reports (item_id, previous_count, current_count, delta, changed_by) VALUES (:i,:p,:c,:d,:by)');
            $ins->execute(['i' => $itemId, 'p' => $prev, 'c' => $newCount, 'd' => $delta, 'by' => $userId]);

            // Auto-archive a per-item monthly consumption report
            try {
                $start = date('Y-m-01');
                $end = date('Y-m-d', strtotime($start . ' +1 month'));
                // Fetch recent logs for this item in month
                $q = $pdo->prepare('SELECT previous_count, current_count, delta, changed_at, u.full_name
                                     FROM consumption_reports cr
                                     LEFT JOIN users u ON u.user_id = cr.changed_by
                                     WHERE cr.item_id = :iid AND cr.changed_at >= :s AND cr.changed_at < :e
                                     ORDER BY cr.changed_at DESC');
                $q->execute(['iid' => $itemId, 's' => $start, 'e' => $end]);
                $rows = [];
                foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $rows[] = [
                        'name' => (string)($rowItem['name'] ?? ('Item #' . $itemId)),
                        'previous' => (int)$r['previous_count'],
                        'current' => (int)$r['current_count'],
                        'delta' => (int)$r['delta'],
                        'user' => (string)($r['full_name'] ?? ''),
                        'at' => date('Y-m-d H:i', strtotime((string)$r['changed_at'])),
                    ];
                }
                // Summary (beginning/delivered/consumed/ending)
                $q2 = $pdo->prepare('SELECT
                        (SELECT previous_count FROM consumption_reports WHERE item_id = :iid AND changed_at >= :s AND changed_at < :e ORDER BY changed_at ASC LIMIT 1) AS beginning,
                        COALESCE(SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END),0) AS delivered,
                        COALESCE(SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END),0) AS consumed,
                        (SELECT current_count FROM consumption_reports WHERE item_id = :iid AND changed_at >= :s AND changed_at < :e ORDER BY changed_at DESC LIMIT 1) AS ending
                    FROM consumption_reports WHERE item_id = :iid AND changed_at >= :s AND changed_at < :e');
                $q2->execute(['iid' => $itemId, 's' => $start, 'e' => $end]);
                $agg = $q2->fetch(\PDO::FETCH_ASSOC) ?: ['beginning'=>null,'delivered'=>0,'consumed'=>0,'ending'=>null];
                $summary = [[
                    'name' => (string)($rowItem['name'] ?? ('Item #' . $itemId)),
                    'unit' => (string)($rowItem['unit'] ?? ''),
                    'beginning' => (int)($agg['beginning'] ?? $prev),
                    'delivered' => (int)($agg['delivered'] ?? 0),
                    'consumed' => (int)($agg['consumed'] ?? 0),
                    'ending' => (int)($agg['ending'] ?? $newCount),
                ]];
                $meta = [
                    'Item' => (string)($rowItem['name'] ?? ('Item #' . $itemId)),
                    'Period' => date('Y-m-01') . ' to ' . date('Y-m-t'),
                    'Prepared By' => (string)($_SESSION['full_name'] ?? ('User #' . $userId)),
                    'Prepared At' => date('Y-m-d H:i'),
                    '_summary' => $summary,
                ];
                $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
                if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                $fileBase = 'consumption_item' . (int)$itemId . '_' . date('Ym') . '_' . date('Ymd_His') . '.pdf';
                $abs = $dir . DIRECTORY_SEPARATOR . $fileBase;
                $pdf = new \App\Services\PDFService();
                $pdf->generateConsumptionReportPDF($meta, $rows, 'F', $abs);
                // Archive entry
                try {
                    $insA = $pdo->prepare('INSERT INTO report_archives (report_type, category, prepared_by, prepared_name, prepared_at, file_name, file_path) VALUES (:t,:c,:u,:n,:at,:fn,:fp)');
                    $insA->execute([
                        't' => 'consumption',
                        'c' => null,
                        'u' => $userId,
                        'n' => (string)($_SESSION['full_name'] ?? ''),
                        'at' => date('Y-m-d H:i:s'),
                        'fn' => $fileBase,
                        'fp' => $abs,
                    ]);
                } catch (\Throwable $ignored2) {}
            } catch (\Throwable $ignored1) {}
            $redir = '/admin-assistant/inventory' . ($category !== '' ? ('?category=' . rawurlencode($category)) : '');
            header('Location: ' . $redir . '&stock_updated=1');
        } catch (\Throwable $e) {
            header('Location: /admin-assistant/inventory?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Update limited metadata (unit, status, minimum_quantity) for Admin Assistant */
    public function updateMeta(): void
    {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'custodian') { $role = 'admin_assistant'; }
        if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant', 'admin'], true)) { header('Location: /login'); return; }
        $itemId = (int)($_POST['item_id'] ?? 0);
        $category = isset($_POST['category']) ? trim((string)$_POST['category']) : '';
        if ($itemId <= 0) { header('Location: /admin-assistant/inventory'); return; }
        $payload = [];
        if (isset($_POST['unit'])) {
            $unitSel = (string)$_POST['unit'];
            $unitOther = isset($_POST['unit_other']) ? trim((string)$_POST['unit_other']) : '';
            $payload['unit'] = ($unitSel === 'others' && $unitOther !== '') ? $unitOther : $unitSel;
        }
        if (isset($_POST['status'])) { $payload['status'] = (string)$_POST['status']; }
        if (isset($_POST['minimum_quantity']) && $_POST['minimum_quantity'] !== '') { $payload['minimum_quantity'] = (int)$_POST['minimum_quantity']; }
        if (isset($_POST['maintaining_quantity']) && $_POST['maintaining_quantity'] !== '') { $payload['maintaining_quantity'] = (int)$_POST['maintaining_quantity']; }
        if ($payload) { $this->inventory()->updateItem($itemId, $payload, (int)($_SESSION['user_id'] ?? 0)); }
        $redir = '/admin-assistant/inventory' . ($category !== '' ? ('?category=' . rawurlencode($category)) : '');
        header('Location: ' . $redir . '&meta_updated=1');
    }

    /** Add low-stock items to a simple PR cart kept in session. */
    public function addToCart(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $ids = isset($_POST['item_ids']) ? (array)$_POST['item_ids'] : [];
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
        if (!isset($_SESSION['pr_cart']) || !is_array($_SESSION['pr_cart'])) { $_SESSION['pr_cart'] = []; }
        $blocked = [];
        foreach ($ids as $id) {
            $iid = (int)$id;
            if ($iid > 0) {
                // Block if an active PR already exists for this item in this branch
                if ($this->requests()->hasActiveRequestForItemBranch($iid, $branchId)) {
                    $blocked[] = $iid; continue;
                }
                $_SESSION['pr_cart'][$iid] = ['quantity' => 1];
            }
        }
        $count = is_array($_SESSION['pr_cart']) ? count($_SESSION['pr_cart']) : 0;
        // If this is an AJAX request, return JSON with the updated count
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            header('Content-Type: application/json');
            $ok = count($blocked) === 0;
            echo json_encode(['ok' => $ok, 'count' => $count, 'blocked' => $blocked]);
            return;
        }
        $ref = $_SERVER['HTTP_REFERER'] ?? '/admin-assistant/inventory';
        if ($blocked) { $ref .= (strpos($ref,'?')===false? '?':'&') . 'error=active_pr_exists'; }
        header('Location: ' . $ref);
    }

    /** Review PR cart and submit multiple requests. */
    public function reviewCart(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $branchId = $_SESSION['branch_id'] ?? null;
        $items = $this->inventory()->listInventory($branchId ? (int)$branchId : null);
        $cart = isset($_SESSION['pr_cart']) && is_array($_SESSION['pr_cart']) ? $_SESSION['pr_cart'] : [];
        $map = [];
        foreach ($items as $it) { $map[(int)$it['item_id']] = $it; }
        $list = [];
        foreach ($cart as $iid => $data) {
            if (isset($map[$iid])) { $list[] = $map[$iid] + ['req_qty' => (int)($data['quantity'] ?? 1)]; }
        }
        // Preview next PR number (first one that will be assigned when submitting)
        $prPreview = $this->requests()->getNextPrNumberPreview();
        $this->render('custodian/requests_create.php', ['cart' => $list, 'pr_preview' => $prPreview]);
    }

    /** Remove a single item from PR cart */
    public function cartRemove(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0 && isset($_SESSION['pr_cart']) && is_array($_SESSION['pr_cart'])) {
            unset($_SESSION['pr_cart'][$itemId]);
        }
        header('Location: /admin-assistant/requests/review');
    }

    /** Submit PR cart: create one purchase_request per item. */
    public function submitCart(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
        $items = isset($_POST['items']) ? (array)$_POST['items'] : [];
        if (empty($items)) {
            // Do not clear cart; redirect to review with error
            header('Location: /admin-assistant/requests/review?error=cart_empty');
            return;
        }
        // Generate a single PR number for the entire submission (multi-item PR)
        $groupPrNumber = $this->requests()->generateNewPrNumber();
        $justification = (string)($_POST['justification'] ?? 'Low stock auto-selected');
        $neededBy = $_POST['needed_by'] ?? null;
        $created = 0; $skipped = 0; $itemLines = [];
        $byName = (string)($_SESSION['full_name'] ?? ('User #' . $userId));
        foreach ($items as $row) {
            $iid = (int)($row['item_id'] ?? 0);
            $qty = max(1, (int)($row['quantity'] ?? 0));
            $unit = isset($row['unit']) ? (string)$row['unit'] : 'pcs';
            if ($iid > 0 && $qty > 0) {
                // Skip if an active PR already exists for this item in this branch
                if ($this->requests()->hasActiveRequestForItemBranch($iid, $branchId)) { $skipped++; continue; }
                $req = $this->requests()->createPurchaseRequest([
                    'item_id' => $iid,
                    'branch_id' => $branchId ?: null,
                    'requested_by' => $userId,
                    'request_type' => 'purchase_order',
                    'quantity' => $qty,
                    'unit' => $unit,
                    'justification' => $justification,
                    'status' => 'pending',
                    'priority' => 3,
                    'needed_by' => $neededBy,
                    'pr_number' => $groupPrNumber,
                ], $userId);
                $created++;
                // Build line for PDF (resolve item name/unit best-effort)
                try {
                    $pdo = \App\Database\Connection::resolve();
                    $st = $pdo->prepare('SELECT name, unit, quantity FROM inventory_items WHERE item_id = :id');
                    $st->execute(['id' => $iid]);
                    $it = $st->fetch(\PDO::FETCH_ASSOC) ?: ['name' => 'Item #' . $iid, 'unit' => $unit, 'quantity' => null];
                    $itemLines[] = [
                        'description' => (string)($it['name'] ?? ('Item #' . $iid)),
                        'unit' => (string)($row['unit'] ?? ($it['unit'] ?? 'pcs')),
                        'qty' => $qty,
                        'stock_on_hand' => isset($it['quantity']) ? (int)$it['quantity'] : null,
                        // 'usage_per_month' can be computed later; leave blank for now
                    ];
                } catch (\Throwable $ignored) {
                    $itemLines[] = ['description' => 'Item #' . $iid, 'unit' => $unit, 'qty' => $qty];
                }
            }
        }
        if ($created > 0) {
            // Clear cart only if something was created
            $_SESSION['pr_cart'] = [];
            // Generate PR PDF and email Procurement a message with attachment
            try {
                $branchName = 'All';
                if ($branchId) {
                    $pdo = \App\Database\Connection::resolve();
                    $sb = $pdo->prepare('SELECT name FROM branches WHERE branch_id = :b');
                    $sb->execute(['b' => $branchId]);
                    $bn = $sb->fetchColumn(); if ($bn) { $branchName = (string)$bn; }
                }
                $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
                if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                $fileBase = 'pr_' . $groupPrNumber . '_' . date('Ymd_His') . '.pdf';
                $abs = $dir . DIRECTORY_SEPARATOR . $fileBase;
                $pdf = new \App\Services\PDFService();
                $pdf->generatePurchaseRequisitionToFile([
                    'pr_number' => $groupPrNumber,
                    'branch_name' => $branchName,
                    'requested_by' => $byName,
                    'prepared_at' => date('Y-m-d H:i'),
                    'justification' => $justification,
                    'needed_by' => $neededBy ?? '',
                ], $itemLines, $abs);
                // Notify procurement users with the PDF attached
                try {
                    $pdo = \App\Database\Connection::resolve();
                    $recips = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement')")->fetchAll();
                    if ($recips) {
                        $subject = 'Purchase Request of ' . $branchName . ' • PR ' . $groupPrNumber;
                        $body = "A new Purchase Request has been submitted.\n\nPR: " . $groupPrNumber . "\nBranch: " . $branchName;
                        foreach ($recips as $r) {
                            $this->requests()->sendMessage($userId, (int)$r['user_id'], $subject, $body, basename($fileBase), $abs);
                        }
                    }
                    // Send a copy to the Admin Assistant (self) for records
                    try {
                        $copySubject = 'Copy: Purchase Request of ' . $branchName . ' • PR ' . $groupPrNumber;
                        $copyBody = "Your PR has been submitted.\n\nPR: " . $groupPrNumber . "\nBranch: " . $branchName;
                        $this->requests()->sendMessage($userId, $userId, $copySubject, $copyBody, basename($fileBase), $abs);
                    } catch (\Throwable $ignoredSelf) {}
                } catch (\Throwable $ignored) {}
            } catch (\Throwable $ignored) {}

            // Redirect to compose with subject (and default recipient if available)
            $toParam = '';
            try {
                $pdo = \App\Database\Connection::resolve();
                $row = $pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement') ORDER BY (role='procurement_manager') DESC, full_name ASC LIMIT 1")?->fetch();
                if ($row && !empty($row['user_id'])) { $toParam = '&to=' . (int)$row['user_id']; }
            } catch (\Throwable $ignored) {}
            $bn = 'Branch';
            try {
                if ($branchId) {
                    $pdo = \App\Database\Connection::resolve();
                    $sb = $pdo->prepare('SELECT name FROM branches WHERE branch_id = :b');
                    $sb->execute(['b' => $branchId]);
                    $bn = (string)($sb->fetchColumn() ?: 'Branch');
                }
            } catch (\Throwable $ignored) {}
            $subject = 'PR ' . $groupPrNumber . ' • ' . $bn . ' • ' . date('Y-m-d');
            // Prefill compose with recipient, subject, and auto-attach the generated PDF
            $attachParams = '&attach_name=' . rawurlencode(basename($fileBase)) . '&attach_path=' . rawurlencode($abs);
            header('Location: /admin/messages?subject=' . rawurlencode($subject) . $toParam . '&created=' . (int)$created . ($skipped > 0 ? ('&skipped=' . (int)$skipped) : '') . $attachParams);
        } else {
            header('Location: /admin-assistant/requests/review?error=cart_empty');
        }
    }

    /** Generate Inventory Report PDF for the selected category */
    public function inventoryReport(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $branchId = $_SESSION['branch_id'] ?? null;
        $category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
        $download = isset($_GET['download']) ? (string)$_GET['download'] === '1' : true;
        // Optional period filters to align with reference (month=YYYY-MM or start/end)
        $month = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
        $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
        $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = $month . '-01';
            $end = date('Y-m-d', strtotime($start . ' +1 month'));
        }
        if ($start === '' || $end === '') {
            // Default to current month
            $start = date('Y-m-01');
            $end = date('Y-m-d', strtotime($start . ' +1 month'));
        }
        $rows = $this->inventory()->listInventory($branchId ? (int)$branchId : null);
        $filtered = [];
        foreach ($rows as $r) {
            if ($category !== '' && strcasecmp((string)$r['category'], $category) !== 0) { continue; }
            $filtered[] = [
                'name' => (string)$r['name'],
                'unit' => (string)($r['unit'] ?? 'pcs'),
                'quantity' => (int)($r['quantity'] ?? 0),
                'minimum_quantity' => (int)($r['minimum_quantity'] ?? 0),
                'is_low' => ((int)($r['minimum_quantity'] ?? 0) > 0) && ((int)($r['quantity'] ?? 0) <= (int)($r['minimum_quantity'] ?? 0)),
            ];
        }
        // Build an optional summary for Beginning/Delivered/Consumed/Ending within the period
        $summary = [];
        try {
            $pdo = \App\Database\Connection::resolve();
            // Ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS consumption_reports (
                id BIGSERIAL PRIMARY KEY,
                item_id BIGINT NOT NULL REFERENCES inventory_items(item_id) ON DELETE CASCADE,
                previous_count INTEGER NOT NULL,
                current_count INTEGER NOT NULL,
                delta INTEGER NOT NULL,
                changed_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                changed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $summarySql = "
                WITH logs AS (
                    SELECT cr.*, i.name, i.unit
                    FROM consumption_reports cr
                    JOIN inventory_items i ON i.item_id = cr.item_id
                    WHERE i.category ILIKE :cat2 AND cr.changed_at >= :s AND cr.changed_at < :e
                ),
                agg AS (
                    SELECT item_id,
                           SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) AS delivered,
                           SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END) AS consumed
                    FROM logs GROUP BY item_id
                ),
                firsts AS (
                    SELECT DISTINCT ON (item_id) item_id, previous_count AS beginning
                    FROM logs ORDER BY item_id, changed_at ASC
                ),
                lasts AS (
                    SELECT DISTINCT ON (item_id) item_id, current_count AS ending
                    FROM logs ORDER BY item_id, changed_at DESC
                )
                SELECT i.item_id, i.name, i.unit,
                       COALESCE(f.beginning, i.quantity) AS beginning,
                       COALESCE(a.delivered, 0) AS delivered,
                       COALESCE(a.consumed, 0) AS consumed,
                       COALESCE(l.ending, i.quantity) AS ending
                FROM inventory_items i
                LEFT JOIN agg a ON a.item_id = i.item_id
                LEFT JOIN firsts f ON f.item_id = i.item_id
                LEFT JOIN lasts l ON l.item_id = i.item_id
                WHERE i.category ILIKE :cat3
                  AND (a.delivered IS NOT NULL OR a.consumed IS NOT NULL)
                ORDER BY i.name ASC
            ";
            $stS = $pdo->prepare($summarySql);
            $stS->execute([
                'cat2' => $category !== '' ? $category : '%',
                's' => $start,
                'e' => $end,
                'cat3' => $category !== '' ? $category : '%',
            ]);
            foreach ($stS->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $summary[] = [
                    'name' => (string)$r['name'],
                    'unit' => (string)($r['unit'] ?? ''),
                    'beginning' => (int)$r['beginning'],
                    'delivered' => (int)$r['delivered'],
                    'consumed' => (int)$r['consumed'],
                    'ending' => (int)$r['ending'],
                ];
            }
        } catch (\Throwable $ignored) {}
        $branchName = 'All';
        if ($branchId) {
            try { $pdo = \App\Database\Connection::resolve(); $stB = $pdo->prepare('SELECT name FROM branches WHERE branch_id = :b'); $stB->execute(['b' => $branchId]); $bn = $stB->fetchColumn(); if ($bn) { $branchName = (string)$bn; } } catch (\Throwable $ignored) {}
        }
        $meta = [
            'Branch' => $branchName,
            'Category' => $category !== '' ? $category : 'All',
            'Period' => date('Y-m-d', strtotime($start)) . ' to ' . date('Y-m-d', strtotime($end . ' -1 day')),
            'Prepared By' => (string)($_SESSION['full_name'] ?? ('User #' . (int)($_SESSION['user_id'] ?? 0))),
            'Prepared At' => date('Y-m-d H:i'),
            '_summary' => $summary,
        ];
        // Save to disk and archive; then stream
        $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $slugCat = $category !== '' ? preg_replace('/[^A-Za-z0-9_-]+/', '_', $category) : 'All';
        $fileBase = 'inventory_' . $slugCat . '_' . date('Ymd_His') . '.pdf';
        $abs = $dir . DIRECTORY_SEPARATOR . $fileBase;
        $pdf = new \App\Services\PDFService();
        $pdf->generateInventoryReportPDF($meta, $filtered, 'F', $abs);
        try {
            $pdo = \App\Database\Connection::resolve();
            $ins = $pdo->prepare('INSERT INTO report_archives (report_type, category, branch_id, prepared_by, prepared_name, prepared_at, file_name, file_path) VALUES (:t,:c,:b,:u,:n,:at,:fn,:fp)');
            $ins->execute([
                't' => 'inventory',
                'c' => $category !== '' ? $category : null,
                'b' => $branchId ?: null,
                'u' => (int)($_SESSION['user_id'] ?? 0),
                'n' => (string)($_SESSION['full_name'] ?? ''),
                'at' => date('Y-m-d H:i:s'),
                'fn' => $fileBase,
                'fp' => $abs,
            ]);
        } catch (\Throwable $ignored) {}
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . basename($fileBase) . '"');
        header('Content-Length: ' . (string)@filesize($abs));
        @readfile($abs);
    }

    /** Purchase Request history: show all PR groups for the assistant's branch and attach/download PDFs when available. */
    public function requestsHistory(): void
    {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'custodian') { $role = 'admin_assistant'; }
        if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant','admin'], true)) { header('Location: /login'); return; }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
        $rows = [];
        try {
            $pdo = \App\Database\Connection::resolve();
            // 1) Fetch PR groups for this branch (so History is complete even if no PDF was generated yet)
            $stPr = $pdo->prepare("SELECT pr_number, MIN(created_at) AS created_at FROM purchase_requests WHERE (:b = 0 OR branch_id = :b) GROUP BY pr_number ORDER BY MIN(created_at) DESC LIMIT 200");
            $stPr->execute(['b' => $branchId]);
            $groups = $stPr->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            // 2) Fetch any messages (received OR sent) with PR-like attachments so we can link downloads
            $stMsg = $pdo->prepare("SELECT id, subject, attachment_name, attachment_path, created_at FROM messages 
                WHERE (recipient_id = :me OR sender_id = :me)
                AND attachment_path IS NOT NULL AND attachment_name IS NOT NULL AND attachment_name ILIKE 'pr_%' 
                ORDER BY created_at DESC LIMIT 500");
            $stMsg->execute(['me' => $me]);
            $msgs = $stMsg->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            // 3) Index messages by PR number parsed from filename or subject
            $byPr = [];
            foreach ($msgs as $m) {
                $subj = (string)($m['subject'] ?? '');
                $fname = (string)($m['attachment_name'] ?? '');
                $pr = '';
                if (preg_match('/PR\s*([0-9\-]+)/i', $subj, $mm)) { $pr = $mm[1]; }
                if ($pr === '' && preg_match('/^pr_([0-9\-]+)/i', $fname, $mm2)) { $pr = $mm2[1]; }
                if ($pr !== '' && !isset($byPr[$pr])) { $byPr[$pr] = $m; }
            }

            // 4) Merge: build rows with PR number + optional attachment
            foreach ($groups as $g) {
                $pr = (string)$g['pr_number'];
                $r = [
                    'pr_number' => $pr,
                    'created_at' => (string)($g['created_at'] ?? ''),
                    'attachment_name' => null,
                    'message_id' => null,
                ];
                if (isset($byPr[$pr])) {
                    $m = $byPr[$pr];
                    $r['attachment_name'] = (string)$m['attachment_name'];
                    $r['message_id'] = (int)$m['id'];
                    // Prefer message timestamp when available
                    $r['created_at'] = (string)($m['created_at'] ?? $r['created_at']);
                }
                $rows[] = $r;
            }
        } catch (\Throwable $ignored) {}
        $this->render('custodian/requests_history.php', [ 'rows' => $rows ]);
    }

    /** Generate a PR PDF for an existing PR number (backfill) and send a self-copy so it appears in History. */
    public function generatePrPdf(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $role = $_SESSION['role'] ?? '';
        if ($role === 'custodian') { $role = 'admin_assistant'; }
        if (!in_array($role, ['admin_assistant','admin'], true)) { header('Location: /login'); return; }
        $pr = isset($_GET['pr']) ? trim((string)$_GET['pr']) : '';
        if ($pr === '' || !preg_match('/^[0-9\-]+$/', $pr)) { header('Location: /admin-assistant/requests/history?error=bad_pr'); return; }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
        try {
            $pdo = \App\Database\Connection::resolve();
            // Load PR lines for this branch only
            $st = $pdo->prepare('SELECT pr.item_id, pr.quantity, pr.unit, pr.branch_id, pr.requested_by, i.name AS item_name, i.unit AS default_unit, i.quantity AS stock_on_hand
                                 FROM purchase_requests pr 
                                 LEFT JOIN inventory_items i ON i.item_id = pr.item_id
                                 WHERE pr.pr_number = :pr AND (:b = 0 OR pr.branch_id = :b)
                                 ORDER BY pr.created_at ASC');
            $st->execute(['pr' => $pr, 'b' => $branchId]);
            $lines = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if (!$lines) { header('Location: /admin-assistant/requests/history?error=not_found'); return; }

            // Resolve branch name
            $branchName = 'Branch';
            if ($branchId) {
                try { $sb = $pdo->prepare('SELECT name FROM branches WHERE branch_id = :b'); $sb->execute(['b' => $branchId]); $bn = $sb->fetchColumn(); if ($bn) { $branchName = (string)$bn; } } catch (\Throwable $e) {}
            }
            $byName = (string)($_SESSION['full_name'] ?? ('User #' . $me));
            $itemLines = [];
            foreach ($lines as $row) {
                $itemLines[] = [
                    'description' => (string)($row['item_name'] ?? 'Item'),
                    'unit' => (string)($row['unit'] ?? ($row['default_unit'] ?? 'pcs')),
                    'qty' => (int)($row['quantity'] ?? 0),
                    'stock_on_hand' => isset($row['stock_on_hand']) ? (int)$row['stock_on_hand'] : null,
                ];
            }
            // Generate PDF
            $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $fileBase = 'pr_' . $pr . '_' . date('Ymd_His') . '.pdf';
            $abs = $dir . DIRECTORY_SEPARATOR . $fileBase;
            $pdf = new \App\Services\PDFService();
            $pdf->generatePurchaseRequisitionToFile([
                'pr_number' => $pr,
                'branch_name' => $branchName,
                'requested_by' => $byName,
                'prepared_at' => date('Y-m-d H:i'),
                'justification' => '',
                'needed_by' => '',
            ], $itemLines, $abs);

            // Self-copy so it appears in History
            try {
                $this->requests()->sendMessage($me, $me, 'Copy: Purchase Request of ' . $branchName . ' • PR ' . $pr, 'Generated PDF copy for your records.', basename($fileBase), $abs);
            } catch (\Throwable $e) {}
            header('Location: /admin-assistant/requests/history?ok=1');
        } catch (\Throwable $e) {
            header('Location: /admin-assistant/requests/history?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Generate Consumption Report PDF for recent stock updates; supports category or single item_id */
    public function consumptionReport(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
        $itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
        $download = isset($_GET['download']) ? (string)$_GET['download'] === '1' : true;
        // Date range: month=YYYY-MM or start/end (ISO), default current month
        $month = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
        $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
        $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = $month . '-01';
            $end = date('Y-m-d', strtotime($start . ' +1 month'));
        }
        if ($start === '' || $end === '') {
            $start = date('Y-m-01');
            $end = date('Y-m-d', strtotime($start . ' +1 month'));
        }
        $pdo = \App\Database\Connection::resolve();
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS consumption_reports (
            id BIGSERIAL PRIMARY KEY,
            item_id BIGINT NOT NULL REFERENCES inventory_items(item_id) ON DELETE CASCADE,
            previous_count INTEGER NOT NULL,
            current_count INTEGER NOT NULL,
            delta INTEGER NOT NULL,
            changed_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
            changed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )");
    $sql = 'SELECT cr.previous_count, cr.current_count, cr.delta, cr.changed_at, u.full_name, i.name, i.category, i.unit
        FROM consumption_reports cr
        JOIN inventory_items i ON i.item_id = cr.item_id
        LEFT JOIN users u ON u.user_id = cr.changed_by';
        $params = [];
        $wheres = [];
    if ($itemId > 0) { $wheres[] = 'i.item_id = :iid'; $params['iid'] = $itemId; }
    elseif ($category !== '') { $wheres[] = 'i.category ILIKE :cat'; $params['cat'] = $category; }
        $wheres[] = 'cr.changed_at >= :start AND cr.changed_at < :end';
        $params['start'] = $start; $params['end'] = $end;
        if ($wheres) { $sql .= ' WHERE ' . implode(' AND ', $wheres); }
        $sql .= ' ORDER BY cr.changed_at DESC LIMIT 500';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'name' => (string)$r['name'],
                'previous' => (int)$r['previous_count'],
                'current' => (int)$r['current_count'],
                'delta' => (int)$r['delta'],
                'user' => (string)($r['full_name'] ?? ''),
                'at' => date('Y-m-d H:i', strtotime((string)$r['changed_at'])),
            ];
        }
        // Summary matrix per item in date range
        $summary = [];
        $summarySql = "
            WITH logs AS (
                SELECT cr.*, i.name, i.unit
                FROM consumption_reports cr
                JOIN inventory_items i ON i.item_id = cr.item_id
                WHERE " . ($itemId > 0 ? 'i.item_id = :item2' : 'i.category ILIKE :cat2') . " AND cr.changed_at >= :s AND cr.changed_at < :e
            ),
            agg AS (
                SELECT item_id,
                       SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) AS delivered,
                       SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END) AS consumed
                FROM logs GROUP BY item_id
            ),
            firsts AS (
                SELECT DISTINCT ON (item_id) item_id, previous_count AS beginning
                FROM logs ORDER BY item_id, changed_at ASC
            ),
            lasts AS (
                SELECT DISTINCT ON (item_id) item_id, current_count AS ending
                FROM logs ORDER BY item_id, changed_at DESC
            )
            SELECT i.item_id, i.name, i.unit,
                   COALESCE(f.beginning, i.quantity) AS beginning,
                   COALESCE(a.delivered, 0) AS delivered,
                   COALESCE(a.consumed, 0) AS consumed,
                   COALESCE(l.ending, i.quantity) AS ending
            FROM inventory_items i
            LEFT JOIN agg a ON a.item_id = i.item_id
            LEFT JOIN firsts f ON f.item_id = i.item_id
            LEFT JOIN lasts l ON l.item_id = i.item_id
            WHERE " . ($itemId > 0 ? 'i.item_id = :item3' : 'i.category ILIKE :cat3') . "
              AND (a.delivered IS NOT NULL OR a.consumed IS NOT NULL)
            ORDER BY i.name ASC
        ";
        $stS = $pdo->prepare($summarySql);
        $paramsS = ['s' => $start, 'e' => $end];
        if ($itemId > 0) { $paramsS += ['item2' => $itemId, 'item3' => $itemId]; }
        else { $paramsS += ['cat2' => $category !== '' ? $category : '%', 'cat3' => $category !== '' ? $category : '%']; }
        $stS->execute($paramsS);
        foreach ($stS->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $summary[] = [
                'name' => (string)$r['name'],
                'beginning' => (int)$r['beginning'],
                'delivered' => (int)$r['delivered'],
                'consumed' => (int)$r['consumed'],
                'ending' => (int)$r['ending'],
            ];
        }
        $meta = [
            $itemId > 0 ? 'Item' : 'Category' => $itemId > 0 ? (string)($summary[0]['name'] ?? ('Item #' . $itemId)) : ($category !== '' ? $category : 'All'),
            'Period' => date('Y-m-d', strtotime($start)) . ' to ' . date('Y-m-d', strtotime($end . ' -1 day')),
            'Prepared By' => (string)($_SESSION['full_name'] ?? ('User #' . (int)($_SESSION['user_id'] ?? 0))),
            'Prepared At' => date('Y-m-d H:i'),
            '_summary' => $summary,
        ];
        $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $slug = $itemId > 0 ? ('item' . $itemId) : ($category !== '' ? preg_replace('/[^A-Za-z0-9_-]+/', '_', $category) : 'All');
        $fileBase = 'consumption_' . $slug . '_' . date('Ymd_His') . '.pdf';
        $abs = $dir . DIRECTORY_SEPARATOR . $fileBase;
        $pdf = new \App\Services\PDFService();
        $pdf->generateConsumptionReportPDF($meta, $rows, 'F', $abs);
        try {
            $ins = $pdo->prepare('INSERT INTO report_archives (report_type, category, prepared_by, prepared_name, prepared_at, file_name, file_path) VALUES (:t,:c,:u,:n,:at,:fn,:fp)');
            $ins->execute([
                't' => 'consumption',
                'c' => $itemId > 0 ? null : ($category !== '' ? $category : null),
                'u' => (int)($_SESSION['user_id'] ?? 0),
                'n' => (string)($_SESSION['full_name'] ?? ''),
                'at' => date('Y-m-d H:i:s'),
                'fn' => $fileBase,
                'fp' => $abs,
            ]);
        } catch (\Throwable $ignored) {}
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . basename($fileBase) . '"');
        header('Content-Length: ' . (string)@filesize($abs));
        @readfile($abs);
    }

    public function reportsModule(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        // Prepare simple category options and current month for convenience
        $categories = ['Office Supplies','Medical Equipments','Medicines','Machines','Electronics','Appliances'];
        $month = isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m');
        // Load items for dependent Item select
        $branchId = $_SESSION['branch_id'] ?? null;
        $items = $this->inventory()->listInventory($branchId ? (int)$branchId : null);
        // Load recent (active) reports histories per type
        $consumptionReports = [];
        $inventoryReports = [];
        try {
            $pdo = \App\Database\Connection::resolve();
            // Ensure archive table has new lifecycle columns
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE");
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ");
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL");
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS restored_at TIMESTAMPTZ");
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS restored_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL");
            $st1 = $pdo->query("SELECT id, report_type, category, file_name, file_path, prepared_name, prepared_at FROM report_archives WHERE report_type = 'consumption' AND COALESCE(is_archived, FALSE) = FALSE ORDER BY prepared_at DESC LIMIT 20");
            $consumptionReports = $st1 ? $st1->fetchAll(\PDO::FETCH_ASSOC) : [];
            $st2 = $pdo->query("SELECT id, report_type, category, file_name, file_path, prepared_name, prepared_at FROM report_archives WHERE report_type = 'inventory' AND COALESCE(is_archived, FALSE) = FALSE ORDER BY prepared_at DESC LIMIT 20");
            $inventoryReports = $st2 ? $st2->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $ignored) {}
        $this->render('dashboard/reports_module.php', [
            'categories' => $categories,
            'month' => $month,
            'items' => $items,
            'recent_consumption' => $consumptionReports,
            'recent_inventory' => $inventoryReports,
        ]);
    }

    public function reportsList(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
        $show = isset($_GET['show']) ? trim((string)$_GET['show']) : 'archived'; // archived|active|all
        $category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
        $pdo = \App\Database\Connection::resolve();
        // Ensure lifecycle columns
        $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ");
        $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL");
        $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS restored_at TIMESTAMPTZ");
        $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS restored_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL");
        $sql = 'SELECT id, report_type, category, file_name, file_path, prepared_name, prepared_at, COALESCE(is_archived, FALSE) AS is_archived, archived_at, restored_at FROM report_archives';
        $conds = [];
        $params = [];
        if ($type !== '' && in_array($type, ['inventory','consumption'], true)) { $conds[] = 'report_type = :t'; $params['t'] = $type; }
        if ($category !== '') { $conds[] = 'category ILIKE :c'; $params['c'] = $category; }
        if ($show === 'archived') { $conds[] = 'COALESCE(is_archived, FALSE) = TRUE'; }
        elseif ($show === 'active') { $conds[] = 'COALESCE(is_archived, FALSE) = FALSE'; }
        if ($conds) { $sql .= ' WHERE ' . implode(' AND ', $conds); }
        $sql .= ' ORDER BY prepared_at DESC LIMIT 500';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $list = $st->fetchAll(\PDO::FETCH_ASSOC);
        $this->render('dashboard/reports.php', ['reports' => $list, 'filter_type' => $type, 'filter_category' => $category, 'show' => $show]);
    }

    /** Mark a report as archived (hidden from active lists) */
    public function archiveReport(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) { header('Location: /admin-assistant/reports?error=Invalid+ID'); return; }
        try {
            $pdo = \App\Database\Connection::resolve();
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE");
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ");
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL");
            $st = $pdo->prepare('UPDATE report_archives SET is_archived = TRUE, archived_at = NOW(), archived_by = :by WHERE id = :id');
            $st->execute(['id' => $id, 'by' => (int)($_SESSION['user_id'] ?? 0)]);
            header('Location: /admin-assistant/reports?archived=1');
        } catch (\Throwable $e) {
            header('Location: /admin-assistant/reports?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Restore an archived report back to active list */
    public function restoreReport(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) { header('Location: /admin-assistant/reports/archives?error=Invalid+ID'); return; }
        try {
            $pdo = \App\Database\Connection::resolve();
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE");
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS restored_at TIMESTAMPTZ");
            $pdo->exec("ALTER TABLE report_archives ADD COLUMN IF NOT EXISTS restored_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL");
            $st = $pdo->prepare('UPDATE report_archives SET is_archived = FALSE, restored_at = NOW(), restored_by = :by WHERE id = :id');
            $st->execute(['id' => $id, 'by' => (int)($_SESSION['user_id'] ?? 0)]);
            header('Location: /admin-assistant/reports/archives?restored=1');
        } catch (\Throwable $e) {
            header('Location: /admin-assistant/reports/archives?error=' . rawurlencode($e->getMessage()));
        }
    }

    public function downloadReport(): void
    {
        if (!$this->auth()->isAuthenticated()) { header('Location: /login'); return; }
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) { header('Location: /admin-assistant/reports'); return; }
        try {
            $pdo = \App\Database\Connection::resolve();
            $st = $pdo->prepare('SELECT file_name, file_path FROM report_archives WHERE id = :id');
            $st->execute(['id' => $id]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row) { header('Location: /admin-assistant/reports'); return; }
            $path = (string)$row['file_path']; $name = (string)$row['file_name'];
            if (!is_file($path)) { header('Location: /admin-assistant/reports?error=File+missing'); return; }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . basename($name) . '"');
            header('Content-Length: ' . (string)@filesize($path));
            @readfile($path);
        } catch (\Throwable $e) {
            header('Location: /admin-assistant/reports?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Purchase Request create form */
    public function newRequest(): void
    {
    $role = $_SESSION['role'] ?? '';
    // Normalize legacy role name to the new alias used across routes
    if ($role === 'custodian') { $role = 'admin_assistant'; }
    if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant', 'admin'], true)) { header('Location: /login'); return; }
        $branchId = $_SESSION['branch_id'] ?? null;
        $items = $this->inventory()->listInventory($branchId ? (int)$branchId : null);
        $prPreview = $this->requests()->getNextPrNumberPreview();
    $this->render('custodian/request_create.php', [ 'items' => $items, 'pr_preview' => $prPreview ]);
    }

    /** Handle Purchase Request submission */
    public function createRequest(): void
    {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'custodian') { $role = 'admin_assistant'; }
    if (!$this->auth()->isAuthenticated() || !in_array($role, ['admin_assistant', 'admin'], true)) { header('Location: /login'); return; }
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
        // Prevent duplicate active PR for the same item in this branch
        $itemIdCheck = (int)($payload['item_id'] ?? 0);
        if ($itemIdCheck > 0 && $this->requests()->hasActiveRequestForItemBranch($itemIdCheck, $branchId)) {
            header('Location: /admin-assistant/requests/new?error=duplicate_for_item');
            return;
        }
        $req = $this->requests()->createPurchaseRequest($payload, $userId);
        // Optional: notify all procurement managers by message
        try {
            $pdo = \App\Database\Connection::resolve();
            $managers = $pdo->query("SELECT user_id FROM users WHERE role IN ('procurement_manager','procurement') AND is_active = TRUE")->fetchAll();
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
            // Resolve user name for Prepared By
            $byName = null;
            try {
                $pdo = \App\Database\Connection::resolve();
                $stN = $pdo->prepare('SELECT full_name FROM users WHERE user_id = :id');
                $stN->execute(['id' => $userId]);
                $byName = (string)($stN->fetchColumn() ?: '');
            } catch (\Throwable $ignored) { $byName = ''; }
            $pdf->generatePurchaseRequestPDF([
                'Request ID' => (int)$req['request_id'],
                'PR Number' => (string)($req['pr_number'] ?? ''),
                'Request Type' => (string)$payload['request_type'],
                'Quantity' => (int)$payload['quantity'],
                'Unit' => (string)$payload['unit'],
                'Justification' => (string)$payload['justification'],
                'Branch ID' => (int)$branchId,
                'Prepared By' => ($byName !== '' ? $byName : ('User #' . (int)$userId)),
                'Prepared At' => date('Y-m-d H:i'),
                'Requested By' => (int)$userId,
                'Status' => (string)($req['status'] ?? 'pending'),
            ]);
            return; // PDF sent to browser
        }
    header('Location: /admin-assistant/requests/new?created=1');
    }
}
 
