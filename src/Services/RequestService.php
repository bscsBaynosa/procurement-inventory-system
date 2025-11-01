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
		// Best-effort: ensure new columns exist so read paths don't fail on older schemas
		$this->ensurePrColumns();
	}

	public function createPurchaseRequest(array $payload, int $userId): array
	{
		// Ensure archive-related columns exist (idempotent)
		try {
			$this->pdo->exec("ALTER TABLE purchase_requests
				ADD COLUMN IF NOT EXISTS pr_number VARCHAR(32),
				ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE,
				ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ,
				ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL
			");
		} catch (\Throwable $e) { /* ignore */ }
		// Ensure pr_number column and sequence table exist
		try {
			// Add column if missing (no UNIQUE to allow multi-item under same PR number)
			$this->pdo->exec("ALTER TABLE purchase_requests ADD COLUMN IF NOT EXISTS pr_number VARCHAR(32)");
			// Drop legacy unique constraint if it exists to support grouping multiple items under one PR
			$this->pdo->exec("ALTER TABLE purchase_requests DROP CONSTRAINT IF EXISTS purchase_requests_pr_number_key");
		} catch (\Throwable $e) { /* ignore */ }
		try {
			$this->pdo->exec("CREATE TABLE IF NOT EXISTS purchase_requisition_sequences (
				calendar_year INTEGER PRIMARY KEY,
				last_value INTEGER NOT NULL DEFAULT 0,
				updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
			)");
		} catch (\Throwable $e) { /* ignore */ }
		// Use provided PR number (for grouped submissions), or generate a new one
		$prNumber = isset($payload['pr_number']) && is_string($payload['pr_number']) && $payload['pr_number'] !== ''
			? (string)$payload['pr_number']
			: $this->generatePrNumber();

		$stmt = $this->pdo->prepare(
			'INSERT INTO purchase_requests (item_id, branch_id, requested_by, request_type, quantity, unit, justification, status, priority, needed_by, created_by, updated_by, pr_number)
			 VALUES (:item_id, :branch_id, :requested_by, :request_type, :quantity, :unit, :justification, :status, :priority, :needed_by, :created_by, :updated_by, :pr_number)
			 RETURNING request_id, status, pr_number'
		);

		$status = $payload['status'] ?? 'pending';

		// Encrypt justification if configured
		$encJustification = \App\Services\CryptoService::encrypt($payload['justification'] ?? null, 'pr:' . $prNumber);

		$stmt->execute([
			'item_id' => $payload['item_id'] ?? null,
			'branch_id' => $payload['branch_id'] ?? null,
			'requested_by' => $payload['requested_by'] ?? $userId,
			'request_type' => $payload['request_type'] ?? 'purchase_order',
			'quantity' => $payload['quantity'] ?? 1,
			'unit' => $payload['unit'] ?? 'pcs',
			'justification' => $encJustification,
			'status' => $status,
			'priority' => $payload['priority'] ?? 3,
			'needed_by' => $payload['needed_by'] ?? null,
			'created_by' => $userId,
			'updated_by' => $userId,
			'pr_number' => $prNumber,
		]);

		$request = $stmt->fetch();

		$this->recordEvent((int)$request['request_id'], null, $status, $userId, 'Request created');
		$this->recordAudit('purchase_requests', (int)$request['request_id'], 'create', $userId, $payload);

		return $request;
	}

	private function generatePrNumber(): string
	{
		$year = (int)date('Y');
		return $this->generatePrNumberForYear($year);
	}

	/** Generate PR number for a specific calendar year using per-year sequence. */
	private function generatePrNumberForYear(int $year): string
	{
		// Upsert year row and increment counter atomically
		$this->pdo->beginTransaction();
		try {
			$this->pdo->prepare('INSERT INTO purchase_requisition_sequences (calendar_year, last_value) VALUES (:y, 0) ON CONFLICT (calendar_year) DO NOTHING')
				->execute(['y' => $year]);
			$st = $this->pdo->prepare('UPDATE purchase_requisition_sequences SET last_value = last_value + 1, updated_at = NOW() WHERE calendar_year = :y RETURNING last_value');
			$st->execute(['y' => $year]);
			$next = (int)$st->fetchColumn();
			$this->pdo->commit();
		} catch (\Throwable $e) {
			$this->pdo->rollBack();
			$next = 1; // fallback
		}
		// Format: YYYY + 3-digit sequential count (e.g., 2025001)
		return sprintf('%04d%03d', $year, $next);
	}

	/**
	 * Preview the next PR number without incrementing the counter.
	 * Returns formatted as YYYY + 3-digit count (e.g., 2025001).
	 */
	public function getNextPrNumberPreview(): string
	{
		$year = (int)date('Y');
		try {
			$this->pdo->exec("CREATE TABLE IF NOT EXISTS purchase_requisition_sequences (
				calendar_year INTEGER PRIMARY KEY,
				last_value INTEGER NOT NULL DEFAULT 0,
				updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
			)");
			$this->pdo->prepare('INSERT INTO purchase_requisition_sequences (calendar_year, last_value) VALUES (:y, 0) ON CONFLICT (calendar_year) DO NOTHING')
				->execute(['y' => $year]);
			$st = $this->pdo->prepare('SELECT last_value FROM purchase_requisition_sequences WHERE calendar_year = :y');
			$st->execute(['y' => $year]);
			$current = (int)$st->fetchColumn();
			$next = $current + 1;
			return sprintf('%04d%03d', $year, $next);
		} catch (\Throwable $e) {
			return sprintf('%04d%03d', $year, 1);
		}
	}

	public function generateNewPrNumber(): string
	{
		return $this->generatePrNumber();
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
		// Ensure columns exist before selecting them
		$this->ensurePrColumns();
	 $sql = 'SELECT pr.request_id, pr.pr_number, pr.item_id, pr.branch_id, pr.request_type, pr.quantity, pr.unit, pr.status, pr.priority, pr.needed_by, pr.created_at, pr.updated_at, pr.is_archived,
		 i.name AS item_name, b.name AS branch_name,
		 ru.user_id AS requested_by_id, ru.full_name AS requested_by_name, au.full_name AS assigned_to_name
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

	/**
	 * Grouped list of purchase requests by PR Number, with basic aggregates and sorting/filtering.
	 * filters: branch_id, status, include_archived (bool), sort ('branch'|'date'|'status'), order ('asc'|'desc')
	 */
	public function getRequestsGrouped(array $filters = []): array
	{
		// Ensure columns for grouping exist (pr_number, is_archived)
		$this->ensurePrColumns();

		$includeArchived = (bool)($filters['include_archived'] ?? false);
		$sort = (string)($filters['sort'] ?? 'date');
		$order = strtolower((string)($filters['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

		$sortExpr = 'min_created_at';
		if ($sort === 'branch') { $sortExpr = 'branch_name'; }
		if ($sort === 'status') { $sortExpr = 'status_rank'; }

		$sql = "WITH grouped AS (
			SELECT
				pr.pr_number,
				MIN(pr.created_at) AS min_created_at,
				MAX(pr.branch_id) AS branch_id,
				MAX(b.name) AS branch_name,
				MIN(pr.requested_by) AS requested_by_id,
				MAX(ru.full_name) AS requested_by_name,
				BOOL_OR(pr.is_archived) AS is_archived,
				-- Aggregate item summaries
				STRING_AGG(CONCAT(COALESCE(i.name,''),' × ', pr.quantity, ' ', COALESCE(pr.unit,'')), '\n' ORDER BY pr.created_at) AS items_summary,
				-- Status rollup: choose a single representative status per PR number
				-- priority: pending > rejected > approved > in_progress > completed > cancelled
				MAX(CASE pr.status
					WHEN 'pending' THEN 100
					WHEN 'rejected' THEN 90
					WHEN 'approved' THEN 80
					WHEN 'in_progress' THEN 70
					WHEN 'completed' THEN 60
					WHEN 'cancelled' THEN 50
					ELSE 0 END) AS status_rank,
				-- Most recent status string for display
				(SELECT pr2.status FROM purchase_requests pr2 WHERE pr2.pr_number = pr.pr_number ORDER BY pr2.updated_at DESC LIMIT 1) AS status
			FROM purchase_requests pr
			LEFT JOIN inventory_items i ON i.item_id = pr.item_id
			LEFT JOIN branches b ON b.branch_id = pr.branch_id
			LEFT JOIN users ru ON ru.user_id = pr.requested_by
			GROUP BY pr.pr_number
		)
		SELECT * FROM grouped";

		$conditions = [];
		$params = [];
		if (!empty($filters['branch_id'])) { $conditions[] = 'branch_id = :branch_id'; $params['branch_id'] = (int)$filters['branch_id']; }
		if (!empty($filters['status'])) { $conditions[] = 'status = :status'; $params['status'] = (string)$filters['status']; }
		if (!$includeArchived) { $conditions[] = 'is_archived = FALSE'; }
		if ($conditions) { $sql .= ' WHERE ' . implode(' AND ', $conditions); }
		$sql .= ' ORDER BY ' . $sortExpr . ' ' . $order . ', pr_number ASC';

		$st = $this->pdo->prepare($sql);
		$st->execute($params);
		$rows = $st->fetchAll();
		return $rows ?: [];
	}

	/** Get full details for a PR group (by pr_number) including item rows. */
	public function getGroupDetails(string $prNumber): array
	{
		$this->ensurePrColumns();
		$stmt = $this->pdo->prepare(
			"SELECT pr.request_id, pr.pr_number, pr.item_id, i.name AS item_name, pr.quantity, pr.unit, pr.status, pr.created_at,
				pr.branch_id, b.name AS branch_name, pr.requested_by, u.full_name AS requested_by_name
			 FROM purchase_requests pr
			 LEFT JOIN inventory_items i ON i.item_id = pr.item_id
			 LEFT JOIN branches b ON b.branch_id = pr.branch_id
			 LEFT JOIN users u ON u.user_id = pr.requested_by
			 WHERE pr.pr_number = :pr
			 ORDER BY pr.created_at ASC"
		);
		$stmt->execute(['pr' => $prNumber]);
		$rows = $stmt->fetchAll();
		return $rows ?: [];
	}

	/** Update status for all requests under the same PR number. */
	public function updateGroupStatus(string $prNumber, string $status, int $performedBy, ?string $notes = null): bool
	{
		$allowed = ['pending','approved','rejected','in_progress','completed','cancelled'];
		if (!in_array($status, $allowed, true)) { return false; }
		$rows = $this->getGroupDetails($prNumber);
		if (!$rows) { return false; }
		$this->pdo->beginTransaction();
		try {
			$ok = true;
			foreach ($rows as $r) {
				$ok = $ok && $this->updateRequestStatus((int)$r['request_id'], $status, $performedBy, $notes);
			}
			$this->pdo->commit();
			return $ok;
		} catch (\Throwable $e) {
			$this->pdo->rollBack();
			return false;
		}
	}

	/** Archive all requests under a PR number. */
	public function archiveGroup(string $prNumber, int $performedBy, ?string $reason = null): bool
	{
		$this->ensurePrColumns();
		try {
			$st = $this->pdo->prepare("UPDATE purchase_requests SET is_archived = TRUE, archived_at = NOW(), archived_by = :by WHERE pr_number = :pr");
			$ok = $st->execute(['by' => $performedBy, 'pr' => $prNumber]);
			if ($ok) {
				// Record a single audit entry for the group leader (arbitrary pick: newest)
				$rid = (int)$this->pdo->query("SELECT request_id FROM purchase_requests WHERE pr_number = '" . str_replace("'", "''", $prNumber) . "' ORDER BY updated_at DESC LIMIT 1")->fetchColumn();
				if ($rid > 0) {
					$this->recordEvent($rid, null, null, $performedBy, $reason ? ('Archived: ' . $reason) : 'Archived');
					$this->recordAudit('purchase_requests', $rid, 'update', $performedBy, ['archived' => true, 'pr_number' => $prNumber, 'reason' => $reason]);
				}
			}
			return $ok;
		} catch (\Throwable $e) { return false; }
	}

	/** Restore archived PR group. */
	public function restoreGroup(string $prNumber, int $performedBy): bool
	{
		$this->ensurePrColumns();
		try {
			$st = $this->pdo->prepare("UPDATE purchase_requests SET is_archived = FALSE WHERE pr_number = :pr");
			$ok = $st->execute(['pr' => $prNumber]);
			if ($ok) {
				$rid = (int)$this->pdo->query("SELECT request_id FROM purchase_requests WHERE pr_number = '" . str_replace("'", "''", $prNumber) . "' ORDER BY updated_at DESC LIMIT 1")->fetchColumn();
				if ($rid > 0) {
					$this->recordEvent($rid, null, null, $performedBy, 'Restored from archive');
					$this->recordAudit('purchase_requests', $rid, 'update', $performedBy, ['archived' => false, 'pr_number' => $prNumber]);
				}
			}
			return $ok;
		} catch (\Throwable $e) { return false; }
	}

	public function getRequestById(int $requestId): ?array
	{
			$this->ensurePrColumns();
			$stmt = $this->pdo->prepare(
				'SELECT request_id, item_id, branch_id, requested_by, assigned_to, request_type, quantity, unit, justification, status, priority, needed_by, created_at, updated_at, pr_number FROM purchase_requests WHERE request_id = :request_id LIMIT 1'
			);
		$stmt->execute(['request_id' => $requestId]);

		$row = $stmt->fetch();

		if ($row) {
			$row['justification'] = \App\Services\CryptoService::maybeDecrypt($row['justification'] ?? null, 'pr:' . (string)($row['pr_number'] ?? ''));
		}
		return $row ?: null;
	}

	public function updateRequest(int $requestId, array $payload, int $performedBy): bool
	{
		$columns = [];
		$params = ['request_id' => $requestId, 'updated_by' => $performedBy];
		$allowed = ['assigned_to', 'quantity', 'unit', 'justification', 'priority', 'needed_by'];

		foreach ($allowed as $field) {
			if (array_key_exists($field, $payload)) {
				if ($field === 'justification') {
					$enc = \App\Services\CryptoService::encrypt($payload[$field], 'pr:' . (string)$requestId);
					$columns[] = "$field = :$field";
					$params[$field] = $enc;
				} else {
					$columns[] = "$field = :$field";
					$params[$field] = $payload[$field];
				}
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
			// Notify procurement when a request is approved
			if ($status === 'approved') {
				try {
					// Ensure messages table exists
					$this->pdo->exec('CREATE TABLE IF NOT EXISTS messages (
						id BIGSERIAL PRIMARY KEY,
						sender_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
						recipient_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
						subject VARCHAR(255) NOT NULL,
						body TEXT NOT NULL,
						is_read BOOLEAN NOT NULL DEFAULT FALSE,
						created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
					)');
					// Send a message to all procurement roles
					$recipients = $this->pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('procurement','procurement_manager')")->fetchAll();
					$subject = 'Purchase Request #' . (string)$requestId . ' approved';
					$body = 'A purchase request has been approved by Admin and is ready for PO issuance.';
					if ($recipients) {
						$ins = $this->pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
						foreach ($recipients as $row) {
							$ins->execute([
								's' => $performedBy,
								'r' => (int)$row['user_id'],
								'j' => $subject,
								'b' => $body,
							]);
						}
					}
				} catch (\Throwable $ignored) {
					// best-effort notification; ignore errors
				}
			}
		}

		return $result;
	}

	public function followUpRequest(int $requestId, int $performedBy, ?string $notes = null): bool
	{
		$request = $this->getRequestById($requestId);
		if (!$request) {
			return false;
		}

		$message = $notes ?: 'Follow-up submitted by admin assistant';

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

	/** Ensure new columns used by grouped PRs exist. Safe to call often. */
	private function ensurePrColumns(): void
	{
		try {
			$this->pdo->exec("ALTER TABLE purchase_requests
				ADD COLUMN IF NOT EXISTS pr_number VARCHAR(32),
				ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE,
				ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ,
				ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL
			");
			// Drop legacy unique constraint if present to allow multiple rows per PR number
			$this->pdo->exec("ALTER TABLE purchase_requests DROP CONSTRAINT IF EXISTS purchase_requests_pr_number_key");
			// Ensure sequences table exists for PR numbers
			$this->pdo->exec("CREATE TABLE IF NOT EXISTS purchase_requisition_sequences (
				calendar_year INTEGER PRIMARY KEY,
				last_value INTEGER NOT NULL DEFAULT 0,
				updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
			)");
			// Backfill missing PR numbers for legacy rows (assign per-row numbers based on created year)
			$count = (int)$this->pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE pr_number IS NULL")->fetchColumn();
			if ($count > 0) {
				$stmt = $this->pdo->query("SELECT request_id, COALESCE(created_at, NOW()) AS created_at FROM purchase_requests WHERE pr_number IS NULL ORDER BY created_at ASC LIMIT 500");
				$rows = $stmt ? $stmt->fetchAll() : [];
				if ($rows) {
					$upd = $this->pdo->prepare("UPDATE purchase_requests SET pr_number = :pr WHERE request_id = :id");
					foreach ($rows as $r) {
						$year = (int)date('Y', strtotime((string)$r['created_at']));
						$prNum = $this->generatePrNumberForYear($year);
						try { $upd->execute(['pr' => $prNum, 'id' => (int)$r['request_id']]); } catch (\Throwable $ignored) {}
					}
				}
			}
		} catch (\Throwable $e) {
			// swallow; read paths will fail gracefully elsewhere if truly missing, but this keeps prod resilient
		}
	}
}

