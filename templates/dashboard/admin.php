<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Overview • Procurement & Inventory</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/main.css">
    <style>
        :root{
            --green: #22c55e; --green-700:#15803d; --green-900:#14532d;
            --surface: #ffffff; --surface-2:#f8fafc; --text:#0f172a; --muted:#64748b; --border:#e2e8f0;
        }
        html[data-theme="dark"]{
            --surface:#0b0b0b; --surface-2:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937;
        }
        body{ background: var(--surface-2); color: var(--text); font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
        .wrap{ max-width: 1100px; margin: 28px auto; padding: 0 16px; }
        .header{ display:flex; justify-content:space-between; align-items:center; margin-bottom: 14px; }
        .title{ font-size: 22px; font-weight: 800; }
        .toggle{ display:inline-flex; align-items:center; gap:8px; }
        .card{ background: var(--surface); border:1px solid var(--border); border-radius: 12px; padding: 14px; }
        .grid{ display:grid; gap:14px; }
        .grid.stats{ grid-template-columns: repeat(4, 1fr); }
        @media (max-width: 900px){ .grid.stats{ grid-template-columns: repeat(2, 1fr);} }
        .stat h4{ margin:0; font-weight:700; color:var(--muted); font-size:12px; }
        .stat .num{ font-size: 26px; font-weight:800; }
        .accent{ color: var(--green-700); }
        .badge{ padding:4px 8px; border-radius:999px; font-size:12px; border:1px solid var(--border); }
        .badge.pending{ background:#fff7ed; color:#9a3412; border-color:#fed7aa; }
        .badge.approved{ background:#ecfdf5; color:#166534; border-color:#a7f3d0; }
        .badge.rejected{ background:#fef2f2; color:#991b1b; border-color:#fecaca; }
        html[data-theme="dark"] .badge.pending{ background:#1f2937; color:#f59e0b; border-color:#374151; }
        html[data-theme="dark"] .badge.approved{ background:#052e16; color:#22c55e; border-color:#064e3b; }
        html[data-theme="dark"] .badge.rejected{ background:#3f1d1d; color:#f87171; border-color:#4b1e1e; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ padding:10px; border-bottom: 1px solid var(--border); text-align:left; }
        th{ color:var(--muted); font-weight:700; }
        .actions{ display:flex; gap:8px; }
        .btn{ display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:8px 12px; border-radius:10px; text-decoration:none; font-weight:700; border:1px solid var(--border); cursor:pointer; }
        .btn-primary{ background: var(--green); color:#fff; border-color: transparent; }
        .btn-outline{ background:transparent; color:var(--text); }
        .section-title{ display:flex; justify-content:space-between; align-items:center; margin:14px 0 8px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <div class="title">Admin Overview</div>
            <div class="toggle">
                <label for="modeToggle">Night mode</label>
                <input type="checkbox" id="modeToggle">
            </div>
        </div>

        <div class="grid stats">
            <div class="card stat">
                <h4>Users (active/total)</h4>
                <div class="num"><span class="accent"><?=(int)($counts['users_active']??0)?></span> / <?=(int)($counts['users_total']??0)?></div>
            </div>
            <div class="card stat">
                <h4>Branches</h4>
                <div class="num"><?=(int)($counts['branches']??0)?></div>
            </div>
            <div class="card stat">
                <h4>Inventory Items</h4>
                <div class="num"><?=(int)($counts['inventory']['total']??0)?></div>
            </div>
            <div class="card stat">
                <h4>Requests (Pending)</h4>
                <div class="num"><?=(int)($counts['requests']['pending']??0)?></div>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: 1.2fr .8fr; margin-top:14px;">
            <div class="card">
                <div class="section-title"><strong>Recent requests</strong></div>
                <table>
                    <thead>
                        <tr><th>ID</th><th>Item</th><th>Branch</th><th>Status</th><th>Created</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($recent)): foreach ($recent as $r): ?>
                        <tr>
                            <td><?=htmlspecialchars($r['request_id'])?></td>
                            <td><?=htmlspecialchars($r['item_name']??'—')?></td>
                            <td><?=htmlspecialchars($r['branch_name']??'—')?></td>
                            <td>
                                <?php $s = (string)($r['status']??''); $cls = 'badge ' . ($s==='approved'?'approved':($s==='rejected'?'rejected':'pending')); ?>
                                <span class="<?=$cls?>"><?=htmlspecialchars(ucfirst($s))?></span>
                            </td>
                            <td><?=htmlspecialchars(substr((string)$r['created_at'],0,16))?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5">No recent activity.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <div class="section-title"><strong>Quick admin</strong></div>
                <div class="actions">
                    <a class="btn btn-primary" href="#" onclick="alert('Coming soon: Manage Accounts UI');return false;">Manage accounts</a>
                    <a class="btn btn-outline" href="#" onclick="alert('Coming soon: Manage Branches UI');return false;">Manage branches</a>
                </div>
                <div style="margin-top:12px; color:var(--muted); font-size:14px;">
                    Admin has top-tier access for configuration and oversight. Procurement and inventory operations are view-only here.
                </div>
                <div style="margin-top:12px;">
                    <strong>Requests</strong>
                    <div style="display:flex; gap:8px; margin-top:6px; flex-wrap:wrap;">
                        <span class="badge pending">Pending: <?=(int)($counts['requests']['pending']??0)?></span>
                        <span class="badge approved">Approved: <?=(int)($counts['requests']['approved']??0)?></span>
                        <span class="badge rejected">Rejected: <?=(int)($counts['requests']['rejected']??0)?></span>
                        <span class="badge">In review: <?=(int)($counts['requests']['in_review']??0)?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Night mode toggle with localStorage
        const root = document.documentElement;
        const toggle = document.getElementById('modeToggle');
        const saved = localStorage.getItem('pocc_admin_theme');
        if (saved === 'dark') { root.setAttribute('data-theme','dark'); toggle.checked = true; }
        toggle.addEventListener('change', () => {
            const mode = toggle.checked ? 'dark' : 'light';
            root.setAttribute('data-theme', mode);
            localStorage.setItem('pocc_admin_theme', mode);
        });
    </script>
</body>
</html>
