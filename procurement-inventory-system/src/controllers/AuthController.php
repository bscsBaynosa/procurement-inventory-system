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
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($this->authService->authenticate($username, $password)) {
            // Set user session and redirect based on role
            session_start();
            $_SESSION['user'] = $username;
            $_SESSION['role'] = $this->authService->getUserRole($username);
            header('Location: /dashboard');
            exit;
        } else {
            // Handle login failure
            $error = 'Invalid credentials';
            require_once '../templates/auth/login.php';
        }
    }

    public function logout()
    {
        session_start();
        session_destroy();
        header('Location: /login');
        exit;
    }
}