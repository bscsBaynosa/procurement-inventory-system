<?php

namespace App\Services;

use App\Database\Connection;
use JsonException;
use PDO;

class RequestService
{
	private PDO $pdo;

	public function __construct(?PDO $pdo = null)
	{
		$this->pdo = $pdo ?? Connection::resolve();
	}

	public function createPurchaseRequest(array $payload, int $userId): array
	{
		$stmt = $this->pdo->prepare(
			'INSERT INTO purchase_requests (item_id, branch_id, requested_by, request_type, quantity, unit, justification, status, priority, needed_by, created_by, updated_by)
			 VALUES (:item_id, :branch_id, :requested_by, :request_type, :quantity, :unit, :justification, :status, :priority, :needed_by, :created_by, :updated_by)
			 RETURNING request_id, status'
		);

		$status = $payload['status'] ?? 'pending';

		$stmt->execute([
			'item_id' => $payload['item_id'] ?? null,
			'branch_id' => $payload['branch_id'] ?? null,
			'requested_by' => $payload['requested_by'] ?? $userId,
			'request_type' => $payload['request_type'] ?? 'purchase_order',
			'quantity' => $payload['quantity'] ?? 1,
			'unit' => $payload['unit'] ?? 'pcs',
			'justification' => $payload['justification'] ?? null,
			'status' => $status,
			'priority' => $payload['priority'] ?? 3,
			'needed_by' => $payload['needed_by'] ?? null,
			'created_by' => $userId,
			'updated_by' => $userId,
		]);

		$request = $stmt->fetch();

		$this->recordEvent((int)$request['request_id'], null, $status, $userId, 'Request created');
		$this->recordAudit('purchase_requests', (int)$request['request_id'], 'create', $userId, $payload);

		return $request;
	}

	public function getPendingRequests(?int $branchId = null): array
	{
		return $this->getAllRequests([
			'status' => 'pending',
			'branch_id' => $branchId,
		]);
	}

	public function getAllRequests(array $filters = []): array
	{
		$sql = 'SELECT pr.request_id, pr.item_id, pr.branch_id, pr.request_type, pr.quantity, pr.unit, pr.status, pr.priority, pr.needed_by, pr.created_at, pr.updated_at,
					   i.name AS item_name, b.name AS branch_name,
					   ru.full_name AS requested_by_name, au.full_name AS assigned_to_name
				FROM purchase_requests pr
				LEFT JOIN inventory_items i ON i.item_id = pr.item_id
				LEFT JOIN branches b ON b.branch_id = pr.branch_id
				LEFT JOIN users ru ON ru.user_id = pr.requested_by
				LEFT JOIN users au ON au.user_id = pr.assigned_to';

		$conditions = [];
		$params = [];

		if (!empty($filters['status'])) {
			$conditions[] = 'pr.status = :status';
			$params['status'] = $filters['status'];
		}

		if (!empty($filters['branch_id'])) {
			$conditions[] = '(pr.branch_id = :branch_id OR pr.branch_id IS NULL)';
			$params['branch_id'] = $filters['branch_id'];
		}

		if ($conditions) {
			$sql .= ' WHERE ' . implode(' AND ', $conditions);
		}

		$sql .= ' ORDER BY pr.created_at DESC';

		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);

		return $stmt->fetchAll();
	}

	public function getRequestById(int $requestId): ?array
	{
		$stmt = $this->pdo->prepare(
			'SELECT request_id, item_id, branch_id, requested_by, assigned_to, request_type, quantity, unit, justification, status, priority, needed_by, created_at, updated_at FROM purchase_requests WHERE request_id = :request_id LIMIT 1'
		);
		$stmt->execute(['request_id' => $requestId]);

		$row = $stmt->fetch();

		return $row ?: null;
	}

	public function updateRequest(int $requestId, array $payload, int $performedBy): bool
	{
		$columns = [];
		$params = ['request_id' => $requestId, 'updated_by' => $performedBy];
		$allowed = ['assigned_to', 'quantity', 'unit', 'justification', 'priority', 'needed_by'];

		foreach ($allowed as $field) {
			if (array_key_exists($field, $payload)) {
				$columns[] = "$field = :$field";
				$params[$field] = $payload[$field];
			}
		}

		if (isset($payload['status'])) {
			return $this->updateRequestStatus($requestId, $payload['status'], $performedBy, $payload['notes'] ?? null);
		}

		if (!$columns) {
			return false;
		}

		$columns[] = 'updated_by = :updated_by';

		$sql = 'UPDATE purchase_requests SET ' . implode(', ', $columns) . ' WHERE request_id = :request_id';
		$stmt = $this->pdo->prepare($sql);
		$result = $stmt->execute($params);

		if ($result) {
			$this->recordAudit('purchase_requests', $requestId, 'update', $performedBy, $payload);
		}

		return $result;
	}

	public function updateRequestStatus(int $requestId, string $status, int $performedBy, ?string $notes = null): bool
	{
		$current = $this->getRequestById($requestId);
		if (!$current) {
			return false;
		}

		$stmt = $this->pdo->prepare(
			'UPDATE purchase_requests SET status = :status, updated_by = :updated_by WHERE request_id = :request_id'
		);

		$result = $stmt->execute([
			'status' => $status,
			'updated_by' => $performedBy,
			'request_id' => $requestId,
		]);

		if ($result) {
			$this->recordEvent($requestId, $current['status'], $status, $performedBy, $notes);
			$this->recordAudit('purchase_requests', $requestId, 'status_change', $performedBy, [
				'old_status' => $current['status'],
				'new_status' => $status,
				'notes' => $notes,
			]);
		}

		return $result;
	}

	public function followUpRequest(int $requestId, int $performedBy, ?string $notes = null): bool
	{
		$request = $this->getRequestById($requestId);
		if (!$request) {
			return false;
		}

		$message = $notes ?: 'Follow-up submitted by custodian';

		$this->recordAudit('purchase_requests', $requestId, 'update', $performedBy, ['follow_up' => $message]);

		return $this->recordEvent($requestId, $request['status'], $request['status'], $performedBy, $message);
	}

	public function getRequestHistory(int $requestId): array
	{
		$stmt = $this->pdo->prepare(
			'SELECT event_id, old_status, new_status, notes, performed_by, performed_at FROM purchase_request_events WHERE request_id = :request_id ORDER BY performed_at DESC'
		);

		$stmt->execute(['request_id' => $requestId]);

		return $stmt->fetchAll();
	}

	private function recordEvent(int $requestId, ?string $oldStatus, ?string $newStatus, int $performedBy, ?string $notes): bool
	{
		$stmt = $this->pdo->prepare(
			'INSERT INTO purchase_request_events (request_id, old_status, new_status, notes, performed_by) VALUES (:request_id, :old_status, :new_status, :notes, :performed_by)'
		);

		return $stmt->execute([
			'request_id' => $requestId,
			'old_status' => $oldStatus,
			'new_status' => $newStatus,
			'notes' => $notes,
			'performed_by' => $performedBy,
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

