<?php

namespace App\Services;

use App\Database\Connection;
use PDO;

class InventoryService
{
	private PDO $pdo;

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
		$sql = 'SELECT item_id, name, category, status, quantity, unit FROM inventory_items';
		$params = [];
		if ($branchId) {
			$sql .= ' WHERE branch_id = :b';
			$params['b'] = $branchId;
		}
		$sql .= ' ORDER BY name ASC';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll();
	}

	public function getItemById(int $itemId): ?array
	{
		$stmt = $this->pdo->prepare('SELECT item_id, branch_id, name, category, status, quantity, unit FROM inventory_items WHERE item_id = :id');
		$stmt->execute(['id' => $itemId]);
		$row = $stmt->fetch();
		return $row ?: null;
	}

	public function createItem(array $data, int $createdBy): int
	{
		$status = $this->normalizeStatus($data['status'] ?? 'good');
		$stmt = $this->pdo->prepare('INSERT INTO inventory_items (branch_id, name, category, status, quantity, unit, created_by, updated_by) VALUES (:b,:n,:c,:s,:q,:u,:by,:by) RETURNING item_id');
		$stmt->execute([
			'b' => $data['branch_id'] ?? null,
			'n' => trim((string)($data['name'] ?? '')),
			'c' => trim((string)($data['category'] ?? '')),
			's' => $status,
			'q' => (int)($data['quantity'] ?? 1),
			'u' => trim((string)($data['unit'] ?? 'pcs')),
			'by' => $createdBy,
		]);
		return (int)$stmt->fetchColumn();
	}

	public function updateItem(int $itemId, array $data, int $updatedBy): bool
	{
		$sets = [];
		$params = ['id' => $itemId, 'by' => $updatedBy];
		$allowed = ['name','category','status','quantity','unit'];
		foreach ($allowed as $f) {
			if (array_key_exists($f, $data)) {
				$val = $f === 'status' ? $this->normalizeStatus($data[$f]) : $data[$f];
				$sets[] = "$f = :$f";
				$params[$f] = $f === 'quantity' ? (int)$val : trim((string)$val);
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
}
