<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • Branches</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <style>
        :root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#22c55e; }
        html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#22c55e; }
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .layout{ display:grid; grid-template-columns: 240px 1fr; min-height:100vh; }
        .sidebar{ background:#fff; border-right:1px solid var(--border); padding:18px 12px; position:sticky; top:0; height:100vh; }
        html[data-theme="dark"] .sidebar{ background:#0f172a; }
        .brand{ font-weight:800; padding:6px 10px; }
        .nav{ margin-top:14px; display:flex; flex-direction:column; gap:6px; }
        .nav a{ padding:10px 12px; color:var(--text); text-decoration:none; border-radius:10px; }
        .nav a:hover{ background:var(--bg); }
        .nav a.active{ background: color-mix(in oklab, var(--accent) 10%, transparent); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); }
        .content{ padding:18px 20px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        input{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; font:inherit; }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
        .grid{ display:grid; grid-template-columns: 1fr 360px; gap:12px; }
        .row{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">🟢 POCC</div>
        <nav class="nav">
            <a href="/dashboard">🟢 Dashboard</a>
            <a href="/admin/users">🟢 Users</a>
            <a href="/admin/branches" class="active">🟢 Branches</a>
            <a href="/admin/messages">🟢 Messages</a>
            <a href="/settings">🟢 Settings</a>
            <a href="/logout">🟢 Logout</a>
        </nav>
    </aside>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Branches</h2>
        <div class="grid">
            <div class="card">
                <table>
                    <thead><tr><th>Code</th><th>Name</th><th>Address</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!empty($branches)): foreach ($branches as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$b['code'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($b['address'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($b['is_active']) ? 'Active' : 'Disabled' ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" style="color:#64748b">No branches found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h3 style="margin-top:0">Add Branch</h3>
                <form method="POST" action="/admin/branches">
                    <div class="row">
                        <div>
                            <label>Code</label>
                            <input name="code" required>
                        </div>
                        <div>
                            <label>Name</label>
                            <input name="name" required>
                        </div>
                    </div>
                    <div style="margin-top:10px">
                        <label>Address</label>
                        <input name="address">
                    </div>
                    <div style="margin-top:10px">
                        <button class="btn" type="submit">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
