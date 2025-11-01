<?php

namespace App\Services;

use App\Database\Connection;
use PDO;

class InventoryService
{
	private PDO $pdo;
	private static ?bool $hasMaintaining = null;

	public function __construct(?PDO $pdo = null)
	{
		$this->pdo = $pdo ?? Connection::resolve();
	}

	public function getStatsByBranch(?int $branchId = null): array
	{
		$sql = 'SELECT status, COUNT(*) as cnt FROM inventory_items';
		$params = [];
		if ($branchId) {
			$sql .= ' WHERE branch_id = :b';
			$params['b'] = $branchId;
		}
		$sql .= ' GROUP BY status';

		$rows = $this->pdo->prepare($sql);
		$rows->execute($params);
		$stats = ['good' => 0, 'for_repair' => 0, 'for_replacement' => 0, 'retired' => 0, 'total' => 0];
		foreach ($rows->fetchAll() as $r) {
			$stats[$r['status']] = (int)$r['cnt'];
			$stats['total'] += (int)$r['cnt'];
		}
		return $stats;
	}

	public function listInventory(?int $branchId = null): array
	{
		// Backward compatible select: if maintaining_quantity column is missing, alias 0 as maintaining_quantity
		$hasMaint = $this->hasMaintainingColumn();
		$selectCols = 'item_id, name, category, status, quantity, unit, minimum_quantity' . ($hasMaint ? ', maintaining_quantity' : ', 0 AS maintaining_quantity');
		$sql = 'SELECT ' . $selectCols . ' FROM inventory_items';
		$params = [];
		$wheres = [];
		if ($branchId) {
			// Include branch-specific items and global items (branch_id IS NULL) so common
			// office supplies appear as choices for PR across branches.
			$wheres[] = '(branch_id = :b OR branch_id IS NULL)';
			$params['b'] = $branchId;
		}
		// Temporary removal: hide any Bondpaper items from all listings
		$wheres[] = 'name NOT ILIKE :hide_bondpaper';
		$params['hide_bondpaper'] = '%bondpaper%';
		if ($wheres) { $sql .= ' WHERE ' . implode(' AND ', $wheres); }
		$sql .= ' ORDER BY name ASC';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll();
	}

	public function getItemById(int $itemId): ?array
	{
		$hasMaint = $this->hasMaintainingColumn();
		$selectCols = 'item_id, branch_id, name, category, status, quantity, unit, minimum_quantity' . ($hasMaint ? ', maintaining_quantity' : ', 0 AS maintaining_quantity');
		$stmt = $this->pdo->prepare('SELECT ' . $selectCols . ' FROM inventory_items WHERE item_id = :id');
		$stmt->execute(['id' => $itemId]);
		$row = $stmt->fetch();
		return $row ?: null;
	}

	public function createItem(array $data, int $createdBy): int
	{
		$status = $this->normalizeStatus($data['status'] ?? 'good');
		$hasMaint = $this->hasMaintainingColumn();
		if ($hasMaint) {
			$stmt = $this->pdo->prepare('INSERT INTO inventory_items (branch_id, name, category, status, quantity, unit, minimum_quantity, maintaining_quantity, created_by, updated_by) VALUES (:b,:n,:c,:s,:q,:u,:min,:maint,:by,:by) RETURNING item_id');
			$stmt->execute([
				'b' => $data['branch_id'] ?? null,
				'n' => trim((string)($data['name'] ?? '')),
				'c' => trim((string)($data['category'] ?? '')),
				's' => $status,
				'q' => (int)($data['quantity'] ?? 1),
				'u' => trim((string)($data['unit'] ?? 'pcs')),
				'min' => (int)($data['minimum_quantity'] ?? 0),
				'maint' => (int)($data['maintaining_quantity'] ?? 0),
				'by' => $createdBy,
			]);
		} else {
			$stmt = $this->pdo->prepare('INSERT INTO inventory_items (branch_id, name, category, status, quantity, unit, minimum_quantity, created_by, updated_by) VALUES (:b,:n,:c,:s,:q,:u,:min,:by,:by) RETURNING item_id');
			$stmt->execute([
				'b' => $data['branch_id'] ?? null,
				'n' => trim((string)($data['name'] ?? '')),
				'c' => trim((string)($data['category'] ?? '')),
				's' => $status,
				'q' => (int)($data['quantity'] ?? 1),
				'u' => trim((string)($data['unit'] ?? 'pcs')),
				'min' => (int)($data['minimum_quantity'] ?? 0),
				'by' => $createdBy,
			]);
		}
		return (int)$stmt->fetchColumn();
	}

