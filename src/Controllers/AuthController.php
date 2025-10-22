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

    public function showLanding(): void
    {
        $this->render('auth/landing.php');
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

        if ($this->auth()->attempt($username, $password, $role, $ip, $ua)) {
            header('Location: /dashboard');
            return;
        }

        $this->showLoginForm('Invalid credentials or role.');
    }

    public function logout(): void
    {
    $this->auth()->logout();
        header('Location: /login');
    }
}
?>

