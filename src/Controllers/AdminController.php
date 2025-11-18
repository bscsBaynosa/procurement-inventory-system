<?php

namespace App\Controllers;

use App\Services\InventoryService;
use App\Services\RequestService;
use App\Database\Connection;
use PDO;

class AdminController extends BaseController
{
    private ?PDO $pdo = null;
    private ?InventoryService $inventory = null;
    private ?RequestService $requests = null;

    public function __construct(?PDO $pdo = null)
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $this->pdo = $pdo ?? Connection::resolve();
    }

    private function inventory(): InventoryService
    {
        if (!$this->inventory) { $this->inventory = new InventoryService($this->pdo); }
        return $this->inventory;
    }

    private function requests(): RequestService
    {
        if (!$this->requests) { $this->requests = new RequestService($this->pdo); }
        return $this->requests;
    }

    public function dashboard(): void
    {
        try {
            // Summary counts
            $counts = [
                'users_total' => 0,
                'users_active' => 0,
                'branches' => 0,
                'managers' => 0,            // procurement_manager
                'procurement' => 0,         // procurement (staff)
                'admin_assistants' => 0,
                'suppliers' => 0,
                'requests' => [
                    'pending' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                    'for_admin_approval' => 0,
                    'canvassing_submitted' => 0,
                    'canvassing_approved' => 0,
                ],
                // Keep only overall inventory stats; no per-branch inventory table on dashboard
                'inventory' => $this->inventory()->getStatsByBranch(null),
            ];

            // Users
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
            $counts['users_total'] = (int)$stmt->fetchColumn();
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM users WHERE is_active = TRUE');
            $counts['users_active'] = (int)$stmt->fetchColumn();
            // Branches
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM branches');
            $counts['branches'] = (int)$stmt->fetchColumn();
            // Role counts (active only; split out managers vs procurement vs suppliers)
            $stmt = $this->pdo->query("SELECT role, COUNT(*) AS c FROM users WHERE is_active = TRUE GROUP BY role");
            foreach ($stmt->fetchAll() as $row) {
                $role = (string)$row['role'];
                $c = (int)$row['c'];
                if ($role === 'procurement_manager') { $counts['managers'] += $c; }
                if ($role === 'procurement') { $counts['procurement'] += $c; }
                if ($role === 'admin_assistant' || $role === 'custodian') { $counts['admin_assistants'] += $c; }
                if ($role === 'supplier') { $counts['suppliers'] += $c; }
            }

            // Requests by status (incoming)
            // Exclude archived for exact current numbers
            try { $this->pdo->exec("ALTER TABLE purchase_requests ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE"); } catch (\Throwable $e) {}
            $rs = $this->pdo->query("SELECT status, COUNT(*) AS c FROM purchase_requests WHERE COALESCE(is_archived, FALSE) = FALSE GROUP BY status");
            foreach ($rs->fetchAll() as $row) {
                $status = (string)$row['status'];
                $c = (int)$row['c'];
                if (!isset($counts['requests'][$status])) { $counts['requests'][$status] = 0; }
                $counts['requests'][$status] += $c;
            }

            // Recent activity: last 6 requests
            $recent = $this->pdo->query(
                "SELECT pr.request_id, pr.pr_number, pr.status, pr.created_at, i.name AS item_name, b.name AS branch_name
                 FROM purchase_requests pr
                 LEFT JOIN inventory_items i ON i.item_id = pr.item_id
                 LEFT JOIN branches b ON b.branch_id = pr.branch_id
                 WHERE COALESCE(pr.is_archived, FALSE) = FALSE
                 ORDER BY pr.created_at DESC
                 LIMIT 6"
            )->fetchAll();

            // Build simple 6-month trend series using generate_series for three metrics
            $seriesIncoming = $this->pdo->query("
                WITH months AS (
                    SELECT generate_series(date_trunc('month', CURRENT_DATE) - INTERVAL '5 months',
                                           date_trunc('month', CURRENT_DATE),
                                           interval '1 month') AS m
                )
                SELECT to_char(m, 'YYYY-MM') AS ym,
                       COALESCE(cnt, 0) AS v
                FROM months
                LEFT JOIN (
                    SELECT date_trunc('month', created_at) AS mth, COUNT(*) AS cnt
                    FROM purchase_requests
                    GROUP BY 1
                ) pr ON pr.mth = months.m
                ORDER BY m
            ")->fetchAll();

            $seriesPO = $this->pdo->query("
                WITH months AS (
                    SELECT generate_series(date_trunc('month', CURRENT_DATE) - INTERVAL '5 months',
                                           date_trunc('month', CURRENT_DATE),
                                           interval '1 month') AS m
                )
                SELECT to_char(m, 'YYYY-MM') AS ym,
                       COALESCE(cnt, 0) AS v
                FROM months
                LEFT JOIN (
                    SELECT date_trunc('month', created_at) AS mth, COUNT(*) AS cnt
                    FROM purchase_requests
                    WHERE request_type = 'purchase_order'
                      AND status IN ('approved','in_progress','completed')
                    GROUP BY 1
                ) po ON po.mth = months.m
                ORDER BY m
            ")->fetchAll();

            $seriesInventory = $this->pdo->query("
                WITH months AS (
                    SELECT generate_series(date_trunc('month', CURRENT_DATE) - INTERVAL '5 months',
                                           date_trunc('month', CURRENT_DATE),
                                           interval '1 month') AS m
                )
                SELECT to_char(m, 'YYYY-MM') AS ym,
                       COALESCE(SUM(abs(quantity_delta))::INT, 0) AS v
                FROM months
                LEFT JOIN inventory_movements im ON date_trunc('month', im.performed_at) = months.m
                GROUP BY m
                ORDER BY m
            ")->fetchAll();

            // Current user for greeting and badges
            $meId = (int)($_SESSION['user_id'] ?? 0);
            $meName = null; $meFirst = null; $avatarPath = null; $unread = 0;
            if ($meId > 0) {
                $st = $this->pdo->prepare('SELECT full_name, first_name, avatar_path FROM users WHERE user_id = :id');
                $st->execute(['id' => $meId]);
                $row = $st->fetch();
                if ($row) {
                    $meName = (string)($row['full_name'] ?? '');
                    $meFirst = (string)($row['first_name'] ?? '');
                    $avatarPath = (string)($row['avatar_path'] ?? '');
                }
                try {
                    $stU = $this->pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = :me AND is_read = FALSE');
                    $stU->execute(['me' => $meId]);
                    $unread = (int)$stU->fetchColumn();
                } catch (\Throwable $ignored) {}
            }

            $this->render('dashboard/admin.php', [
                'counts' => $counts,
                'recent' => $recent,
                'items' => [],
                'series_incoming' => array_map(fn($r) => (int)$r['v'], $seriesIncoming ?: []),
                'series_po' => array_map(fn($r) => (int)$r['v'], $seriesPO ?: []),
                'series_inventory' => array_map(fn($r) => (int)$r['v'], $seriesInventory ?: []),
                'me_name' => $meName,
                'me_first' => $meFirst ?: ($meName ? explode(' ', $meName)[0] : 'User'),
                'unread_count' => $unread,
                'avatar_path' => $avatarPath,
            ]);
        } catch (\Throwable $e) {
            // Friendly guidance if DB not initialized or migrations not run
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            $scheme = $forwarded !== '' ? explode(',', $forwarded)[0] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $setupUrl = $scheme . '://' . $host . '/setup?token=YOUR_TOKEN';
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Setup required</title>';
            echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px;background:#0b0b0b;color:#e5e7eb} .box{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:18px;max-width:800px} a{color:#22c55e;text-decoration:none} .dim{color:#94a3b8} pre{white-space:pre-wrap;background:#0b0b0b;padding:12px;border-radius:8px;overflow:auto}</style>';
            echo '</head><body><div class="box">';
            echo '<h2>Database setup required</h2>';
            echo '<p class="dim">The database may not be initialized yet. Run the one-time setup route to apply the schema and seed the admin user.</p>';
            echo '<ol><li>Set the <code>SETUP_TOKEN</code> config var in Heroku.</li><li>Visit <code>' . htmlspecialchars($setupUrl, ENT_QUOTES, 'UTF-8') . '</code> on this app.</li></ol>';
            echo '<p class="dim">After that, reload this page.</p>';
            echo '<details><summary>Error details</summary><pre>' . $msg . '</pre></details>';
            echo '</div></body></html>';
        }
    }

    /**
     * POST: Approve a submitted Purchase Order and notify Procurement and Supplier.
     */
    public function approvePO(): void
    {
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $poId = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
        if ($pr === '') { header('Location: /inbox?error=No+PR'); return; }
        // Find the PO
        $pk = \App\Database\SchemaHelper::getPoPrimaryKey($this->pdo);
        $selectSql = $poId > 0
            ? ('SELECT * FROM purchase_orders WHERE ' . $pk . ' = :id AND pr_number = :pr')
            : ("SELECT * FROM purchase_orders WHERE pr_number = :pr ORDER BY created_at DESC LIMIT 1");
        $st = $this->pdo->prepare($selectSql);
        $st->execute($poId > 0 ? ['id' => $poId, 'pr' => $pr] : ['pr' => $pr]);
        $po = $st->fetch();
        if (!$po) { header('Location: /inbox?error=PO+not+found'); return; }
        $idVal = (int)($po[$pk] ?? 0);
        $this->pdo->prepare("UPDATE purchase_orders SET status='po_admin_approved', updated_at=NOW() WHERE $pk = :id")
            ->execute(['id' => $idVal]);
        // Notify Procurement (all) and Supplier with the PO attachment
        try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}
        $subject = 'PO Approved • PR ' . $pr . ' • PO ' . (string)$po['po_number'];
        $body = 'Your Purchase Order has been approved by Admin.';
        $file = (string)($po['pdf_path'] ?? '');
        // Procurement recipients
        $man = $this->pdo->query("SELECT user_id FROM users WHERE is_active=TRUE AND role IN ('procurement_manager','procurement')")->fetchAll();
        if ($man) {
            $ins = $this->pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, attachment_name, attachment_path) VALUES (:s,:r,:j,:b,:an,:ap)');
            foreach ($man as $row) { $ins->execute(['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => (int)$row['user_id'], 'j' => $subject, 'b' => $body, 'an' => ($file !== '' ? basename($file) : null), 'ap' => ($file !== '' ? $file : null)]); }
        }
        // Do not auto-send to Supplier; Procurement will send after Admin approval.
        // Update PR group status
        try { $this->requests()->updateGroupStatus($pr, 'po_admin_approved', (int)($_SESSION['user_id'] ?? 0), 'PO approved by admin'); } catch (\Throwable $ignored) {}
        header('Location: /inbox?po_approved=1');
    }

    /**
     * POST: Reject a submitted Purchase Order
     */
    public function rejectPO(): void
    {
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $poId = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($pr === '') { header('Location: /inbox?error=No+PR'); return; }
        $pk = \App\Database\SchemaHelper::getPoPrimaryKey($this->pdo);
        $selectSql = $poId > 0
            ? ('SELECT * FROM purchase_orders WHERE ' . $pk . ' = :id AND pr_number = :pr')
            : ("SELECT * FROM purchase_orders WHERE pr_number = :pr ORDER BY created_at DESC LIMIT 1");
        $st = $this->pdo->prepare($selectSql);
        $st->execute($poId > 0 ? ['id' => $poId, 'pr' => $pr] : ['pr' => $pr]);
        $po = $st->fetch();
        if (!$po) { header('Location: /inbox?error=PO+not+found'); return; }
        $idVal = (int)($po[$pk] ?? 0);
        $this->pdo->prepare("UPDATE purchase_orders SET status='po_rejected', updated_at=NOW() WHERE $pk = :id")
            ->execute(['id' => $idVal]);
        try { $this->requests()->updateGroupStatus($pr, 'po_rejected', (int)($_SESSION['user_id'] ?? 0), $reason !== '' ? ('PO rejected: ' . $reason) : 'PO rejected'); } catch (\Throwable $ignored) {}
        header('Location: /inbox?po_rejected=1');
    }

    /** Admin: Archive a Purchase Order (soft-delete via is_archived flag). */
    public function archivePO(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: /login'); return; }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) { header('Location: /admin/pos?error=Invalid+PO'); return; }
        try {
            $pdo = $this->pdo;
            try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE"); } catch (\Throwable $e) {}
            $idCol = 'id';
            try {
                $hasId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='id'")->fetchColumn();
                if (!$hasId) {
                    $hasPoId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='po_id'")->fetchColumn();
                    if ($hasPoId) { $idCol = 'po_id'; }
                }
            } catch (\Throwable $e) {}
            // Ensure timestamp columns exist
            try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL"); } catch (\Throwable $e) {}
            $st = $pdo->prepare("UPDATE purchase_orders SET is_archived = TRUE, archived_at = NOW(), archived_by = :by, updated_at = NOW() WHERE " . $idCol . " = :id");
            $st->execute(['id' => $id, 'by' => (int)($_SESSION['user_id'] ?? 0)]);
            header('Location: /admin/pos?archived=1');
        } catch (\Throwable $e) {
            header('Location: /admin/pos?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Admin: Restore an archived Purchase Order. */
    public function restorePO(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: /login'); return; }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) { header('Location: /admin/pos?show=archived&error=Invalid+PO'); return; }
        try {
            $pdo = $this->pdo;
            try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE"); } catch (\Throwable $e) {}
            $idCol = 'id';
            try {
                $hasId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='id'")->fetchColumn();
                if (!$hasId) {
                    $hasPoId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='po_id'")->fetchColumn();
                    if ($hasPoId) { $idCol = 'po_id'; }
                }
            } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS restored_at TIMESTAMPTZ"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS restored_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL"); } catch (\Throwable $e) {}
            $st = $pdo->prepare("UPDATE purchase_orders SET is_archived = FALSE, restored_at = NOW(), restored_by = :by, updated_at = NOW() WHERE " . $idCol . " = :id");
            $st->execute(['id' => $id, 'by' => (int)($_SESSION['user_id'] ?? 0)]);
            header('Location: /admin/pos?show=archived&restored=1');
        } catch (\Throwable $e) {
            header('Location: /admin/pos?show=archived&error=' . rawurlencode($e->getMessage()));
        }
    }

    /**
     * POST: Approve a submitted Request For Payment (RFP) and notify Procurement.
     */
    public function approveRFP(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $poNumber = isset($_POST['po_number']) ? trim((string)$_POST['po_number']) : '';
        $msgId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        // Notify procurement team
        try {
            // If original message has an attachment, forward it along with the approval notice
            $attName = null; $attPath = null;
            if ($msgId > 0) {
                try {
                    $stM = $this->pdo->prepare('SELECT attachment_name, attachment_path FROM messages WHERE id = :id');
                    $stM->execute(['id' => $msgId]);
                    $m = $stM->fetch();
                    if ($m) { $attName = (string)($m['attachment_name'] ?? ''); $attPath = (string)($m['attachment_path'] ?? ''); }
                } catch (\Throwable $ignored) {}
            }
            // Ensure messages has attachment columns
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}

            $recips = $this->pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement')")->fetchAll();
            if ($recips) {
                $hasAtt = ($attName !== null && $attName !== '' && $attPath !== null && $attPath !== '');
                $sql = $hasAtt
                    ? 'INSERT INTO messages (sender_id, recipient_id, subject, body, attachment_name, attachment_path) VALUES (:s,:r,:j,:b,:an,:ap)'
                    : 'INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)';
                $ins = $this->pdo->prepare($sql);
                $subject = 'RFP Approved' . ($pr !== '' ? (' • PR ' . $pr) : '') . ($poNumber !== '' ? (' • PO ' . $poNumber) : '');
                $body = 'Admin approved the Request For Payment.';
                foreach ($recips as $r) {
                    $params = ['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => (int)$r['user_id'], 'j' => $subject, 'b' => $body];
                    if ($hasAtt) { $params['an'] = basename($attName); $params['ap'] = $attPath; }
                    $ins->execute($params);
                }
            }
            if ($msgId > 0) {
                $st = $this->pdo->prepare('UPDATE messages SET is_read = TRUE WHERE id = :id AND recipient_id = :me');
                $st->execute(['id' => $msgId, 'me' => (int)($_SESSION['user_id'] ?? 0)]);
            }
            header('Location: /inbox?rfp_approved=1');
        } catch (\Throwable $e) {
            header('Location: /inbox?error=' . rawurlencode($e->getMessage()));
        }
    }

    /**
     * POST: Reject a submitted Request For Payment (RFP) and notify Procurement.
     */
    public function rejectRFP(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $poNumber = isset($_POST['po_number']) ? trim((string)$_POST['po_number']) : '';
        $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
        $msgId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        try {
            // Fetch original attachment, if any, to include in rejection notice
            $attName = null; $attPath = null;
            if ($msgId > 0) {
                try {
                    $stM = $this->pdo->prepare('SELECT attachment_name, attachment_path FROM messages WHERE id = :id');
                    $stM->execute(['id' => $msgId]);
                    $m = $stM->fetch();
                    if ($m) { $attName = (string)($m['attachment_name'] ?? ''); $attPath = (string)($m['attachment_path'] ?? ''); }
                } catch (\Throwable $ignored) {}
            }
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}

            $recips = $this->pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement')")->fetchAll();
            if ($recips) {
                $hasAtt = ($attName !== null && $attName !== '' && $attPath !== null && $attPath !== '');
                $sql = $hasAtt
                    ? 'INSERT INTO messages (sender_id, recipient_id, subject, body, attachment_name, attachment_path) VALUES (:s,:r,:j,:b,:an,:ap)'
                    : 'INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)';
                $ins = $this->pdo->prepare($sql);
                $subject = 'RFP Rejected' . ($pr !== '' ? (' • PR ' . $pr) : '') . ($poNumber !== '' ? (' • PO ' . $poNumber) : '');
                $body = 'Admin rejected the Request For Payment.' . ($reason !== '' ? ("\n\nReason: " . $reason) : '');
                foreach ($recips as $r) {
                    $params = ['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => (int)$r['user_id'], 'j' => $subject, 'b' => $body];
                    if ($hasAtt) { $params['an'] = basename($attName); $params['ap'] = $attPath; }
                    $ins->execute($params);
                }
            }
            if ($msgId > 0) {
                $st = $this->pdo->prepare('UPDATE messages SET is_read = TRUE WHERE id = :id AND recipient_id = :me');
                $st->execute(['id' => $msgId, 'me' => (int)($_SESSION['user_id'] ?? 0)]);
            }
            header('Location: /inbox?rfp_rejected=1');
        } catch (\Throwable $e) {
            header('Location: /inbox?error=' . rawurlencode($e->getMessage()));
        }
    }

    /**
     * Simple Users page for admins: list users and provide a quick create form.
     */
    public function users(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }

        try {
            $list = $this->pdo->query('SELECT user_id, username, first_name, last_name, full_name, email, role, is_active, branch_id, created_at FROM users ORDER BY created_at DESC LIMIT 100')->fetchAll();
            $branches = $this->pdo->query('SELECT branch_id, name FROM branches WHERE is_active = TRUE ORDER BY name ASC')->fetchAll();
            // Branches already handled by an Admin Assistant (reserved)
            $reserved = $this->pdo->query("SELECT DISTINCT branch_id FROM users WHERE is_active = TRUE AND role = 'admin_assistant' AND branch_id IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN);
            $reservedSet = [];
            foreach ($reserved as $bid) { $reservedSet[(int)$bid] = true; }
            $branchesUnassigned = [];
            foreach ($branches as $b) { if (empty($reservedSet[(int)$b['branch_id']])) { $branchesUnassigned[] = $b; } }
            $created = isset($_GET['created']) ? true : false;
            $error = isset($_GET['error']) ? (string)$_GET['error'] : '';
            $editUser = null;
            $branchesForEdit = $branchesUnassigned; // default
            if (isset($_GET['edit'])) {
                $id = (int)$_GET['edit'];
                if ($id > 0) {
                    $st = $this->pdo->prepare('SELECT user_id, username, first_name, last_name, email, role, is_active, branch_id FROM users WHERE user_id = :id');
                    $st->execute(['id' => $id]);
                    $editUser = $st->fetch();
                    // If editing an admin assistant, allow their currently assigned branch too
                    if ($editUser && ($editUser['role'] ?? '') === 'admin_assistant') {
                        $currentBid = $editUser['branch_id'] ?? null;
                        if ($currentBid !== null) {
                            $currentBid = (int)$currentBid;
                            $present = false;
                            foreach ($branchesUnassigned as $b) { if ((int)$b['branch_id'] === $currentBid) { $present = true; break; } }
                            if (!$present) {
                                foreach ($branches as $b) {
                                    if ((int)$b['branch_id'] === $currentBid) { $branchesForEdit[] = $b; break; }
                                }
                            }
                        }
                    }
                }
            }
            $this->render('dashboard/users.php', [
                'users' => $list,
                'branches' => $branches,
                'branches_unassigned' => $branchesUnassigned,
                'branches_for_edit' => $branchesForEdit,
                'created' => $created,
                'error' => $error,
                'editUser' => $editUser
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '42703') { // undefined_column first_name/last_name on legacy DB
                try {
                    $this->pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(120) NOT NULL DEFAULT ''");
                    $this->pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(120) NOT NULL DEFAULT ''");
                    // Backfill from full_name where needed
                    $this->pdo->exec(<<<SQL
                        UPDATE users SET
                            first_name = CASE WHEN first_name = '' THEN COALESCE(split_part(full_name, ' ', 1), full_name) ELSE first_name END,
                            last_name = CASE WHEN last_name = '' THEN COALESCE(nullif(split_part(full_name, ' ', array_length(regexp_split_to_array(full_name, '\\s+'), 1)), ''), full_name) ELSE last_name END
                        WHERE first_name = '' OR last_name = '';
                    SQL);
                    // Retry load
                    $list = $this->pdo->query('SELECT user_id, username, first_name, last_name, full_name, email, role, is_active, branch_id, created_at FROM users ORDER BY created_at DESC LIMIT 100')->fetchAll();
                    $branches = $this->pdo->query('SELECT branch_id, name FROM branches WHERE is_active = TRUE ORDER BY name ASC')->fetchAll();
                    $reserved = $this->pdo->query("SELECT DISTINCT branch_id FROM users WHERE is_active = TRUE AND role = 'admin_assistant' AND branch_id IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN);
                    $reservedSet = [];
                    foreach ($reserved as $bid) { $reservedSet[(int)$bid] = true; }
                    $branchesUnassigned = [];
                    foreach ($branches as $b) { if (empty($reservedSet[(int)$b['branch_id']])) { $branchesUnassigned[] = $b; } }
                    $created = isset($_GET['created']) ? true : false;
                    $error = isset($_GET['error']) ? (string)$_GET['error'] : '';
                    $editUser = null;
                    $branchesForEdit = $branchesUnassigned;
                    if (isset($_GET['edit'])) {
                        $id = (int)$_GET['edit'];
                        if ($id > 0) {
                            $st = $this->pdo->prepare('SELECT user_id, username, first_name, last_name, email, role, is_active, branch_id FROM users WHERE user_id = :id');
                            $st->execute(['id' => $id]);
                            $editUser = $st->fetch();
                            if ($editUser && ($editUser['role'] ?? '') === 'admin_assistant') {
                                $currentBid = $editUser['branch_id'] ?? null;
                                if ($currentBid !== null) {
                                    $currentBid = (int)$currentBid;
                                    $present = false;
                                    foreach ($branchesUnassigned as $b) { if ((int)$b['branch_id'] === $currentBid) { $present = true; break; } }
                                    if (!$present) {
                                        foreach ($branches as $b) {
                                            if ((int)$b['branch_id'] === $currentBid) { $branchesForEdit[] = $b; break; }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    $this->render('dashboard/users.php', [
                        'users' => $list,
                        'branches' => $branches,
                        'branches_unassigned' => $branchesUnassigned,
                        'branches_for_edit' => $branchesForEdit,
                        'created' => $created,
                        'error' => $error,
                        'editUser' => $editUser
                    ]);
                    $adminId = (int)($_SESSION['user_id'] ?? 0);
                    $st = $this->pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id');
                    $st->execute(['id' => $adminId]);
                    // Fallback completed; stop here after successful rerender
                    return;
                } catch (\Throwable $ee) {
                    $msg = rawurlencode($ee->getMessage());
                    header('Location: /admin/users?error=' . $msg);
                    return;
                }
            }
            // If a different SQL error occurred, show a simple message
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading users: ' . $e->getMessage();
        }
    }

    /** Create a new user (admin). */
    public function createUser(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }

        $username = trim((string)($_POST['username'] ?? ''));
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = (string)($_POST['role'] ?? '');
        $branchId = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int)$_POST['branch_id'] : null;
        $password = (string)($_POST['password'] ?? '');
    $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');

        if ($username === '' || $firstName === '' || $lastName === '' || $role === '' || $password === '') {
            header('Location: /admin/users?error=Missing+required+fields');
            return;
        }

        // Confirm admin identity by password (extra security)
        if ($adminPasswordConfirm === '') {
            header('Location: /admin/users?error=Admin+password+required');
            return;
        }
        try {
            $adminId = (int)($_SESSION['user_id'] ?? 0);
            $st = $this->pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id AND role = \CAST(:r AS user_role)');
            // Some DBs can't cast parameterized enums directly, fallback to string compare
        } catch (\Throwable $ignored) {}
        try {
            $st = $this->pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id AND role = \CAST(\'admin\' AS user_role)');
            $st->execute(['id' => $adminId]);
        } catch (\Throwable $e) {
            // Fallback when enum cast is problematic
            $st = $this->pdo->prepare("SELECT password_hash FROM users WHERE user_id = :id AND role = 'admin'");
            $st->execute(['id' => $adminId]);
        }
        $hashRow = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$hashRow || !password_verify($adminPasswordConfirm, (string)$hashRow['password_hash'])) {
            header('Location: /admin/users?error=Invalid+admin+password');
            return;
        }

        // Ensure new roles exist
        try { $this->pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'supplier'"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'admin_assistant'"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'procurement'"); } catch (\Throwable $e) {}
        // Accept only updated roles (legacy roles no longer allowed for creation)
        if (!in_array($role, ['admin','admin_assistant','procurement','supplier'], true)) {
            header('Location: /admin/users?error=Invalid+role');
            return;
        }

        // Enforce branch constraints
        if ($role === 'admin_assistant') {
            if ($branchId === null) { header('Location: /admin/users?error=Branch+is+required+for+Admin+Assistant'); return; }
            $chk = $this->pdo->prepare("SELECT user_id FROM users WHERE is_active = TRUE AND role = 'admin_assistant' AND branch_id = :b LIMIT 1");
            $chk->execute(['b' => $branchId]);
            if ($chk->fetch()) { header('Location: /admin/users?error=This+branch+already+has+an+Admin+Assistant'); return; }
        } else {
            // Non-admin_assistant roles shouldn't carry a branch assignment
            $branchId = null;
        }

        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $this->pdo->prepare('INSERT INTO users (username, password_hash, first_name, last_name, full_name, email, role, branch_id, is_active, created_by, updated_by) VALUES (:u, :p, :fn, :ln, :n, :e, :r, :b, TRUE, :by, :by)');
            $by = $_SESSION['user_id'] ?? null;
            $stmt->execute([
                'u' => $username,
                'p' => $hash,
                'fn' => $firstName,
                'ln' => $lastName,
                'n' => trim($firstName . ' ' . $lastName),
                'e' => $email !== '' ? $email : null,
                'r' => $role,
                'b' => $branchId,
                'by' => $by,
            ]);
            header('Location: /admin/users?created=1');
        } catch (\PDOException $e) {
            // If columns don't exist yet (older DB), add them on the fly then retry once
            if ($e->getCode() === '42703') { // undefined_column
                try {
                    $this->pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(120) NOT NULL DEFAULT ''");
                    $this->pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(120) NOT NULL DEFAULT ''");
                    // Retry insert
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $this->pdo->prepare('INSERT INTO users (username, password_hash, first_name, last_name, full_name, email, role, branch_id, is_active, created_by, updated_by) VALUES (:u, :p, :fn, :ln, :n, :e, :r, :b, TRUE, :by, :by)');
                    $by = $_SESSION['user_id'] ?? null;
                    $stmt->execute([
                        'u' => $username,
                        'p' => $hash,
                        'fn' => $firstName,
                        'ln' => $lastName,
                        'n' => trim($firstName . ' ' . $lastName),
                        'e' => $email !== '' ? $email : null,
                        'r' => $role,
                        'b' => $branchId,
                        'by' => $by,
                    ]);
                    header('Location: /admin/users?created=1');
                    return;
                } catch (\Throwable $ee) {
                    $msg = rawurlencode($ee->getMessage());
                    header('Location: /admin/users?error=' . $msg);
                    return;
                }
            }
            $msg = rawurlencode($e->getMessage());
            header('Location: /admin/users?error=' . $msg);
        }
    }

    /** Update an existing user (admin). */
    public function updateUser(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (( $_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }

        $id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = (string)($_POST['role'] ?? '');
        $branchId = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int)$_POST['branch_id'] : null;
        $active = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        if ($id <= 0 || $firstName === '' || $lastName === '' || $role === '') { header('Location: /admin/users?error=Invalid+data'); return; }
        // Enforce branch rules and uniqueness when role is admin_assistant
        if ($role === 'admin_assistant') {
            if ($branchId === null) { header('Location: /admin/users?error=Branch+is+required+for+Admin+Assistant'); return; }
            $chk = $this->pdo->prepare("SELECT user_id FROM users WHERE is_active = TRUE AND role = 'admin_assistant' AND branch_id = :b AND user_id <> :id LIMIT 1");
            $chk->execute(['b' => $branchId, 'id' => $id]);
            if ($chk->fetch()) { header('Location: /admin/users?error=This+branch+already+has+an+Admin+Assistant'); return; }
        } else {
            $branchId = null;
        }
        try {
            $stmt = $this->pdo->prepare('UPDATE users SET first_name = :fn, last_name = :ln, full_name = :n, email = :e, role = :r, branch_id = :b, is_active = :a, updated_by = :by WHERE user_id = :id');
            $stmt->execute([
                'fn' => $firstName,
                'ln' => $lastName,
                'n' => trim($firstName . ' ' . $lastName),
                'e' => $email !== '' ? $email : null,
                'r' => $role,
                'b' => $branchId,
                'a' => $active ? 1 : 0,
                'by' => $_SESSION['user_id'] ?? null,
                'id' => $id,
            ]);
            header('Location: /admin/users');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /admin/users?error=' . $msg);
        }
    }

    /** Delete a user (hard delete). */
    public function deleteUser(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (( $_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        if ($id <= 0) { header('Location: /admin/users?error=Invalid+user'); return; }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM users WHERE user_id = :id');
            $stmt->execute(['id' => $id]);
            header('Location: /admin/users');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /admin/users?error=' . $msg);
        }
    }

    /** Reset a user's password to their last name (surname). */
    public function resetUserPassword(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (( $_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        if ($id <= 0) { header('Location: /admin/users?error=Invalid+user'); return; }
        try {
            $st = $this->pdo->prepare('SELECT last_name FROM users WHERE user_id = :id');
            $st->execute(['id' => $id]);
            $surname = trim((string)$st->fetchColumn());
            if ($surname === '') { header('Location: /admin/users?error=No+surname+on+record'); return; }
            $hash = password_hash($surname, PASSWORD_BCRYPT);
            $up = $this->pdo->prepare('UPDATE users SET password_hash = :p, password_changed_at = NOW() WHERE user_id = :id');
            $up->execute(['p' => $hash, 'id' => $id]);
            header('Location: /admin/users?created=1');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /admin/users?error=' . $msg);
        }
    }

    public function branches(): void
    {
        try {
            $list = $this->pdo->query('SELECT branch_id, code, name, address, is_active, created_at FROM branches ORDER BY name ASC')->fetchAll();
            $editBranch = null;
            if (isset($_GET['edit'])) {
                $id = (int)$_GET['edit'];
                if ($id > 0) {
                    $st = $this->pdo->prepare('SELECT branch_id, code, name, address, is_active FROM branches WHERE branch_id = :id');
                    $st->execute(['id' => $id]);
                    $editBranch = $st->fetch();
                }
            }
            $this->render('dashboard/branches.php', ['branches' => $list, 'editBranch' => $editBranch]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading branches: ' . $e->getMessage();
        }
    }

    public function createBranch(): void
    {
        $code = trim((string)($_POST['code'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        if ($code === '' || $name === '') { header('Location: /admin/branches'); return; }
        try {
            $stmt = $this->pdo->prepare('INSERT INTO branches (code, name, address, is_active) VALUES (:c,:n,:a, TRUE)');
            $stmt->execute(['c' => $code, 'n' => $name, 'a' => $address !== '' ? $address : null]);
            header('Location: /admin/branches');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /admin/branches?error=' . $msg);
        }
    }

    public function updateBranch(): void
    {
        $id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
        $code = trim((string)($_POST['code'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $active = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
        if ($id <= 0 || $code === '' || $name === '') { header('Location: /admin/branches?error=Invalid+data'); return; }
        try {
            $stmt = $this->pdo->prepare('UPDATE branches SET code=:c, name=:n, address=:a, is_active=:s WHERE branch_id=:id');
            $stmt->execute(['c' => $code, 'n' => $name, 'a' => $address !== '' ? $address : null, 's' => $active ? 1 : 0, 'id' => $id]);
            header('Location: /admin/branches');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /admin/branches?error=' . $msg);
        }
    }

    public function deleteBranch(): void
    {
        $id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
        if ($id <= 0) { header('Location: /admin/branches?error=Invalid+branch'); return; }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM branches WHERE branch_id = :id');
            $stmt->execute(['id' => $id]);
            header('Location: /admin/branches');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /admin/branches?error=' . $msg);
        }
    }

    

    public function messages(): void
    {
        $me = (int)($_SESSION['user_id'] ?? 0);
        try {
            $stmt = $this->pdo->prepare('SELECT m.id, m.subject, m.body, m.is_read, m.created_at, u.full_name AS from_name
                FROM messages m JOIN users u ON u.user_id = m.sender_id WHERE m.recipient_id = :me ORDER BY m.created_at DESC LIMIT 50');
            $stmt->execute(['me' => $me]);
            $inbox = $stmt->fetchAll();
            // Decrypt message bodies, if encrypted
            foreach ($inbox as &$row) {
                $row['body'] = \App\Services\CryptoService::maybeDecrypt((string)$row['body']);
            }
            $users = $this->pdo->query("SELECT user_id, full_name, role FROM users WHERE is_active = TRUE ORDER BY role, full_name")->fetchAll();
            // Allow prefill of multiple recipients via to[] or comma-separated to
            $prefillTo = isset($_GET['to']) ? (int)$_GET['to'] : 0;
            $prefillToList = [];
            if (isset($_GET['to'])) {
                if (is_array($_GET['to'])) {
                    foreach ($_GET['to'] as $v) { $id = (int)$v; if ($id > 0) { $prefillToList[] = $id; } }
                } else {
                    $raw = (string)$_GET['to'];
                    if (strpos($raw, ',') !== false) {
                        foreach (explode(',', $raw) as $part) { $id = (int)trim($part); if ($id > 0) { $prefillToList[] = $id; } }
                    } elseif ($prefillTo > 0) {
                        $prefillToList[] = $prefillTo;
                    }
                }
            }
            $prefillSubject = isset($_GET['subject']) ? (string)$_GET['subject'] : '';
            $prefillAttachName = isset($_GET['attach_name']) ? (string)$_GET['attach_name'] : '';
            $prefillAttachPath = isset($_GET['attach_path']) ? (string)$_GET['attach_path'] : '';
            // Optional second prefilled attachment
            $prefillAttachName2 = isset($_GET['attach_name2']) ? (string)$_GET['attach_name2'] : '';
            $prefillAttachPath2 = isset($_GET['attach_path2']) ? (string)$_GET['attach_path2'] : '';
            $this->render('dashboard/messages.php', [
                'inbox' => $inbox,
                'users' => $users,
                'prefill_to' => $prefillTo,
                'prefill_to_list' => $prefillToList,
                'prefill_subject' => $prefillSubject,
                'prefill_attachment_name' => $prefillAttachName,
                'prefill_attachment_path' => $prefillAttachPath,
                'prefill_attachment_name2' => $prefillAttachName2,
                'prefill_attachment_path2' => $prefillAttachPath2,
            ]);
        } catch (\PDOException $e) {
            // If messages table doesn't exist yet, create it and retry once
            if ($e->getCode() === '42P01') {
                $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages (
                    id BIGSERIAL PRIMARY KEY,
                    sender_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                    recipient_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                    subject VARCHAR(255) NOT NULL,
                    body TEXT NOT NULL,
                    is_read BOOLEAN NOT NULL DEFAULT FALSE,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )');
                // retry once
                $stmt = $this->pdo->prepare('SELECT m.id, m.subject, m.body, m.is_read, m.created_at, u.full_name AS from_name
                    FROM messages m JOIN users u ON u.user_id = m.sender_id WHERE m.recipient_id = :me ORDER BY m.created_at DESC LIMIT 50');
                $stmt->execute(['me' => $me]);
                $inbox = $stmt->fetchAll();
                $users = $this->pdo->query("SELECT user_id, full_name, role FROM users WHERE is_active = TRUE ORDER BY role, full_name")->fetchAll();
                $this->render('dashboard/messages.php', ['inbox' => $inbox, 'users' => $users]);
                return;
            }
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading messages: ' . $e->getMessage();
        }
    }

    public function sendMessage(): void
    {
        $me = (int)($_SESSION['user_id'] ?? 0);
        // Accept single or multiple recipients
        $toParam = $_POST['to'] ?? null;
        $toList = [];
        if (is_array($toParam)) {
            foreach ($toParam as $v) { $id = (int)$v; if ($id > 0) { $toList[] = $id; } }
        } else {
            $id = (int)$toParam; if ($id > 0) { $toList[] = $id; }
        }
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        if (empty($toList) || $subject === '' || $body === '') { header('Location: /admin/messages'); return; }
        try {
            // Insert one message per recipient
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages (
                id BIGSERIAL PRIMARY KEY,
                sender_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                recipient_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                is_read BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )');
            $stmt = $this->pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
            $msgIds = [];
            foreach ($toList as $to) {
                $encBody = \App\Services\CryptoService::encrypt($body, 'msg:' . (string)$me . '->' . (string)$to);
                $stmt->execute(['s' => $me, 'r' => $to, 'j' => $subject, 'b' => $encBody]);
                $msgIds[] = (int)$this->pdo->lastInsertId('messages_id_seq');
            }
            // Optionally auto-attach an existing file (e.g., pre-generated PR PDF)
            $autoName = isset($_POST['attach_name']) ? trim((string)$_POST['attach_name']) : '';
            $autoPath = isset($_POST['attach_path']) ? trim((string)$_POST['attach_path']) : '';
            if (!empty($msgIds) && $autoName !== '' && $autoPath !== '' && @is_file($autoPath)) {
                try {
                    $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);");
                    $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;");
                } catch (\Throwable $e) {}
                $up = $this->pdo->prepare('UPDATE messages SET attachment_name = :n, attachment_path = :p WHERE id = :id');
                foreach ($msgIds as $mid) { $up->execute(['n' => $autoName, 'p' => $autoPath, 'id' => $mid]); }
            }
            // Optional second prefilled attachment saved to messages_attachments
            $autoName2 = isset($_POST['attach_name2']) ? trim((string)$_POST['attach_name2']) : '';
            $autoPath2 = isset($_POST['attach_path2']) ? trim((string)$_POST['attach_path2']) : '';
            if (!empty($msgIds) && $autoName2 !== '' && $autoPath2 !== '' && @is_file($autoPath2)) {
                try {
                    $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages_attachments (
                        id BIGSERIAL PRIMARY KEY,
                        message_id BIGINT REFERENCES messages(id) ON DELETE CASCADE,
                        file_name VARCHAR(255) NOT NULL,
                        file_path TEXT NOT NULL,
                        uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )');
                } catch (\Throwable $e) {}
                $insA0 = $this->pdo->prepare('INSERT INTO messages_attachments (message_id, file_name, file_path) VALUES (:m,:n,:p)');
                foreach ($msgIds as $mid) { $insA0->execute(['m' => $mid, 'n' => basename($autoName2), 'p' => $autoPath2]); }
            }
            // Handle optional attachment upload
            if (!empty($msgIds) && !empty($_FILES['attachment']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
                // Ensure attachments table
                try {
                    $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages_attachments (
                        id BIGSERIAL PRIMARY KEY,
                        message_id BIGINT REFERENCES messages(id) ON DELETE CASCADE,
                        file_name VARCHAR(255) NOT NULL,
                        file_path TEXT NOT NULL,
                        uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )');
                } catch (\Throwable $e) {}
                $name = basename((string)$_FILES['attachment']['name']);
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
                $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage';
                if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                $dest = $dir . DIRECTORY_SEPARATOR . uniqid('att_') . '_' . $safe;
                if (@move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                    $insA = $this->pdo->prepare('INSERT INTO messages_attachments (message_id, file_name, file_path) VALUES (:m,:n,:p)');
                    foreach ($msgIds as $mid) { $insA->execute(['m' => $mid, 'n' => $safe, 'p' => $dest]); }
                }
            }
            header('Location: /admin/messages');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /admin/messages?error=' . $msg);
        }
    }

    /** Admin Assistant: Accept Admin's revision proposal for a PR group. */
    public function acceptRevision(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin_assistant','custodian','admin'], true)) { header('Location: /login'); return; }
        // Treat legacy 'custodian' as admin_assistant
        $role = $_SESSION['role'] === 'custodian' ? 'admin_assistant' : $_SESSION['role'];
        if ($role !== 'admin_assistant' && ($role !== 'admin')) { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $msgId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        if ($pr === '') { header('Location: /inbox'); return; }
        try {
            // Mark group revision as accepted
            try {
                $st = $this->pdo->prepare("UPDATE purchase_requests SET revision_state = 'accepted', updated_by = :by, updated_at = NOW() WHERE pr_number = :pr");
                $st->execute(['by' => (int)($_SESSION['user_id'] ?? 0), 'pr' => $pr]);
            } catch (\Throwable $ignored) {}
            // Record a follow-up event on all requests under PR
            $rows = $this->requests()->getGroupDetails($pr);
            foreach ($rows as $r) {
                $this->requests()->followUpRequest((int)$r['request_id'], (int)($_SESSION['user_id'] ?? 0), 'Revision accepted by Admin Assistant');
            }
            // Notify Admin users
            $stU = $this->pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role = 'admin'");
            $admins = $stU ? $stU->fetchAll() : [];
            if ($admins) {
                $ins = $this->pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
                $subject = 'PR ' . $pr . ' • Revision Accepted';
                $body = 'The Admin Assistant accepted the proposed revision for PR ' . $pr . '. You may proceed to review and approve.';
                foreach ($admins as $a) {
                    $ins->execute(['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => (int)$a['user_id'], 'j' => $subject, 'b' => $body]);
                }
            }
            if ($msgId > 0) {
                $st = $this->pdo->prepare('UPDATE messages SET is_read = TRUE WHERE id = :id AND recipient_id = :me');
                $st->execute(['id' => $msgId, 'me' => (int)($_SESSION['user_id'] ?? 0)]);
            }
            header('Location: /inbox?ok=1');
        } catch (\Throwable $e) {
            header('Location: /inbox?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Admin Assistant: Send justification for a revision proposal. */
    public function justifyRevision(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin_assistant','custodian','admin'], true)) { header('Location: /login'); return; }
        $role = $_SESSION['role'] === 'custodian' ? 'admin_assistant' : $_SESSION['role'];
        if ($role !== 'admin_assistant' && ($role !== 'admin')) { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
        $msgId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        if ($pr === '' || $notes === '') { header('Location: /inbox'); return; }
        try {
            // Mark group revision as justified with notes
            try {
                $st = $this->pdo->prepare("UPDATE purchase_requests SET revision_state = 'justified', revision_notes = :notes, updated_by = :by, updated_at = NOW() WHERE pr_number = :pr");
                $st->execute(['notes' => $notes, 'by' => (int)($_SESSION['user_id'] ?? 0), 'pr' => $pr]);
            } catch (\Throwable $ignored) {}
            // Record follow-up notes on all requests
            $rows = $this->requests()->getGroupDetails($pr);
            foreach ($rows as $r) {
                $this->requests()->followUpRequest((int)$r['request_id'], (int)($_SESSION['user_id'] ?? 0), 'Justification from Admin Assistant: ' . $notes);
            }
            // Notify Admin users
            $stU = $this->pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role = 'admin'");
            $admins = $stU ? $stU->fetchAll() : [];
            if ($admins) {
                $ins = $this->pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
                $subject = 'PR ' . $pr . ' • Justification Provided';
                $body = "The Admin Assistant provided a justification for PR $pr:\n\n" . $notes;
                foreach ($admins as $a) {
                    $ins->execute(['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => (int)$a['user_id'], 'j' => $subject, 'b' => $body]);
                }
            }
            if ($msgId > 0) {
                $st = $this->pdo->prepare('UPDATE messages SET is_read = TRUE WHERE id = :id AND recipient_id = :me');
                $st->execute(['id' => $msgId, 'me' => (int)($_SESSION['user_id'] ?? 0)]);
            }
            header('Location: /inbox?ok=1');
        } catch (\Throwable $e) {
            header('Location: /inbox?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Mark a message as read for the current user. */
    public function markMessageRead(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($me <= 0 || $id <= 0) { header('Location: /admin/messages'); return; }
        try {
            $st = $this->pdo->prepare('UPDATE messages SET is_read = TRUE WHERE id = :id AND recipient_id = :me');
            $st->execute(['id' => $id, 'me' => $me]);
        } catch (\Throwable $e) {
            // ignore errors for UX; still redirect back
        }
        $ref = $_SERVER['HTTP_REFERER'] ?? '/admin/messages';
        header('Location: ' . $ref);
    }

    public function notifications(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        if ($me <= 0) { header('Location: /login'); return; }
        try {
            // Ensure archive columns exist (idempotent)
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE"); } catch (\Throwable $ignored) {}
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ"); } catch (\Throwable $ignored) {}
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL"); } catch (\Throwable $ignored) {}
            $show = isset($_GET['show']) ? (string)$_GET['show'] : 'active';
            $where = 'm.recipient_id = :me';
            if ($show === 'archived') { $where .= ' AND COALESCE(m.is_archived, FALSE) = TRUE'; }
            elseif ($show === 'active') { $where .= ' AND COALESCE(m.is_archived, FALSE) = FALSE'; }
            $sql = 'SELECT m.id, m.subject, m.body, m.created_at, m.archived_at, m.sender_id, m.is_read, COALESCE(m.is_archived, FALSE) AS is_archived, u.full_name AS from_name
                FROM messages m JOIN users u ON u.user_id = m.sender_id
                WHERE ' . $where . '
                ORDER BY COALESCE(m.archived_at, m.created_at) DESC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['me' => $me]);
            $list = $stmt->fetchAll();
            foreach ($list as &$row) { $row['body'] = \App\Services\CryptoService::maybeDecrypt((string)$row['body']); }
            $this->render('dashboard/inbox.php', ['inbox' => $list, 'filters' => ['show' => $show]]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading notifications: ' . $e->getMessage();
        }
    }

    /** Archive a message for the current user (soft delete). */
    public function archiveMessage(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($me <= 0 || $id <= 0) { header('Location: /inbox'); return; }
        try {
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE"); } catch (\Throwable $ignored) {}
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ"); } catch (\Throwable $ignored) {}
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL"); } catch (\Throwable $ignored) {}
            $st = $this->pdo->prepare('UPDATE messages SET is_archived = TRUE, archived_at = NOW(), archived_by = :by WHERE id = :id AND recipient_id = :me');
            $st->execute(['by' => $me, 'id' => $id, 'me' => $me]);
            $ref = $_SERVER['HTTP_REFERER'] ?? '/inbox?show=active';
            header('Location: ' . $ref);
        } catch (\Throwable $e) {
            header('Location: /inbox?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Restore an archived message for the current user. */
    public function restoreMessage(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($me <= 0 || $id <= 0) { header('Location: /inbox?show=archived'); return; }
        try {
            try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE"); } catch (\Throwable $ignored) {}
            $st = $this->pdo->prepare('UPDATE messages SET is_archived = FALSE WHERE id = :id AND recipient_id = :me');
            $st->execute(['id' => $id, 'me' => $me]);
            $ref = $_SERVER['HTTP_REFERER'] ?? '/inbox?show=archived';
            header('Location: ' . $ref);
        } catch (\Throwable $e) {
            header('Location: /inbox?show=archived&error=' . rawurlencode($e->getMessage()));
        }
    }

    /** View a single notification (message) without removing it; allow actions. */
    public function viewNotification(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($me <= 0 || $id <= 0) { header('Location: /notifications'); return; }
        try {
            // Attempt to select including optional attachment columns; add them on the fly if missing
            try {
                $st = $this->pdo->prepare('SELECT m.id, m.subject, m.body, m.is_read, m.created_at, m.sender_id, m.attachment_name, m.attachment_path, u.full_name AS from_name
                    FROM messages m JOIN users u ON u.user_id = m.sender_id WHERE m.id = :id AND m.recipient_id = :me');
                $st->execute(['id' => $id, 'me' => $me]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '42703') { // undefined_column on legacy DB
                    try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $ignored) {}
                    try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $ignored) {}
                    $st = $this->pdo->prepare('SELECT m.id, m.subject, m.body, m.is_read, m.created_at, m.sender_id, m.attachment_name, m.attachment_path, u.full_name AS from_name
                        FROM messages m JOIN users u ON u.user_id = m.sender_id WHERE m.id = :id AND m.recipient_id = :me');
                    $st->execute(['id' => $id, 'me' => $me]);
                } else {
                    throw $e;
                }
            }
            $msg = $st->fetch();
            if ($msg) { $msg['body'] = \App\Services\CryptoService::maybeDecrypt((string)$msg['body']); }
            if (!$msg) { header('Location: /notifications'); return; }
            // Load any additional attachments for this message
            $attachments = [];
            try {
                $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages_attachments (
                    id BIGSERIAL PRIMARY KEY,
                    message_id BIGINT REFERENCES messages(id) ON DELETE CASCADE,
                    file_name VARCHAR(255) NOT NULL,
                    file_path TEXT NOT NULL,
                    uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )');
                $stA = $this->pdo->prepare('SELECT id, file_name FROM messages_attachments WHERE message_id = :mid ORDER BY uploaded_at ASC');
                $stA->execute(['mid' => (int)$msg['id']]);
                $attachments = $stA->fetchAll() ?: [];
            } catch (\Throwable $ignored) {}
            // Optional canvassing suppliers for Admin edit (derive PR from subject)
            $canvassing = null;
            $subj = (string)($msg['subject'] ?? '');
            $prNum = null;
            if (preg_match('/PR\s*([0-9\-]+)/i', $subj, $mm)) { $prNum = $mm[1]; }
            if ($prNum) {
                try {
                    $this->pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
                        pr_number VARCHAR(32) PRIMARY KEY,
                        supplier1 VARCHAR(255),
                        supplier2 VARCHAR(255),
                        supplier3 VARCHAR(255),
                        awarded_to VARCHAR(255),
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )");
                    $stC = $this->pdo->prepare('SELECT supplier1, supplier2, supplier3, awarded_to FROM pr_canvassing WHERE pr_number = :pr');
                    $stC->execute(['pr' => $prNum]);
                    if ($row = $stC->fetch()) {
                        $sup = [];
                        foreach (['supplier1','supplier2','supplier3'] as $k) { if (!empty($row[$k])) { $sup[] = (string)$row[$k]; } }
                        $canvassing = [ 'pr' => $prNum, 'suppliers' => $sup, 'awarded_to' => (string)($row['awarded_to'] ?? '') ];
                    }
                } catch (\Throwable $ignored) {}
            }
            $this->render('dashboard/notification_view.php', ['message' => $msg, 'attachments' => $attachments, 'canvassing' => $canvassing]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading message: ' . $e->getMessage();
        }
    }

    /** Approve canvassing for a PR (admin action). */
    public function approveCanvassing(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $msgId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        if ($pr === '') { header('Location: /inbox'); return; }
        try {
            // Optional: Update awarded vendor if provided (must match one of the canvassed suppliers)
            $awarded = isset($_POST['awarded_to']) ? trim((string)$_POST['awarded_to']) : '';
            if ($awarded !== '') {
                try {
                    $stC = $this->pdo->prepare('SELECT supplier1, supplier2, supplier3 FROM pr_canvassing WHERE pr_number = :pr');
                    $stC->execute(['pr' => $pr]);
                    if ($cv = $stC->fetch()) {
                        $allowed = [];
                        foreach (['supplier1','supplier2','supplier3'] as $k) { if (!empty($cv[$k])) { $allowed[] = (string)$cv[$k]; } }
                        if (in_array($awarded, $allowed, true)) {
                            $upA = $this->pdo->prepare('UPDATE pr_canvassing SET awarded_to = :aw, updated_at = NOW() WHERE pr_number = :pr');
                            $upA->execute(['aw' => $awarded, 'pr' => $pr]);
                        }
                    }
                } catch (\Throwable $ignored) {}
            }
            $this->requests()->updateGroupStatus($pr, 'canvassing_approved', (int)($_SESSION['user_id'] ?? 0), 'Canvassing approved by Admin');
            // Ensure PR-Canvass snapshot is persisted (details + items). If missing, reconstruct and save.
            try {
                // Short-circuit if already present
                $chk = $this->pdo->prepare('SELECT 1 FROM pr_canvassing_details WHERE pr_number = :pr LIMIT 1');
                $has = false; try { $chk->execute(['pr'=>$pr]); $has = (bool)$chk->fetchColumn(); } catch (\Throwable $ignored) { $has = false; }
                if (!$has) {
                    // Gather supplier IDs from legacy pr_canvassing (ids or names), otherwise fallback to active suppliers
                    $supplierIds = [];
                    $names = [];
                    try {
                        $stCv = $this->pdo->prepare('SELECT supplier1_id, supplier2_id, supplier3_id, supplier1, supplier2, supplier3 FROM pr_canvassing WHERE pr_number = :pr');
                        $stCv->execute(['pr'=>$pr]);
                        if ($cv = $stCv->fetch()) {
                            foreach (['supplier1_id','supplier2_id','supplier3_id'] as $k) { $v = isset($cv[$k]) ? (int)$cv[$k] : 0; if ($v > 0) { $supplierIds[] = $v; } }
                            foreach (['supplier1','supplier2','supplier3'] as $k) { if (!empty($cv[$k])) { $names[] = strtolower(trim((string)$cv[$k])); } }
                        }
                    } catch (\Throwable $ignored) {}
                    $supplierIds = array_values(array_unique(array_filter(array_map('intval',$supplierIds), static fn($v)=>$v>0)));
                    if (empty($supplierIds) && !empty($names)) {
                        $in = implode(',', array_fill(0, count($names), '?'));
                        try {
                            $stMap = $this->pdo->prepare('SELECT user_id, LOWER(full_name) AS lname FROM users WHERE role=\'supplier\' AND is_active=TRUE AND LOWER(full_name) IN (' . $in . ')');
                            $stMap->execute($names);
                            foreach ($stMap->fetchAll() as $r) { $supplierIds[] = (int)$r['user_id']; }
                        } catch (\Throwable $ignored) {}
                        $supplierIds = array_values(array_unique(array_filter($supplierIds, static fn($v)=>$v>0)));
                    }
                    if (empty($supplierIds)) {
                        // Fallback to all active suppliers (bounded by 50 to stay safe)
                        try {
                            $stAll = $this->pdo->query("SELECT user_id FROM users WHERE role='supplier' AND is_active=TRUE ORDER BY user_id ASC LIMIT 50");
                            foreach ($stAll->fetchAll() as $r) { $supplierIds[] = (int)$r['user_id']; }
                        } catch (\Throwable $ignored) {}
                        $supplierIds = array_values(array_unique(array_filter($supplierIds, static fn($v)=>$v>0)));
                    }
                    // Load PR items
                    $rows = (new \App\Services\RequestService($this->pdo))->getGroupDetails($pr);
                    // Generate canvass_id
                    try { $this->pdo->exec("CREATE TABLE IF NOT EXISTS canvass_sequences (calendar_year INTEGER PRIMARY KEY, last_value INTEGER NOT NULL DEFAULT 0, updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())"); } catch (\Throwable $ignored) {}
                    $year = (int)date('Y');
                    try { $this->pdo->prepare('INSERT INTO canvass_sequences (calendar_year,last_value) VALUES (:y,0) ON CONFLICT (calendar_year) DO NOTHING')->execute(['y'=>$year]); } catch (\Throwable $ignored) {}
                    $stSeq = $this->pdo->prepare('UPDATE canvass_sequences SET last_value = last_value + 1, updated_at = NOW() WHERE calendar_year = :y RETURNING last_value');
                    $stSeq->execute(['y'=>$year]); $seqVal = (int)$stSeq->fetchColumn(); if ($seqVal <= 0) { $seqVal = 1; }
                    $canvassId = 'C' . sprintf('%04d%03d', $year, $seqVal);
                    // Ensure tables
                    try { $this->pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing_details (canvass_id VARCHAR(16) PRIMARY KEY, pr_number VARCHAR(32) NOT NULL, suppliers JSONB NOT NULL, selections JSONB, awards JSONB, created_by BIGINT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())"); } catch (\Throwable $ignored) {}
                    try { $this->pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing_items (id BIGSERIAL PRIMARY KEY, canvass_id VARCHAR(16) NOT NULL, pr_number VARCHAR(32) NOT NULL, item_id BIGINT NOT NULL, item_name VARCHAR(255), suppliers JSONB, selected_suppliers JSONB, quotes JSONB, awarded_supplier_id BIGINT, awarded_price NUMERIC(14,2), created_by BIGINT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())"); } catch (\Throwable $ignored) {}
                    // Compute per-item quotes and awards for chosen suppliers
                    $lowerById = []; $qtyById = []; $nameById = [];
                    foreach ($rows as $r) { $iid=(int)($r['item_id']??0); if($iid<=0) continue; $nm=(string)($r['item_name']??''); $lower=strtolower(trim($nm)); $lowerById[$iid]=$lower; $nameById[$iid]=$nm; $qtyById[$iid]=($qtyById[$iid]??0)+(int)($r['quantity']??0); }
                    $quotesByItem = []; $awardByItem = []; $awPriceByItem = [];
                    if ($lowerById && $supplierIds) {
                        $inSup = implode(',', array_fill(0, count($supplierIds), '?'));
                        $namesLower = array_values(array_unique(array_values($lowerById)));
                        $byName = [];
                        if ($namesLower) {
                            $inNames = implode(',', array_fill(0, count($namesLower), '?'));
                            $params = []; foreach($supplierIds as $sid){ $params[]=(int)$sid; } foreach($namesLower as $nm){ $params[]=$nm; }
                            $sql = 'SELECT id, supplier_id, LOWER(name) AS lname, price, pieces_per_package FROM supplier_items WHERE supplier_id IN (' . $inSup . ') AND LOWER(name) IN (' . $inNames . ')';
                            $st = $this->pdo->prepare($sql); $st->execute($params);
                            foreach ($st->fetchAll() as $it) { $byName[(string)$it['lname']][] = $it; }
                        }
                        foreach ($lowerById as $iid=>$lname) {
                            $needQty = (int)($qtyById[$iid] ?? 0);
                            $pp = [];
                            if (isset($byName[$lname])) {
                                foreach ($byName[$lname] as $it) {
                                    $sid=(int)$it['supplier_id']; if(!in_array($sid,$supplierIds,true)) continue;
                                    $base=(float)($it['price']??0); $ppp=max(1,(int)($it['pieces_per_package']??1)); $needPk=$ppp>0?(int)ceil($needQty/$ppp):0; $best=$base;
                                    if ($needPk>0) {
                                        try { $tq=$this->pdo->prepare('SELECT min_packages,max_packages,price_per_package FROM supplier_item_price_tiers WHERE supplier_item_id = :id ORDER BY min_packages ASC'); $tq->execute(['id'=>(int)$it['id']]); foreach($tq->fetchAll() as $t){ $min=(int)$t['min_packages']; $max=$t['max_packages']!==null?(int)$t['max_packages']:null; if($needPk>=$min && ($max===null || $needPk<=$max)){ $best=min($best,(float)$t['price_per_package']); } } } catch(\Throwable $ignored){}
                                    }
                                    if(!isset($pp[$sid]) || $best < $pp[$sid]){ $pp[$sid]=$best; }
                                }
                            }
                            // Bondpaper size-aware fallback
                            if (empty($pp) && preg_match('/^(a4|long|short|f4)\s+bond\s*paper?$/i', (string)($nameById[$iid] ?? ''))) {
                                $size=strtolower(preg_replace('/\s+bond\s*paper?/i','', strtolower((string)($nameById[$iid] ?? ''))));
                                $syns=[]; if($size==='a4'){ $syns=['a4','210x297','210 x 297','8.27x11.69','8.3x11.7']; } elseif($size==='long'){ $syns=['long','legal','8.5x13','8.5 x 13']; } elseif($size==='short'){ $syns=['short','letter','8.5x11','8.5 x 11']; } elseif($size==='f4'){ $syns=['f4','8.5x13','8.5 x 13']; }
                                $conds=[]; $params2=[]; $conds[]='supplier_id IN (' . $inSup . ')'; foreach($supplierIds as $sid){ $params2[]=(int)$sid; }
                                $conds[]="(LOWER(name)='bondpaper' OR LOWER(name) ILIKE '%bond%paper%')";
                                foreach($syns as $s){ $conds[]='LOWER(description)=?'; $params2[]=strtolower($s);} foreach($syns as $s){ $conds[]='LOWER(description) ILIKE ?'; $params2[]='%'.strtolower($s).'%'; }
                                $sql2='SELECT id,supplier_id,price,pieces_per_package FROM supplier_items WHERE ' . implode(' AND ', $conds);
                                $st2=$this->pdo->prepare($sql2); $st2->execute($params2);
                                foreach($st2->fetchAll() as $it){ $sid=(int)$it['supplier_id']; $base=(float)($it['price']??0); $ppp=max(1,(int)($it['pieces_per_package']??1)); $needPk=$ppp>0?(int)ceil($needQty/$ppp):0; $best=$base; if($needPk>0){ try{ $tq=$this->pdo->prepare('SELECT min_packages,max_packages,price_per_package FROM supplier_item_price_tiers WHERE supplier_item_id = :id ORDER BY min_packages ASC'); $tq->execute(['id'=>(int)$it['id']]); foreach($tq->fetchAll() as $t){ $min=(int)$t['min_packages']; $max=$t['max_packages']!==null?(int)$t['max_packages']:null; if($needPk>=$min && ($max===null || $needPk<=$max)){ $best=min($best,(float)$t['price_per_package']); } } } catch(\Throwable $ignored){} } if(!isset($pp[$sid]) || $best < $pp[$sid]){ $pp[$sid]=$best; }
                                }
                            }
                            if (!empty($pp)) {
                                asort($pp); $awardSid=(int)array_key_first($pp); $awardPrice=(float)$pp[$awardSid];
                                $quotesByItem[$iid] = $pp; $awardByItem[$iid] = $awardSid; $awPriceByItem[$iid] = $awardPrice;
                            }
                        }
                    }
                    // Persist
                    $this->pdo->beginTransaction();
                    try {
                        $insD = $this->pdo->prepare('INSERT INTO pr_canvassing_details (canvass_id, pr_number, suppliers, selections, awards, created_by) VALUES (:cid,:pr,:sup,:sel,:awd,:uid)');
                        $awardsJson = json_encode($awardByItem ?: new \stdClass());
                        $insD->execute(['cid'=>$canvassId,'pr'=>$pr,'sup'=>json_encode($supplierIds),'sel'=>null,'awd'=>$awardsJson,'uid'=>(int)($_SESSION['user_id'] ?? 0)]);
                        if (!empty($quotesByItem)) {
                            $insI = $this->pdo->prepare('INSERT INTO pr_canvassing_items (canvass_id, pr_number, item_id, item_name, suppliers, selected_suppliers, quotes, awarded_supplier_id, awarded_price, created_by) VALUES (:cid,:pr,:iid,:nm,:sup,:sel,:qt,:aw,:ap,:uid)');
                            foreach ($quotesByItem as $iid=>$map) {
                                $insI->execute([
                                    'cid'=>$canvassId,
                                    'pr'=>$pr,
                                    'iid'=>$iid,
                                    'nm'=>$nameById[$iid] ?? 'Item',
                                    'sup'=>json_encode($supplierIds),
                                    'sel'=>null,
                                    'qt'=>json_encode($map),
                                    'aw'=>$awardByItem[$iid] ?? null,
                                    'ap'=>isset($awPriceByItem[$iid]) ? (float)$awPriceByItem[$iid] : null,
                                    'uid'=>(int)($_SESSION['user_id'] ?? 0),
                                ]);
                            }
                        }
                        $this->pdo->commit();
                    } catch (\Throwable $ex) { $this->pdo->rollBack(); /* if persisting fails, continue but do not crash approval */ }
                }
            } catch (\Throwable $ignored) {}
            // Record canvassing approver for PR-Canvass PDF signature
            try {
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS pr_canvassing (
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
            } catch (\Throwable $ignored) {}
            try {
                $adminName = '';
                $stN = $this->pdo->prepare('SELECT full_name FROM users WHERE user_id = :id');
                $stN->execute(['id' => (int)($_SESSION['user_id'] ?? 0)]);
                $nm = $stN->fetchColumn();
                if ($nm) { $adminName = (string)$nm; }
                $upC = $this->pdo->prepare('INSERT INTO pr_canvassing (pr_number, approved_by, approved_at) VALUES (:pr, :n, NOW()) ON CONFLICT (pr_number) DO UPDATE SET approved_by = EXCLUDED.approved_by, approved_at = NOW(), updated_at = NOW()');
                $upC->execute(['pr' => $pr, 'n' => ($adminName !== '' ? ($adminName . ' - Admin') : 'Admin')]);
            } catch (\Throwable $ignored) {}
            // Mark message read if provided
            if ($msgId > 0) {
                $st = $this->pdo->prepare('UPDATE messages SET is_read = TRUE WHERE id = :id AND recipient_id = :me');
                $st->execute(['id' => $msgId, 'me' => (int)($_SESSION['user_id'] ?? 0)]);
            }
            header('Location: /inbox');
        } catch (\Throwable $e) {
            header('Location: /inbox?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Reject canvassing for a PR (admin action). */
    public function rejectCanvassing(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
        $msgId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        if ($pr === '') { header('Location: /inbox'); return; }
        try {
            $this->requests()->updateGroupStatus($pr, 'canvassing_rejected', (int)($_SESSION['user_id'] ?? 0), $notes !== '' ? ('Canvassing rejected: ' . $notes) : 'Canvassing rejected by Admin');
            if ($msgId > 0) {
                $st = $this->pdo->prepare('UPDATE messages SET is_read = TRUE WHERE id = :id AND recipient_id = :me');
                $st->execute(['id' => $msgId, 'me' => (int)($_SESSION['user_id'] ?? 0)]);
            }
            header('Location: /inbox');
        } catch (\Throwable $e) {
            header('Location: /inbox?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Approve a PR (pre-canvassing) for a PR number from an admin action. */
    public function approvePR(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $msgId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        if ($pr === '') { header('Location: /inbox'); return; }
        try {
            $this->requests()->updateGroupStatus($pr, 'approved', (int)($_SESSION['user_id'] ?? 0), 'PR approved by Admin');
            // Record approver and approval date on all rows of the PR group (for PDFs)
            try {
                $this->pdo->exec("ALTER TABLE purchase_requests ADD COLUMN IF NOT EXISTS approved_by VARCHAR(255);");
            } catch (\Throwable $ignored) {}
            try {
                $this->pdo->exec("ALTER TABLE purchase_requests ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ;");
            } catch (\Throwable $ignored) {}
            try {
                $adminName = '';
                $stN = $this->pdo->prepare('SELECT full_name FROM users WHERE user_id = :id');
                $stN->execute(['id' => (int)($_SESSION['user_id'] ?? 0)]);
                $nm = $stN->fetchColumn();
                if ($nm) { $adminName = (string)$nm; }
                $up = $this->pdo->prepare('UPDATE purchase_requests SET approved_by = :n, approved_at = NOW() WHERE pr_number = :pr');
                $up->execute(['n' => ($adminName !== '' ? ($adminName . ' - Admin') : 'Admin'), 'pr' => $pr]);
            } catch (\Throwable $ignored) {}
            if ($msgId > 0) {
                $st = $this->pdo->prepare('UPDATE messages SET is_read = TRUE WHERE id = :id AND recipient_id = :me');
                $st->execute(['id' => $msgId, 'me' => (int)($_SESSION['user_id'] ?? 0)]);
            }
            header('Location: /inbox');
        } catch (\Throwable $e) {
            header('Location: /inbox?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Reject a PR (pre-canvassing) for a PR number from an admin action. */
    public function rejectPR(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
        $msgId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
        if ($pr === '') { header('Location: /inbox'); return; }
        try {
            $this->requests()->updateGroupStatus($pr, 'rejected', (int)($_SESSION['user_id'] ?? 0), $notes !== '' ? ('PR rejected: ' . $notes) : 'PR rejected by Admin');
            if ($msgId > 0) {
                $st = $this->pdo->prepare('UPDATE messages SET is_read = TRUE WHERE id = :id AND recipient_id = :me');
                $st->execute(['id' => $msgId, 'me' => (int)($_SESSION['user_id'] ?? 0)]);
            }
            header('Location: /inbox');
        } catch (\Throwable $e) {
            header('Location: /inbox?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Admin: Request recheck from Admin Assistant for a PR group revision. */
    public function recheckRevision(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
        if ($pr === '') { header('Location: /admin/requests'); return; }
        try {
            // Update revision_state for the whole group
            $st = $this->pdo->prepare("UPDATE purchase_requests SET revision_state = 'recheck_requested', revision_notes = :notes, updated_by = :by, updated_at = NOW() WHERE pr_number = :pr");
            $st->execute(['notes' => ($notes !== '' ? $notes : null), 'by' => (int)($_SESSION['user_id'] ?? 0), 'pr' => $pr]);
            // Notify requesters
            try {
                $pdo = $this->pdo;
                $stR = $pdo->prepare("SELECT DISTINCT requested_by FROM purchase_requests WHERE pr_number = :pr");
                $stR->execute(['pr' => $pr]);
                $recipients = array_map(static fn($r) => (int)$r['requested_by'], $stR->fetchAll() ?: []);
                if ($recipients) {
                    $ins = $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
                    $subject = 'PR ' . $pr . ' • Recheck Requested';
                    $body = "Admin requested a recheck for PR $pr." . ($notes !== '' ? ("\n\nNotes:\n" . $notes) : '');
                    foreach ($recipients as $to) { $ins->execute(['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => $to, 'j' => $subject, 'b' => $body]); }
                }
            } catch (\Throwable $ignored) {}
            header('Location: /admin/requests/review?pr=' . rawurlencode($pr) . '&recheck=1');
        } catch (\Throwable $e) {
            header('Location: /admin/requests/review?pr=' . rawurlencode($pr) . '&error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Admin: Grouped PRs view */
    public function viewRequestsAdmin(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $branchId = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int)$_GET['branch'] : null;
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;
        $revision = isset($_GET['revision']) && $_GET['revision'] !== '' ? (string)$_GET['revision'] : null;
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'date';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $rows = $this->requests()->getRequestsGrouped([
            'branch_id' => $branchId,
            'status' => $status,
            'revision' => $revision,
            'include_archived' => false,
            'sort' => $sort,
            'order' => $order,
        ]);
        // Visibility rule: hide raw 'pending' PRs from Admin until Procurement forwards for Admin Approval
        $rows = array_values(array_filter($rows, static fn($r) => (string)($r['status'] ?? '') !== 'pending'));
        $this->render('admin/requests_list.php', [ 'groups' => $rows, 'filters' => [ 'branch' => $branchId, 'status' => $status, 'revision' => $revision, 'sort' => $sort, 'order' => $order ] ]);
    }

    /** Admin: Review a PR group with actions (approve, reject, revise quantities). */
    public function reviewRequestGroup(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_GET['pr']) ? trim((string)$_GET['pr']) : '';
        if ($pr === '') { header('Location: /admin/requests'); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        if (!$rows) { header('Location: /admin/requests'); return; }
        // Derive latest revision state/notes from group rows
        $revState = null; $revNotes = null;
        foreach ($rows as $r) {
            if (!empty($r['revision_state'])) { $revState = (string)$r['revision_state']; }
            if (!empty($r['revision_notes'])) { $revNotes = (string)$r['revision_notes']; }
        }
        $this->render('admin/request_review.php', [ 'pr' => $pr, 'rows' => $rows, 'revision_state' => $revState, 'revision_notes' => $revNotes ]);
    }

    /** Admin: Update group status */
    public function adminUpdateGroupStatus(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        $status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
        if ($pr === '' || $status === '') { header('Location: /admin/requests'); return; }
        $this->requests()->updateGroupStatus($pr, $status, (int)($_SESSION['user_id'] ?? 0), $_POST['notes'] ?? null);
        header('Location: /admin/requests');
    }

    /** Delete a message after password confirmation. */
    public function deleteMessage(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $pwd = (string)($_POST['password'] ?? '');
        if ($me <= 0 || $id <= 0 || $pwd === '') { header('Location: /notifications'); return; }
        try {
            $st = $this->pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id');
            $st->execute(['id' => $me]);
            $hash = (string)$st->fetchColumn();
            if ($hash === '' || !password_verify($pwd, $hash)) { header('Location: /notifications?error=Bad+password'); return; }
            $del = $this->pdo->prepare('DELETE FROM messages WHERE id = :mid AND recipient_id = :me');
            $del->execute(['mid' => $id, 'me' => $me]);
            $ref = $_SERVER['HTTP_REFERER'] ?? '/notifications';
            header('Location: ' . $ref);
        } catch (\Throwable $e) {
            header('Location: /notifications?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Admin: Apply revision to quantities for a PR group and notify requesters. */
    public function revisePR(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $pr = isset($_POST['pr_number']) ? trim((string)$_POST['pr_number']) : '';
        if ($pr === '') { header('Location: /admin/requests'); return; }
        $ids = isset($_POST['request_id']) && is_array($_POST['request_id']) ? array_map('intval', $_POST['request_id']) : [];
        $qtys = isset($_POST['quantity']) && is_array($_POST['quantity']) ? array_map('intval', $_POST['quantity']) : [];
        $revNotes = isset($_POST['revision_notes']) ? trim((string)$_POST['revision_notes']) : '';
        if (!$ids) { header('Location: /admin/requests/review?pr=' . rawurlencode($pr)); return; }
        $rows = $this->requests()->getGroupDetails($pr);
        $origById = [];
        foreach ($rows as $r) { $origById[(int)$r['request_id']] = (int)($r['quantity'] ?? 0); }
        $changed = [];
        foreach ($ids as $idx => $rid) {
            $newQ = isset($qtys[$idx]) ? max(0, (int)$qtys[$idx]) : null;
            if ($rid > 0 && $newQ !== null && isset($origById[$rid]) && $newQ !== (int)$origById[$rid]) {
                // Update quantity
                $this->requests()->updateRequest($rid, ['quantity' => $newQ], (int)($_SESSION['user_id'] ?? 0));
                $this->requests()->followUpRequest($rid, (int)($_SESSION['user_id'] ?? 0), 'Revision proposed by Admin: quantity ' . (int)$origById[$rid] . ' → ' . $newQ);
                $changed[] = [$rid, (int)$origById[$rid], $newQ];
            }
        }
        // Set group revision_state to proposed and store optional notes
        if ($changed) {
            try {
                $st = $this->pdo->prepare("UPDATE purchase_requests SET revision_state = 'proposed', revision_notes = :notes, updated_by = :by, updated_at = NOW() WHERE pr_number = :pr");
                $st->execute(['notes' => ($revNotes !== '' ? $revNotes : null), 'by' => (int)($_SESSION['user_id'] ?? 0), 'pr' => $pr]);
            } catch (\Throwable $ignored) {}
        }
        // Notify all requesters in the group
        try {
            $pdo = $this->pdo;
            $st = $pdo->prepare("SELECT DISTINCT requested_by FROM purchase_requests WHERE pr_number = :pr");
            $st->execute(['pr' => $pr]);
            $recipients = array_map(static fn($r) => (int)$r['requested_by'], $st->fetchAll() ?: []);
            if ($recipients && $changed) {
                $lines = [];
                foreach ($changed as [$rid,$old,$new]) { $lines[] = 'Request #' . $rid . ': ' . $old . ' → ' . $new; }
                $subject = 'PR ' . $pr . ' • Revision Proposed';
                $body = "The Admin proposed a revision of quantities for PR $pr.\n\nChanges:\n" . implode("\n", $lines) . ($revNotes !== '' ? ("\n\nNotes:\n" . $revNotes) : '') . "\n\nPlease accept the revision or reply with justification.";
                $ins = $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
                foreach ($recipients as $to) {
                    $ins->execute(['s' => (int)($_SESSION['user_id'] ?? 0), 'r' => $to, 'j' => $subject, 'b' => $body]);
                }
            }
        } catch (\Throwable $ignored) {}
        header('Location: /admin/requests/review?pr=' . rawurlencode($pr) . '&revised=1');
    }

    /** Securely stream a message attachment to the current user (download). */
    public function downloadMessageAttachment(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $aid = isset($_GET['aid']) ? (int)$_GET['aid'] : 0; // specific attachment id (optional)
        if ($me <= 0 || $id <= 0) { http_response_code(403); echo 'Forbidden'; return; }
        try {
            // If a specific attachment id is provided, serve that file after verifying ownership
            if ($aid > 0) {
                try {
                    $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages_attachments (
                        id BIGSERIAL PRIMARY KEY,
                        message_id BIGINT REFERENCES messages(id) ON DELETE CASCADE,
                        file_name VARCHAR(255) NOT NULL,
                        file_path TEXT NOT NULL,
                        uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )');
                } catch (\Throwable $ignored) {}
                $stA = $this->pdo->prepare('SELECT a.file_name, a.file_path FROM messages_attachments a JOIN messages m ON m.id = a.message_id WHERE a.id = :aid AND a.message_id = :mid AND (m.recipient_id = :me OR m.sender_id = :me)');
                $stA->execute(['aid' => $aid, 'mid' => $id, 'me' => $me]);
                $row = $stA->fetch();
                if ($row && !empty($row['file_path']) && is_file((string)$row['file_path'])) {
                    $name = (string)($row['file_name'] ?? 'attachment');
                    $path = (string)$row['file_path'];
                    $size = @filesize($path) ?: null;
                    $isPdf = preg_match('/\.pdf$/i', $name) === 1;
                    header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'application/octet-stream'));
                    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
                    if ($size !== null) { header('Content-Length: ' . (string)$size); }
                    @readfile($path);
                    return;
                }
                // fallthrough to legacy behavior if not found
            }
            // Prefer attachment stored on messages table (attachment_name/path)
            try {
                $st = $this->pdo->prepare('SELECT attachment_name, attachment_path FROM messages WHERE id = :id AND (recipient_id = :me OR sender_id = :me)');
                $st->execute(['id' => $id, 'me' => $me]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '42703') {
                    try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $ignored) {}
                    try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $ignored) {}
                    $st = $this->pdo->prepare('SELECT attachment_name, attachment_path FROM messages WHERE id = :id AND (recipient_id = :me OR sender_id = :me)');
                    $st->execute(['id' => $id, 'me' => $me]);
                } else { throw $e; }
            }
            $row = $st->fetch();
            $name = (string)($row['attachment_name'] ?? 'attachment');
            $path = (string)($row['attachment_path'] ?? '');
            if (!$row || $path === '' || !is_file($path)) {
                // Attempt PO PDF regeneration if filename suggests a PO document
                if ($path === '' || !is_file($path)) {
                    $regen = $this->tryRegeneratePoByAttachmentName($name);
                    if ($regen && is_file($regen)) {
                        $path = $regen;
                        // Persist regenerated path back to message for durability
                        try { $up = $this->pdo->prepare('UPDATE messages SET attachment_path = :p WHERE id = :id'); $up->execute(['p' => $path, 'id' => $id]); } catch (\Throwable $ignored) {}
                    }
                }
                // Fallback: messages_attachments table
                try {
                    $st2 = $this->pdo->prepare('SELECT a.file_name, a.file_path FROM messages_attachments a JOIN messages m ON m.id = a.message_id WHERE a.message_id = :id AND (m.recipient_id = :me OR m.sender_id = :me) ORDER BY a.uploaded_at DESC LIMIT 1');
                    $st2->execute(['id' => $id, 'me' => $me]);
                    $r2 = $st2->fetch();
                    $name = (string)($r2['file_name'] ?? $name);
                    $path = (string)($r2['file_path'] ?? $path);
                } catch (\Throwable $ignored) {}
            }
            if ($path === '' || !is_file($path)) { http_response_code(404); echo 'File not found'; return; }
            $size = @filesize($path) ?: null;
            $isPdf = preg_match('/\.pdf$/i', $name) === 1;
            header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
            if ($size !== null) { header('Content-Length: ' . (string)$size); }
            @readfile($path);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Download error: ' . $e->getMessage();
        }
    }

    /** Securely preview (inline) a message attachment for the current user. Supports messages.attachment_* or messages_attachments (via optional aid). */
    public function previewMessageAttachment(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0; // message id
        $aid = isset($_GET['aid']) ? (int)$_GET['aid'] : 0; // specific attachment id (optional)
        if ($me <= 0 || $id <= 0) { http_response_code(403); echo 'Forbidden'; return; }
        try {
            $name = 'attachment';
            $path = '';

            // If a specific attachment id is provided, use messages_attachments after verifying access
            if ($aid > 0) {
                try {
                    $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages_attachments (
                        id BIGSERIAL PRIMARY KEY,
                        message_id BIGINT REFERENCES messages(id) ON DELETE CASCADE,
                        file_name VARCHAR(255) NOT NULL,
                        file_path TEXT NOT NULL,
                        uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )');
                } catch (\Throwable $ignored) {}
                $stA = $this->pdo->prepare('SELECT a.file_name, a.file_path FROM messages_attachments a JOIN messages m ON m.id = a.message_id WHERE a.id = :aid AND a.message_id = :mid AND (m.recipient_id = :me OR m.sender_id = :me)');
                $stA->execute(['aid' => $aid, 'mid' => $id, 'me' => $me]);
                $rowA = $stA->fetch();
                if ($rowA) {
                    $name = (string)($rowA['file_name'] ?? $name);
                    $path = (string)($rowA['file_path'] ?? '');
                }
            }

            // If no explicit aid or file not found, prefer attachment on messages table (legacy)
            if ($path === '' || !is_file($path)) {
                try {
                    $st = $this->pdo->prepare('SELECT attachment_name, attachment_path FROM messages WHERE id = :id AND (recipient_id = :me OR sender_id = :me)');
                    $st->execute(['id' => $id, 'me' => $me]);
                } catch (\PDOException $e) {
                    if ($e->getCode() === '42703') {
                        try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $ignored) {}
                        try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $ignored) {}
                        $st = $this->pdo->prepare('SELECT attachment_name, attachment_path FROM messages WHERE id = :id AND (recipient_id = :me OR sender_id = :me)');
                        $st->execute(['id' => $id, 'me' => $me]);
                    } else { throw $e; }
                }
                $row = $st->fetch();
                if ($row) {
                    $name = (string)($row['attachment_name'] ?? $name);
                    $path = (string)($row['attachment_path'] ?? '');
                }
            }

            // If still missing, fall back to most recent record in messages_attachments for this message
            if ($path === '' || !is_file($path)) {
                try {
                    $st2 = $this->pdo->prepare('SELECT a.file_name, a.file_path FROM messages_attachments a JOIN messages m ON m.id = a.message_id WHERE a.message_id = :id AND (m.recipient_id = :me OR m.sender_id = :me) ORDER BY a.uploaded_at DESC LIMIT 1');
                    $st2->execute(['id' => $id, 'me' => $me]);
                    $r2 = $st2->fetch();
                    if ($r2) {
                        $name = (string)($r2['file_name'] ?? $name);
                        $path = (string)($r2['file_path'] ?? '');
                    }
                } catch (\Throwable $ignored) {}
            }

            if ($path === '' || !is_file($path)) {
                // Attempt PO PDF regeneration if filename indicates a PO
                $regen = $this->tryRegeneratePoByAttachmentName($name);
                if ($regen && is_file($regen)) {
                    $path = $regen;
                    // Persist to message when primary attachment
                    try { $up = $this->pdo->prepare('UPDATE messages SET attachment_path = :p WHERE id = :id'); $up->execute(['p' => $path, 'id' => $id]); } catch (\Throwable $ignored) {}
                }
            }
            if ($path === '' || !is_file($path)) { http_response_code(404); echo 'File not found'; return; }

            // Best-effort content type detection (inline). Prefer PDF; allow images to preview inline too.
            $ctype = 'application/octet-stream';
            $lname = strtolower($name);
            if (preg_match('/\.pdf$/i', $lname)) { $ctype = 'application/pdf'; }
            elseif (preg_match('/\.(png|jpg|jpeg|gif|webp)$/i', $lname)) { $map = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp']; $ext = pathinfo($lname, PATHINFO_EXTENSION); $ctype = $map[$ext] ?? 'application/octet-stream'; }

            $size = @filesize($path) ?: null;
            header('Content-Type: ' . $ctype);
            header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
            if ($size !== null) { header('Content-Length: ' . (string)$size); }
            @readfile($path);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Preview error: ' . $e->getMessage();
        }
    }

    /**
     * Best-effort regeneration for a missing official PO PDF based on attachment filename.
     * Recognizes names like "PO-2025001.pdf" and rebuilds from purchase_orders and lines.
     * Returns the path to the regenerated file or null on failure.
     */
    private function tryRegeneratePoByAttachmentName(string $attachmentName): ?string
    {
        $name = trim($attachmentName);
        if ($name === '') { return null; }
        if (!preg_match('/^po[-_\s]?([0-9]{7})\.pdf$/i', $name, $m)) { return null; }
        $poNum = $m[1];
        try {
            $pdo = $this->pdo;
            // Detect legacy primary key name
            $idCol = 'id';
            try {
                $hasId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='id'")->fetchColumn();
                if (!$hasId) {
                    $hasPoId = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name='purchase_orders' AND column_name='po_id'")->fetchColumn();
                    if ($hasPoId) { $idCol = 'po_id'; }
                }
            } catch (\Throwable $e) {}
            // Load PO by po_number
            $st = $pdo->prepare('SELECT ' . $idCol . ' AS id, po_number, vendor_name, vendor_address, vendor_tin, reference, terms, supplier_terms, center, notes, discount, deliver_to, look_for, prepared_by, reviewed_by, approved_by, created_at FROM purchase_orders WHERE po_number = :po LIMIT 1');
            $st->execute(['po' => $poNum]);
            $po = $st->fetch();
            if (!$po) { return null; }
            $id = (int)$po['id'];
            $it = $pdo->prepare('SELECT description, unit, qty, unit_price, line_total FROM purchase_order_items WHERE po_id = :id ORDER BY id ASC');
            $it->execute(['id' => $id]);
            $items = [];
            foreach ($it->fetchAll() as $r) {
                $items[] = [
                    'description' => (string)$r['description'],
                    'unit' => (string)$r['unit'],
                    'qty' => (int)$r['qty'],
                    'unit_price' => (float)$r['unit_price'],
                    'total' => (float)$r['line_total'],
                ];
            }
            if (empty($items)) { return null; }
            $root = @realpath(__DIR__ . '/../../..') ?: null;
            $dir = $root ? ($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf') : (sys_get_temp_dir());
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'PO-' . preg_replace('/[^A-Za-z0-9_-]/','_', (string)$po['po_number']) . '.pdf';
            // Generate
            try {
                $pdf = new \App\Services\PDFService();
                $pdf->generatePurchaseOrderPDFToFile([
                    'po_number' => (string)$po['po_number'],
                    'date' => date('Y-m-d', strtotime((string)($po['created_at'] ?? date('Y-m-d')))),
                    'vendor_name' => (string)($po['vendor_name'] ?? ''),
                    'vendor_address' => (string)($po['vendor_address'] ?? ''),
                    'vendor_tin' => (string)($po['vendor_tin'] ?? ''),
                    'reference' => (string)($po['reference'] ?? ''),
                    'terms' => (string)($po['terms'] ?? ''),
                    'supplier_terms' => (string)($po['supplier_terms'] ?? ''),
                    'center' => (string)($po['center'] ?? ''),
                    'notes' => (string)($po['notes'] ?? ''),
                    'discount' => isset($po['discount']) ? (float)$po['discount'] : 0.0,
                    'deliver_to' => (string)($po['deliver_to'] ?? ''),
                    'look_for' => (string)($po['look_for'] ?? ''),
                    'prepared_by' => (string)($po['prepared_by'] ?? ''),
                    'reviewed_by' => (string)($po['reviewed_by'] ?? ''),
                    'approved_by' => (string)($po['approved_by'] ?? ''),
                    'items' => $items,
                ], $file);
                if (is_file($file)) {
                    try { $pdo->prepare('UPDATE purchase_orders SET pdf_path = :p, updated_at = NOW() WHERE ' . $idCol . ' = :id')->execute(['p' => $file, 'id' => $id]); } catch (\Throwable $ignored) {}
                    return $file;
                }
            } catch (\Throwable $e) { return null; }
        } catch (\Throwable $e) { return null; }
        return null;
    }

    public function settings(): void
    {
        $me = (int)($_SESSION['user_id'] ?? 0);
        try {
            // Ensure avatar column exists
            $this->pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path TEXT");
            $stmt = $this->pdo->prepare('SELECT user_id, username, full_name, email, avatar_path FROM users WHERE user_id = :id');
            $stmt->execute(['id' => $me]);
            $user = $stmt->fetch();
            $this->render('dashboard/settings.php', ['user' => $user]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading settings: ' . $e->getMessage();
        }
    }

    /** Admin: Announcements list + create form */
    public function announcements(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        try {
            // Ensure table exists
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS announcements (
                id BIGSERIAL PRIMARY KEY,
                topic VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                created_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )');
            $list = $this->pdo->query('SELECT id, topic, content, created_by, created_at FROM announcements ORDER BY created_at DESC LIMIT 100')->fetchAll();
            $err = isset($_GET['error']) ? (string)$_GET['error'] : '';
            $ok = isset($_GET['created']) ? true : false;
            $this->render('dashboard/announcements.php', ['announcements' => $list, 'created' => $ok, 'error' => $err]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading announcements: ' . $e->getMessage();
        }
    }

    /** Admin: Create a new announcement and notify Admin Assistants and Procurement */
    public function createAnnouncement(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $topic = trim((string)($_POST['topic'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        if ($topic === '' || $content === '') { header('Location: /admin/announcements?error=' . rawurlencode('Topic and content are required.')); return; }
        try {
            // Ensure table exists then insert
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS announcements (
                id BIGSERIAL PRIMARY KEY,
                topic VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                created_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )');
            $ins = $this->pdo->prepare('INSERT INTO announcements (topic, content, created_by) VALUES (:t, :c, :by)');
            $ins->execute(['t' => $topic, 'c' => $content, 'by' => (int)($_SESSION['user_id'] ?? 0)]);

            // Notify Admin Assistants and Procurement roles via messages
            try {
                // Ensure messages table exists
                $this->pdo->exec('CREATE TABLE IF NOT EXISTS messages (
                    id BIGSERIAL PRIMARY KEY,
                    sender_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                    recipient_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
                    subject VARCHAR(255) NOT NULL,
                    body TEXT NOT NULL,
                    is_read BOOLEAN NOT NULL DEFAULT FALSE,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )');
                $recips = $this->pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('admin_assistant','custodian','procurement','procurement_manager')")->fetchAll();
                if ($recips) {
                    $subject = 'Announcement • ' . $topic;
                    $encBody = \App\Services\CryptoService::encrypt($content, 'announce');
                    $insMsg = $this->pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
                    $sender = (int)($_SESSION['user_id'] ?? 0);
                    foreach ($recips as $r) {
                        $insMsg->execute(['s' => $sender, 'r' => (int)$r['user_id'], 'j' => $subject, 'b' => $encBody]);
                    }
                }
            } catch (\Throwable $ignored) {}

            header('Location: /admin/announcements?created=1');
        } catch (\Throwable $e) {
            header('Location: /admin/announcements?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** Admin: Delete an announcement */
    public function deleteAnnouncement(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) { header('Location: /admin/announcements?error=' . rawurlencode('Invalid announcement.')); return; }
        try {
            $del = $this->pdo->prepare('DELETE FROM announcements WHERE id = :id');
            $del->execute(['id' => $id]);
            header('Location: /admin/announcements');
        } catch (\Throwable $e) {
            header('Location: /admin/announcements?error=' . rawurlencode($e->getMessage()));
        }
    }

    /** View a single purchase request details with history. */
    public function viewRequest(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (!isset($_SESSION['user_id'])) { header('Location: /login'); return; }
        $id = 0;
        if (isset($_GET['request_id'])) { $id = (int)$_GET['request_id']; }
        if ($id <= 0 && isset($_GET['id'])) { $id = (int)$_GET['id']; }
        if ($id <= 0) { header('Location: /dashboard'); return; }
        try {
            $request = $this->requests()->getRequestById($id);
            $history = $this->requests()->getRequestHistory($id);
            $this->render('requests/show.php', ['request' => $request, 'history' => $history]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading request: ' . $e->getMessage();
        }
    }

    /** Admin: Archived/History view (grouped by PR number with is_archived=TRUE). */
    public function viewRequestsHistoryAdmin(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }
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
        $rows = array_values(array_filter($rows, static fn($r) => !empty($r['is_archived'])));
        $this->render('admin/requests_history.php', [ 'groups' => $rows, 'filters' => [ 'branch' => $branchId, 'status' => $status, 'sort' => $sort, 'order' => $order ] ]);
    }

    public function saveSettings(): void
    {
        $me = (int)($_SESSION['user_id'] ?? 0);
        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($name === '') { header('Location: /settings'); return; }
        try {
            // Split full_name into first/last for consistency
            $parts = preg_split('/\s+/', $name);
            $first = $parts ? (string)array_shift($parts) : '';
            $last = $parts ? (string)array_pop($parts) : '';
            if ($first === '' && $name !== '') { $first = $name; }
            if ($last === '' && $first !== '' && $name !== $first) { $last = trim(str_replace($first, '', $name)); }

            // Always update profile fields first (without password)
            $stmt = $this->pdo->prepare('UPDATE users SET first_name = :fn, last_name = :ln, full_name = :n, email = :e WHERE user_id = :id');
            $stmt->execute(['fn' => $first, 'ln' => $last, 'n' => $name, 'e' => $email !== '' ? $email : null, 'id' => $me]);

            // Optional avatar upload (profile picture)
            try { $this->pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path TEXT"); } catch (\Throwable $e) {}
            if (!empty($_FILES['avatar']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
                $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                $type = (string)($_FILES['avatar']['type'] ?? '');
                $ext = $allowed[$type] ?? null;
                if ($ext) {
                    $dir = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'profile';
                    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                    $dest = $dir . DIRECTORY_SEPARATOR . 'user_' . $me . '.' . $ext;
                    // Move the file (replace existing)
                    @move_uploaded_file($_FILES['avatar']['tmp_name'], $dest);
                    $upA = $this->pdo->prepare('UPDATE users SET avatar_path = :p WHERE user_id = :id');
                    $upA->execute(['p' => $dest, 'id' => $me]);
                }
            }

            // Handle password change if requested
            $wantsChange = ($current !== '' || $new !== '' || $confirm !== '');
            if ($wantsChange) {
                if ($current === '' || $new === '' || $confirm === '') { header('Location: /settings?error=' . rawurlencode('Please fill all password fields.')); return; }
                if ($new !== $confirm) { header('Location: /settings?error=' . rawurlencode('New passwords do not match.')); return; }
                if (strlen($new) < 6) { header('Location: /settings?error=' . rawurlencode('New password must be at least 6 characters.')); return; }
                // Verify old password
                $st = $this->pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id');
                $st->execute(['id' => $me]);
                $hash = (string)($st->fetchColumn() ?: '');
                if ($hash === '' || !password_verify($current, $hash)) { header('Location: /settings?error=' . rawurlencode('Current password is incorrect.')); return; }
                // Save new hash
                $newHash = password_hash($new, PASSWORD_BCRYPT);
                $up = $this->pdo->prepare('UPDATE users SET password_hash = :p, password_changed_at = NOW() WHERE user_id = :id');
                $up->execute(['p' => $newHash, 'id' => $me]);
            }

            header('Location: /settings?saved=1');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /settings?error=' . $msg);
        }
    }

}