	public function updateItem(int $itemId, array $data, int $updatedBy): bool
	{
		$sets = [];
		$params = ['id' => $itemId, 'by' => $updatedBy];
		$hasMaint = $this->hasMaintainingColumn();
		$allowed = ['name','category','status','quantity','unit','minimum_quantity'];
		if ($hasMaint) { $allowed[] = 'maintaining_quantity'; }
		foreach ($allowed as $f) {
			if (array_key_exists($f, $data)) {
				$val = $f === 'status' ? $this->normalizeStatus($data[$f]) : $data[$f];
				$sets[] = "$f = :$f";
				if ($f === 'quantity' || $f === 'minimum_quantity' || $f === 'maintaining_quantity') {
					$params[$f] = (int)$val;
				} else {
					$params[$f] = trim((string)$val);
				}
			}
		}
		if (!$sets) { return false; }
		$sets[] = 'updated_by = :by';
		$sql = 'UPDATE inventory_items SET ' . implode(',', $sets) . ' WHERE item_id = :id';
		$stmt = $this->pdo->prepare($sql);
		return $stmt->execute($params);
	}

	public function deleteItem(int $itemId): bool
	{
		$stmt = $this->pdo->prepare('DELETE FROM inventory_items WHERE item_id = :id');
		return $stmt->execute(['id' => $itemId]);
	}

	private function normalizeStatus(string $status): string
	{
		$s = strtolower(trim($status));
		if ($s === 'good') { return 'good'; }
		if ($s === 'for repair' || $s === 'for_repair' || $s === 'repair') { return 'for_repair'; }
		if ($s === 'for replacement' || $s === 'for_replacement' || $s === 'replacement') { return 'for_replacement'; }
		if ($s === 'retired') { return 'retired'; }
		return 'good';
	}

	/**
	 * Return per-branch inventory counts broken down by status.
	 * [ { branch_id, name, total, good, for_repair, for_replacement, retired } ]
	 */
	public function getStatsPerBranch(): array
	{
		$sql = "
			SELECT b.branch_id, b.name,
				COALESCE(COUNT(i.item_id),0) AS total,
				COALESCE(SUM(CASE WHEN i.status='good' THEN 1 ELSE 0 END),0) AS good,
				COALESCE(SUM(CASE WHEN i.status='for_repair' THEN 1 ELSE 0 END),0) AS for_repair,
				COALESCE(SUM(CASE WHEN i.status='for_replacement' THEN 1 ELSE 0 END),0) AS for_replacement,
				COALESCE(SUM(CASE WHEN i.status='retired' THEN 1 ELSE 0 END),0) AS retired
			FROM branches b
			LEFT JOIN inventory_items i ON i.branch_id = b.branch_id
			GROUP BY b.branch_id, b.name
			ORDER BY b.name ASC
		";
		$stmt = $this->pdo->query($sql);
		return $stmt->fetchAll();
	}

	private function hasMaintainingColumn(): bool
	{
		if (self::$hasMaintaining !== null) { return self::$hasMaintaining; }
		try {
			$sql = "SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'inventory_items' AND column_name = 'maintaining_quantity'";
			$stmt = $this->pdo->query($sql);
			self::$hasMaintaining = (bool)$stmt->fetchColumn();
		} catch (\Throwable $e) {
			self::$hasMaintaining = false;
		}
		return self::$hasMaintaining;
	}
}
