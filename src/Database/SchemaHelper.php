<?php
namespace App\Database;

use PDO;

class SchemaHelper
{
    /** Detect primary key column for purchase_orders table (supports 'id' or 'po_id'). */
    public static function getPoPrimaryKey(PDO $pdo): string
    {
        try {
            $cols = [];
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'purchase_orders'");
            if ($stmt) {
                foreach ($stmt->fetchAll() as $r) { $cols[] = strtolower((string)$r['column_name']); }
            }
            if (in_array('id', $cols, true)) return 'id';
            if (in_array('po_id', $cols, true)) return 'po_id';
        } catch (\Throwable $e) {
            // Fallback to probing
            try { $pdo->query('SELECT id FROM purchase_orders LIMIT 1'); return 'id'; } catch (\Throwable $e2) {}
            try { $pdo->query('SELECT po_id FROM purchase_orders LIMIT 1'); return 'po_id'; } catch (\Throwable $e3) {}
        }
        return 'id';
    }

    /** Find a purchase order row by PO number, ID, or PR number. Returns associative array or null. */
    public static function findPurchaseOrder(PDO $pdo, ?string $poNumber = null, ?int $poId = null, ?string $prNumber = null): ?array
    {
        try {
            if ($poNumber !== null && $poNumber !== '') {
                $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE po_number = :po LIMIT 1');
                $st->execute(['po' => $poNumber]);
                $row = $st->fetch();
                if ($row) return $row;
            }
            if ($poId !== null && $poId > 0) {
                $pk = self::getPoPrimaryKey($pdo);
                $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE ' . $pk . ' = :id LIMIT 1');
                $st->execute(['id' => $poId]);
                $row = $st->fetch();
                if ($row) return $row;
            }
            if ($prNumber !== null && $prNumber !== '') {
                $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE pr_number = :pr ORDER BY created_at DESC LIMIT 1');
                $st->execute(['pr' => $prNumber]);
                $row = $st->fetch();
                if ($row) return $row;
            }
        } catch (\Throwable $e) { }
        return null;
    }

    /** Ensure PO-related tables/columns exist with idempotent operations (best-effort, Postgres/MySQL via information_schema). */
    public static function ensurePoTables(PDO $pdo): void
    {
        // Create tables if missing (minimal columns); then add columns/indexes if not present
        try {
            // purchase_orders
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS purchase_orders (
                    id SERIAL PRIMARY KEY,
                    po_number VARCHAR(64) UNIQUE,
                    pr_number VARCHAR(64),
                    supplier_id INTEGER,
                    vendor_name VARCHAR(255),
                    vendor_address TEXT,
                    vendor_tin VARCHAR(64),
                    reference VARCHAR(128),
                    terms VARCHAR(128),
                    center VARCHAR(128),
                    notes TEXT,
                    discount NUMERIC(12,2) DEFAULT 0,
                    deliver_to TEXT,
                    look_for VARCHAR(255),
                    prepared_by VARCHAR(255),
                    finance_officer VARCHAR(255),
                    admin_name VARCHAR(255),
                    pdf_path TEXT,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL
                )'
            );
        } catch (\Throwable $e) { /* Table may already exist with different PK; ignore */ }

        // Column adds (idempotent)
        $alterCols = [
            "ADD COLUMN IF NOT EXISTS po_number VARCHAR(64)",
            "ADD COLUMN IF NOT EXISTS pr_number VARCHAR(64)",
            "ADD COLUMN IF NOT EXISTS supplier_id INTEGER",
            "ADD COLUMN IF NOT EXISTS vendor_name VARCHAR(255)",
            "ADD COLUMN IF NOT EXISTS vendor_address TEXT",
            "ADD COLUMN IF NOT EXISTS vendor_tin VARCHAR(64)",
            "ADD COLUMN IF NOT EXISTS reference VARCHAR(128)",
            "ADD COLUMN IF NOT EXISTS terms VARCHAR(128)",
            "ADD COLUMN IF NOT EXISTS center VARCHAR(128)",
            "ADD COLUMN IF NOT EXISTS notes TEXT",
            "ADD COLUMN IF NOT EXISTS discount NUMERIC(12,2) DEFAULT 0",
            "ADD COLUMN IF NOT EXISTS deliver_to TEXT",
            "ADD COLUMN IF NOT EXISTS look_for VARCHAR(255)",
            "ADD COLUMN IF NOT EXISTS prepared_by VARCHAR(255)",
            "ADD COLUMN IF NOT EXISTS finance_officer VARCHAR(255)",
            "ADD COLUMN IF NOT EXISTS admin_name VARCHAR(255)",
            "ADD COLUMN IF NOT EXISTS pdf_path TEXT",
            "ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL",
            "ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL",
        ];
        foreach ($alterCols as $alt) {
            try { $pdo->exec('ALTER TABLE purchase_orders ' . $alt); } catch (\Throwable $e) { }
        }
        try { $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_po_number_unique ON purchase_orders (po_number)'); } catch (\Throwable $e) { }

        // purchase_order_items
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS purchase_order_items (
                    id SERIAL PRIMARY KEY,
                    po_id INTEGER,
                    description TEXT,
                    unit VARCHAR(64),
                    qty INTEGER,
                    unit_price NUMERIC(12,2),
                    line_total NUMERIC(12,2)
                )'
            );
        } catch (\Throwable $e) { }
        $alterItemCols = [
            "ADD COLUMN IF NOT EXISTS po_id INTEGER",
            "ADD COLUMN IF NOT EXISTS description TEXT",
            "ADD COLUMN IF NOT EXISTS unit VARCHAR(64)",
            "ADD COLUMN IF NOT EXISTS qty INTEGER",
            "ADD COLUMN IF NOT EXISTS unit_price NUMERIC(12,2)",
            "ADD COLUMN IF NOT EXISTS line_total NUMERIC(12,2)",
        ];
        foreach ($alterItemCols as $alt) {
            try { $pdo->exec('ALTER TABLE purchase_order_items ' . $alt); } catch (\Throwable $e) { }
        }
        try { $pdo->exec('CREATE INDEX IF NOT EXISTS idx_po_items_po_id ON purchase_order_items (po_id)'); } catch (\Throwable $e) { }
    }

    /** Resolve a writable directory for PDFs. Optionally returns a full path if $fileName is provided. */
    public static function resolvePdfStoragePath(?string $fileName = null): string
    {
        // Prefer env var
        $envDir = getenv('PDF_STORAGE_DIR');
        if ($envDir && @is_dir($envDir) && @is_writable($envDir)) {
            return rtrim($envDir, DIRECTORY_SEPARATOR) . ($fileName ? DIRECTORY_SEPARATOR . $fileName : '');
        }

        // Project storage/pdf
        $base = @realpath(__DIR__ . '/../../');
        $dir = $base ? ($base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdf') : null;
        if ($dir) {
            if (!@is_dir($dir)) { @mkdir($dir, 0775, true); }
            if (@is_dir($dir) && @is_writable($dir)) {
                return rtrim($dir, DIRECTORY_SEPARATOR) . ($fileName ? DIRECTORY_SEPARATOR . $fileName : '');
            }
        }

        // Fallback to system temp with subdir
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'po_pdfs';
        if (!@is_dir($tmp)) { @mkdir($tmp, 0775, true); }
        return $tmp . ($fileName ? DIRECTORY_SEPARATOR . $fileName : '');
    }
}
