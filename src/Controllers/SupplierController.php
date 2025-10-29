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
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS supplier_items (
            id BIGSERIAL PRIMARY KEY,
            supplier_id BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price NUMERIC(12,2) NOT NULL DEFAULT 0,
            unit VARCHAR(32) NOT NULL DEFAULT \"pcs\",
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )');
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
        $st = $this->pdo->prepare('SELECT id, name, description, price, unit FROM supplier_items WHERE supplier_id = :me ORDER BY name');
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
        if ($name === '' || $price < 0) { header('Location: /supplier/items?error=Invalid+data'); return; }
        $st = $this->pdo->prepare('INSERT INTO supplier_items (supplier_id, name, description, price, unit) VALUES (:s,:n,:d,:p,:u)');
        $st->execute(['s' => $me, 'n' => $name, 'd' => $desc !== '' ? $desc : null, 'p' => $price, 'u' => $unit]);
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
        if ($id <= 0 || $name === '' || $price < 0) { header('Location: /supplier/items?error=Invalid+data'); return; }
        $st = $this->pdo->prepare('UPDATE supplier_items SET name=:n, description=:d, price=:p, unit=:u, updated_at=NOW() WHERE id=:id AND supplier_id=:s');
        $st->execute(['n' => $name, 'd' => $desc !== '' ? $desc : null, 'p' => $price, 'u' => $unit, 'id' => $id, 's' => $me]);
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
