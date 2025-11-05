<?php

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Inventory service with PHP 5-compatible syntax (no typed properties, no short array, no ??).
 */
class InventoryService
{
	/** @var PDO */
	private $pdo;

	/** @var bool|null */
	private static $hasMaintaining = null;

	/**
	 * Fixed set of categories used in dashboards and supplier counts.
	 * @return array
	 */
	private function allowedCategories()
	{
		return array(
			'Office Supplies',
			'Medical Equipments',
			'Medicines',
			'Machines',
			'Electronics',
			'Appliances',
		);
	}

	/** Public accessor for categories list (for dashboards). */
	public function getAllowedCategories()
	{
		return $this->allowedCategories();
	}

	/**
	 * @param PDO|null $pdo
	 */
	public function __construct($pdo = null)
	{
		$this->pdo = $pdo ? $pdo : Connection::resolve();
	}

	/**
	 * High-level status counts (optionally filtered by branch).
	 * @param int|null $branchId
	 * @return array
	 */
	public function getStatsByBranch($branchId = null)
	{
		$sql = 'SELECT status, COUNT(*) as cnt FROM inventory_items';
		$params = array();
		$wheres = array();
		if ($branchId) {
			$wheres[] = 'branch_id = :b';
			$params['b'] = $branchId;
		}
		// Exclude deprecated categories from counts
		$wheres[] = '(category IS NULL OR category NOT ILIKE :no_paper)';
		$params['no_paper'] = 'paper%';
		$wheres[] = '(category IS NULL OR category NOT ILIKE :no_bondpaper)';
		$params['no_bondpaper'] = 'bondpaper%';
		if (!empty($wheres)) {
			$sql .= ' WHERE ' . implode(' AND ', $wheres);
		}
		$sql .= ' GROUP BY status';

		$rows = $this->pdo->prepare($sql);
		$rows->execute($params);
		$stats = array('good' => 0, 'for_repair' => 0, 'for_replacement' => 0, 'retired' => 0, 'total' => 0);
		foreach ($rows->fetchAll() as $r) {
			$key = isset($r['status']) ? $r['status'] : '';
			if ($key !== '') {
				$stats[$key] = (int)$r['cnt'];
				$stats['total'] += (int)$r['cnt'];
			}
		}
		return $stats;
	}

