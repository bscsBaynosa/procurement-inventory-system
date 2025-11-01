<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Assistant • Dashboard</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <?php require __DIR__ . '/../layouts/_theme.php'; ?>
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
        .nav svg{ width:18px; height:18px; fill: var(--accent); }
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; }
    .cards{ display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; }
    .grid{ display:grid; grid-template-columns: repeat(auto-fill,minmax(260px,1fr)); gap:12px; margin-bottom:14px; }
    .card h3{ margin:0 0 6px; font-size:14px; color:var(--muted); font-weight:600; }
    .stats{ display:flex; gap:8px; flex-wrap:wrap; font-size:12px; color:var(--muted); }
    .pill{ background:color-mix(in oklab, var(--accent) 12%, transparent); color:var(--text); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); padding:4px 8px; border-radius:999px; }
        @media (max-width: 1100px){ .cards{ grid-template-columns: repeat(2, 1fr);} }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        .muted{ color:var(--muted); }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .badge{ padding:4px 8px; border-radius:999px; font-size:12px; border:1px solid var(--border); }
        .status-good{ background:#ecfdf5; color:#166534; border-color:#a7f3d0; }
        .status-repair{ background:#fffbeb; color:#92400e; border-color:#fde68a; }
        .status-repl{ background:#fef2f2; color:#991b1b; border-color:#fecaca; }
        .topbar{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
        .righttools{ display:flex; align-items:center; gap:8px; }
        .righttools .circle{ width:40px; height:40px; border-radius:999px; background:#1118270d; border:1px solid var(--border); display:inline-flex; align-items:center; justify-content:center; position:relative; text-decoration:none; overflow:hidden; }
        .righttools .dot{ position:absolute; top:2px; right:2px; width:10px; height:10px; border-radius:999px; background:#ef4444; border:2px solid #fff; }
    .righttools .greet{ margin-right:8px; white-space:nowrap; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
    <?php
        $first = isset($me_first) && $me_first !== '' ? $me_first : (isset($_SESSION['full_name']) ? explode(' ', (string)$_SESSION['full_name'])[0] : 'User');
        $unread = (int)($unread_count ?? 0);
        $avatarData = '';
        if (!empty($avatar_path) && is_file($avatar_path)) {
            $bin = @file_get_contents($avatar_path);
            if ($bin !== false) { $avatarData = 'data:image/*;base64,' . base64_encode($bin); }
        }
    ?>
    <div class="topbar">
        <div class="h1" style="margin:0;">Overview</div>
        <div class="righttools">
            <span class="greet">Hello, <?= htmlspecialchars((string)$first, ENT_QUOTES, 'UTF-8') ?>.</span>
            <a href="/admin/messages" title="Messages" class="circle">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M4 4h16v12H5.17L4 17.17V4zm2 2v8h12V6H6z"/></svg>
                <?php if ($unread > 0): ?><span class="dot"></span><?php endif; ?>
            </a>
            <a href="/notifications" title="Notifications" class="circle">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 22a2 2 0 0 0 2-2H10a2 2 0 0 0 2 2zm6-6v-5a6 6 0 1 0-12 0v5l-2 2v1h16v-1l-2-2z"/></svg>
                <?php if ($unread > 0): ?><span class="dot"></span><?php endif; ?>
            </a>
            <a href="/settings" title="Settings" class="circle">
                <?php if ($avatarData !== ''): ?>
                    <img src="<?= htmlspecialchars($avatarData, ENT_QUOTES, 'UTF-8') ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;" />
                <?php else: ?>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 12c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm0 2c-3.33 0-10 1.67-10 5v3h20v-3c0-3.33-6.67-5-10-5z"/></svg>
                <?php endif; ?>
            </a>
        </div>
    </div>
    <?php if (isset($branch_name) && $branch_name): ?>
        <div class="muted" style="margin-top:-6px; margin-bottom:12px;">Branch: <?= htmlspecialchars((string)$branch_name, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
        <div class="cards">
            <div class="card"><div class="muted" style="font-size:12px;">Good</div><div style="font-size:28px;font-weight:800;"><?= (int)($inventoryStats['good'] ?? 0) ?></div></div>
            <div class="card"><div class="muted" style="font-size:12px;">For Repair</div><div style="font-size:28px;font-weight:800;"><?= (int)($inventoryStats['for_repair'] ?? 0) ?></div></div>
            <div class="card"><div class="muted" style="font-size:12px;">For Replacement</div><div style="font-size:28px;font-weight:800;"><?= (int)($inventoryStats['for_replacement'] ?? 0) ?></div></div>
            <div class="card"><div class="muted" style="font-size:12px;">Total Items</div><div style="font-size:28px;font-weight:800;"><?= (int)($inventoryStats['total'] ?? 0) ?></div></div>
        </div>

        <?php $catStats = $categoryStats ?? []; if (!empty($catStats)): ?>
        <div class="h1" style="margin-top:16px;">By Category</div>
        <div class="grid">
            <?php foreach ($catStats as $row): ?>
                <div class="card">
                    <h3><?= htmlspecialchars((string)($row['category'] ?? 'Category'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <div class="stats">
                        <span class="pill">Total: <?= (int)($row['total'] ?? 0) ?></span>
                        <span class="pill">Good: <?= (int)($row['good'] ?? 0) ?></span>
                        <span class="pill">For Repair: <?= (int)($row['for_repair'] ?? 0) ?></span>
                        <span class="pill">For Replacement: <?= (int)($row['for_replacement'] ?? 0) ?></span>
                        <span class="pill">Retired: <?= (int)($row['retired'] ?? 0) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th style="text-align:right;">Good</th>
                    <th style="text-align:right;">For Repair</th>
                    <th style="text-align:right;">For Replacement</th>
                    <th style="text-align:right;">Retired</th>
                    <th style="text-align:right;">Total</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($catStats as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string)($row['category'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="text-align:right;"><span class="badge status-good"><?= (int)($row['good'] ?? 0) ?></span></td>
                    <td style="text-align:right;"><span class="badge status-repair"><?= (int)($row['for_repair'] ?? 0) ?></span></td>
                    <td style="text-align:right;"><span class="badge status-repl"><?= (int)($row['for_replacement'] ?? 0) ?></span></td>
                    <td style="text-align:right;"><span class="badge"><?= (int)($row['retired'] ?? 0) ?></span></td>
                    <td style="text-align:right;"><strong><?= (int)($row['total'] ?? 0) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="h1" style="margin-top:16px;">Pending Requests</div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Item</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($pendingRequests)): foreach ($pendingRequests as $r): ?>
                <tr>
                    <td><?= (int)$r['request_id'] ?></td>
                    <td><?= htmlspecialchars((string)($r['item_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" class="muted">No pending requests.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </main>
</div>
</body>
</html>