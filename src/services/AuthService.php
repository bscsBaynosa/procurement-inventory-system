<?php

namespace App\Services;

use App\Database\Connection;
use JsonException;
use PDO;

class AuthService
{
    private PDO $pdo;

    /**
     * Cached user details after a successful authentication attempt.
     *
     * @var array<string, mixed>|null
     */
    private ?array $authenticatedUser = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::resolve();
    }

    /**
     * Attempt to authenticate a user by username and password.
     * The password column is expected to contain a password_hash() value.
     */
    public function authenticate(string $username, string $password, ?string $expectedRole = null, array $context = []): bool
    {
        $this->authenticatedUser = null;

        $stmt = $this->pdo->prepare(
            'SELECT user_id, username, password_hash, role, is_active, branch_id FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);

        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            return false;
        }

        if (!(bool)$user['is_active']) {
            return false;
        }

        if ($expectedRole !== null && $user['role'] !== $expectedRole) {
            return false;
        }

        $this->authenticatedUser = $user;

        $ipAddress = $context['ip_address'] ?? ($context['REMOTE_ADDR'] ?? null);
        $userAgent = $context['user_agent'] ?? ($context['HTTP_USER_AGENT'] ?? null);

        $this->recordLogin((int)$user['user_id'], $ipAddress, $userAgent);

        return true;
    }

    /**
     * Return the user details from the most recent successful authentication.
     *
     * @return array<string, mixed>|null
     */
    public function getAuthenticatedUser(): ?array
    {
        return $this->authenticatedUser;
    }

    public function getUserRole(string $username): ?string
    {
        $stmt = $this->pdo->prepare('SELECT role FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        return $row['role'] ?? null;
    }

    public function findUserById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, username, full_name, email, role, branch_id, is_active FROM users WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function createUser(array $payload, int $performedBy): array
    {
        $hash = password_hash($payload['password'], PASSWORD_DEFAULT);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, role, branch_id, is_active, created_by, updated_by) VALUES (:username, :password_hash, :full_name, :email, :role, :branch_id, :is_active, :created_by, :updated_by) RETURNING user_id, username, role'
        );

        $stmt->execute([
            'username' => $payload['username'],
            'password_hash' => $hash,
            'full_name' => $payload['full_name'],
            'email' => $payload['email'] ?? null,
            'role' => $payload['role'],
            'branch_id' => $payload['branch_id'] ?? null,
            'is_active' => $payload['is_active'] ?? true,
            'created_by' => $performedBy,
            'updated_by' => $performedBy,
        ]);

        $user = $stmt->fetch();

        $this->recordAudit('users', (int)$user['user_id'], 'create', $performedBy, [
            'username' => $user['username'],
            'role' => $user['role'],
        ]);

        return $user;
    }

    public function recordLogout(int $userId, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_activity (user_id, action, ip_address, user_agent) VALUES (:user_id, :action, :ip_address, :user_agent)'
        );

        $stmt->execute([
            'user_id' => $userId,
            'action' => 'logout',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    private function recordLogin(int $userId, ?string $ipAddress, ?string $userAgent): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = :ip_address WHERE user_id = :user_id')
            ->execute([
                'ip_address' => $ipAddress,
                'user_id' => $userId,
            ]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_activity (user_id, action, ip_address, user_agent) VALUES (:user_id, :action, :ip_address, :user_agent)'
        );

        $stmt->execute([
            'user_id' => $userId,
            'action' => 'login',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    private function recordAudit(string $tableName, int $recordId, string $action, int $performedBy, array $payload = []): void
    {
        $jsonPayload = null;
        if (!empty($payload)) {
            try {
                $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $jsonPayload = json_encode([
                    'serialization_error' => $exception->getMessage(),
                ]);
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (table_name, record_id, action, payload, performed_by) VALUES (:table_name, :record_id, :action, :payload, :performed_by)'
        );

        $stmt->execute([
            'table_name' => $tableName,
            'record_id' => $recordId,
            'action' => $action,
            'payload' => $jsonPayload,
            'performed_by' => $performedBy,
        ]);
    }
}