	/**
	 * Per-category inventory counts (optionally filtered by branch).
	 * Each row: category, total, good, for_repair, for_replacement, retired
	 * @param int|null $branchId
	 * @return array
	 */
	public function getStatsByCategory($branchId = null)
	{
		$cats = $this->allowedCategories();
		$vals = array();
		$params = array();
		foreach ($cats as $i => $c) {
			$key = 'c' . $i;
			$vals[] = '(:' . $key . ')';
			$params[$key] = $c;
		}
		$sql = "WITH cats(category) AS (VALUES " . implode(',', $vals) . "),\nagg AS (\n    SELECT COALESCE(NULLIF(TRIM(category), ''), 'Uncategorized') AS category,\n           COUNT(*) AS total,\n           SUM(CASE WHEN status = 'good' THEN 1 ELSE 0 END) AS good,\n           SUM(CASE WHEN status = 'for_repair' THEN 1 ELSE 0 END) AS for_repair,\n           SUM(CASE WHEN status = 'for_replacement' THEN 1 ELSE 0 END) AS for_replacement,\n           SUM(CASE WHEN status = 'retired' THEN 1 ELSE 0 END) AS retired\n    FROM inventory_items";
		$conds = array();
		if ($branchId) {
			$conds[] = '(branch_id = :b OR branch_id IS NULL)';
			$params['b'] = $branchId;
		}
		$conds[] = '(category IS NULL OR category NOT ILIKE :no_paper)';
		$params['no_paper'] = 'paper%';
		$conds[] = '(category IS NULL OR category NOT ILIKE :no_bondpaper)';
		$params['no_bondpaper'] = 'bondpaper%';
		if (!empty($conds)) {
			$sql .= ' WHERE ' . implode(' AND ', $conds);
		}
		$sql .= "\n    GROUP BY COALESCE(NULLIF(TRIM(category), ''), 'Uncategorized')\n)\nSELECT cats.category,\n       COALESCE(agg.total, 0) AS total,\n       COALESCE(agg.good, 0) AS good,\n       COALESCE(agg.for_repair, 0) AS for_repair,\n       COALESCE(agg.for_replacement, 0) AS for_replacement,\n       COALESCE(agg.retired, 0) AS retired\nFROM cats\nLEFT JOIN agg ON agg.category = cats.category\nORDER BY cats.category ASC";
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * List inventory items; includes branch-specific and global items.
	 * @param int|null $branchId
	 * @return array
	 */
	public function listInventory($branchId = null)
	{
		$hasMaint = $this->hasMaintainingColumn();
		$selectCols = 'item_id, name, category, status, quantity, unit, minimum_quantity' . ($hasMaint ? ', maintaining_quantity' : ', 0 AS maintaining_quantity');
		$sql = 'SELECT ' . $selectCols . ' FROM inventory_items';
		$params = array();
		$wheres = array();
		if ($branchId) {
			$wheres[] = '(branch_id = :b OR branch_id IS NULL)';
			$params['b'] = $branchId;
		}
		$wheres[] = '(category IS NULL OR category NOT ILIKE :no_paper)';
		$params['no_paper'] = 'paper%';
		$wheres[] = '(category IS NULL OR category NOT ILIKE :no_bondpaper)';
		$params['no_bondpaper'] = 'bondpaper%';
		if (!empty($wheres)) {
			$sql .= ' WHERE ' . implode(' AND ', $wheres);
		}
		$sql .= ' ORDER BY name ASC';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll();
	}

	/**
	 * Get a single item.
	 * @param int $itemId
	 * @return array|null
	 */
	public function getItemById($itemId)
	{
		$hasMaint = $this->hasMaintainingColumn();
		$selectCols = 'item_id, branch_id, name, category, status, quantity, unit, minimum_quantity' . ($hasMaint ? ', maintaining_quantity' : ', 0 AS maintaining_quantity');
		$stmt = $this->pdo->prepare('SELECT ' . $selectCols . ' FROM inventory_items WHERE item_id = :id');
		$stmt->execute(array('id' => (int)$itemId));
		$row = $stmt->fetch();
		return $row ? $row : null;
	}

	/**
	 * Create a new item.
	 * @param array $data
	 * @param int $createdBy
	 * @return int item_id
	 */
	public function createItem(array $data, $createdBy)
	{
		$status = isset($data['status']) ? $this->normalizeStatus($data['status']) : 'good';
		$hasMaint = $this->hasMaintainingColumn();
		if ($hasMaint) {
			$stmt = $this->pdo->prepare('INSERT INTO inventory_items (branch_id, name, category, status, quantity, unit, minimum_quantity, maintaining_quantity, created_by, updated_by) VALUES (:b,:n,:c,:s,:q,:u,:min,:maint,:by,:by) RETURNING item_id');
			$stmt->execute(array(
				'b' => isset($data['branch_id']) ? $data['branch_id'] : null,
				'n' => trim(isset($data['name']) ? (string)$data['name'] : ''),
				'c' => trim(isset($data['category']) ? (string)$data['category'] : ''),
				's' => $status,
				'q' => (int)(isset($data['quantity']) ? $data['quantity'] : 1),
				'u' => trim(isset($data['unit']) ? (string)$data['unit'] : 'pcs'),
				'min' => (int)(isset($data['minimum_quantity']) ? $data['minimum_quantity'] : 0),
				'maint' => (int)(isset($data['maintaining_quantity']) ? $data['maintaining_quantity'] : 0),
				'by' => (int)$createdBy,
			));
		} else {
			$stmt = $this->pdo->prepare('INSERT INTO inventory_items (branch_id, name, category, status, quantity, unit, minimum_quantity, created_by, updated_by) VALUES (:b,:n,:c,:s,:q,:u,:min,:by,:by) RETURNING item_id');
			$stmt->execute(array(
				'b' => isset($data['branch_id']) ? $data['branch_id'] : null,
				'n' => trim(isset($data['name']) ? (string)$data['name'] : ''),
				'c' => trim(isset($data['category']) ? (string)$data['category'] : ''),
				's' => $status,
				'q' => (int)(isset($data['quantity']) ? $data['quantity'] : 1),
				'u' => trim(isset($data['unit']) ? (string)$data['unit'] : 'pcs'),
				'min' => (int)(isset($data['minimum_quantity']) ? $data['minimum_quantity'] : 0),
				'by' => (int)$createdBy,
			));
		}
		return (int)$stmt->fetchColumn();
	}

	/**
	 * Update fields for an item.
	 * @param int $itemId
	 * @param array $data
	 * @param int $updatedBy
	 * @return bool
	 */
	public function updateItem($itemId, array $data, $updatedBy)
	{
		$sets = array();
		$params = array('id' => (int)$itemId, 'by' => (int)$updatedBy);
		$hasMaint = $this->hasMaintainingColumn();
		$allowed = array('name','category','status','quantity','unit','minimum_quantity');
		if ($hasMaint) { $allowed[] = 'maintaining_quantity'; }
		foreach ($allowed as $f) {
			if (array_key_exists($f, $data)) {
				$val = ($f === 'status') ? $this->normalizeStatus($data[$f]) : $data[$f];
				$sets[] = $f . ' = :' . $f;
				if ($f === 'quantity' || $f === 'minimum_quantity' || $f === 'maintaining_quantity') {
					$params[$f] = (int)$val;
				} else {
					$params[$f] = trim((string)$val);
				}
			}
		}
		if (empty($sets)) { return false; }
		$sets[] = 'updated_by = :by';
		$sql = 'UPDATE inventory_items SET ' . implode(',', $sets) . ' WHERE item_id = :id';
		$stmt = $this->pdo->prepare($sql);
		return $stmt->execute($params);
	}

	/**
	 * Delete an item.
	 * @param int $itemId
	 * @return bool
	 */
	public function deleteItem($itemId)
	{
		$stmt = $this->pdo->prepare('DELETE FROM inventory_items WHERE item_id = :id');
		return $stmt->execute(array('id' => (int)$itemId));
	}

	/**
	 * Normalize status strings to canonical values used in DB.
	 * @param string $status
	 * @return string
	 */
	private function normalizeStatus($status)
	{
		$s = strtolower(trim((string)$status));
		if ($s === 'good') { return 'good'; }
		if ($s === 'for repair' || $s === 'for_repair' || $s === 'repair') { return 'for_repair'; }
		if ($s === 'for replacement' || $s === 'for_replacement' || $s === 'replacement') { return 'for_replacement'; }
		if ($s === 'retired') { return 'retired'; }
		return 'good';
	}

	/**
	 * Per-branch inventory counts broken down by status.
	 * @return array
	 */
	public function getStatsPerBranch()
	{
		// One-time cleanup: ensure deprecated categories do not skew counts
		try { $this->pdo->exec("DELETE FROM inventory_items WHERE category ILIKE 'paper%'"); } catch (\Throwable $e) {}
		try { $this->pdo->exec("DELETE FROM inventory_items WHERE category ILIKE 'bondpaper%'"); } catch (\Throwable $e) {}
		$sql = "
			SELECT b.branch_id, b.name,
				COALESCE(COUNT(i.item_id),0) AS total,
				COALESCE(SUM(CASE WHEN i.status='good' THEN 1 ELSE 0 END),0) AS good,
				COALESCE(SUM(CASE WHEN i.status='for_repair' THEN 1 ELSE 0 END),0) AS for_repair,
				COALESCE(SUM(CASE WHEN i.status='for_replacement' THEN 1 ELSE 0 END),0) AS for_replacement,
				COALESCE(SUM(CASE WHEN i.status='retired' THEN 1 ELSE 0 END),0) AS retired
			FROM branches b
			LEFT JOIN inventory_items i ON i.branch_id = b.branch_id AND (i.category IS NULL OR (i.category NOT ILIKE 'paper%' AND i.category NOT ILIKE 'bondpaper%'))
			GROUP BY b.branch_id, b.name
			ORDER BY b.name ASC
		";
		$stmt = $this->pdo->query($sql);
		return $stmt->fetchAll();
	}

	/**
	 * Count distinct suppliers per category based on supplier_items.category.
	 * Returns rows for all categories even if 0 suppliers.
	 * @return array
	 */
	public function getSupplierCountsByCategory()
	{
		$cats = $this->allowedCategories();
		$vals = array();
		$params = array();
		foreach ($cats as $i => $c) { $key = 'c'.$i; $vals[] = '(:'.$key.')'; $params[$key] = $c; }
		// Normalize supplier_items.category to our allowed buckets so near-miss labels still count
		$sql = "WITH cats(category) AS (VALUES ".implode(',', $vals)."), src AS (
			SELECT CASE
				WHEN category IS NULL OR TRIM(category) = '' THEN 'Office Supplies'
				WHEN category ILIKE 'office%%' OR category ILIKE '%%stationery%%' THEN 'Office Supplies'
				WHEN category ILIKE 'medical equip%%' OR category ILIKE '%%equipment%%' OR category ILIKE 'medical%%' THEN 'Medical Equipments'
				WHEN category ILIKE 'medicine%%' OR category ILIKE 'drug%%' THEN 'Medicines'
				WHEN category ILIKE 'machine%%' THEN 'Machines'
				WHEN category ILIKE 'electronic%%' OR category ILIKE 'computer%%' OR category ILIKE 'it%%' THEN 'Electronics'
				WHEN category ILIKE 'appliance%%' THEN 'Appliances'
				ELSE 'Uncategorized'
			END AS category,
			supplier_id
			FROM supplier_items
		), agg AS (
			SELECT category, COUNT(DISTINCT supplier_id) AS suppliers
			FROM src
			GROUP BY category
		)
		SELECT cats.category, COALESCE(agg.suppliers, 0) AS suppliers
		FROM cats
		LEFT JOIN agg ON agg.category = cats.category
		ORDER BY cats.category ASC";
		try {
			$st = $this->pdo->prepare($sql);
			$st->execute($params);
			return $st->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			$out = array();
			foreach ($cats as $c) { $out[] = array('category' => $c, 'suppliers' => 0); }
			return $out;
		}
	}

	/**
	 * Detect whether maintaining_quantity column exists.
	 * @return bool
	 */
	private function hasMaintainingColumn()
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

