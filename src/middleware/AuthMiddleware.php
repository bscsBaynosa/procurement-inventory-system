<?php

namespace App\Middleware;

use App\Models\User;
use App\Services\AuthService;

class AuthMiddleware
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function handle($request, $next)
    {
        if (!$this->authService->isAuthenticated()) {
            header('Location: /login.php');
            exit();
        }

        return $next($request);
    }
}