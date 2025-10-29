<?php

namespace App\Controllers;

use App\Services\AuthService;

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

    public function showLanding(?string $error = null): void
    {
        $this->render('auth/landing.php', [ 'error' => $error ]);
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

    /** Supplier Signup (GET) */
    public function showSupplierSignup(?string $error = null, ?string $success = null): void
    {
        $this->render('auth/signup_supplier.php', [ 'error' => $error, 'success' => $success ]);
    }

    /** Supplier Signup (POST): creates a new supplier user and emails a random password */
    public function signupSupplier(): void
    {
        $company = trim((string)($_POST['company'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $contact = trim((string)($_POST['contact'] ?? ''));
        if ($company === '' || $category === '' || $username === '' || $email === '') {
            $this->showSupplierSignup('All required fields must be filled.');
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

            // Send email with the generated password
            $mail = new \App\Services\MailService();
            $mail->send($email, 'Your Supplier Account Credentials', "Hello,\n\nYour supplier account has been created.\nUsername: {$username}\nPassword: {$pwd}\n\nPlease sign in and change your password in Settings.\n");

            $this->showSupplierSignup(null, 'Account created. Please check your email for the password.');
        } catch (\Throwable $e) {
            $this->showSupplierSignup('Signup failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }
}
?>

