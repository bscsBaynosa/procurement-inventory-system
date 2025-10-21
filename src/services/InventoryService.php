<?php

namespace App\Services;

use App\Database\Connection;
use JsonException;
use PDO;

class InventoryService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::resolve();
    }

    /**
     * Retrieve every inventory item, optionally filtered by branch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllItems(?int $branchId = null): array
    {
        $sql = 'SELECT item_id, sku, asset_tag, name, category, description, quantity, unit, status, branch_id FROM inventory_items';
        $params = [];

        if ($branchId !== null) {
            $sql .= ' WHERE branch_id = :branch_id OR branch_id IS NULL';
            $params['branch_id'] = $branchId;
        }

        $sql .= ' ORDER BY name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getItemById(int $itemId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT item_id, sku, asset_tag, name, category, description, quantity, unit, status, branch_id, created_at, created_by, updated_at, updated_by FROM inventory_items WHERE item_id = :item_id LIMIT 1'
        );
        $stmt->execute(['item_id' => $itemId]);

        $item = $stmt->fetch();

        return $item ?: null;
    }

    public function addItem(array $payload, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_items (sku, asset_tag, name, category, description, quantity, unit, status, branch_id, created_by, updated_by)
             VALUES (:sku, :asset_tag, :name, :category, :description, :quantity, :unit, :status, :branch_id, :created_by, :updated_by)
             RETURNING item_id, name, status, quantity'
        );

        $status = $payload['status'] ?? 'good';

        $stmt->execute([
            'sku' => $payload['sku'] ?? null,
            'asset_tag' => $payload['asset_tag'] ?? null,
            'name' => $payload['name'],
            'category' => $payload['category'],
            'description' => $payload['description'] ?? null,
            'quantity' => $payload['quantity'] ?? 0,
            'unit' => $payload['unit'] ?? 'pcs',
            'status' => $status,
            'branch_id' => $payload['branch_id'] ?? null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $item = $stmt->fetch();

        $this->recordMovement(
            (int)$item['item_id'],
            (int)($payload['quantity'] ?? 0),
            $payload['quantity'] ?? 0,
            'stock_in',
            $userId,
            'Initial stock entry'
        );

        $this->recordAudit('inventory_items', (int)$item['item_id'], 'create', $userId, [
            'name' => $payload['name'],
            'status' => $status,
            'quantity' => $payload['quantity'] ?? 0,
        ]);

        return $item;
    }

    public function updateItem(int $itemId, array $payload, int $userId): bool
    {
        $current = $this->getItemById($itemId);
        if (!$current) {
            return false;
        }

        $columns = [];
        $params = ['item_id' => $itemId, 'updated_by' => $userId];

        $mutableFields = ['sku', 'asset_tag', 'name', 'category', 'description', 'quantity', 'unit', 'status', 'branch_id'];
        foreach ($mutableFields as $field) {
            if (array_key_exists($field, $payload)) {
                $columns[] = "$field = :$field";
                $params[$field] = $payload[$field];
            }
        }

        if (empty($columns)) {
            return false;
        }

        $columns[] = 'updated_by = :updated_by';

        $sql = 'UPDATE inventory_items SET ' . implode(', ', $columns) . ' WHERE item_id = :item_id';

        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute($params);

        if ($success) {
            if (array_key_exists('quantity', $payload)) {
                $quantityAfter = (int)$payload['quantity'];
                $delta = $quantityAfter - (int)$current['quantity'];

                if ($delta !== 0) {
                    $this->recordMovement(
                        $itemId,
                        $delta,
                        $quantityAfter,
                        'adjustment',
                        $userId,
                        'Manual quantity update'
                    );
                }
            }

            $this->recordAudit('inventory_items', $itemId, 'update', $userId, [
                'old' => $current,
                'new' => $payload,
            ]);
        }

        return $success;
    }

    public function toggleItemStatus(int $itemId, string $status, int $userId): bool
    {
        $validStatuses = ['good', 'for_repair', 'for_replacement', 'retired'];
        if (!in_array($status, $validStatuses, true)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE inventory_items SET status = :status, updated_by = :updated_by WHERE item_id = :item_id'
        );
        $result = $stmt->execute([
            'status' => $status,
            'updated_by' => $userId,
            'item_id' => $itemId,
        ]);

        if ($result) {
            $this->recordAudit('inventory_items', $itemId, 'update', $userId, ['status' => $status]);
        }

        return $result;
    }

    public function deleteItem(int $itemId, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM inventory_items WHERE item_id = :item_id');
        $result = $stmt->execute(['item_id' => $itemId]);

        if ($result) {
            $this->recordAudit('inventory_items', $itemId, 'delete', $userId);
        }

        return $result;
    }

    public function getInventoryStats(?int $branchId = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM inventory_items';
        $params = [];

        if ($branchId !== null) {
            $sql .= ' WHERE branch_id = :branch_id OR branch_id IS NULL';
            $params['branch_id'] = $branchId;
        }

        $sql .= ' GROUP BY status';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $stats = [
            'good' => 0,
            'for_repair' => 0,
            'for_replacement' => 0,
            'retired' => 0,
            'total' => 0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $stats[$row['status']] = (int)$row['total'];
            $stats['total'] += (int)$row['total'];
        }

        return $stats;
    }

    public function getReportData(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM inventory_items WHERE updated_at BETWEEN :start AND :end';
        $params = [
            'start' => $startDate,
            'end' => $endDate,
        ];

        if ($branchId !== null) {
            $sql .= ' AND (branch_id = :branch_id OR branch_id IS NULL)';
            $params['branch_id'] = $branchId;
        }

        $sql .= ' GROUP BY status';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();

        $summary = ['total' => 0];
        foreach ($rows as $row) {
            $summary[$row['status']] = (int)$row['total'];
            $summary['total'] += (int)$row['total'];
        }

        return $summary;
    }

    private function recordMovement(int $itemId, int $quantityDelta, int $quantityAfter, string $reason, int $performedBy, ?string $notes = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_movements (item_id, quantity_delta, quantity_after, reason, notes, performed_by)
             VALUES (:item_id, :quantity_delta, :quantity_after, :reason, :notes, :performed_by)'
        );

        $stmt->execute([
            'item_id' => $itemId,
            'quantity_delta' => $quantityDelta,
            'quantity_after' => $quantityAfter,
            'reason' => $reason,
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