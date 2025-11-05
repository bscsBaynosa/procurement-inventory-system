<?php

namespace App\Services;

use App\Database\Connection;
use PDO;

class RequestService
{
	/** @var PDO */
	private $pdo;

	/**
	 * @param PDO|null $pdo
	 */
	public function __construct($pdo = null)
	{
		$this->pdo = $pdo ? $pdo : Connection::resolve();
		// Best-effort: ensure new columns exist so read paths don't fail on older schemas
		$this->ensurePrColumns();
		// Ensure enum values used by canvassing gate exist (safe to call often)
		$this->ensureRequestStatusEnum();
		// Ensure revision columns exist
		$this->ensureRevisionColumns();
	}

	/**
	 * @param array $payload
	 * @param int $userId
	 * @return array
	 */
	public function createPurchaseRequest(array $payload, $userId)
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

		$status = isset($payload['status']) ? $payload['status'] : 'pending';

		// Encrypt justification if configured
		$encJustification = \App\Services\CryptoService::encrypt(isset($payload['justification']) ? $payload['justification'] : null, 'pr:' . $prNumber);

		$stmt->execute(array(
			'item_id' => isset($payload['item_id']) ? $payload['item_id'] : null,
			'branch_id' => isset($payload['branch_id']) ? $payload['branch_id'] : null,
			'requested_by' => isset($payload['requested_by']) ? $payload['requested_by'] : $userId,
			'request_type' => isset($payload['request_type']) ? $payload['request_type'] : 'purchase_order',
			'quantity' => isset($payload['quantity']) ? $payload['quantity'] : 1,
			'unit' => isset($payload['unit']) ? $payload['unit'] : 'pcs',
			'justification' => $encJustification,
			'status' => $status,
			'priority' => isset($payload['priority']) ? $payload['priority'] : 3,
			'needed_by' => isset($payload['needed_by']) ? $payload['needed_by'] : null,
			'created_by' => $userId,
			'updated_by' => $userId,
			'pr_number' => $prNumber,
		));

		$request = $stmt->fetch();

		$this->recordEvent((int)$request['request_id'], null, $status, $userId, 'Request created');
		$this->recordAudit('purchase_requests', (int)$request['request_id'], 'create', $userId, $payload);

