<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\MailService;
use App\Database\Connection;

class AuthController extends BaseController
{
    private ?AuthService $auth = null;
    private int $otpExpirySeconds = 300;
    private int $otpResendCooldown = 45;
    private int $otpMaxAttempts = 5;
    private int $otpMaxResends = 5;

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

    public function showLanding(
        ?string $error = null,
        ?string $signupError = null,
        ?string $signupSuccess = null,
        ?string $mode = null,
        array $extras = []
    ): void {
        // Provide categories for supplier sign-up select
        $categories = require __DIR__ . '/../config/categories.php';
        $payload = [
            'error' => $error,
            'signup_error' => $signupError,
            'signup_success' => $signupSuccess,
            'mode' => $mode,
            'categories' => $categories,
        ];
        if (!empty($extras)) {
            $payload = array_merge($payload, $extras);
        }
        $this->render('auth/landing.php', $payload);
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
            // If the core users table doesn't exist yet, auto-redirect to setup which can bootstrap without a token
            try {
                $pdo = Connection::resolve();
                $hasUsers = (bool)$pdo->query("SELECT to_regclass('public.users')")->fetchColumn();
                if (!$hasUsers) {
                    header('Location: /setup');
                    return;
                }
            } catch (\Throwable $ignored) {
                // Fall through to guidance page below
            }
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
        $extras = [
            'forgot_success' => $msg,
            'forgot_error' => $err,
        ];
        if (!empty($_SESSION['login_otp'])) {
            $extras['identifier'] = $_SESSION['login_otp']['identifier'] ?? '';
            $extras['otp'] = $this->otpViewContext($_SESSION['login_otp']);
        }
        $this->showLanding(null, null, null, 'forgot', $extras);
    }

    /**
     * Forgot password (POST): issue OTP via email and display verification modal.
     */
    public function requestOtp(): void
    {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        if ($identifier === '') {
            $this->showLanding(null, null, null, 'forgot', [
                'forgot_error' => 'Please enter your username or email.',
            ]);
            return;
        }

        try {
            $pdo = Connection::resolve();
            $stmt = $pdo->prepare('SELECT user_id, username, email, is_active FROM users WHERE LOWER(username)=LOWER(:id) OR LOWER(email)=LOWER(:id) LIMIT 1');
            $stmt->execute(['id' => $identifier]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user) {
                $this->showLanding(null, null, null, 'forgot', [
                    'forgot_error' => 'We could not find an account with that username or email.',
                    'identifier' => $identifier,
                ]);
                return;
            }
            if (empty($user['email'])) {
                $this->showLanding(null, null, null, 'forgot', [
                    'forgot_error' => 'This account does not have an email address on file. Please contact the administrator.',
                    'identifier' => $identifier,
                ]);
                return;
            }
            if (empty($user['is_active'])) {
                $this->showLanding(null, null, null, 'forgot', [
                    'forgot_error' => 'This account is disabled. Please contact the administrator.',
                    'identifier' => $identifier,
                ]);
                return;
            }

            $context = $this->issueOtp($user, $identifier, false);
            $this->showLanding(null, null, null, 'forgot', [
                'identifier' => $identifier,
                'forgot_success' => $context['sent'] ? 'We sent a one-time code to your email.' : null,
                'otp' => $context,
                'otp_error' => $context['sent'] ? null : 'We could not send the email. Please contact the administrator.',
            ]);
        } catch (\Throwable $e) {
            error_log('[AuthController@requestOtp] ' . $e->getMessage());
            $this->showLanding(null, null, null, 'forgot', [
                'forgot_error' => 'Could not process your request. Please try again.',
                'identifier' => $identifier,
            ]);
        }
    }

