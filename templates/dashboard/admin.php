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
    <aside class="sidebar">
    <div class="brand">Admin Control</div>
        <nav class="nav">
            <a href="/dashboard" class="active"><svg viewBox="0 0 24 24"><path d="M12 3l9 8h-3v9h-5v-6H11v6H6v-9H3z"/></svg> Dashboard</a>
            <a href="/admin/users"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zM8 11c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.67 0-8 1.34-8 4v2h10v-2c0-2.66-5.33-4-8-4zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.96 1.97 3.45v2h6v-2c0-2.66-5.33-4-8-4z"/></svg> Users</a>
            <a href="/admin/branches"><svg viewBox="0 0 24 24"><path d="M12 2l7 6v12H5V8l7-6zm0 2.2L7 8v10h10V8l-5-3.8z"/></svg> Branches</a>
            <a href="/admin/messages"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H5.17L4 17.17V4zm2 2v8h12V6H6z"/></svg> Messages</a>
            <a href="/settings"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 00.12-.64l-1.92-3.32a.5.5 0 00-.6-.22l-2.39.96a7.03 7.03 0 00-1.63-.94l-.36-2.54A.5.5 0 0013 1h-4a.5.5 0 00-.5.42l-.36 2.54c-.57.22-1.11.52-1.63.94l-2.39-.96a.5.5 0 00-.6.22L1.6 7.02a.5.5 0 00.12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L1.72 13.7a.5.5 0 00-.12.64l1.92 3.32c.14.24.44.34.7.22l2.39-.96c.52.42 1.06.76 1.63.98l.36 2.52c.04.25.25.44.5.44h4c.25 0 .46-.19.5-.44l.36-2.52c.57-.22 1.11-.56 1.63-.98l2.39.96c.26.12.56.02.7-.22l1.92-3.32a.5.5 0 00-.12-.64l-2.03-1.58zM11 9a3 3 0 110 6 3 3 0 010-6z"/></svg> Settings</a>
            <a href="/logout"><svg viewBox="0 0 24 24"><path d="M10 17l1.41-1.41L8.83 13H20v-2H8.83l2.58-2.59L10 7l-5 5 5 5zM4 19h6v2H4a2 2 0 01-2-2V5a2 2 0 012-2h6v2H4v14z"/></svg> Logout</a>
        </nav>
    </aside>
    <main class="content">
        <div class="topbar">
            <div class="greet">Hello, <?= htmlspecialchars((string)($me_name ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?>.</div>
            <div class="righttools">
                <a href="/settings" class="profile" title="Account settings"><span class="muted">Settings</span> <span>🧑‍💻</span></a>
            </div>
        </div>

        <div class="h1">Overview</div>
        <div class="cards">
            <div class="card"><div style="font-size:12px;color:var(--muted)">Users</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['users_total'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Active</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['users_active'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Managers</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['managers'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Custodians</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['custodians'] ?? 0) ?></div></div>
        </div>

        <div class="cards grid-3" style="margin-top:12px;">
            <div class="card"><div style="font-size:12px;color:var(--muted)">Branches</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['branches'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Pending Requests</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['requests']['pending'] ?? 0) ?></div></div>
            <div class="card"><div style="font-size:12px;color:var(--muted)">Inventory Items</div><div style="font-size:28px;font-weight:800;"><?= (int)($counts['inventory']['total'] ?? 0) ?></div></div>
        </div>

            <div class="h1" style="margin-top:16px;">Trends</div>
            <div class="cards grid-3">
                <div class="card">
                    <div style="font-size:12px;color:var(--muted)">Inventory Activity</div>
                    <?php $d = $series_inventory ?? []; $max = max(1, max($d ?: [0])); $n = max(1, count($d)); $w = 220; $h = 60; $step = ($n>1? $w/($n-1):$w); $pts=[]; for($i=0;$i<$n;$i++){ $x=$i*$step; $y=$h - (($d[$i]??0)/$max*$h); $pts[] = [$x,$y]; } function pathSmooth($pts){ if(count($pts)<2) return ''; $d='M'.$pts[0][0].','.$pts[0][1]; for($i=1;$i<count($pts);$i++){ $x=$pts[$i][0]; $y=$pts[$i][1]; $px=$pts[$i-1][0]; $py=$pts[$i-1][1]; $cx1=$px+($x-$px)/3; $cy1=$py; $cx2=$px+2*($x-$px)/3; $cy2=$y; $d.=' C'.$cx1.','.$cy1.' '.$cx2.','.$cy2.' '.$x.','.$y; } return $d; } $dPath = pathSmooth($pts); ?>
                    <svg class="spark" viewBox="0 0 220 60" preserveAspectRatio="none">
                        <path class="bg" d="M0,59 L220,59" />
                        <path d="<?= htmlspecialchars($dPath, ENT_QUOTES, 'UTF-8') ?>" />
                    </svg>
                </div>
                <div class="card">
                    <div style="font-size:12px;color:var(--muted)">Incoming Requests</div>
                    <?php $d = $series_incoming ?? []; $max = max(1, max($d ?: [0])); $n = max(1, count($d)); $w = 220; $h = 60; $step = ($n>1? $w/($n-1):$w); $pts=[]; for($i=0;$i<$n;$i++){ $x=$i*$step; $y=$h - (($d[$i]??0)/$max*$h); $pts[] = [$x,$y]; } $dPath = pathSmooth($pts); ?>
                    <svg class="spark" viewBox="0 0 220 60" preserveAspectRatio="none">
                        <path class="bg" d="M0,59 L220,59" />
                        <path d="<?= htmlspecialchars($dPath, ENT_QUOTES, 'UTF-8') ?>" />
                    </svg>
                </div>
                <div class="card">
                    <div style="font-size:12px;color:var(--muted)">Outgoing Purchase Orders</div>
                    <?php $d = $series_po ?? []; $max = max(1, max($d ?: [0])); $n = max(1, count($d)); $w = 220; $h = 60; $step = ($n>1? $w/($n-1):$w); $pts=[]; for($i=0;$i<$n;$i++){ $x=$i*$step; $y=$h - (($d[$i]??0)/$max*$h); $pts[] = [$x,$y]; } $dPath = pathSmooth($pts); ?>
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
                <thead><tr><th>ID</th><th>Item</th><th>Branch</th><th>Status</th><th>Created</th></tr></thead>
                <tbody id="rqBody">
                <?php if (!empty($recent)): foreach ($recent as $r): ?>
                    <tr data-status="<?= htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <td><?= (int)$r['request_id'] ?></td>
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
        // Client-side filter for recent requests
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
