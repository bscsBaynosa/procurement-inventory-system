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
                'custodians' => 0,
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
                if ($row['role'] === 'procurement_manager') { $counts['managers'] = (int)$row['c']; }
                if ($row['role'] === 'custodian') { $counts['custodians'] = (int)$row['c']; }
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
                'SELECT pr.request_id, pr.status, pr.created_at, i.name AS item_name, b.name AS branch_name
                 FROM purchase_requests pr
                 LEFT JOIN inventory_items i ON i.item_id = pr.item_id
                 LEFT JOIN branches b ON b.branch_id = pr.branch_id
                 ORDER BY pr.created_at DESC
                 LIMIT 6'
            )->fetchAll();

            $this->render('dashboard/admin.php', [
                'counts' => $counts,
                'recent' => $recent,
                'items' => [],
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
            $list = $this->pdo->query('SELECT user_id, username, full_name, email, role, is_active, branch_id, created_at FROM users ORDER BY created_at DESC LIMIT 100')->fetchAll();
            $branches = $this->pdo->query('SELECT branch_id, name FROM branches WHERE is_active = TRUE ORDER BY name ASC')->fetchAll();
            $created = isset($_GET['created']) ? true : false;
            $error = isset($_GET['error']) ? (string)$_GET['error'] : '';
            $this->render('dashboard/users.php', [ 'users' => $list, 'branches' => $branches, 'created' => $created, 'error' => $error ]);
        } catch (\Throwable $e) {
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
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = (string)($_POST['role'] ?? '');
        $branchId = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int)$_POST['branch_id'] : null;
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $fullName === '' || $role === '' || $password === '') {
            header('Location: /admin/users?error=Missing+required+fields');
            return;
        }

        if (!in_array($role, ['custodian','procurement_manager','admin'], true)) {
            header('Location: /admin/users?error=Invalid+role');
            return;
        }

        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $this->pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role, branch_id, is_active, created_by, updated_by) VALUES (:u, :p, :n, :e, :r, :b, TRUE, :by, :by)');
            $by = $_SESSION['user_id'] ?? null;
            $stmt->execute([
                'u' => $username,
                'p' => $hash,
                'n' => $fullName,
                'e' => $email !== '' ? $email : null,
                'r' => $role,
                'b' => $branchId,
                'by' => $by,
            ]);
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
            $this->render('dashboard/branches.php', ['branches' => $list]);
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

    public function messages(): void
    {
        $me = (int)($_SESSION['user_id'] ?? 0);
        try {
            $stmt = $this->pdo->prepare('SELECT m.id, m.subject, m.body, m.is_read, m.created_at, u.full_name AS from_name
                FROM messages m JOIN users u ON u.user_id = m.sender_id WHERE m.recipient_id = :me ORDER BY m.created_at DESC LIMIT 50');
            $stmt->execute(['me' => $me]);
            $inbox = $stmt->fetchAll();
            $users = $this->pdo->query("SELECT user_id, full_name, role FROM users WHERE is_active = TRUE ORDER BY role, full_name")->fetchAll();
            $this->render('dashboard/messages.php', ['inbox' => $inbox, 'users' => $users]);
        } catch (\Throwable $e) {
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
            $stmt->execute(['s' => $me, 'r' => $to, 'j' => $subject, 'b' => $body]);
            header('Location: /admin/messages');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /admin/messages?error=' . $msg);
        }
    }

    public function settings(): void
    {
        $me = (int)($_SESSION['user_id'] ?? 0);
        try {
            $stmt = $this->pdo->prepare('SELECT user_id, username, full_name, email FROM users WHERE user_id = :id');
            $stmt->execute(['id' => $me]);
            $user = $stmt->fetch();
            $this->render('dashboard/settings.php', ['user' => $user]);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Error loading settings: ' . $e->getMessage();
        }
    }

    public function saveSettings(): void
    {
        $me = (int)($_SESSION['user_id'] ?? 0);
        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $pwd = (string)($_POST['password'] ?? '');
        if ($name === '') { header('Location: /settings'); return; }
        try {
            if ($pwd !== '') {
                $hash = password_hash($pwd, PASSWORD_BCRYPT);
                $stmt = $this->pdo->prepare('UPDATE users SET full_name = :n, email = :e, password_hash = :p WHERE user_id = :id');
                $stmt->execute(['n' => $name, 'e' => $email !== '' ? $email : null, 'p' => $hash, 'id' => $me]);
            } else {
                $stmt = $this->pdo->prepare('UPDATE users SET full_name = :n, email = :e WHERE user_id = :id');
                $stmt->execute(['n' => $name, 'e' => $email !== '' ? $email : null, 'id' => $me]);
            }
            header('Location: /settings?saved=1');
        } catch (\Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /settings?error=' . $msg);
        }
    }
}