    /**
     * Verify the OTP and sign the user in.
     */
    public function verifyOtp(): void
    {
        $codeRaw = trim((string)($_POST['otp_code'] ?? ''));
        $code = preg_replace('/\D+/', '', $codeRaw);
        if ($code === '') {
            $this->showLanding(null, null, null, 'forgot', [
                'otp_error' => 'Please enter the code that was sent to your email.',
                'otp' => isset($_SESSION['login_otp']) ? $this->otpViewContext($_SESSION['login_otp']) : null,
                'identifier' => $_SESSION['login_otp']['identifier'] ?? null,
            ]);
            return;
        }

        $session = $_SESSION['login_otp'] ?? null;
        if (!$session || empty($session['otp_id']) || empty($session['user_id'])) {
            $this->showLanding(null, null, null, 'forgot', [
                'forgot_error' => 'Your verification session has expired. Please request a new code.',
            ]);
            return;
        }

        try {
            $pdo = Connection::resolve();
            $this->ensureOtpTable();

            $stmt = $pdo->prepare('SELECT id, user_id, code_hash, expires_at, attempts, max_attempts, consumed_at FROM login_otps WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmt->execute(['id' => (int)$session['otp_id'], 'uid' => (int)$session['user_id']]);
            $otpRow = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$otpRow) {
                unset($_SESSION['login_otp']);
                $this->showLanding(null, null, null, 'forgot', [
                    'forgot_error' => 'This code is no longer valid. Please request a new one.',
                ]);
                return;
            }

            $expiresTs = strtotime((string)$otpRow['expires_at']);
            if ($expiresTs !== false) {
                $_SESSION['login_otp']['expires_at_ts'] = $expiresTs;
                $_SESSION['login_otp']['expires_at'] = date('Y-m-d H:i:sP', $expiresTs);
            }

            if (!empty($otpRow['consumed_at'])) {
                unset($_SESSION['login_otp']);
                $this->showLanding(null, null, null, 'forgot', [
                    'forgot_error' => 'This code has already been used. Please request a new one.',
                ]);
                return;
            }

            if ($expiresTs !== false && $expiresTs < time()) {
                $pdo->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE id = :id')
                    ->execute(['id' => (int)$otpRow['id']]);
                unset($_SESSION['login_otp']);
                $this->showLanding(null, null, null, 'forgot', [
                    'forgot_error' => 'Your code has expired. Please request a new one.',
                ]);
                return;
            }

            $attempts = (int)$otpRow['attempts'];
            $maxAttempts = (int)$otpRow['max_attempts'] ?: $this->otpMaxAttempts;
            if ($attempts >= $maxAttempts) {
                unset($_SESSION['login_otp']);
                $this->showLanding(null, null, null, 'forgot', [
                    'forgot_error' => 'Too many incorrect attempts. Please request a new code.',
                ]);
                return;
            }

            $hash = hash('sha256', $code);
            if (!hash_equals((string)$otpRow['code_hash'], $hash)) {
                $pdo->prepare('UPDATE login_otps SET attempts = attempts + 1 WHERE id = :id')
                    ->execute(['id' => (int)$otpRow['id']]);
                $remaining = max(0, $maxAttempts - ($attempts + 1));
                $context = $this->otpViewContext($_SESSION['login_otp'], [
                    'message' => $remaining > 0
                        ? 'Incorrect code. You have ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' left.'
                        : 'Incorrect code. This code is now locked; request a new one.',
                ]);
                if ($remaining <= 0) {
                    unset($_SESSION['login_otp']);
                    $context['resend_disabled'] = true;
                }
                $this->showLanding(null, null, null, 'forgot', [
                    'otp_error' => $remaining > 0
                        ? 'Incorrect code. You have ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' left.'
                        : 'Incorrect code. Please request a new code.',
                    'otp' => $context,
                    'identifier' => $session['identifier'] ?? null,
                ]);
                return;
            }

            $pdo->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE id = :id')
                ->execute(['id' => (int)$otpRow['id']]);

            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if ($this->auth()->loginById((int)$otpRow['user_id'], $ip, $ua, 'login_otp')) {
                unset($_SESSION['login_otp']);
                header('Location: /dashboard');
                return;
            }

            unset($_SESSION['login_otp']);
            $this->showLanding('Could not sign you in with the provided code. Please try again.', null, null, 'signin');
        } catch (\Throwable $e) {
            error_log('[AuthController@verifyOtp] ' . $e->getMessage());
            $context = $session ? $this->otpViewContext($session) : null;
            $this->showLanding(null, null, null, 'forgot', [
                'otp_error' => 'Something went wrong while verifying your code. Please try again.',
                'otp' => $context,
                'identifier' => $session['identifier'] ?? null,
            ]);
        }
    }

