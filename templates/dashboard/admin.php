<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • Inventory</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <style>
        :root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#22c55e; }
        html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#22c55e; }
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .layout{ display:grid; grid-template-columns: 240px 1fr; min-height:100vh; }
        .sidebar{ background:#fff; border-right:1px solid var(--border); padding:18px 12px; position:sticky; top:0; height:100vh; }
        html[data-theme="dark"] .sidebar{ background:#0f172a; }
        .brand{ display:flex; align-items:center; gap:10px; font-weight:800; padding:6px 10px; }
        .nav{ margin-top:14px; display:flex; flex-direction:column; gap:6px; }
        .nav a{ display:flex; align-items:center; gap:10px; padding:10px 12px; color:var(--text); text-decoration:none; border-radius:10px; }
        .nav a:hover{ background:var(--bg); }
        .nav a.active{ background: color-mix(in oklab, var(--accent) 10%, transparent); color: var(--text); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); }
        .icon{ width:18px; height:18px; display:inline-flex; align-items:center; justify-content:center; }
        .content{ padding:18px 20px; }
        .topbar{ display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
        .search{ flex:1; max-width:520px; }
        .search input{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; font:inherit; }
        html[data-theme="dark"] .search input{ background:#0b0b0b; color:#e5e7eb; }
        .profile{ display:flex; align-items:center; gap:10px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; }
        .cards{ display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; }
        @media (max-width: 1100px){ .cards{ grid-template-columns: repeat(2, 1fr);} }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        .card .btn{ margin-top:8px; background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
        .tabs{ display:flex; gap:10px; margin:16px 0 8px; border-bottom:1px solid var(--border); }
        .tab{ padding:10px 12px; border-bottom:2px solid transparent; color:var(--muted); font-weight:700; text-decoration:none; }
        .tab.active{ color:var(--text); border-bottom-color:var(--accent); }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .badge{ padding:4px 8px; border-radius:999px; font-size:12px; border:1px solid var(--border); }
        .status-good{ background:#ecfdf5; color:#166534; border-color:#a7f3d0; }
        .status-repair{ background:#fffbeb; color:#92400e; border-color:#fde68a; }
        .status-repl{ background:#fef2f2; color:#991b1b; border-color:#fecaca; }
        .actions a{ text-decoration:none; color:var(--muted); margin-right:8px; }
        .muted{ color:var(--muted); }
        .righttools{ display:flex; align-items:center; gap:12px; }
        .toggle{ display:inline-flex; align-items:center; gap:6px; font-size:13px; color:var(--muted); }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">🏥 POCC</div>
        <nav class="nav">
            <a href="/dashboard" class="active"><span class="icon">📦</span> Inventory</a>
            <a href="#"><span class="icon">🏠</span> Dashboard</a>
            <a href="#"><span class="icon">🧾</span> Listings</a>
            <a href="#"><span class="icon">🧺</span> Orders</a>
            <a href="#"><span class="icon">💳</span> Payments</a>
            <a href="#"><span class="icon">🚚</span> Shipments</a>
            <a href="#"><span class="icon">🛒</span> Sales Channels</a>
            <a href="#"><span class="icon">📈</span> Reports</a>
            <a href="#"><span class="icon">🔔</span> Notifications</a>
            <a href="/logout"><span class="icon">↩️</span> Logout</a>
        </nav>
    </aside>
    <main class="content">
        <div class="topbar">
            <div class="search"><input placeholder="Search" /></div>
            <div class="righttools">
                <label class="toggle"><input type="checkbox" id="modeToggle"> Night</label>
                <div class="profile"><span class="muted">Admin</span> <span>🧑‍💻</span></div>
            </div>
        </div>

        <div class="h1">Inventory</div>
        <div class="cards">
            <div class="card"><div>🛍️</div><div class="muted">Create a new item</div><a class="btn" href="#" onclick="alert('Coming soon');return false;">New Item</a></div>
            <div class="card"><div>🛒🛒</div><div class="muted">Group items together</div><a class="btn" href="#" onclick="alert('Coming soon');return false;">New Item Groups</a></div>
            <div class="card"><div>🧩</div><div class="muted">Build composite items</div><a class="btn" href="#" onclick="alert('Coming soon');return false;">New Composite Items</a></div>
            <div class="card"><div>🔳</div><div class="muted">Generate barcodes</div><a class="btn" href="#" onclick="alert('Coming soon');return false;">Barcodes</a></div>
        </div>

        <div class="tabs">
            <a class="tab active" href="#">Items</a>
            <a class="tab" href="#" onclick="alert('Coming soon');return false;">Item Groups (Variants)</a>
            <a class="tab" href="#" onclick="alert('Coming soon');return false;">Price List</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:90px;">ID</th>
                        <th>Product Name</th>
                        <th style="width:100px;">Total QTY</th>
                        <th style="width:120px;">Buy Price</th>
                        <th style="width:120px;">Sell Price</th>
                        <th style="width:120px;">Location</th>
                        <th style="width:110px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): foreach ($items as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars(str_pad((string)$it['item_id'], 6, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($it['name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($it['quantity'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="muted">—</td>
                            <td class="muted">—</td>
                            <td><?= htmlspecialchars((string)($it['branch_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="actions">
                                <a href="#" title="View" onclick="alert('Coming soon');return false;">👁️</a>
                                <a href="#" title="Edit" onclick="alert('Coming soon');return false;">✏️</a>
                                <a href="#" title="More" onclick="alert('Coming soon');return false;">⋯</a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="muted">No items yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script>
    // Night mode toggle with localStorage
    const root = document.documentElement;
    const toggle = document.getElementById('modeToggle');
    const saved = localStorage.getItem('pocc_admin_theme');
    if (saved === 'dark') { root.setAttribute('data-theme','dark'); toggle.checked = true; }
    toggle?.addEventListener('change', () => {
        const mode = toggle.checked ? 'dark' : 'light';
        root.setAttribute('data-theme', mode);
        localStorage.setItem('pocc_admin_theme', mode);
    });
</script>
</body>
</html>
