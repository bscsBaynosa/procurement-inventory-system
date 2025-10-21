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
}
