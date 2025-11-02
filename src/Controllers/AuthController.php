<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\MailService;
use App\Database\Connection;

class AuthController extends BaseController
{
    private ?AuthService $auth = null;

    public function __construct(?AuthService $auth = null)
    {
        // Lazily create AuthService only when needed to avoid DB connect on landing.
        $this->auth = $auth; // may be null
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    private function auth(): AuthService
    {
        if (!$this->auth) {
            $this->auth = new AuthService();
        }
        return $this->auth;
    }

    public function showLanding(?string $error = null, ?string $signupError = null, ?string $signupSuccess = null, ?string $mode = null): void
    {
        // Provide categories for supplier sign-up select
        $categories = require __DIR__ . '/../config/categories.php';
        $this->render('auth/landing.php', [
            'error' => $error,
            'signup_error' => $signupError,
            'signup_success' => $signupSuccess,
            'mode' => $mode,
            'categories' => $categories,
        ]);
    }

    public function showLoginForm(?string $error = null): void
    {
        $this->render('auth/login.php', [ 'error' => $error ]);
    }

    public function login(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($username === '' || $password === '') {
            $this->showLoginForm('All fields are required.');
            return;
        }

        try {
            if ($this->auth()->attempt($username, $password, $ip, $ua)) {
                header('Location: /dashboard');
                return;
            }
            $from = (string)($_POST['from'] ?? '');
            $errorMsg = $this->auth()->lastError() ?: 'Invalid credentials or role.';
            if ($from === 'landing') {
                $this->showLanding($errorMsg);
                return;
            }
            $this->showLoginForm($errorMsg);
        } catch (\Throwable $e) {
            // Most common cause: database not initialized or not reachable
            error_log('[AuthController@login] ' . $e->getMessage());
            $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            $scheme = $forwarded !== '' ? explode(',', $forwarded)[0] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $setupUrl = $scheme . '://' . $host . '/setup?token=YOUR_TOKEN';
            $safeMsg = preg_replace('/(password|pwd|pass|secret)=([^;\s]+)/i', '$1=***', (string)$e->getMessage());
            $hint = 'Sign-in is temporarily unavailable. Please run setup to initialize the database: ' . $setupUrl . ' | Error: ' . $safeMsg;
            $from = (string)($_POST['from'] ?? '');
            if ($from === 'landing') {
                $this->showLanding($hint);
                return;
            }
            $this->showLoginForm($hint);
        }
    }

    public function logout(): void
    {
    $this->auth()->logout();
        header('Location: /');
    }

    /** Forgot password (GET): show request form on landing */
    public function showForgot(?string $msg = null, ?string $err = null): void
    {
        $this->showLanding($err, null, $msg, 'forgot');
    }

    /** Ensure password_resets support table exists */
    private function ensurePasswordResetsTable(): void
    {
        $pdo = Connection::resolve();
        $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
            id BIGSERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            token_hash CHAR(64) NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL,
            used_at TIMESTAMPTZ NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )');
        // Helpful index
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets(token_hash)');
    }

    /** Forgot password (POST): send reset link via email */
    public function sendReset(): void
    {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        if ($identifier === '') { $this->showForgot(null, 'Please enter your username or email.'); return; }
        try {
            $this->ensurePasswordResetsTable();
            $pdo = Connection::resolve();
            $stmt = $pdo->prepare('SELECT user_id, username, email FROM users WHERE LOWER(username)=LOWER(:id) OR LOWER(email)=LOWER(:id) LIMIT 1');
            $stmt->execute(['id' => $identifier]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            // Always behave as if success to prevent user enumeration; only send if we find the user
            if ($user && !empty($user['email'])) {
                $token = bin2hex(random_bytes(32));
                $hash = hash('sha256', $token);
                $exp  = date('Y-m-d H:i:sP', time() + 3600); // 1 hour
                $ins = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:u,:h,:e)');
                $ins->execute(['u' => (int)$user['user_id'], 'h' => $hash, 'e' => $exp]);

                // Compose email
                $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
                $scheme = $forwarded !== '' ? explode(',', $forwarded)[0] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $link = $scheme . '://' . $host . '/reset-password?token=' . $token;
                $body = "Hello,\n\nWe received a request to reset your password.\n" .
                        "If you made this request, click the link below to set a new password.\n\n" .
                        $link . "\n\nThis link will expire in 1 hour. If you didn't request this, you can ignore this email.";
                (new MailService())->send((string)$user['email'], 'Reset your password', $body);
            }
            $this->showForgot('If the account exists, we sent a reset link to its email address. Please check your inbox.', null);
        } catch (\Throwable $e) {
            $this->showForgot(null, 'Could not process request at this time.');
        }
    }

    /** Reset form (GET) */
    public function showResetForm(): void
    {
        $token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
        $this->render('auth/reset.php', ['token' => $token, 'error' => null]);
    }

