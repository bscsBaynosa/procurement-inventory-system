<?php
/**
 * Standalone migration script for the Purchase Order (PO) module.
 * Run once (idempotent) to create or evolve purchase_orders & purchase_order_items schema
 * separate from runtime ensurePoTables() logic. Safe to re-run.
 *
 * Usage (Windows PowerShell):
 *   php scripts/migrate_po_module.php
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($autoload)) { require_once $autoload; }

// Minimal fallback autoload for Connection if composer isn't ready yet.
spl_autoload_register(static function(string $class): void {
    if (strpos($class, 'App\\') !== 0) { return; }
    $rel = substr($class, 4);
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) { require_once $path; }
});

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

try {
    $pdo = \App\Database\Connection::resolve();
} catch (Throwable $e) {
    fwrite(STDERR, "[PO MIGRATION] Failed to resolve DB connection: {$e->getMessage()}\n");
    exit(1);
}

$log = [];
$exec = function(string $sql, string $label) use ($pdo, &$log): void {
    try {
        $pdo->exec($sql);
        $log[] = "OK: $label";
    } catch (Throwable $e) {
        $log[] = "WARN: $label => " . $e->getMessage();
    }
};

// 1. Create tables
$exec("CREATE TABLE IF NOT EXISTS purchase_orders (
    id BIGSERIAL PRIMARY KEY,
    pr_number VARCHAR(64) NOT NULL,
    po_number VARCHAR(64) NOT NULL,
    supplier_id BIGINT NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
    branch_id BIGINT REFERENCES branches(branch_id) ON DELETE SET NULL,
    vendor_name VARCHAR(255),
    vendor_address TEXT,
    vendor_tin VARCHAR(64),
    center VARCHAR(128),
    reference VARCHAR(255),
    terms TEXT,
    notes TEXT,
    deliver_to TEXT,
    look_for VARCHAR(255),
    status VARCHAR(64) NOT NULL DEFAULT 'draft',
    total NUMERIC(14,2) NOT NULL DEFAULT 0,
    discount NUMERIC(12,2) NOT NULL DEFAULT 0,
    pdf_path TEXT,
    created_by BIGINT NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
    prepared_by VARCHAR(255),
    finance_officer VARCHAR(255),
    admin_name VARCHAR(255),
    reviewed_by VARCHAR(255),
    approved_by VARCHAR(255),
    pr_id BIGINT REFERENCES purchase_requests(request_id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
)", 'create purchase_orders');

$exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
    id BIGSERIAL PRIMARY KEY,
    po_id BIGINT NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    description TEXT NOT NULL,
    unit VARCHAR(32) NOT NULL,
    qty INTEGER NOT NULL DEFAULT 0,
    unit_price NUMERIC(12,2) NOT NULL DEFAULT 0,
    line_total NUMERIC(12,2) NOT NULL DEFAULT 0
)", 'create purchase_order_items');

// 2. Add missing columns / evolve existing installs
foreach ([
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS pr_id BIGINT REFERENCES purchase_requests(request_id) ON DELETE SET NULL" => 'add pr_id',
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS branch_id BIGINT REFERENCES branches(branch_id) ON DELETE SET NULL" => 'add branch_id',
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS prepared_by VARCHAR(255)" => 'add prepared_by',
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS finance_officer VARCHAR(255)" => 'add finance_officer',
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_name VARCHAR(255)" => 'add admin_name',
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(255)" => 'add reviewed_by',
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS approved_by VARCHAR(255)" => 'add approved_by',
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS discount NUMERIC(12,2) DEFAULT 0" => 'add discount'
] as $sql => $label) { $exec($sql, $label); }

// 3. Indexes
$exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_purchase_orders_po_number_unique ON purchase_orders(po_number)", 'index po_number unique');
$exec("CREATE INDEX IF NOT EXISTS idx_purchase_orders_supplier ON purchase_orders(supplier_id)", 'index supplier_id');
$exec("CREATE INDEX IF NOT EXISTS idx_purchase_orders_status ON purchase_orders(status)", 'index status');
$exec("CREATE INDEX IF NOT EXISTS idx_purchase_orders_branch ON purchase_orders(branch_id)", 'index branch_id');

// 4. Messages attachment evolution for PO flow
foreach ([
    "ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255)" => 'messages.attachment_name',
    "ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT" => 'messages.attachment_path'
] as $sql => $label) { $exec($sql, $label); }

// 5. Output log
$exec("UPDATE purchase_orders po
             SET branch_id = pr.branch_id
             FROM purchase_requests pr
             WHERE po.branch_id IS NULL
                 AND (
                     (po.pr_id IS NOT NULL AND pr.request_id = po.pr_id)
                     OR (po.pr_number IS NOT NULL AND pr.pr_number = po.pr_number)
                 )", 'backfill branch_id from purchase_requests');
fwrite(STDOUT, "Purchase Order module migration complete.\n" . implode("\n", $log) . "\n");

// Optional: exit code 0 success
exit(0);