    /**
     * Resend the OTP with cooldowns and attempt limits.
     */
    public function resendOtp(): void
    {
        $session = $_SESSION['login_otp'] ?? null;
        if (!$session || empty($session['user_id'])) {
            $this->showLanding(null, null, null, 'forgot', [
                'forgot_error' => 'Your verification session has expired. Please enter your username or email again.',
            ]);
            return;
        }

        $now = time();
        $identifier = (string)($session['identifier'] ?? '');

        if (!empty($session['last_sent_at'])) {
            $elapsed = $now - (int)$session['last_sent_at'];
            if ($elapsed < $this->otpResendCooldown) {
                $wait = $this->otpResendCooldown - $elapsed;
                $context = $this->otpViewContext($session, [
                    'resend_wait' => $wait,
                    'resend_disabled' => true,
                    'message' => 'Please wait before requesting a new code.',
                ]);
                $this->showLanding(null, null, null, 'forgot', [
                    'otp_error' => 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before resending.',
                    'otp' => $context,
                    'identifier' => $identifier,
                ]);
                return;
            }
        }

        if (!empty($session['resend_count']) && (int)$session['resend_count'] >= $this->otpMaxResends) {
            $context = $this->otpViewContext($session, [
                'resend_disabled' => true,
                'resend_limit_reached' => true,
                'message' => 'You have reached the resend limit. Try again later.',
            ]);
            $this->showLanding(null, null, null, 'forgot', [
                'otp_error' => 'You have reached the maximum number of resend attempts. Please try again later.',
                'otp' => $context,
                'identifier' => $identifier,
            ]);
            return;
        }

        try {
            $pdo = Connection::resolve();
            $stmt = $pdo->prepare('SELECT user_id, username, email, is_active FROM users WHERE user_id = :id LIMIT 1');
            $stmt->execute(['id' => (int)$session['user_id']]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$user || empty($user['is_active'])) {
                unset($_SESSION['login_otp']);
                $this->showLanding('This account is not available. Please contact the administrator.', null, null, 'signin');
                return;
            }

            $context = $this->issueOtp($user, $identifier, true);
            $this->showLanding(null, null, null, 'forgot', [
                'otp' => $context,
                'identifier' => $identifier,
                'forgot_success' => $context['sent'] ? 'We sent you a new code.' : null,
                'otp_error' => $context['sent'] ? null : 'We could not send the email. Please contact the administrator.',
            ]);
        } catch (\Throwable $e) {
            error_log('[AuthController@resendOtp] ' . $e->getMessage());
            $context = $this->otpViewContext($session, [
                'message' => 'Could not resend the code. Please try again.',
            ]);
            $this->showLanding(null, null, null, 'forgot', [
                'otp_error' => 'Could not resend the code. Please try again.',
                'otp' => $context,
                'identifier' => $identifier,
            ]);
        }
    }

