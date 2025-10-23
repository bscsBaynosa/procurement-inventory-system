<?php
// Shared sidebar navigation for all roles
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$role = $_SESSION['role'] ?? 'guest';
$meName = $_SESSION['full_name'] ?? null;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$brand = 'Dashboard';
if ($role === 'admin') { $brand = 'Admin Control'; }
elseif ($role === 'custodian') { $brand = 'Custodian'; }
elseif ($role === 'procurement_manager') { $brand = 'Manager'; }

function nav_active($href, $path) {
    if ($href === '/dashboard') {
        return $path === '/dashboard' ? 'active' : '';
    }
    return strpos($path, $href) === 0 ? 'active' : '';
}
?>
<aside class="sidebar">
    <div class="brand"><?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?></div>
    <nav class="nav">
        <a href="/dashboard" class="<?= nav_active('/dashboard', $path) ?>"><svg viewBox="0 0 24 24"><path d="M12 3l9 8h-3v9h-5v-6H11v6H6v-9H3z"/></svg> Dashboard</a>
        <?php if ($role === 'admin'): ?>
            <a href="/admin/users" class="<?= nav_active('/admin/users', $path) ?>"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zM8 11c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.67 0-8 1.34-8 4v2h10v-2c0-2.66-5.33-4-8-4zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.96 1.97 3.45v2h6v-2c0-2.66-5.33-4-8-4z"/></svg> Users</a>
            <a href="/admin/branches" class="<?= nav_active('/admin/branches', $path) ?>"><svg viewBox="0 0 24 24"><path d="M12 2l7 6v12H5V8l7-6zm0 2.2L7 8v10h10V8l-5-3.8z"/></svg> Branches</a>
            <a href="/admin/messages" class="<?= nav_active('/admin/messages', $path) ?>"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H5.17L4 17.17V4zm2 2v8h12V6H6z"/></svg> Messages</a>
        <?php elseif ($role === 'custodian'): ?>
            <!-- Custodian has dashboard plus messages for now -->
            <a href="/admin/messages" class="<?= nav_active('/admin/messages', $path) ?>"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H5.17L4 17.17V4zm2 2v8h12V6H6z"/></svg> Messages</a>
        <?php elseif ($role === 'procurement_manager'): ?>
            <!-- Manager uses /dashboard for requests listing in current router -->
            <a href="/admin/messages" class="<?= nav_active('/admin/messages', $path) ?>"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H5.17L4 17.17V4zm2 2v8h12V6H6z"/></svg> Messages</a>
        <?php endif; ?>
        <a href="/settings" class="<?= nav_active('/settings', $path) ?>"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 00.12-.64l-1.92-3.32a.5.5 0 00-.6-.22l-2.39.96a7.03 7.03 0 00-1.63-.94l-.36-2.54A.5.5 0 0013 1h-4a.5.5 0 00-.5.42l-.36 2.54c-.57.22-1.11.52-1.63.94l-2.39-.96a.5.5 0 00-.6.22L1.6 7.02a.5.5 0 00.12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L1.72 13.7a.5.5 0 00-.12.64l1.92 3.32c.14.24.44.34.7.22l2.39-.96c.52.42 1.06.76 1.63.98l.36 2.52c.04.25.25.44.5.44h4c.25 0 .46-.19.5-.44l.36-2.52c.57-.22 1.11-.56 1.63-.98l2.39.96c.26.12.56.02.7-.22l1.92-3.32a.5.5 0 00-.12-.64l-2.03-1.58zM11 9a3 3 0 110 6 3 3 0 010-6z"/></svg> Settings</a>
        <a href="/logout"><svg viewBox="0 0 24 24"><path d="M10 17l1.41-1.41L8.83 13H20v-2H8.83l2.58-2.59L10 7l-5 5 5 5zM4 19h6v2H4a2 2 0 01-2-2V5a2 2 0 012-2h6v2H4v14z"/></svg> Logout</a>
    </nav>
</aside>
