<?php

namespace App\Controllers;

use App\Models\User;
use App\Services\AuthService;

class AuthController
{
    protected $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function showLoginForm()
    {
        require_once '../templates/auth/login.php';
    }

    public function login()
    {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $requestedRole = $_POST['role'] ?? null;

        $context = [
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

    if ($this->authService->authenticate($username, $password, $requestedRole, $context)) {
            $user = $this->authService->getAuthenticatedUser();

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['user'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['branch_id'] = $user['branch_id'] ?? null;

            header('Location: /dashboard');
            exit;
        }

        $error = 'Invalid credentials';
        require_once '../templates/auth/login.php';
    }

    public function logout()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        if ($userId !== null) {
            $this->authService->recordLogout((int)$userId, $ip, $agent);
        }

        session_destroy();
        header('Location: /login');
        exit;
    }
}