<?php
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$role = $_SESSION['role'] ?? 'guest';
// Normalize legacy roles to new naming
function norm_role($r){
    if ($r === 'custodian') return 'admin_assistant';
    if ($r === 'procurement_manager') return 'procurement';
    return $r;
}
$role = norm_role($role);
$meName = $_SESSION['full_name'] ?? null;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$brand = 'Dashboard';
if ($role === 'admin') { $brand = 'Administrator'; }
elseif ($role === 'admin_assistant') { $brand = 'Admin Assistant'; }
elseif ($role === 'procurement') { $brand = 'Procurement'; }
elseif ($role === 'supplier') { $brand = 'Supplier'; }

function nav_active($href, $path) {
    if ($href === '/dashboard') {
        return $path === '/dashboard' ? 'active' : '';
    }
    return strpos($path, $href) === 0 ? 'active' : '';
}

function nav_active_many(array $hrefs, $path) {
    foreach ($hrefs as $h) {
        if (nav_active($h, $path) === 'active') return 'active';
    }
    return '';
}


$unreadCount = 0;
try {
    if (class_exists('App\\Database\\Connection')) {
        $pdo = \App\Database\Connection::resolve();
        $me = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($me > 0) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = :me AND is_read = FALSE');
            $st->execute(['me' => $me]);
            $unreadCount = (int)$st->fetchColumn();
        }
    }
} catch (\Throwable $ignored) {
    
}
?>
<aside class="sidebar">
    <div class="brand"><?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?></div>
    <nav class="nav">
        <a href="/dashboard" class="<?= nav_active('/dashboard', $path) ?>"><svg viewBox="0 0 24 24"><path d="M12 3l9 8h-3v9h-5v-6H11v6H6v-9H3z"/></svg> Dashboard</a>
        <?php if ($role === 'admin'): ?>
            <a href="/admin/users" class="<?= nav_active('/admin/users', $path) ?>"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zM8 11c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.67 0-8 1.34-8 4v2h10v-2c0-2.66-5.33-4-8-4zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.96 1.97 3.45v2h6v-2c0-2.66-5.33-4-8-4z"/></svg> Users</a>
            <a href="/admin/branches" class="<?= nav_active('/admin/branches', $path) ?>"><svg viewBox="0 0 24 24"><path d="M12 2l7 6v12H5V8l7-6zm0 2.2L7 8v10h10V8l-5-3.8z"/></svg> Branches</a>
            <a href="/admin/requests" class="<?= nav_active('/admin/requests', $path) ?>">
                <svg viewBox="0 0 24 24"><path d="M3 3h18v14H6l-3 3V3z"/></svg>
                Purchase Requests
            </a>
            <a href="/admin/requests/history" class="<?= nav_active('/admin/requests/history', $path) ?>">
                <svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 100 18 9 9 0 000-18zm-1 5h2v5h-5V11h3V8z"/></svg>
                PR History
            </a>
        <?php elseif ($role === 'custodian'): ?>
        <?php elseif ($role === 'admin_assistant'): ?>
            <a href="/admin-assistant/inventory" class="<?= nav_active_many(['/admin-assistant/inventory','/custodian/inventory'], $path) ?>"><svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm4 0h14v-2H7v2zM3 17h2v-2H3v2zm4 0h14v-2H7v2zM3 9h2V7H3v2zm4 0h14V7H7v2z"/></svg> Inventory</a>
            <div class="nav-group <?= nav_active_many(['/admin-assistant/requests','/custodian/requests'], $path) ?> <?= strpos($path, '/admin-assistant/requests') === 0 ? 'open' : '' ?>" data-nav-group>
                <button type="button" class="nav-toggle" data-nav-toggle style="display:flex;align-items:center;gap:10px;background:none;border:0;padding:0;color:inherit;cursor:pointer;width:100%;text-align:left;">
                    <svg viewBox="0 0 24 24"><path d="M3 3h18v14H6l-3 3V3z"/></svg>
                    <span>Purchase Request</span>
                    <svg viewBox="0 0 24 24" style="margin-left:auto;transition:transform .2s ease;" class="chev"><path d="M9 6l6 6-6 6"/></svg>
                </button>
                <div class="nav-sub" style="display:block;overflow:hidden;max-height:0;transition:max-height .25s ease;margin-left:26px;">
                    <div style="display:flex;flex-direction:column;gap:6px;padding-top:6px;">
                        <a href="/admin-assistant/requests/review" class="<?= nav_active('/admin-assistant/requests/review', $path) ?>">Requisitions</a>
                        <a href="/admin-assistant/requests/history" class="<?= nav_active('/admin-assistant/requests/history', $path) ?>">History</a>
                    </div>
                </div>
            </div>
            <a href="/admin-assistant/reports" class="<?= nav_active('/admin-assistant/reports', $path) ?>"><svg viewBox="0 0 24 24"><path d="M3 5h18v14H3zM5 7v10h14V7H5z"/></svg> Reports</a>
        <?php elseif ($role === 'procurement'): ?>
            <a href="/manager/requests" class="<?= nav_active('/manager/requests', $path) ?>">
                <svg viewBox="0 0 24 24"><path d="M3 3h18v14H6l-3 3V3z"/></svg>
                Purchase Requests
            </a>
            <a href="/procurement/pos" class="<?= nav_active('/procurement/pos', $path) ?>"><svg viewBox="0 0 24 24"><path d="M3 5h18v14H3zM5 7v10h14V7H5z"/></svg> Purchase Orders</a>
        <?php elseif ($role === 'supplier'): ?>
            <a href="/supplier/items" class="<?= nav_active('/supplier/items', $path) ?>"><svg viewBox="0 0 24 24"><path d="M3 5h18v14H3zM5 7v10h14V7H5z"/></svg> Items & Pricing</a>
            <a href="/supplier/packages" class="<?= nav_active('/supplier/packages', $path) ?>"><svg viewBox="0 0 24 24"><path d="M3 6l9-4 9 4-9 4-9-4zm0 6l9 4 9-4M3 18l9 4 9-4"/></svg> Package Deals</a>
            <a href="/supplier/pos" class="<?= nav_active('/supplier/pos', $path) ?>"><svg viewBox="0 0 24 24"><path d="M3 5h18v14H3zM5 7v10h14V7H5z"/></svg> Purchase Orders</a>
        <?php endif; ?>
        <?php if ($role !== 'guest'): ?>
            <a href="/inbox" class="<?= nav_active_many(['/inbox','/notifications'], $path) ?>">
                <svg viewBox="0 0 24 24"><path d="M12 22a2 2 0 0 0 2-2H10a2 2 0 0 0 2 2zm6-6v-5a6 6 0 1 0-12 0v5l-2 2v1h16v-1l-2-2z"/></svg>
                Inbox
                <?php if ($unreadCount > 0): ?>
                    <span style="margin-left:8px; display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 6px; border-radius:999px; background:#dc2626; color:#fff; font-size:12px; font-weight:700;"><?= (int)$unreadCount ?></span>
                <?php endif; ?>
            </a>
                <a href="/admin/messages" class="<?= nav_active('/admin/messages', $path) ?>">
                    <svg viewBox="0 0 24 24"><path d="M4 4h16v12H5.17L4 17.17V4zm2 2v8h12V6H6z"/></svg>
                    Messages
                </a>
        <?php endif; ?>
        <a href="/settings" class="<?= nav_active('/settings', $path) ?>"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 00.12-.64l-1.92-3.32a.5.5 0 00-.6-.22l-2.39.96a7.03 7.03 0 00-1.63-.94l-.36-2.54A.5.5 0 0013 1h-4a.5.5 0 00-.5.42l-.36 2.54c-.57.22-1.11.52-1.63.94l-2.39-.96a.5.5 0 00-.6.22L1.6 7.02a.5.5 0 00.12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L1.72 13.7a.5.5 0 00-.12.64l1.92 3.32c.14.24.44.34.7.22l2.39-.96c.52.42 1.06.76 1.63.98l.36 2.52c.04.25.25.44.5.44h4c.25 0 .46-.19.5-.44l.36-2.52c.57-.22 1.11-.56 1.63-.98l2.39.96c.26.12.56.02.7-.22l1.92-3.32a.5.5 0 00-.12-.64l-2.03-1.58zM11 9a3 3 0 110 6 3 3 0 010-6z"/></svg> Settings</a>
        <a href="/logout"><svg viewBox="0 0 24 24"><path d="M10 17l1.41-1.41L8.83 13H20v-2H8.83l2.58-2.59L10 7l-5 5 5 5zM4 19h6v2H4a2 2 0 01-2-2V5a2 2 0 012-2h6v2H4v14z"/></svg> Logout</a>
    </nav>
</aside>
<script>
// Sidebar dropdown toggle (scoped to this partial)
(function(){
    var group = document.querySelector('[data-nav-group]');
    if(!group) return;
    var toggle = group.querySelector('[data-nav-toggle]');
    var sub = group.querySelector('.nav-sub');
    var chev = group.querySelector('.chev');
    function setOpen(open){
        if(open){ group.classList.add('open'); sub.style.maxHeight = sub.scrollHeight + 'px'; if(chev){chev.style.transform='rotate(90deg)';} }
        else { group.classList.remove('open'); sub.style.maxHeight = '0px'; if(chev){chev.style.transform='rotate(0deg)';} }
    }
    // Initialize based on current page
    setOpen(group.classList.contains('active') || group.classList.contains('open'));
    toggle && toggle.addEventListener('click', function(){ setOpen(!group.classList.contains('open')); });
    // Recompute height on window resize for smoothness
    window.addEventListener('resize', function(){ if(group.classList.contains('open')){ sub.style.maxHeight = sub.scrollHeight + 'px'; }});
})();
</script>
