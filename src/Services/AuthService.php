<?php

namespace App\Services;

use App\Database\Connection;
use PDO;

class AuthService
{
	private PDO $pdo;

	public function __construct(?PDO $pdo = null)
	{
		$this->pdo = $pdo ?? Connection::resolve();
	}

	/**
	 * Attempt to authenticate a user and start a session.
	 */
	public function attempt(string $username, string $password, string $role, string $ip = '', string $userAgent = ''): bool
	{
		$stmt = $this->pdo->prepare('SELECT user_id, username, password_hash, role, branch_id, is_active FROM users WHERE username = :u LIMIT 1');
		$stmt->execute(['u' => $username]);
		$user = $stmt->fetch();

		if (!$user || !$user['is_active']) {
			return false;
		}

		// Strict role check: selected role must match the user's actual role
		if ($user['role'] !== $role) {
			return false;
		}

		if (!password_verify($password, (string)$user['password_hash'])) {
			$this->incrementFailedAttempts((int)$user['user_id']);
			return false;
		}

		// Successful login: reset failed attempts, set last login, record activity
		$this->pdo->prepare('UPDATE users SET failed_login_attempts = 0, last_login_at = NOW(), last_login_ip = :ip WHERE user_id = :id')
			->execute(['ip' => $ip ?: null, 'id' => $user['user_id']]);

		$this->recordAuthActivity((int)$user['user_id'], 'login', $ip, $userAgent);

		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		$_SESSION['user_id'] = (int)$user['user_id'];
		$_SESSION['username'] = (string)$user['username'];
		$_SESSION['role'] = (string)$user['role'];
		$_SESSION['branch_id'] = $user['branch_id'] !== null ? (int)$user['branch_id'] : null;

		return true;
	}

	public function logout(): void
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
		if ($userId) {
			$this->recordAuthActivity($userId, 'logout', $ip, $ua);
		}
		$_SESSION = [];
		if (ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'] ?? false, $params['httponly'] ?? true);
		}
		@session_destroy();
	}

	public function isAuthenticated(): bool
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		return !empty($_SESSION['user_id']);
	}

	public function user(): ?array
	{
		if (!$this->isAuthenticated()) {
			return null;
		}
		return [
			'user_id' => (int)$_SESSION['user_id'],
			'username' => (string)$_SESSION['username'],
			'role' => (string)$_SESSION['role'],
			'branch_id' => $_SESSION['branch_id'] !== null ? (int)$_SESSION['branch_id'] : null,
		];
	}

	private function recordAuthActivity(int $userId, string $action, string $ip, string $ua): void
	{
		$stmt = $this->pdo->prepare('INSERT INTO auth_activity (user_id, action, ip_address, user_agent) VALUES (:u, :a, :ip, :ua)');
		$stmt->execute(['u' => $userId, 'a' => $action, 'ip' => $ip ?: null, 'ua' => $ua ?: null]);
	}

	private function incrementFailedAttempts(int $userId): void
	{
		$this->pdo->prepare('UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE user_id = :id')
			->execute(['id' => $userId]);
	}
}