    /** Reset handler (POST) */
    public function handleReset(): void
    {
        $token = trim((string)($_POST['token'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        if ($token === '' || $password === '' || $confirm === '') {
            $this->render('auth/reset.php', ['token' => $token, 'error' => 'All fields are required.']); return;
        }
        if ($password !== $confirm) {
            $this->render('auth/reset.php', ['token' => $token, 'error' => 'Passwords do not match.']); return;
        }
        if (strlen($password) < 6) {
            $this->render('auth/reset.php', ['token' => $token, 'error' => 'Password must be at least 6 characters.']); return;
        }
        try {
            $this->ensurePasswordResetsTable();
            $pdo = Connection::resolve();
            $hash = hash('sha256', $token);
            $sel = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM password_resets WHERE token_hash=:h ORDER BY id DESC LIMIT 1');
            $sel->execute(['h' => $hash]);
            $row = $sel->fetch(\PDO::FETCH_ASSOC);
            if (!$row) { $this->render('auth/reset.php', ['token' => $token, 'error' => 'Invalid or expired link.']); return; }
            if (!empty($row['used_at'])) { $this->render('auth/reset.php', ['token' => $token, 'error' => 'This link has already been used.']); return; }
            if (strtotime((string)$row['expires_at']) < time()) { $this->render('auth/reset.php', ['token' => $token, 'error' => 'This link has expired.']); return; }

            // Update user password
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $upd = $pdo->prepare('UPDATE users SET password_hash=:p WHERE user_id=:u');
            $upd->execute(['p' => $newHash, 'u' => (int)$row['user_id']]);

            // Mark token as used
            $mark = $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=:id');
            $mark->execute(['id' => (int)$row['id']]);

            // Show success on landing
            $this->showLanding(null, null, 'Password updated. You can now sign in with your new password.', 'signin');
        } catch (\Throwable $e) {
            $this->render('auth/reset.php', ['token' => $token, 'error' => 'Could not reset password.']);
        }
    }

    /** Supplier Signup (GET) */
    public function showSupplierSignup(?string $error = null, ?string $success = null): void
    {
        $categories = require __DIR__ . '/../config/categories.php';
        $this->render('auth/signup_supplier.php', [ 'error' => $error, 'success' => $success, 'categories' => $categories ]);
    }

    /** Supplier Signup (POST): creates a new supplier user and emails a random password */
    public function signupSupplier(): void
    {
        $company = trim((string)($_POST['company'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $contact = trim((string)($_POST['contact'] ?? ''));
        $from = (string)($_POST['from'] ?? '');
        if ($company === '' || $category === '' || $username === '' || $email === '') {
            if ($from === 'landing') {
                $this->showLanding(null, 'All required fields must be filled.', null, 'signup');
                return;
            }
            $this->showSupplierSignup('All required fields must be filled.');
            return;
        }
        // Validate category against allowed list
        $allowedCategories = require __DIR__ . '/../config/categories.php';
        if (!in_array($category, $allowedCategories, true)) {
            $msg = 'Please choose a valid category from the list.';
            if ($from === 'landing') { $this->showLanding(null, $msg, null, 'signup'); return; }
            $this->showSupplierSignup($msg);
            return;
        }
        try {
            // Ensure new roles exist in enum
            $pdo = \App\Database\Connection::resolve();
            try { $pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'supplier'"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'admin_assistant'"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'procurement'"); } catch (\Throwable $e) {}

            // Generate random 6-char password
            $pwd = \App\Services\PasswordService::randomPassword(6);
            $hash = password_hash($pwd, PASSWORD_BCRYPT);

            // Create user as supplier; store company in first_name, last_name fallback
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, first_name, last_name, full_name, email, role, is_active) VALUES (:u,:p,:fn,:ln,:n,:e,\'supplier\', TRUE)');
            $fn = $company; $ln = $category;
            $stmt->execute(['u' => $username, 'p' => $hash, 'fn' => $fn, 'ln' => $ln, 'n' => $company, 'e' => $email]);

            // Send email with the generated password; also show credentials on screen for local/dev reliability
            $mail = new \App\Services\MailService();
            $sent = $mail->send($email, 'Your Supplier Account Credentials', "Hello,\n\nYour supplier account has been created.\nUsername: {$username}\nPassword: {$pwd}\n\nPlease sign in and change your password in Settings.\n");
            if ($sent) {
                $succ = 'Account created. We emailed your credentials.';
            } else {
                $succ = 'Account created, but email sending may be unavailable. Please copy your credentials now — Username: ' . $username . ' • Password: ' . $pwd . ' — and sign in, then change your password in Settings.';
            }
            if ($from === 'landing') {
                $this->showLanding(null, null, $succ, 'signin');
                return;
            }
            $this->showSupplierSignup(null, $succ);
        } catch (\Throwable $e) {
            if ($from === 'landing') {
                $this->showLanding(null, 'Signup failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), null, 'signup');
                return;
            }
            $this->showSupplierSignup('Signup failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }
}
?>

