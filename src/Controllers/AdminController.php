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
                'managers' => 0,
                'admin_assistants' => 0,
                'requests' => [
                    'pending' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                    'in_review' => 0,
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
            // Role counts
            $stmt = $this->pdo->query("SELECT role, COUNT(*) AS c FROM users GROUP BY role");
            foreach ($stmt->fetchAll() as $row) {
                if ($row['role'] === 'procurement_manager' || $row['role'] === 'procurement') { $counts['managers'] += (int)$row['c']; }
                if ($row['role'] === 'admin_assistant' || $row['role'] === 'custodian') { $counts['admin_assistants'] += (int)$row['c']; }
            }

            // Requests by status (incoming)
            $rs = $this->pdo->query('SELECT status, COUNT(*) AS c FROM purchase_requests GROUP BY status');
            foreach ($rs->fetchAll() as $row) {
                $status = (string)$row['status'];
                $c = (int)$row['c'];
                if (!isset($counts['requests'][$status])) { $counts['requests'][$status] = 0; }
                $counts['requests'][$status] += $c;
            }

            // Recent activity: last 6 requests
            $recent = $this->pdo->query(
                'SELECT pr.request_id, pr.pr_number, pr.status, pr.created_at, i.name AS item_name, b.name AS branch_name
                 FROM purchase_requests pr
                 LEFT JOIN inventory_items i ON i.item_id = pr.item_id
                 LEFT JOIN branches b ON b.branch_id = pr.branch_id
                 ORDER BY pr.created_at DESC
                 LIMIT 6'
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
     * Simple Users page for admins: list users and provide a quick create form.
     */
    public function users(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); return; }

        try {
            $list = $this->pdo->query('SELECT user_id, username, first_name, last_name, full_name, email, role, is_active, branch_id, created_at FROM users ORDER BY created_at DESC LIMIT 100')->fetchAll();
            $branches = $this->pdo->query('SELECT branch_id, name FROM branches WHERE is_active = TRUE ORDER BY name ASC')->fetchAll();
            $created = isset($_GET['created']) ? true : false;
            $error = isset($_GET['error']) ? (string)$_GET['error'] : '';
            $editUser = null;
            if (isset($_GET['edit'])) {
                $id = (int)$_GET['edit'];
                if ($id > 0) {
                    $st = $this->pdo->prepare('SELECT user_id, username, first_name, last_name, email, role, is_active, branch_id FROM users WHERE user_id = :id');
                    $st->execute(['id' => $id]);
                    $editUser = $st->fetch();
                }
            }
            $this->render('dashboard/users.php', [ 'users' => $list, 'branches' => $branches, 'created' => $created, 'error' => $error, 'editUser' => $editUser ]);
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
                    $created = isset($_GET['created']) ? true : false;
                    $error = isset($_GET['error']) ? (string)$_GET['error'] : '';
                    $editUser = null;
                    if (isset($_GET['edit'])) {
                        $id = (int)$_GET['edit'];
                        if ($id > 0) {
                            $st = $this->pdo->prepare('SELECT user_id, username, first_name, last_name, email, role, is_active, branch_id FROM users WHERE user_id = :id');
                            $st->execute(['id' => $id]);
                            $editUser = $st->fetch();
                        }
                    }
                    $this->render('dashboard/users.php', [ 'users' => $list, 'branches' => $branches, 'created' => $created, 'error' => $error, 'editUser' => $editUser ]);
                    return;
                } catch (\Throwable $mig) {
                    // Fall back to minimal list using full_name only
                }
            }
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading users: ' . $e->getMessage();
        }
    }

    /**
     * Handle admin user creation POST.
     */
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

        if ($username === '' || $firstName === '' || $lastName === '' || $role === '' || $password === '') {
            header('Location: /admin/users?error=Missing+required+fields');
            return;
        }

        // Ensure new roles exist
        try { $this->pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'supplier'"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'admin_assistant'"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'procurement'"); } catch (\Throwable $e) {}
        if (!in_array($role, ['admin','admin_assistant','procurement','supplier','custodian','procurement_manager'], true)) {
            header('Location: /admin/users?error=Invalid+role');
            return;
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
            $prefillTo = isset($_GET['to']) ? (int)$_GET['to'] : 0;
            $prefillSubject = isset($_GET['subject']) ? (string)$_GET['subject'] : '';
            $this->render('dashboard/messages.php', ['inbox' => $inbox, 'users' => $users, 'prefill_to' => $prefillTo, 'prefill_subject' => $prefillSubject]);
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
        $to = isset($_POST['to']) ? (int)$_POST['to'] : 0;
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        if ($to <= 0 || $subject === '' || $body === '') { header('Location: /admin/messages'); return; }
        try {
            $stmt = $this->pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
            $encBody = \App\Services\CryptoService::encrypt($body, 'msg:' . (string)$me . '->' . (string)$to);
            $stmt->execute(['s' => $me, 'r' => $to, 'j' => $subject, 'b' => $encBody]);
            $msgId = (int)$this->pdo->lastInsertId('messages_id_seq');
            // Handle optional attachment upload
            if (!empty($_FILES['attachment']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
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
                    $insA->execute(['m' => $msgId, 'n' => $safe, 'p' => $dest]);
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
            // Inbox: show all messages (read and unread), newest first
            $stmt = $this->pdo->prepare('SELECT m.id, m.subject, m.body, m.created_at, m.sender_id, m.is_read, u.full_name AS from_name
                FROM messages m JOIN users u ON u.user_id = m.sender_id
                WHERE m.recipient_id = :me
                ORDER BY m.created_at DESC');
            $stmt->execute(['me' => $me]);
            $list = $stmt->fetchAll();
            foreach ($list as &$row) { $row['body'] = \App\Services\CryptoService::maybeDecrypt((string)$row['body']); }
            $this->render('dashboard/inbox.php', ['inbox' => $list]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading notifications: ' . $e->getMessage();
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
            $st = $this->pdo->prepare('SELECT m.id, m.subject, m.body, m.is_read, m.created_at, m.sender_id, m.attachment_name, m.attachment_path, u.full_name AS from_name
                FROM messages m JOIN users u ON u.user_id = m.sender_id WHERE m.id = :id AND m.recipient_id = :me');
            $st->execute(['id' => $id, 'me' => $me]);
            $msg = $st->fetch();
            if ($msg) { $msg['body'] = \App\Services\CryptoService::maybeDecrypt((string)$msg['body']); }
            if (!$msg) { header('Location: /notifications'); return; }
            $this->render('dashboard/notification_view.php', ['message' => $msg]);
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
            $this->requests()->updateGroupStatus($pr, 'canvassing_approved', (int)($_SESSION['user_id'] ?? 0), 'Canvassing approved by Admin');
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

    /** Securely stream a message attachment to the current user. */
    public function downloadMessageAttachment(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($me <= 0 || $id <= 0) { http_response_code(403); echo 'Forbidden'; return; }
        try {
            // Prefer attachment stored on messages table (attachment_name/path)
            $st = $this->pdo->prepare('SELECT attachment_name, attachment_path FROM messages WHERE id = :id AND recipient_id = :me');
            $st->execute(['id' => $id, 'me' => $me]);
            $row = $st->fetch();
            $name = (string)($row['attachment_name'] ?? 'attachment');
            $path = (string)($row['attachment_path'] ?? '');
            if (!$row || $path === '' || !is_file($path)) {
                // Fallback: messages_attachments table
                try {
                    $st2 = $this->pdo->prepare('SELECT a.file_name, a.file_path FROM messages_attachments a JOIN messages m ON m.id = a.message_id WHERE a.message_id = :id AND m.recipient_id = :me ORDER BY a.uploaded_at DESC LIMIT 1');
                    $st2->execute(['id' => $id, 'me' => $me]);
                    $r2 = $st2->fetch();
                    $name = (string)($r2['file_name'] ?? $name);
                    $path = (string)($r2['file_path'] ?? $path);
                } catch (\Throwable $ignored) {}
            }
            if ($path === '' || !is_file($path)) { http_response_code(404); echo 'File not found'; return; }
            $size = @filesize($path) ?: null;
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
            if ($size !== null) { header('Content-Length: ' . (string)$size); }
            @readfile($path);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Download error: ' . $e->getMessage();
        }
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

    /** Send a test email using configured SMTP/mail to verify delivery. */
    public function sendTestEmail(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $me = (int)($_SESSION['user_id'] ?? 0);
        if ($me <= 0) { header('Location: /login'); return; }
        try {
            $to = trim((string)($_POST['to'] ?? ''));
            if ($to === '') {
                $stmt = $this->pdo->prepare('SELECT email FROM users WHERE user_id = :id');
                $stmt->execute(['id' => $me]);
                $to = (string)($stmt->fetchColumn() ?: '');
            }
            if ($to === '') { header('Location: /settings?error=' . rawurlencode('No recipient email set. Add your email, save, then try again.')); return; }
            $mail = new \App\Services\MailService();
            $ok = $mail->send($to, 'SMTP Test • Procurement System', "This is a test email from the Procurement & Inventory System.\nIf you can read this, email delivery works.");
            header('Location: /settings?' . ($ok ? 'saved=1' : ('error=' . rawurlencode('Send failed. Check SMTP settings or spam folder.'))));
        } catch (\Throwable $e) {
            header('Location: /settings?error=' . rawurlencode($e->getMessage()));
        }
    }
}
