<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • Users</title>
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
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; }
        .grid{ display:grid; grid-template-columns: 1fr 360px; gap:12px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .muted{ color:var(--muted); }
        .ok{ background:#ecfdf5; color:#166534; padding:10px 12px; border:1px solid #a7f3d0; border-radius:10px; margin-bottom:10px; }
        .err{ background:#fef2f2; color:#991b1b; padding:10px 12px; border:1px solid #fecaca; border-radius:10px; margin-bottom:10px; }
        input, select{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; font:inherit; }
        html[data-theme="dark"] input, html[data-theme="dark"] select{ background:#0b0b0b; color:#e5e7eb; }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
        .row{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
        .row3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; }
        .mb8{ margin-bottom:8px; }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">🏥 POCC</div>
        <nav class="nav">
            <a href="/dashboard"><span>📊</span> Dashboard</a>
            <a href="/admin/users" class="active"><span>👥</span> Users</a>
            <a href="/logout"><span>↩️</span> Logout</a>
        </nav>
    </aside>
    <main class="content">
        <div class="h1">Users</div>
        <div class="grid">
            <div class="card">
                <?php if (!empty($created)): ?>
                <div class="ok">User created successfully.</div>
                <?php endif; ?>
                <?php if (!empty($error)): ?>
                <div class="err">Error: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Branch</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($users)): foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$u['user_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$u['role'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($u['email'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($u['branch_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($u['is_active']) ? 'Active' : 'Disabled' ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="muted">No users yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h3 style="margin-top:0">Create User</h3>
                <form method="POST" action="/admin/users">
                    <div class="row mb8">
                        <div>
                            <label>Username</label>
                            <input name="username" required>
                        </div>
                        <div>
                            <label>Full name</label>
                            <input name="full_name" required>
                        </div>
                    </div>
                    <div class="row mb8">
                        <div>
                            <label>Email</label>
                            <input name="email" type="email">
                        </div>
                        <div>
                            <label>Password</label>
                            <input name="password" type="password" required>
                        </div>
                    </div>
                    <div class="row3 mb8">
                        <div>
                            <label>Role</label>
                            <select name="role" required>
                                <option value="custodian">Custodian</option>
                                <option value="procurement_manager">Procurement manager</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div>
                            <label>Branch</label>
                            <select name="branch_id">
                                <option value="">— None —</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?= (int)$b['branch_id'] ?>"><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>&nbsp;</label>
                            <button class="btn" type="submit" style="width:100%">Create</button>
                        </div>
                    </div>
                </form>
                <p class="muted">New users are created active. You can assign branches later by editing directly in the database if needed.</p>
            </div>
        </div>
    </main>
</div>
</body>
</html>
