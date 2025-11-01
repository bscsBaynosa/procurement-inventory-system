<?php
declare(strict_types=1);

// CLI script: Apply database/schema.sql to the connected Postgres and seed initial data

use App\Database\Connection;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Composer autoload not found. Run composer install.\n");
    exit(1);
}
require_once $autoload;

// Connect to DB
try {
    $pdo = Connection::resolve();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

// Apply schema
$schemaPath = __DIR__ . '/../database/schema.sql';
if (!file_exists($schemaPath)) {
    fwrite(STDERR, "Schema file not found at database/schema.sql\n");
    exit(3);
}

$sql = file_get_contents($schemaPath);
if ($sql === false) {
    fwrite(STDERR, "Failed to read schema.sql\n");
    exit(4);
}

try {
    $pdo->exec($sql);
    fwrite(STDOUT, "Schema applied successfully.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Schema apply error: " . $e->getMessage() . "\n");
    exit(5);
}

// Cleanup: remove deprecated 'Paper' category entirely
try {
    $cntStmt = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE category ILIKE 'paper%'");
    $cnt = $cntStmt ? (int)$cntStmt->fetchColumn() : 0;
    if ($cnt > 0) {
        $pdo->exec("DELETE FROM inventory_items WHERE category ILIKE 'paper%'");
        fwrite(STDOUT, "Removed {$cnt} inventory item(s) from deprecated 'Paper' category.\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Warning: cleanup of 'Paper' category failed: " . $e->getMessage() . "\n");
}

// Seed a default branch if none exists
try {
    $exists = $pdo->query("SELECT 1 FROM branches LIMIT 1");
} catch (Throwable $e) {
    $exists = false;
}

if (!$exists || $exists->fetchColumn() === false) {
    $stmt = $pdo->prepare("INSERT INTO branches (code, name, address, is_active) VALUES (:code, :name, :address, TRUE) RETURNING branch_id");
    $stmt->execute([
        'code' => 'HQ',
        'name' => 'Head Office',
        'address' => 'N/A',
    ]);
    $branchId = (int)$stmt->fetchColumn();
    fwrite(STDOUT, "Seeded default branch with ID {$branchId}.\n");
} else {
    $branchId = (int)($pdo->query("SELECT branch_id FROM branches ORDER BY branch_id ASC LIMIT 1")->fetchColumn());
}

// Seed admin user if missing
$seedUsername = getenv('SEED_ADMIN_USERNAME') ?: 'admin';
$seedPassword = getenv('SEED_ADMIN_PASSWORD') ?: null; // force explicit set unless empty allows default
if ($seedPassword === null) {
    // Fallback password if not provided via env
    $seedPassword = 'ChangeMe123!';
}

$stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = :u LIMIT 1');
$stmt->execute(['u' => $seedUsername]);
$existing = $stmt->fetchColumn();

if ($existing === false) {
    $hash = password_hash($seedPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, email, role, branch_id, is_active) '
        . 'VALUES (:username, :password_hash, :full_name, :email, :role, :branch_id, TRUE) '
        . 'RETURNING user_id'
    );
    $stmt->execute([
        'username' => $seedUsername,
        'password_hash' => $hash,
        'full_name' => 'System Administrator',
        'email' => null,
        'role' => 'admin',
        'branch_id' => $branchId ?: null,
    ]);
    $newId = (int)$stmt->fetchColumn();
    fwrite(STDOUT, "Seeded admin user '{$seedUsername}' with ID {$newId}.\n");
} else {
    fwrite(STDOUT, "Admin user '{$seedUsername}' already exists (ID {$existing}).\n");
}

// Seed common Office Supplies into inventory_items as global (branch_id IS NULL)
try {
    $existingNames = $pdo->query("SELECT LOWER(name) AS n FROM inventory_items")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $existingNames = [];
}

$existingSet = [];
foreach ($existingNames as $n) { $existingSet[$n] = true; }

$officeItems = [
    ['Short Bondpaper Rim', 'Office Supplies', 0, 'rim'],
    ['Long Bondpaper Rim', 'Office Supplies', 0, 'rim'],
    ['A4 Bondpaper Rim', 'Office Supplies', 0, 'rim'],
    ['Ballpen Black (Box of 12)', 'Office Supplies', 0, 'box'],
    ['Ballpen Blue (Box of 12)', 'Office Supplies', 0, 'box'],
    ['Whiteboard Marker Black (Box of 12)', 'Office Supplies', 0, 'box'],
    ['Yellow Pad (Pack)', 'Office Supplies', 0, 'pack'],
    ['Sticky Notes 3x3 (Pack)', 'Office Supplies', 0, 'pack'],
    ['Stapler No.10', 'Office Supplies', 0, 'pc'],
    ['Staple Wire No.10 (Box)', 'Office Supplies', 0, 'box'],
    ['Paper Clips 33mm (Box)', 'Office Supplies', 0, 'box'],
    ['Folder Long Manila (Pack of 10)', 'Office Supplies', 0, 'pack'],
    ['Brown Envelope Long (Pack of 10)', 'Office Supplies', 0, 'pack'],
    ['Correction Tape 5mm', 'Office Supplies', 0, 'pc'],
    ['Highlighter Yellow', 'Office Supplies', 0, 'pc'],
    ['Fastener Plastic (Pack of 50)', 'Office Supplies', 0, 'pack'],
    ['Record Book 300 pages', 'Office Supplies', 0, 'pc'],
];

$inserted = 0;
$stmt = $pdo->prepare('INSERT INTO inventory_items (name, category, quantity, unit, status, branch_id) VALUES (:n, :c, :q, :u, :s, NULL)');
foreach ($officeItems as [$name, $category, $qty, $unit]) {
    if (!isset($existingSet[strtolower($name)])) {
        $stmt->execute(['n' => $name, 'c' => $category, 'q' => $qty, 'u' => $unit, 's' => 'good']);
        $inserted++;
    }
}
if ($inserted > 0) {
    fwrite(STDOUT, "Seeded {$inserted} office supply item(s) into inventory_items.\n");
} else {
    fwrite(STDOUT, "Office supply seed items already present.\n");
}

fwrite(STDOUT, "Done.\n");
exit(0);
