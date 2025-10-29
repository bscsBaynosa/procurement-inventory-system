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
            price NUMERIC(12,2) NOT NULL DEFAULT 0,
            unit VARCHAR(32) NOT NULL DEFAULT 'pcs',
            package_label VARCHAR(32) NOT NULL DEFAULT 'pack',
            pieces_per_package INTEGER NOT NULL DEFAULT 1,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )");
        // If table exists from older version, add missing columns
        $this->pdo->exec("ALTER TABLE supplier_items ADD COLUMN IF NOT EXISTS package_label VARCHAR(32) NOT NULL DEFAULT 'pack'");
        $this->pdo->exec("ALTER TABLE supplier_items ADD COLUMN IF NOT EXISTS pieces_per_package INTEGER NOT NULL DEFAULT 1");
        $this->pdo->exec("ALTER TABLE supplier_items ALTER COLUMN unit SET DEFAULT 'pcs'");
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
        $this->render('dashboard/supplier_items.php', ['items' => $items]);
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
}
