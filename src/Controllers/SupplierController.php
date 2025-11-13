<?php

namespace App\Controllers;

use App\Database\Connection;
use PDO;

class SupplierController extends BaseController
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) { @session_start(); }
        $this->pdo = $pdo ?? Connection::resolve();
    }

    private function ensureTable(): void
    {
        // Create table with packaging fields if it doesn't exist
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS supplier_items (
            id BIGSERIAL PRIMARY KEY,
            supplier_id BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            category VARCHAR(100),
            price NUMERIC(12,2) NOT NULL DEFAULT 0,
            unit VARCHAR(32) NOT NULL DEFAULT 'pcs',
            package_label VARCHAR(32) NOT NULL DEFAULT 'pack',
            pieces_per_package INTEGER NOT NULL DEFAULT 1,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )");
        // If table exists from older version, add missing columns
        $this->pdo->exec("ALTER TABLE supplier_items ADD COLUMN IF NOT EXISTS category VARCHAR(100)");
        $this->pdo->exec("ALTER TABLE supplier_items ADD COLUMN IF NOT EXISTS package_label VARCHAR(32) NOT NULL DEFAULT 'pack'");
        $this->pdo->exec("ALTER TABLE supplier_items ADD COLUMN IF NOT EXISTS pieces_per_package INTEGER NOT NULL DEFAULT 1");
        $this->pdo->exec("ALTER TABLE supplier_items ALTER COLUMN unit SET DEFAULT 'pcs'");
        // Helpful index for category supplier lookups
        try { $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_supplier_items_category ON supplier_items((COALESCE(NULLIF(TRIM(category), ''), 'Uncategorized')))"); } catch (\Throwable $e) {}
        // Price tiers for bulk/package quantity breaks
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS supplier_item_price_tiers (
            id BIGSERIAL PRIMARY KEY,
            supplier_item_id BIGINT NOT NULL REFERENCES supplier_items(id) ON DELETE CASCADE,
            min_packages INTEGER NOT NULL DEFAULT 1,
            max_packages INTEGER,
            price_per_package NUMERIC(12,2) NOT NULL,
            note VARCHAR(255),
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )");
        // Packages (bundles) that group multiple supplier items with a package price
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS supplier_packages (
            id BIGSERIAL PRIMARY KEY,
            supplier_id BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price NUMERIC(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS supplier_package_items (
            id BIGSERIAL PRIMARY KEY,
            package_id BIGINT NOT NULL REFERENCES supplier_packages(id) ON DELETE CASCADE,
            supplier_item_id BIGINT NOT NULL REFERENCES supplier_items(id) ON DELETE CASCADE,
            quantity INTEGER NOT NULL DEFAULT 1
        )");
    }

    public function dashboard(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->render('dashboard/supplier_dashboard.php', [
            'incoming' => 0,
            'on_process' => 0,
            'processed' => 0,
        ]);
    }

    public function itemsPage(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
    $st = $this->pdo->prepare('SELECT id, name, description, price, unit, package_label, pieces_per_package FROM supplier_items WHERE supplier_id = :me ORDER BY name');
        $st->execute(['me' => $me]);
        $items = $st->fetchAll();
        // Load tiers grouped by item id for the current supplier
        $tiers = [];
        if ($items) {
            $ids = array_map(static fn($r) => (int)$r['id'], $items);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st2 = $this->pdo->prepare('SELECT id, supplier_item_id, min_packages, max_packages, price_per_package, note FROM supplier_item_price_tiers WHERE supplier_item_id IN (' . $in . ') ORDER BY min_packages ASC, price_per_package ASC');
            $st2->execute($ids);
            foreach ($st2->fetchAll() as $t) {
                $sid = (int)$t['supplier_item_id'];
                if (!isset($tiers[$sid])) { $tiers[$sid] = []; }
                $tiers[$sid][] = $t;
            }
        }
        $this->render('dashboard/supplier_items.php', ['items' => $items, 'tiers' => $tiers]);
    }

    public function itemsCreate(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
    $price = (float)($_POST['price'] ?? 0);
    $unit = trim((string)($_POST['unit'] ?? 'pcs'));
    $pkgLabel = trim((string)($_POST['package_label'] ?? 'pack'));
    $pkgQty = (int)($_POST['pieces_per_package'] ?? 1);
    if ($name === '' || $price < 0 || $pkgQty <= 0 || $pkgLabel === '') { header('Location: /supplier/items?error=Invalid+data'); return; }
    $st = $this->pdo->prepare('INSERT INTO supplier_items (supplier_id, name, description, price, unit, package_label, pieces_per_package) VALUES (:s,:n,:d,:p,:u,:pl,:pq)');
    $st->execute(['s' => $me, 'n' => $name, 'd' => $desc !== '' ? $desc : null, 'p' => $price, 'u' => $unit, 'pl' => $pkgLabel, 'pq' => $pkgQty]);
        header('Location: /supplier/items?created=1');
    }

    public function itemsUpdate(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
    $price = (float)($_POST['price'] ?? 0);
    $unit = trim((string)($_POST['unit'] ?? 'pcs'));
    $pkgLabel = trim((string)($_POST['package_label'] ?? 'pack'));
    $pkgQty = (int)($_POST['pieces_per_package'] ?? 1);
    if ($id <= 0 || $name === '' || $price < 0 || $pkgQty <= 0 || $pkgLabel === '') { header('Location: /supplier/items?error=Invalid+data'); return; }
    $st = $this->pdo->prepare('UPDATE supplier_items SET name=:n, description=:d, price=:p, unit=:u, package_label=:pl, pieces_per_package=:pq, updated_at=NOW() WHERE id=:id AND supplier_id=:s');
    $st->execute(['n' => $name, 'd' => $desc !== '' ? $desc : null, 'p' => $price, 'u' => $unit, 'pl' => $pkgLabel, 'pq' => $pkgQty, 'id' => $id, 's' => $me]);
        header('Location: /supplier/items?updated=1');
    }

    public function itemsDelete(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { header('Location: /supplier/items?error=Invalid+item'); return; }
        $st = $this->pdo->prepare('DELETE FROM supplier_items WHERE id=:id AND supplier_id=:s');
        $st->execute(['id' => $id, 's' => $me]);
        header('Location: /supplier/items?deleted=1');
    }

    // --- Price tiers management ---
    public function addPriceTier(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $itemId = (int)($_POST['supplier_item_id'] ?? 0);
        $min = (int)($_POST['min_packages'] ?? 1);
        $max = isset($_POST['max_packages']) && $_POST['max_packages'] !== '' ? (int)$_POST['max_packages'] : null;
        $price = (float)($_POST['price_per_package'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if ($itemId <= 0 || $min <= 0 || $price < 0) { header('Location: /supplier/items?error=Invalid+tier'); return; }
        // Verify ownership
        $chk = $this->pdo->prepare('SELECT 1 FROM supplier_items WHERE id=:id AND supplier_id=:sid');
        $chk->execute(['id' => $itemId, 'sid' => $me]);
        if (!$chk->fetchColumn()) { header('Location: /supplier/items?error=Not+found'); return; }
        $ins = $this->pdo->prepare('INSERT INTO supplier_item_price_tiers (supplier_item_id, min_packages, max_packages, price_per_package, note) VALUES (:i,:mi,:ma,:p,:no)');
        $ins->execute(['i' => $itemId, 'mi' => $min, 'ma' => $max, 'p' => $price, 'no' => $note !== '' ? $note : null]);
        header('Location: /supplier/items?tier_added=1#item-' . $itemId);
    }

    public function deletePriceTier(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $tierId = (int)($_POST['id'] ?? 0);
        if ($tierId <= 0) { header('Location: /supplier/items?error=Invalid+tier'); return; }
        // Ensure the tier belongs to this supplier
        $chk = $this->pdo->prepare('SELECT t.id FROM supplier_item_price_tiers t JOIN supplier_items i ON i.id = t.supplier_item_id WHERE t.id=:tid AND i.supplier_id=:sid');
        $chk->execute(['tid' => $tierId, 'sid' => $me]);
        if (!$chk->fetchColumn()) { header('Location: /supplier/items?error=Not+found'); return; }
        $del = $this->pdo->prepare('DELETE FROM supplier_item_price_tiers WHERE id=:id');
        $del->execute(['id' => $tierId]);
        header('Location: /supplier/items?tier_deleted=1');
    }

    // --- Packages (bundles) management ---
    public function packagesPage(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        // Load packages
        $pk = $this->pdo->prepare('SELECT id, name, description, price FROM supplier_packages WHERE supplier_id=:sid ORDER BY name ASC');
        $pk->execute(['sid' => $me]);
        $packages = $pk->fetchAll();
        // Load all items for add-item dropdown
        $it = $this->pdo->prepare('SELECT id, name, package_label, pieces_per_package, unit FROM supplier_items WHERE supplier_id=:sid ORDER BY name');
        $it->execute(['sid' => $me]);
        $items = $it->fetchAll();
        // Load package items grouped by package
        $byPkg = [];
        if ($packages) {
            $pids = array_map(static fn($r) => (int)$r['id'], $packages);
            $in = implode(',', array_fill(0, count($pids), '?'));
            $st = $this->pdo->prepare('SELECT spi.id, spi.package_id, spi.supplier_item_id, spi.quantity, si.name FROM supplier_package_items spi JOIN supplier_items si ON si.id = spi.supplier_item_id WHERE spi.package_id IN (' . $in . ') ORDER BY si.name ASC');
            $st->execute($pids);
            foreach ($st->fetchAll() as $row) {
                $pid = (int)$row['package_id'];
                if (!isset($byPkg[$pid])) { $byPkg[$pid] = []; }
                $byPkg[$pid][] = $row;
            }
        }
        $this->render('dashboard/supplier_packages.php', [ 'packages' => $packages, 'items' => $items, 'byPkg' => $byPkg ]);
    }

    public function packagesCreate(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $price = (float)($_POST['price'] ?? 0);
        if ($name === '' || $price < 0) { header('Location: /supplier/packages?error=Invalid+package'); return; }
        $ins = $this->pdo->prepare('INSERT INTO supplier_packages (supplier_id, name, description, price) VALUES (:sid,:n,:d,:p)');
        $ins->execute(['sid' => $me, 'n' => $name, 'd' => $desc !== '' ? $desc : null, 'p' => $price]);
        header('Location: /supplier/packages?created=1');
    }

    public function packagesUpdate(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $price = (float)($_POST['price'] ?? 0);
        if ($id <= 0 || $name === '' || $price < 0) { header('Location: /supplier/packages?error=Invalid+package'); return; }
        $chk = $this->pdo->prepare('SELECT 1 FROM supplier_packages WHERE id=:id AND supplier_id=:sid');
        $chk->execute(['id' => $id, 'sid' => $me]);
        if (!$chk->fetchColumn()) { header('Location: /supplier/packages?error=Not+found'); return; }
        $up = $this->pdo->prepare('UPDATE supplier_packages SET name=:n, description=:d, price=:p, updated_at=NOW() WHERE id=:id');
        $up->execute(['n' => $name, 'd' => $desc !== '' ? $desc : null, 'p' => $price, 'id' => $id]);
        header('Location: /supplier/packages?updated=1');
    }

    public function packagesDelete(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { header('Location: /supplier/packages?error=Invalid+package'); return; }
        $chk = $this->pdo->prepare('SELECT 1 FROM supplier_packages WHERE id=:id AND supplier_id=:sid');
        $chk->execute(['id' => $id, 'sid' => $me]);
        if (!$chk->fetchColumn()) { header('Location: /supplier/packages?error=Not+found'); return; }
        $del = $this->pdo->prepare('DELETE FROM supplier_packages WHERE id=:id');
        $del->execute(['id' => $id]);
        header('Location: /supplier/packages?deleted=1');
    }

    public function packageAddItem(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $packageId = (int)($_POST['package_id'] ?? 0);
        $itemId = (int)($_POST['supplier_item_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 1);
        if ($packageId <= 0 || $itemId <= 0 || $qty <= 0) { header('Location: /supplier/packages?error=Invalid+item'); return; }
        // Ownership checks
        $chkPkg = $this->pdo->prepare('SELECT 1 FROM supplier_packages WHERE id=:id AND supplier_id=:sid');
        $chkPkg->execute(['id' => $packageId, 'sid' => $me]);
        if (!$chkPkg->fetchColumn()) { header('Location: /supplier/packages?error=Not+found'); return; }
        $chkItem = $this->pdo->prepare('SELECT 1 FROM supplier_items WHERE id=:id AND supplier_id=:sid');
        $chkItem->execute(['id' => $itemId, 'sid' => $me]);
        if (!$chkItem->fetchColumn()) { header('Location: /supplier/packages?error=Not+found'); return; }
        $ins = $this->pdo->prepare('INSERT INTO supplier_package_items (package_id, supplier_item_id, quantity) VALUES (:p,:i,:q)');
        $ins->execute(['p' => $packageId, 'i' => $itemId, 'q' => $qty]);
        header('Location: /supplier/packages?item_added=1#pkg-' . $packageId);
    }

    public function packageRemoveItem(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $this->ensureTable();
        $me = (int)($_SESSION['user_id'] ?? 0);
        $rowId = (int)($_POST['id'] ?? 0);
        if ($rowId <= 0) { header('Location: /supplier/packages?error=Invalid+row'); return; }
        // Ensure row belongs to this supplier via join
        $chk = $this->pdo->prepare('SELECT spi.package_id FROM supplier_package_items spi JOIN supplier_packages sp ON sp.id = spi.package_id WHERE spi.id=:id AND sp.supplier_id=:sid');
        $chk->execute(['id' => $rowId, 'sid' => $me]);
        $pkgId = $chk->fetchColumn();
        if (!$pkgId) { header('Location: /supplier/packages?error=Not+found'); return; }
        $del = $this->pdo->prepare('DELETE FROM supplier_package_items WHERE id=:id');
        $del->execute(['id' => $rowId]);
        header('Location: /supplier/packages?item_removed=1#pkg-' . (int)$pkgId);
    }

    // --- Purchase Orders assigned to Supplier ---
    public function posPage(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $me = (int)($_SESSION['user_id'] ?? 0);
        // Ensure table exists (created by ProcurementController normally)
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (id BIGSERIAL PRIMARY KEY, pr_number VARCHAR(64) NOT NULL, po_number VARCHAR(64) NOT NULL, supplier_id BIGINT NOT NULL, status VARCHAR(64) NOT NULL DEFAULT 'draft', pdf_path TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW());");
        } catch (\Throwable $e) {}
        $pk = \App\Database\SchemaHelper::getPoPrimaryKey($this->pdo);
        $st = $this->pdo->prepare('SELECT ' . $pk . ' AS id, pr_number, po_number, status, pdf_path, created_at FROM purchase_orders WHERE supplier_id = :sid ORDER BY created_at DESC');
        $st->execute(['sid' => $me]);
        $pos = $st->fetchAll();
        $this->render('dashboard/supplier_pos.php', ['pos' => $pos]);
    }

    public function poRespond(): void
    {
        if (($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); return; }
        $me = (int)($_SESSION['user_id'] ?? 0);
        $poId = (int)($_POST['po_id'] ?? 0);
        $payment = trim((string)($_POST['payment_method'] ?? ''));
        $delivery = trim((string)($_POST['delivery_option'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        // Optional: attach deal PDF path from a file upload (out of scope here). We allow message only.
        if ($poId <= 0) { header('Location: /supplier/pos?error=Invalid+PO'); return; }
        // Verify ownership
        $pk = \App\Database\SchemaHelper::getPoPrimaryKey($this->pdo);
        $st = $this->pdo->prepare('SELECT pr_number FROM purchase_orders WHERE ' . $pk . ' = :id AND supplier_id = :sid');
        $st->execute(['id' => $poId, 'sid' => $me]);
        $pr = $st->fetchColumn();
        if (!$pr) { header('Location: /supplier/pos?error=Not+found'); return; }
        // Ensure messages has attachment columns
        try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255);"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path TEXT;"); } catch (\Throwable $e) {}
        // Send response to procurement
        $subject = 'Supplier PO Response • PR ' . (string)$pr;
        $body = "Payment: $payment\nDelivery: $delivery\n\n" . ($message !== '' ? $message : '');
        $recipients = $this->pdo->query("SELECT user_id FROM users WHERE is_active = TRUE AND role IN ('procurement_manager','procurement')")->fetchAll();
        if ($recipients) {
            $ins = $this->pdo->prepare('INSERT INTO messages (sender_id, recipient_id, subject, body) VALUES (:s,:r,:j,:b)');
            foreach ($recipients as $row) { $ins->execute(['s' => $me, 'r' => (int)$row['user_id'], 'j' => $subject, 'b' => $body]); }
        }
        // Mark PO as responded
        $this->pdo->prepare("UPDATE purchase_orders SET status='supplier_response_submitted', updated_at=NOW() WHERE $pk = :id")
            ->execute(['id' => $poId]);
        // Update PR group status
        try { (new \App\Services\RequestService())->updateGroupStatus((string)$pr, 'supplier_response', $me, 'Supplier responded to PO'); } catch (\Throwable $ignored) {}
        header('Location: /supplier/pos?responded=1');
    }

    public function downloadPO(): void
    {
        if (!isset($_SESSION['user_id'])) { header('Location: /login'); return; }
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) { http_response_code(404); echo 'Not found'; return; }
        // Load PO
        $pk = \App\Database\SchemaHelper::getPoPrimaryKey($this->pdo);
        $st = $this->pdo->prepare('SELECT supplier_id, pdf_path FROM purchase_orders WHERE ' . $pk . ' = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        if (!$row) { http_response_code(404); echo 'Not found'; return; }
        $role = $_SESSION['role'] ?? '';
        $me = (int)($_SESSION['user_id'] ?? 0);
        // Authorization: supplier owner OR procurement/admin roles
        if (!($role === 'admin' || $role === 'procurement' || $role === 'procurement_manager' || ($role === 'supplier' && $me === (int)$row['supplier_id']))) {
            http_response_code(403); echo 'Forbidden'; return; }
        $path = (string)($row['pdf_path'] ?? '');
        if ($path === '' || !is_file($path)) { http_response_code(404); echo 'File not found'; return; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
    }
}
