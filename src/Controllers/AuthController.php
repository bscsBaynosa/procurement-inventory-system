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
        $selectedRole = isset($_GET['role']) ? (string)$_GET['role'] : null;
        $this->render('auth/login.php', [
            'error' => $error,
            'selectedRole' => $selectedRole,
        ]);
    }

    public function login(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? '');

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($username === '' || $password === '' || $role === '') {
            $this->showLoginForm('All fields are required.');
            return;
        }

        try {
            if ($this->auth()->attempt($username, $password, $role, $ip, $ua)) {
                header('Location: /dashboard');
                return;
            }
            $from = (string)($_POST['from'] ?? '');
            $errorMsg = 'Invalid credentials or role.';
            if ($from === 'landing') {
                $this->showLanding($errorMsg);
                return;
            }
            $this->showLoginForm($errorMsg);
        } catch (\Throwable $e) {
            // Most common cause: database not initialized or not reachable
            error_log('[AuthController@login] ' . $e->getMessage());
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $setupUrl = $scheme . '://' . $host . '/setup?token=YOUR_TOKEN';
            $hint = 'Sign-in is temporarily unavailable. Please run setup to initialize the database: ' . $setupUrl;
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
        header('Location: /login');
    }
}
?>

