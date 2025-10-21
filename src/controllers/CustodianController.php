<?php

namespace App\Controllers;

use App\Services\InventoryService;
use App\Services\RequestService;

class CustodianController
{
    protected InventoryService $inventoryService;
    protected RequestService $requestService;

    public function __construct()
    {
        $this->inventoryService = new InventoryService();
        $this->requestService = new RequestService();
    }

    public function dashboard(): void
    {
        $session = $this->ensureAuthenticated('custodian');

        $branchId = $session['branch_id'] ?? null;
        $inventoryStats = $this->inventoryService->getInventoryStats($branchId);
        $pendingRequests = $this->requestService->getPendingRequests($branchId);

        require_once '../templates/dashboard/custodian.php';
    }

    public function createInventoryItem(string $name, string $category, string $status, int $quantity, array $extra = []): bool
    {
        $session = $this->ensureAuthenticated('custodian');

        $payload = array_merge($extra, [
            'name' => trim($name),
            'category' => trim($category),
            'status' => $this->normaliseStatus($status),
            'quantity' => max(0, (int)$quantity),
            'branch_id' => $session['branch_id'] ?? null,
        ]);

        $this->inventoryService->addItem($payload, (int)$session['user_id']);

        return true;
    }

    public function updateInventoryItem(int $itemId, array $data): bool
    {
        $session = $this->ensureAuthenticated('custodian');

        if (isset($data['status'])) {
            $data['status'] = $this->normaliseStatus($data['status']);
        }

        if (isset($data['quantity'])) {
            $data['quantity'] = max(0, (int)$data['quantity']);
        }

        $filtered = array_filter(
            $data,
            static fn($value) => $value !== null && $value !== ''
        );

        if (!$filtered) {
            return false;
        }

        return $this->inventoryService->updateItem($itemId, $filtered, (int)$session['user_id']);
    }

    public function toggleItemStatus(int $itemId, string $status): bool
    {
        $session = $this->ensureAuthenticated('custodian');

        return $this->inventoryService->toggleItemStatus($itemId, $this->normaliseStatus($status), (int)$session['user_id']);
    }

    public function createPurchaseRequest(array $data): array
    {
        $session = $this->ensureAuthenticated('custodian');

        $data['branch_id'] = $data['branch_id'] ?? $session['branch_id'] ?? null;
        $data['requested_by'] = $session['user_id'];

        return $this->requestService->createPurchaseRequest($data, (int)$session['user_id']);
    }

    public function followUpRequest(int $requestId, ?string $notes = null): bool
    {
        $session = $this->ensureAuthenticated('custodian');

        return $this->requestService->followUpRequest($requestId, (int)$session['user_id'], $notes);
    }

    public function generateInventoryReport(string $startDate, string $endDate): array
    {
        $session = $this->ensureAuthenticated('custodian');

        return $this->inventoryService->getReportData($startDate, $endDate, $session['branch_id'] ?? null);
    }

    private function ensureAuthenticated(?string $requiredRole = null): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        if ($requiredRole !== null && ($_SESSION['role'] ?? null) !== $requiredRole) {
            http_response_code(403);
            exit('Forbidden');
        }

        return $_SESSION;
    }

    private function normaliseStatus(string $status): string
    {
        $map = [
            'good' => 'good',
            'serviceable' => 'good',
            'in_stock' => 'good',
            'for repair' => 'for_repair',
            'for_repair' => 'for_repair',
            'repair' => 'for_repair',
            'for replacement' => 'for_replacement',
            'for_replacement' => 'for_replacement',
            'replacement' => 'for_replacement',
            'retired' => 'retired',
        ];

        $key = strtolower(trim($status));

        return $map[$key] ?? 'good';
    }
}