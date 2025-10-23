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
    .nav svg{ width:18px; height:18px; fill: var(--accent); }
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
        <div class="brand">🟢 POCC</div>
        <nav class="nav">
            <a href="/dashboard"><svg viewBox="0 0 24 24"><path d="M12 3l9 8h-3v9h-5v-6H11v6H6v-9H3z"/></svg> Dashboard</a>
            <a href="/admin/users" class="active"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zM8 11c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.67 0-8 1.34-8 4v2h10v-2c0-2.66-5.33-4-8-4zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.96 1.97 3.45v2h6v-2c0-2.66-5.33-4-8-4z"/></svg> Users</a>
            <a href="/admin/branches"><svg viewBox="0 0 24 24"><path d="M12 2l7 6v12H5V8l7-6zm0 2.2L7 8v10h10V8l-5-3.8z"/></svg> Branches</a>
            <a href="/admin/messages"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H5.17L4 17.17V4zm2 2v8h12V6H6z"/></svg> Messages</a>
            <a href="/settings"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 00.12-.64l-1.92-3.32a.5.5 0 00-.6-.22l-2.39.96a7.03 7.03 0 00-1.63-.94l-.36-2.54A.5.5 0 0013 1h-4a.5.5 0 00-.5.42l-.36 2.54c-.57.22-1.11.52-1.63.94l-2.39-.96a.5.5 0 00-.6.22L1.6 7.02a.5.5 0 00.12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L1.72 13.7a.5.5 0 00-.12.64l1.92 3.32c.14.24.44.34.7.22l2.39-.96c.52.42 1.06.76 1.63.98l.36 2.52c.04.25.25.44.5.44h4c.25 0 .46-.19.5-.44l.36-2.52c.57-.22 1.11-.56 1.63-.98l2.39.96c.26.12.56.02.7-.22l1.92-3.32a.5.5 0 00-.12-.64l-2.03-1.58zM11 9a3 3 0 110 6 3 3 0 010-6z"/></svg> Settings</a>
            <a href="/logout"><svg viewBox="0 0 24 24"><path d="M10 17l1.41-1.41L8.83 13H20v-2H8.83l2.58-2.59L10 7l-5 5 5 5zM4 19h6v2H4a2 2 0 01-2-2V5a2 2 0 012-2h6v2H4v14z"/></svg> Logout</a>
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
