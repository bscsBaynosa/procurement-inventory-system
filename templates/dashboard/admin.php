<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • Inventory</title>
    
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
    .icon{ width:18px; height:18px; display:inline-flex; align-items:center; justify-content:center; }
        .content{ padding:18px 20px; }
    .topbar{ display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
    .greet{ font-size:16px; font-weight:700; }
    .profile{ display:flex; align-items:center; gap:10px; text-decoration:none; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; }
        .cards{ display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; }
        @media (max-width: 1100px){ .cards{ grid-template-columns: repeat(2, 1fr);} }
    .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; transform:scale(.98); animation: pop .6s cubic-bezier(.22,.61,.36,1) both; }
        .card .btn{ margin-top:8px; background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
    @keyframes pop{ from{ opacity:0; transform: translateY(8px) scale(.98);} to{ opacity:1; transform: translateY(0) scale(1);} }
    .nav svg{ width:18px; height:18px; fill: var(--accent); }
    .grid-3{ display:grid; grid-template-columns: repeat(3, 1fr); gap:12px; }
    @media (max-width: 900px){ .layout{ grid-template-columns: 1fr; } .sidebar{ position:relative; height:auto;} .cards{ grid-template-columns: repeat(2,1fr);} .grid-3{ grid-template-columns: 1fr; } }
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
            .spark{ width:100%; height:80px; }
            .spark path{ stroke: var(--accent); fill: none; stroke-width: 2; }
            .spark .bg{ stroke: color-mix(in oklab, var(--accent) 18%, var(--border)); opacity:.5; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <?php
            $first = isset($me_first) && $me_first !== '' ? $me_first : (($me_name ?? '') ? explode(' ', (string)$me_name)[0] : 'User');
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
                <div class="greet" style="margin-right:8px;">Hello, <?= htmlspecialchars((string)$first, ENT_QUOTES, 'UTF-8') ?>.</div>
                <a href="/admin/messages" title="Messages" style="width:36px;height:36px;border-radius:999px;background:#1118270d;border:1px solid var(--border);display:inline-flex;align-items:center;justify-content:center;position:relative;text-decoration:none;color:var(--text);">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M4 4h16v12H5.17L4 17.17V4zm2 2v8h12V6H6z"/></svg>
                    <?php if ($unread > 0): ?><span style="position:absolute;top:2px;right:2px;width:10px;height:10px;border-radius:999px;background:#ef4444;border:2px solid var(--card);"></span><?php endif; ?>
                </a>
                <a href="/notifications" title="Notifications" style="width:36px;height:36px;border-radius:999px;background:#1118270d;border:1px solid var(--border);display:inline-flex;align-items:center;justify-content:center;position:relative;text-decoration:none;color:var(--text);">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 22a2 2 0 0 0 2-2H10a2 2 0 0 0 2 2zm6-6v-5a6 6 0 1 0-12 0v5l-2 2v1h16v-1l-2-2z"/></svg>
                    <?php if ($unread > 0): ?><span style="position:absolute;top:2px;right:2px;width:10px;height:10px;border-radius:999px;background:#ef4444;border:2px solid var(--card);"></span><?php endif; ?>
                </a>
                <a href="/settings" title="Settings" style="width:36px;height:36px;border-radius:999px;background:#1118270d;border:1px solid var(--border);display:inline-flex;align-items:center;justify-content:center;overflow:hidden;text-decoration:none;color:var(--text);">
                    <?php if ($avatarData !== ''): ?>
                        <img src="<?= htmlspecialchars($avatarData, ENT_QUOTES, 'UTF-8') ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;" />
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 12c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm0 2c-3.33 0-10 1.67-10 5v3h20v-3c0-3.33-6.67-5-10-5z"/></svg>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <div class="cards">
            <div class="card"><div style="font-size:12px;color:var(--muted)">Users</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['users_total'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Active</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['users_active'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Managers</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['managers'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Procurement</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['procurement'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Admin Assistants</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['admin_assistants'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Suppliers</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['suppliers'] ?? 0) ?></div></div>
        </div>

        <div class="cards grid-3" style="margin-top:12px;">
            <div class="card"><div style="font-size:12px;color:var(--muted)">Branches</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['branches'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Pending Requests</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['requests']['pending'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Inventory Items</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['inventory']['total'] ?? 0) ?></div></div>
        </div>

        <div style="display:flex; gap:8px; margin:10px 0 4px;">
            <a href="/admin/requests" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:10px;border:1px solid var(--border);text-decoration:none;color:var(--text);background:color-mix(in oklab, var(--accent) 8%, transparent);">Purchase Requests</a>
            <a href="/admin/requests/history" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:10px;border:1px solid var(--border);text-decoration:none;color:var(--text);">History</a>
        </div>

            <div class="h1" style="margin-top:16px;">Trends</div>
            <div class="cards grid-3">
                <div class="card">
                    <div style="font-size:12px;color:var(--muted)">Inventory Activity</div>
                    <?php
                        // Make sparkline generation compatible with older PHP versions (no short array syntax)
                        $d = isset($series_inventory) && is_array($series_inventory) ? $series_inventory : array();
                        $max = max(1, max(!empty($d) ? $d : array(0)));
                        $n = max(1, count($d));
                        $w = 220; $h = 60; $step = ($n > 1 ? $w/($n-1) : $w);
                        $pts = array();
                        for ($i = 0; $i < $n; $i++) {
                            $x = $i * $step;
                            $y = $h - ((isset($d[$i]) ? $d[$i] : 0) / $max * $h);
                            $pts[] = array($x, $y);
                        }
                        if (!function_exists('pathSmooth')) {
                            function pathSmooth($pts){
                                if(count($pts) < 2) return '';
                                $d = 'M' . $pts[0][0] . ',' . $pts[0][1];
                                for ($i = 1; $i < count($pts); $i++) {
                                    $x = $pts[$i][0];
                                    $y = $pts[$i][1];
                                    $px = $pts[$i-1][0];
                                    $py = $pts[$i-1][1];
                                    $cx1 = $px + ($x - $px) / 3; $cy1 = $py;
                                    $cx2 = $px + 2 * ($x - $px) / 3; $cy2 = $y;
                                    $d .= ' C' . $cx1 . ',' . $cy1 . ' ' . $cx2 . ',' . $cy2 . ' ' . $x . ',' . $y;
                                }
                                return $d;
                            }
                        }
                        $dPath = pathSmooth($pts);
                    ?>
                    <svg class="spark" viewBox="0 0 220 60" preserveAspectRatio="none">
                        <path class="bg" d="M0,59 L220,59" />
                        <path d="<?= htmlspecialchars($dPath, ENT_QUOTES, 'UTF-8') ?>" />
                    </svg>
                </div>
                <div class="card">
                    <div style="font-size:12px;color:var(--muted)">Incoming Requests</div>
                    <?php
                        $d = isset($series_incoming) && is_array($series_incoming) ? $series_incoming : array();
                        $max = max(1, max(!empty($d) ? $d : array(0)));
                        $n = max(1, count($d));
                        $w = 220; $h = 60; $step = ($n > 1 ? $w/($n-1) : $w);
                        $pts = array();
                        for ($i = 0; $i < $n; $i++) {
                            $x = $i * $step;
                            $y = $h - ((isset($d[$i]) ? $d[$i] : 0) / $max * $h);
                            $pts[] = array($x, $y);
                        }
                        $dPath = pathSmooth($pts);
                    ?>
                    <svg class="spark" viewBox="0 0 220 60" preserveAspectRatio="none">
                        <path class="bg" d="M0,59 L220,59" />
                        <path d="<?= htmlspecialchars($dPath, ENT_QUOTES, 'UTF-8') ?>" />
                    </svg>
                </div>
                <div class="card">
                    <div style="font-size:12px;color:var(--muted)">Outgoing Purchase Orders</div>
                    <?php
                        $d = isset($series_po) && is_array($series_po) ? $series_po : array();
                        $max = max(1, max(!empty($d) ? $d : array(0)));
                        $n = max(1, count($d));
                        $w = 220; $h = 60; $step = ($n > 1 ? $w/($n-1) : $w);
                        $pts = array();
                        for ($i = 0; $i < $n; $i++) {
                            $x = $i * $step;
                            $y = $h - ((isset($d[$i]) ? $d[$i] : 0) / $max * $h);
                            $pts[] = array($x, $y);
                        }
                        $dPath = pathSmooth($pts);
                    ?>
                    <svg class="spark" viewBox="0 0 220 60" preserveAspectRatio="none">
                        <path class="bg" d="M0,59 L220,59" />
                        <path d="<?= htmlspecialchars($dPath, ENT_QUOTES, 'UTF-8') ?>" />
                    </svg>
                </div>
            </div>

        <div class="card" style="margin-top:12px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                <div style="font-weight:700;">Recent requests</div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <a href="/admin/requests" class="btn" style="text-decoration:none;">Go to Purchase Requests</a>
                    <input id="rqSearch" placeholder="Search" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;"/>
                    <select id="rqFilter" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;">
                        <option value="">All</option>
                        <option>pending</option>
                        <option>approved</option>
                        <option>rejected</option>
                        <option>in_progress</option>
                        <option>completed</option>
                    </select>
                </div>
            </div>
            <table>
                <thead><tr><th>PR ID</th><th>Item</th><th>Branch</th><th>Status</th><th>Created</th></tr></thead>
                <tbody id="rqBody">
                <?php if (!empty($recent)): foreach ($recent as $r): ?>
                    <tr data-status="<?= htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <td class="mono"><?= htmlspecialchars(\App\Services\IdService::format('PR', (string)($r['pr_number'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['item_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['branch_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="muted">No recent requests.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script>
        (function(){
            var s = document.getElementById('rqSearch');
            var f = document.getElementById('rqFilter');
            var body = document.getElementById('rqBody');
            function apply(){
                var q = (s?.value || '').toLowerCase();
                var st = f?.value || '';
                Array.from(body.querySelectorAll('tr')).forEach(function(tr){
                    var text = tr.textContent.toLowerCase();
                    var okQ = !q || text.indexOf(q) !== -1;
                    var okS = !st || (tr.getAttribute('data-status') === st);
                    tr.style.display = (okQ && okS) ? '' : 'none';
                });
            }
            s?.addEventListener('input', apply);
            f?.addEventListener('change', apply);
        })();
</script>
</body>
</html>