		return $request;
	}

	/** @return string */
	private function generatePrNumber()
	{
		$year = (int)date('Y');
		return $this->generatePrNumberForYear($year);
	}

	/** Generate PR number for a specific calendar year using per-year sequence. */
	/**
	 * @param int $year
	 * @return string
	 */
	private function generatePrNumberForYear($year)
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
	/** @return string */
	public function getNextPrNumberPreview()
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

	/** @return string */
	public function generateNewPrNumber()
	{
		return $this->generatePrNumber();
	}

	/**
	 * @param int|null $branchId
	 * @return array
	 */
	public function getPendingRequests($branchId = null)
	{
		return $this->getAllRequests(array(
			'status' => 'pending',
			'branch_id' => $branchId,
		));
	}

	/**
	 * @param array $filters
	 * @return array
	 */
	public function getAllRequests($filters = array())
	{
		// Ensure columns exist before selecting them
		$this->ensurePrColumns();
		$this->ensureRevisionColumns();
		$this->ensureRequestStatusEnum();
	 $sql = 'SELECT pr.request_id, pr.pr_number, pr.item_id, pr.branch_id, pr.request_type, pr.quantity, pr.unit, pr.status, pr.priority, pr.needed_by, pr.created_at, pr.updated_at, pr.is_archived,
		 i.name AS item_name, b.name AS branch_name,
		 ru.user_id AS requested_by_id, ru.full_name AS requested_by_name, au.full_name AS assigned_to_name
			FROM purchase_requests pr
			LEFT JOIN inventory_items i ON i.item_id = pr.item_id
			LEFT JOIN branches b ON b.branch_id = pr.branch_id
			LEFT JOIN users ru ON ru.user_id = pr.requested_by
			LEFT JOIN users au ON au.user_id = pr.assigned_to';

		$conditions = array();
		$params = array();

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
	 * Check if there's an existing active (not-archived) PR for the same item and branch
	 * in a status that implies it's still in progress (prevents duplicates).
	 */
	/**
	 * @param int $itemId
	 * @param int $branchId
	 * @return bool
	 */
	public function hasActiveRequestForItemBranch($itemId, $branchId)
	{
		try {
			$this->ensurePrColumns();
			// If branchId is not set (>0), treat as ANY branch to prevent duplicates globally for this item
			$bid = $branchId > 0 ? $branchId : null;
			$sql = "SELECT 1 FROM purchase_requests WHERE item_id = :iid AND COALESCE(is_archived, FALSE) = FALSE\n\t\t\t\t  AND status IN ('pending','approved','canvassing_submitted','canvassing_approved','in_progress','po_submitted','po_admin_approved')";
			$params = ['iid' => $itemId];
			if ($bid !== null) { $sql .= " AND branch_id = :bid"; $params['bid'] = $bid; }
			$sql .= " LIMIT 1";
			$st = $this->pdo->prepare($sql);
			$st->execute($params);
			return (bool)$st->fetchColumn();
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * Return DISTINCT item_ids that currently have an active (not-archived) PR in-progress for a branch.
	 * Active statuses: pending, approved, canvassing_submitted, canvassing_approved, in_progress
	 */
	/**
	 * @param int|null $branchId
	 * @return array
	 */
	public function getActiveRequestItemIdsForBranch($branchId = null)
	{
		try {
			$this->ensurePrColumns();
			$sql = "SELECT DISTINCT item_id FROM purchase_requests\n\t\t\t\tWHERE COALESCE(is_archived, FALSE) = FALSE\n\t\t\t\t  AND status IN ('pending','approved','canvassing_submitted','canvassing_approved','in_progress','po_submitted','po_admin_approved')\n\t\t\t\t  AND item_id IS NOT NULL";
			$params = [];
			if ($branchId !== null && $branchId > 0) {
				$sql .= " AND branch_id = :bid";
				$params['bid'] = $branchId;
			}
			$st = $this->pdo->prepare($sql);
			$st->execute($params);
			$ids = array();
			$rows = $st->fetchAll() ?: array();
			foreach ($rows as $r) { $ids[] = (int)$r['item_id']; }
			return $ids;
		} catch (\Throwable $e) {
			return array();
		}
	}

	/**
	 * Grouped list of purchase requests by PR Number, with basic aggregates and sorting/filtering.
	 * filters: branch_id, status, include_archived (bool), sort ('branch'|'date'|'status'), order ('asc'|'desc')
	 */
	/**
	 * @param array $filters
			$sql = "SELECT DISTINCT item_id FROM purchase_requests\n\t\t\t\tWHERE COALESCE(is_archived, FALSE) = FALSE\n\t\t\t\t  AND status IN ('pending','for_admin_approval','approved','canvassing_submitted','canvassing_approved','in_progress','po_submitted','po_admin_approved')\n\t\t\t\t  AND item_id IS NOT NULL";
	 */
	public function getRequestsGrouped($filters = array())
	{
		// Ensure columns for grouping exist (pr_number, is_archived)
		$this->ensurePrColumns();
		$this->ensureRevisionColumns();

		$includeArchived = (bool)(isset($filters['include_archived']) ? $filters['include_archived'] : false);
		$sort = (string)(isset($filters['sort']) ? $filters['sort'] : 'date');
		$order = strtolower((string)(isset($filters['order']) ? $filters['order'] : 'desc')) === 'asc' ? 'ASC' : 'DESC';

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
				-- priority: pending > canvassing_rejected > rejected > canvassing_approved > approved > canvassing_submitted > in_progress > completed > cancelled
				MAX(CASE pr.status
					WHEN 'pending' THEN 100
					WHEN 'canvassing_rejected' THEN 95
					WHEN 'rejected' THEN 90
					WHEN 'canvassing_approved' THEN 85
					WHEN 'approved' THEN 80
					WHEN 'canvassing_submitted' THEN 75
					WHEN 'in_progress' THEN 70
					WHEN 'completed' THEN 60
					WHEN 'cancelled' THEN 50
					ELSE 0 END) AS status_rank,
				-- Most recent status string for display
				(SELECT pr2.status FROM purchase_requests pr2 WHERE pr2.pr_number = pr.pr_number ORDER BY pr2.updated_at DESC LIMIT 1) AS status,
				-- Revision state aggregation and most recent value
				MAX(CASE pr.revision_state
					WHEN 'proposed' THEN 100
					WHEN 'justified' THEN 90
					WHEN 'recheck_requested' THEN 80
					WHEN 'accepted' THEN 70
					ELSE 0 END) AS revision_rank,
				(SELECT pr3.revision_state FROM purchase_requests pr3 WHERE pr3.pr_number = pr.pr_number AND pr3.revision_state IS NOT NULL ORDER BY pr3.updated_at DESC LIMIT 1) AS revision_state
			FROM purchase_requests pr
			LEFT JOIN inventory_items i ON i.item_id = pr.item_id
			LEFT JOIN branches b ON b.branch_id = pr.branch_id
			LEFT JOIN users ru ON ru.user_id = pr.requested_by
			GROUP BY pr.pr_number
		)
		SELECT * FROM grouped";

		$conditions = array();
		$params = array();
		if (!empty($filters['branch_id'])) { $conditions[] = '(branch_id = :branch_id OR branch_id IS NULL)'; $params['branch_id'] = (int)$filters['branch_id']; }
		if (!empty($filters['status'])) { $conditions[] = 'status = :status'; $params['status'] = (string)$filters['status']; }
		if (!empty($filters['revision'])) { $conditions[] = 'revision_state = :revision'; $params['revision'] = (string)$filters['revision']; }
		if (!$includeArchived) { $conditions[] = 'is_archived = FALSE'; }
		if ($conditions) { $sql .= ' WHERE ' . implode(' AND ', $conditions); }
		$sql .= ' ORDER BY ' . $sortExpr . ' ' . $order . ', pr_number ASC';

		$st = $this->pdo->prepare($sql);
		$st->execute($params);
		$rows = $st->fetchAll();
		return $rows ?: array();
	}

	/** Get full details for a PR group (by pr_number) including item rows. */
	/**
	 * @param string $prNumber
	 * @return array
	 */
	public function getGroupDetails($prNumber)
	{
		$this->ensurePrColumns();
		$this->ensureRevisionColumns();
		$stmt = $this->pdo->prepare(
			"SELECT pr.request_id, pr.pr_number, pr.item_id, i0.name AS item_name, pr.quantity, pr.unit, pr.status, pr.created_at,
				pr.branch_id, b.name AS branch_name, pr.requested_by, u.full_name AS requested_by_name,
				pr.revision_state, pr.revision_notes,
				pr.needed_by, pr.justification,
				pr.approved_by, pr.approved_at,
				(
				  SELECT quantity FROM inventory_items ii
				   WHERE ii.item_id = pr.item_id AND ii.branch_id = pr.branch_id
				   ORDER BY ii.updated_at DESC NULLS LAST, ii.item_id LIMIT 1
				) IS NOT NULL
				  ? (
				      SELECT quantity FROM inventory_items ii
				       WHERE ii.item_id = pr.item_id AND ii.branch_id = pr.branch_id
				       ORDER BY ii.updated_at DESC NULLS LAST, ii.item_id LIMIT 1
				    )
				  : COALESCE(
				      (SELECT quantity FROM inventory_items ii WHERE ii.item_id = pr.item_id AND (ii.branch_id IS NULL OR ii.branch_id = 0)
				       ORDER BY ii.updated_at DESC NULLS LAST, ii.item_id LIMIT 1),
				      (SELECT quantity FROM inventory_items ii WHERE ii.item_id = pr.item_id
				       ORDER BY ii.updated_at DESC NULLS LAST, ii.item_id LIMIT 1)
				    ) AS stock_on_hand
			 FROM purchase_requests pr
			 LEFT JOIN inventory_items i0 ON i0.item_id = pr.item_id
			 LEFT JOIN branches b ON b.branch_id = pr.branch_id
			 LEFT JOIN users u ON u.user_id = pr.requested_by
			 WHERE pr.pr_number = :pr
			 ORDER BY pr.created_at ASC"
		);
		$stmt->execute(array('pr' => $prNumber));
		$rows = $stmt->fetchAll();
		return $rows ?: array();
	}

	/** Update status for all requests under the same PR number. */
	/**
	 * @param string $prNumber
	 * @param string $status
	 * @param int $performedBy
	 * @param string|null $notes
	 * @return bool
	 */
	public function updateGroupStatus($prNumber, $status, $performedBy, $notes = null)
	{
		$allowed = ['pending','for_admin_approval','approved','rejected','in_progress','completed','cancelled','canvassing_submitted','canvassing_approved','canvassing_rejected'];
		if (!in_array($status, $allowed, true)) { return false; }
		$this->ensureRequestStatusEnum();
		$rows = $this->getGroupDetails($prNumber);
		if (!$rows) { return false; }
		$this->pdo->beginTransaction();
		try {
			$ok = true;
			foreach ($rows as $r) {
				// Suppress per-row requester notify; we'll send a single group notification later
				$ok = $ok && $this->updateRequestStatus((int)$r['request_id'], $status, $performedBy, $notes, true);
			}
			$this->pdo->commit();
			// Single consolidated notification to requester(s)
			$this->notifyRequestersForGroup($prNumber, $status, $notes, $performedBy);
			return $ok;
		} catch (\Throwable $e) {
			$this->pdo->rollBack();
			return false;
		}
	}

	/** Archive all requests under a PR number. */
	/**
	 * @param string $prNumber
	 * @param int $performedBy
	 * @param string|null $reason
	 * @return bool
	 */
	public function archiveGroup($prNumber, $performedBy, $reason = null)
	{
		$this->ensurePrColumns();
		try {
			$st = $this->pdo->prepare("UPDATE purchase_requests SET is_archived = TRUE, archived_at = NOW(), archived_by = :by WHERE pr_number = :pr");
			$ok = $st->execute(array('by' => $performedBy, 'pr' => $prNumber));
			if ($ok) {
				// Record a single audit entry for the group leader (arbitrary pick: newest)
				$rid = (int)$this->pdo->query("SELECT request_id FROM purchase_requests WHERE pr_number = '" . str_replace("'", "''", $prNumber) . "' ORDER BY updated_at DESC LIMIT 1")->fetchColumn();
				if ($rid > 0) {
					$this->recordEvent($rid, null, null, $performedBy, $reason ? ('Archived: ' . $reason) : 'Archived');
					$this->recordAudit('purchase_requests', $rid, 'update', $performedBy, array('archived' => true, 'pr_number' => $prNumber, 'reason' => $reason));
				}
			}
			return $ok;
		} catch (\Throwable $e) { return false; }
	}

	/** Restore archived PR group. */
	/**
	 * @param string $prNumber
	 * @param int $performedBy
	 * @return bool
	 */
	public function restoreGroup($prNumber, $performedBy)
	{
		$this->ensurePrColumns();
		try {
			$st = $this->pdo->prepare("UPDATE purchase_requests SET is_archived = FALSE WHERE pr_number = :pr");
			$ok = $st->execute(array('pr' => $prNumber));
			if ($ok) {
				$rid = (int)$this->pdo->query("SELECT request_id FROM purchase_requests WHERE pr_number = '" . str_replace("'", "''", $prNumber) . "' ORDER BY updated_at DESC LIMIT 1")->fetchColumn();
				if ($rid > 0) {
					$this->recordEvent($rid, null, null, $performedBy, 'Restored from archive');
					$this->recordAudit('purchase_requests', $rid, 'update', $performedBy, array('archived' => false, 'pr_number' => $prNumber));
				}
			}
			return $ok;
		} catch (\Throwable $e) { return false; }
	}

	/**
	 * @param int $requestId
	 * @return array|null
	 */
	public function getRequestById($requestId)
	{
			$this->ensurePrColumns();
			$stmt = $this->pdo->prepare(
				'SELECT request_id, item_id, branch_id, requested_by, assigned_to, request_type, quantity, unit, justification, status, priority, needed_by, created_at, updated_at, pr_number FROM purchase_requests WHERE request_id = :request_id LIMIT 1'
			);
		$stmt->execute(array('request_id' => $requestId));

		$row = $stmt->fetch();

		if ($row) {
			$just = isset($row['justification']) ? $row['justification'] : null;
			$prn = isset($row['pr_number']) ? (string)$row['pr_number'] : '';
			$row['justification'] = \App\Services\CryptoService::maybeDecrypt($just, 'pr:' . $prn);
		}
		return $row ?: null;
	}

	/**
	 * @param int $requestId
	 * @param array $payload
	 * @param int $performedBy
	 * @return bool
	 */
	public function updateRequest($requestId, array $payload, $performedBy)
	{
		$columns = array();
		$params = array('request_id' => $requestId, 'updated_by' => $performedBy);
		$allowed = array('assigned_to', 'quantity', 'unit', 'justification', 'priority', 'needed_by');

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
			return $this->updateRequestStatus($requestId, $payload['status'], $performedBy, isset($payload['notes']) ? $payload['notes'] : null);
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

	/**
	 * @param int $requestId
	 * @param string $status
	 * @param int $performedBy
	 * @param string|null $notes
	 * @param bool $suppressRequesterNotify
	 * @return bool
	 */
	public function updateRequestStatus($requestId, $status, $performedBy, $notes = null, $suppressRequesterNotify = false)
	{
		$this->ensureRequestStatusEnum();
		$current = $this->getRequestById($requestId);
		if (!$current) {
			return false;
		}

		$stmt = $this->pdo->prepare(
			'UPDATE purchase_requests SET status = :status, updated_by = :updated_by WHERE request_id = :request_id'
		);

		$result = $stmt->execute(array(
			'status' => $status,
			'updated_by' => $performedBy,
			'request_id' => $requestId,
		));

		if ($result) {
			$this->recordEvent($requestId, $current['status'], $status, $performedBy, $notes);
			$this->recordAudit('purchase_requests', $requestId, 'status_change', $performedBy, [
				'old_status' => $current['status'],
				'new_status' => $status,
				'notes' => $notes,
			]);
			// Notify procurement when a request is approved (or canvassing approved)
			if ($status === 'approved' || $status === 'canvassing_approved') {
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
					$subject = ($status === 'canvassing_approved')
						? ('PR ' . (string)(isset($current['pr_number']) ? $current['pr_number'] : (string)$requestId) . ' • Canvassing Approved')
						: ('Purchase Request #' . (string)$requestId . ' approved');
					$body = ($status === 'canvassing_approved')
						? 'Canvassing has been approved by Admin. You may proceed to create the PO.'
						: 'A purchase request has been approved by Admin and is ready for next steps.';
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
			// Notify the requester (Admin Assistant) of status change
			if (!$suppressRequesterNotify) {
				try {
					$this->ensureMessagesTable();
					$label = $this->statusLabel($status);
					$pr = (string)(isset($current['pr_number']) ? $current['pr_number'] : (string)$requestId);
					$items = $this->buildItemsSummaryForPr($pr);
					$subject = 'PR ' . $pr . ' • ' . $label;
					$body = 'PR ' . $pr . ' status update: ' . $label . ".\n\nItems Requested:\n" . $items . ($notes ? "\n\nNotes: " . $notes : '');
					$this->sendMessage((int)$performedBy, (int)(isset($current['requested_by']) ? $current['requested_by'] : 0), $subject, $body);
				} catch (\Throwable $ignored) {}
			}
		}

		return $result;
	}

	/**
	 * @param int $requestId
	 * @param int $performedBy
	 * @param string|null $notes
	 * @return bool
	 */
	public function followUpRequest($requestId, $performedBy, $notes = null)
	{
		$request = $this->getRequestById($requestId);
		if (!$request) {
			return false;
		}

		$message = $notes ?: 'Follow-up submitted by admin assistant';

		$this->recordAudit('purchase_requests', $requestId, 'update', $performedBy, array('follow_up' => $message));

		return $this->recordEvent($requestId, $request['status'], $request['status'], $performedBy, $message);
	}

	/**
	 * @param int $requestId
	 * @return array
	 */
	public function getRequestHistory($requestId)
	{
		$stmt = $this->pdo->prepare(
			'SELECT event_id, old_status, new_status, notes, performed_by, performed_at FROM purchase_request_events WHERE request_id = :request_id ORDER BY performed_at DESC'
		);

		$stmt->execute(array('request_id' => $requestId));

		return $stmt->fetchAll();
	}

	/**
	 * @param int $requestId
	 * @param string|null $oldStatus
	 * @param string|null $newStatus
	 * @param int $performedBy
	 * @param string|null $notes
	 * @return bool
	 */
	private function recordEvent($requestId, $oldStatus, $newStatus, $performedBy, $notes)
	{
		$stmt = $this->pdo->prepare(
			'INSERT INTO purchase_request_events (request_id, old_status, new_status, notes, performed_by) VALUES (:request_id, :old_status, :new_status, :notes, :performed_by)'
		);

		return $stmt->execute(array(
			'request_id' => $requestId,
			'old_status' => $oldStatus,
			'new_status' => $newStatus,
			'notes' => $notes,
			'performed_by' => $performedBy,
		));
	}

	/**
	 * @param string $tableName
	 * @param int $recordId
	 * @param string $action
	 * @param int $performedBy
	 * @param array $payload
	 * @return void
	 */
	private function recordAudit($tableName, $recordId, $action, $performedBy, $payload = array())
	{
		$jsonPayload = null;
		if (!empty($payload)) {
			$jsonPayload = json_encode($payload);
		}

		$stmt = $this->pdo->prepare(
			'INSERT INTO audit_logs (table_name, record_id, action, payload, performed_by) VALUES (:table_name, :record_id, :action, :payload, :performed_by)'
		);

		$stmt->execute(array(
			'table_name' => $tableName,
			'record_id' => $recordId,
			'action' => $action,
			'payload' => $jsonPayload,
			'performed_by' => $performedBy,
		));
	}

	/** Consolidated notify for all requesters of a PR group after a group status update. */
	/**
	 * @param string $prNumber
	 * @param string $status
	 * @param string|null $notes
	 * @param int $performedBy
	 * @return void
	 */
	private function notifyRequestersForGroup($prNumber, $status, $notes, $performedBy)
	{
		try {
			$this->ensureMessagesTable();
			$st = $this->pdo->prepare("SELECT DISTINCT requested_by FROM purchase_requests WHERE pr_number = :pr");
			$st->execute(array('pr' => $prNumber));
			$recipients = array();
			$rows = $st->fetchAll() ?: array();
			foreach ($rows as $r) { $recipients[] = (int)$r['requested_by']; }
			if (!$recipients) { return; }
			$label = $this->statusLabel($status);
			$items = $this->buildItemsSummaryForPr($prNumber);
			$subject = 'PR ' . $prNumber . ' • ' . $label;
			$body = 'PR ' . $prNumber . ' status update: ' . $label . ".\n\nItems Requested:\n" . $items . ($notes ? "\n\nNotes: " . $notes : '');
			foreach ($recipients as $to) {
				$this->sendMessage($performedBy, $to, $subject, $body);
			}
		} catch (\Throwable $ignored) {}
	}

	/** Create messages table if needed (with attachment columns). */
	/** @return void */
	private function ensureMessagesTable()
	{
		try {
			$this->pdo->exec('CREATE TABLE IF NOT EXISTS messages (
				id BIGSERIAL PRIMARY KEY,
				sender_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
				recipient_id BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
				subject VARCHAR(255) NOT NULL,
				body TEXT NOT NULL,
				is_read BOOLEAN NOT NULL DEFAULT FALSE,
				created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
				attachment_name VARCHAR(255),
				attachment_path TEXT
			)');
		} catch (\Throwable $e) {}
	}

	/** Send a message (best-effort). */
	/**
	 * @param int $senderId
	 * @param int $recipientId
	 * @param string $subject
	 * @param string $body
	 * @param string|null $attachmentName
	 * @param string|null $attachmentPath
	 * @return void
	 */
	public function sendMessage($senderId, $recipientId, $subject, $body, $attachmentName = null, $attachmentPath = null)
	{
		if ($recipientId <= 0) { return; }
		try {
			$this->ensureMessagesTable();
			$sql = 'INSERT INTO messages (sender_id, recipient_id, subject, body, attachment_name, attachment_path) VALUES (:s,:r,:j,:b,:an,:ap)';
			$st = $this->pdo->prepare($sql);
			$st->execute(array('s' => $senderId ?: null, 'r' => $recipientId, 'j' => $subject, 'b' => $body, 'an' => $attachmentName, 'ap' => $attachmentPath));
		} catch (\Throwable $ignored) {}
	}

	/** Build items summary string for a PR group. */
	/**
	 * @param string $prNumber
	 * @return string
	 */
	private function buildItemsSummaryForPr($prNumber)
	{
		try {
			$st = $this->pdo->prepare("SELECT i.name AS item_name, pr.quantity, pr.unit FROM purchase_requests pr LEFT JOIN inventory_items i ON i.item_id = pr.item_id WHERE pr.pr_number = :pr ORDER BY pr.created_at ASC");
			$st->execute(array('pr' => $prNumber));
			$lines = array();
			foreach ($st->fetchAll() as $r) {
				$itemName = isset($r['item_name']) ? (string)$r['item_name'] : 'Item';
				$qty = isset($r['quantity']) ? (string)$r['quantity'] : '0';
				$unit = isset($r['unit']) ? (string)$r['unit'] : '';
				$lines[] = $itemName . ' × ' . $qty . ' ' . $unit;
			}
			return implode("\n", $lines);
		} catch (\Throwable $e) { return ''; }
	}

	/** Map status codes to human-friendly labels used in UI. */
	/**
	 * @param string $status
	 * @return string
	 */
	private function statusLabel($status)
	{
		$labelMap = array(
			'pending' => 'For Admin Approval',
			'approved' => 'Approved',
			'canvassing_submitted' => 'Canvassing Submitted',
			'canvassing_approved' => 'Canvassing Approved',
			'canvassing_rejected' => 'Canvassing Rejected',
			'rejected' => 'Rejected',
			'in_progress' => 'In Progress',
			'completed' => 'Completed',
			'cancelled' => 'Cancelled',
		);
		return isset($labelMap[$status]) ? $labelMap[$status] : $status;
	}

	/** Ensure new columns used by grouped PRs exist. Safe to call often. */
	/** @return void */
	private function ensurePrColumns()
	{
		try {
			$this->pdo->exec("ALTER TABLE purchase_requests
				ADD COLUMN IF NOT EXISTS pr_number VARCHAR(32),
				ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE,
				ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ,
				ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(user_id) ON DELETE SET NULL,
				ADD COLUMN IF NOT EXISTS approved_by VARCHAR(255),
				ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ
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
				$rows = $stmt ? $stmt->fetchAll() : array();
				if ($rows) {
					$upd = $this->pdo->prepare("UPDATE purchase_requests SET pr_number = :pr WHERE request_id = :id");
					foreach ($rows as $r) {
						$year = (int)date('Y', strtotime((string)$r['created_at']));
						$prNum = $this->generatePrNumberForYear($year);
						try { $upd->execute(array('pr' => $prNum, 'id' => (int)$r['request_id'])); } catch (\Throwable $ignored) {}
					}
				}
			}
		} catch (\Throwable $e) {
			// swallow; read paths will fail gracefully elsewhere if truly missing, but this keeps prod resilient
		}
	}

	/** Ensure canvassing-related enum values exist in request_status (idempotent best-effort). */
	/** @return void */
	private function ensureRequestStatusEnum()
	{
		try {
			// Fast path using DO block with IF checks and AFTER placement when supported
			$this->pdo->exec(<<<SQL
DO $$ BEGIN
	IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'request_status') THEN
		IF NOT EXISTS (
			SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
			WHERE t.typname = 'request_status' AND e.enumlabel = 'for_admin_approval'
		) THEN
			BEGIN
				ALTER TYPE request_status ADD VALUE 'for_admin_approval' AFTER 'pending';
			EXCEPTION WHEN others THEN
				ALTER TYPE request_status ADD VALUE 'for_admin_approval';
			END;
		END IF;
		IF NOT EXISTS (
			SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
			WHERE t.typname = 'request_status' AND e.enumlabel = 'canvassing_submitted'
		) THEN
			BEGIN
				ALTER TYPE request_status ADD VALUE 'canvassing_submitted' AFTER 'approved';
			EXCEPTION WHEN others THEN
				ALTER TYPE request_status ADD VALUE 'canvassing_submitted';
			END;
		END IF;
		IF NOT EXISTS (
			SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
			WHERE t.typname = 'request_status' AND e.enumlabel = 'canvassing_approved'
		) THEN
			BEGIN
				ALTER TYPE request_status ADD VALUE 'canvassing_approved' AFTER 'canvassing_submitted';
			EXCEPTION WHEN others THEN
				ALTER TYPE request_status ADD VALUE 'canvassing_approved';
			END;
		END IF;
		IF NOT EXISTS (
			SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
			WHERE t.typname = 'request_status' AND e.enumlabel = 'canvassing_rejected'
		) THEN
			BEGIN
				ALTER TYPE request_status ADD VALUE 'canvassing_rejected' AFTER 'canvassing_approved';
			EXCEPTION WHEN others THEN
				ALTER TYPE request_status ADD VALUE 'canvassing_rejected';
			END;
		END IF;
	END IF;
END $$;
SQL);
		} catch (\Throwable $e) {
			// Fallback for environments where DO/AFTER may not be available
			try {
				$labelsStmt = $this->pdo->query("SELECT e.enumlabel FROM pg_type t JOIN pg_enum e ON e.enumtypid = t.oid WHERE t.typname = 'request_status'");
				$labels = $labelsStmt ? $labelsStmt->fetchAll(\PDO::FETCH_COLUMN) : array();
				foreach (array('for_admin_approval','canvassing_submitted','canvassing_approved','canvassing_rejected') as $v) {
					if (!in_array($v, $labels, true)) {
						$this->pdo->exec("ALTER TYPE request_status ADD VALUE '" . $v . "'");
					}
				}
			} catch (\Throwable $ignored) {}
		}
	}

	/** Ensure revision-related columns exist for grouped PR flow. */
	/** @return void */
	private function ensureRevisionColumns()
	{
		try {
			$this->pdo->exec("ALTER TABLE purchase_requests
				ADD COLUMN IF NOT EXISTS revision_state VARCHAR(32),
				'for_admin_approval' => 'Forwarded to Admin',
				ADD COLUMN IF NOT EXISTS revision_notes TEXT");
		} catch (\Throwable $e) {}
	}
}
 