    private function ensureOtpTable(): void
    {
        $pdo = Connection::resolve();
        $pdo->exec('CREATE TABLE IF NOT EXISTS login_otps (
            id BIGSERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            code_hash CHAR(64) NOT NULL,
            sent_to VARCHAR(255) NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL,
            attempts SMALLINT NOT NULL DEFAULT 0,
            max_attempts SMALLINT NOT NULL DEFAULT 5,
            consumed_at TIMESTAMPTZ NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_otps_user ON login_otps(user_id)');
    }

    private function otpViewContext(array $session, array $overrides = []): array
    {
        $now = time();
        $base = [
            'show' => true,
            'email' => (string)($session['email'] ?? ''),
            'identifier' => (string)($session['identifier'] ?? ''),
            'expires_at' => $session['expires_at'] ?? null,
            'expires_at_ts' => $session['expires_at_ts'] ?? null,
            'resend_wait' => 0,
            'resend_disabled' => false,
        ];
        $base['sent'] = array_key_exists('sent', $overrides) ? (bool)$overrides['sent'] : true;
        if (!empty($session['last_sent_at'])) {
            $remaining = $this->otpResendCooldown - ($now - (int)$session['last_sent_at']);
            if ($remaining > 0) {
                $base['resend_wait'] = $remaining;
                $base['resend_disabled'] = true;
            }
        }
        if (!empty($session['resend_count']) && (int)$session['resend_count'] >= $this->otpMaxResends) {
            $base['resend_disabled'] = true;
            $base['resend_limit_reached'] = true;
        }
        if (!empty($session['expires_at_ts'])) {
            $base['expires_in'] = max(0, (int)$session['expires_at_ts'] - $now);
            $base['expires_at'] = date('Y-m-d H:i:sP', (int)$session['expires_at_ts']);
        } else {
            $base['expires_in'] = $this->otpExpirySeconds;
        }
        return array_merge($base, $overrides);
    }

    private function issueOtp(array $user, string $identifier, bool $resend): array
    {
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') {
            throw new \RuntimeException('No email is associated with this user.');
        }

        $this->ensureOtpTable();
        $pdo = Connection::resolve();

        $pdo->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE user_id = :u AND consumed_at IS NULL')
            ->execute(['u' => (int)$user['user_id']]);

        $code = (string)random_int(100000, 999999);
        $hash = hash('sha256', $code);
        $expiresTs = time() + $this->otpExpirySeconds;
        $expiresIso = date('Y-m-d H:i:sP', $expiresTs);

        $insert = $pdo->prepare('INSERT INTO login_otps (user_id, code_hash, sent_to, expires_at, max_attempts) VALUES (:u, :h, :to, :exp, :max)');
        $insert->execute([
            'u' => (int)$user['user_id'],
            'h' => $hash,
            'to' => $email,
            'exp' => $expiresIso,
            'max' => $this->otpMaxAttempts,
        ]);
        $otpId = (int)$pdo->lastInsertId();

        $minutes = max(1, (int)ceil($this->otpExpirySeconds / 60));
        $body = "Hello {$user['username']},\n\nUse this one-time code to sign in:\n\n{$code}\n\nThe code expires in {$minutes} minute" . ($minutes === 1 ? '' : 's') . ".\nIf you did not request this, you can ignore this email.\n";
        $mail = new MailService();
        $sent = $mail->send($email, 'Your one-time sign-in code', $body);

        $resendCount = $resend ? (int)(($_SESSION['login_otp']['resend_count'] ?? 0) + 1) : 0;

        $_SESSION['login_otp'] = [
            'otp_id' => $otpId,
            'user_id' => (int)$user['user_id'],
            'username' => (string)$user['username'],
            'email' => $email,
            'identifier' => $identifier,
            'expires_at_ts' => $expiresTs,
            'expires_at' => $expiresIso,
            'last_sent_at' => time(),
            'resend_count' => $resendCount,
        ];
        if (!$sent) {
            $_SESSION['login_otp']['last_sent_at'] = time() - $this->otpResendCooldown;
        }

        $context = $this->otpViewContext($_SESSION['login_otp'], [
            'sent' => $sent,
            'message' => $sent
                ? 'We sent a one-time code to your email.'
                : 'We could not send the email. Please contact the administrator.',
        ]);
        $context['otp_id'] = $otpId;

        return $context;
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

