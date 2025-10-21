<?php

namespace App\Controllers;

use App\Services\RequestService;

class ProcurementController
{
    protected RequestService $requestService;

    public function __construct()
    {
        $this->requestService = new RequestService();
    }

    public function index(): void
    {
        $session = $this->ensureAuthenticated('procurement_manager');

        $requests = $this->requestService->getAllRequests([
            'branch_id' => $session['branch_id'] ?? null,
        ]);

        require_once '../templates/dashboard/manager.php';
    }

    public function viewRequest(int $requestId): void
    {
        $this->ensureAuthenticated('procurement_manager');

        $request = $this->requestService->getRequestById($requestId);
        $history = $this->requestService->getRequestHistory($requestId);

        require_once '../templates/requests/show.php';
    }

    public function updateRequest(int $requestId): void
    {
        $session = $this->ensureAuthenticated('procurement_manager');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /manager/requests');
            return;
        }

        $data = [
            'assigned_to' => $_POST['assigned_to'] ?? null,
            'quantity' => isset($_POST['quantity']) ? (int)$_POST['quantity'] : null,
            'unit' => $_POST['unit'] ?? null,
            'justification' => $_POST['justification'] ?? null,
            'priority' => isset($_POST['priority']) ? (int)$_POST['priority'] : null,
            'needed_by' => $_POST['needed_by'] ?? null,
        ];

        if (!empty($_POST['status'])) {
            $data['status'] = $_POST['status'];
        }

        $this->requestService->updateRequest($requestId, $data, (int)$session['user_id']);

        header('Location: /manager/requests');
    }

    public function updateStatus(int $requestId): void
    {
        $session = $this->ensureAuthenticated('procurement_manager');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /manager/requests');
            return;
        }

        $status = $_POST['status'] ?? 'pending';
        $notes = $_POST['notes'] ?? null;

        $this->requestService->updateRequestStatus($requestId, $status, (int)$session['user_id'], $notes);

        header('Location: /manager/requests');
    }

    public function followUpRequest(int $requestId): void
    {
        $session = $this->ensureAuthenticated('procurement_manager');

        $notes = $_POST['notes'] ?? 'Follow-up logged by procurement manager';
        $this->requestService->followUpRequest($requestId, (int)$session['user_id'], $notes);

        header('Location: /manager/requests');
    }

    private function ensureAuthenticated(?string $role = null): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        if ($role !== null && ($_SESSION['role'] ?? null) !== $role) {
            http_response_code(403);
            exit('Forbidden');
        }

        return $_SESSION;
    }
}