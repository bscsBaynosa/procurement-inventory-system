<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • Branches</title>
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
        .brand{ font-weight:800; padding:6px 10px; }
        .nav{ margin-top:14px; display:flex; flex-direction:column; gap:6px; }
    .nav a{ display:flex; align-items:center; gap:10px; padding:10px 12px; color:var(--text); text-decoration:none; border-radius:10px; }
        .nav a:hover{ background:var(--bg); }
        .nav a.active{ background: color-mix(in oklab, var(--accent) 10%, transparent); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); }
    .nav svg{ width:18px; height:18px; fill: var(--accent); }
        .content{ padding:18px 20px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        input{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; font:inherit; }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
    .grid{ display:grid; grid-template-columns: 1fr minmax(380px, 520px); gap:12px; }
        .row{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    @media (max-width: 1100px){ .grid{ grid-template-columns: 1fr; } .row{ grid-template-columns: 1fr; } }
    @media (max-width: 900px){ .layout{ grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
    <div class="brand">Admin Control</div>
        <nav class="nav">
            <a href="/dashboard"><svg viewBox="0 0 24 24"><path d="M12 3l9 8h-3v9h-5v-6H11v6H6v-9H3z"/></svg> Dashboard</a>
            <a href="/admin/users"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zM8 11c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.67 0-8 1.34-8 4v2h10v-2c0-2.66-5.33-4-8-4zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.96 1.97 3.45v2h6v-2c0-2.66-5.33-4-8-4z"/></svg> Users</a>
            <a href="/admin/branches" class="active"><svg viewBox="0 0 24 24"><path d="M12 2l7 6v12H5V8l7-6zm0 2.2L7 8v10h10V8l-5-3.8z"/></svg> Branches</a>
            <a href="/admin/messages"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H5.17L4 17.17V4zm2 2v8h12V6H6z"/></svg> Messages</a>
            <a href="/settings"><svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 00.12-.64l-1.92-3.32a.5.5 0 00-.6-.22l-2.39.96a7.03 7.03 0 00-1.63-.94l-.36-2.54A.5.5 0 0013 1h-4a.5.5 0 00-.5.42l-.36 2.54c-.57.22-1.11.52-1.63.94l-2.39-.96a.5.5 0 00-.6.22L1.6 7.02a.5.5 0 00.12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L1.72 13.7a.5.5 0 00-.12.64l1.92 3.32c.14.24.44.34.7.22l2.39-.96c.52.42 1.06.76 1.63.98l.36 2.52c.04.25.25.44.5.44h4c.25 0 .46-.19.5-.44l.36-2.52c.57-.22 1.11-.56 1.63-.98l2.39.96c.26.12.56.02.7-.22l1.92-3.32a.5.5 0 00-.12-.64l-2.03-1.58zM11 9a3 3 0 110 6 3 3 0 010-6z"/></svg> Settings</a>
        </nav>
    </aside>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Branches</h2>
        <div class="grid">
            <div class="card">
                <table>
                    <thead><tr><th>Code</th><th>Name</th><th>Address</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!empty($branches)): foreach ($branches as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$b['code'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= nl2br(htmlspecialchars((string)($b['address'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></td>
                            <td><?= !empty($b['is_active']) ? 'Active' : 'Disabled' ?></td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap">
                                    <a class="btn" href="/admin/branches?edit=<?= (int)$b['branch_id'] ?>" style="background:#10b981">Edit</a>
                                    <form method="POST" action="/admin/branches/delete" onsubmit="return confirm('Delete this branch?');">
                                        <input type="hidden" name="branch_id" value="<?= (int)$b['branch_id'] ?>">
                                        <button class="btn" type="submit" style="background:#ef4444">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" style="color:#64748b">No branches found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <?php if (!empty($editBranch)): ?>
                <h3 style="margin-top:0">Edit Branch</h3>
                <form method="POST" action="/admin/branches/update">
                    <input type="hidden" name="branch_id" value="<?= (int)$editBranch['branch_id'] ?>">
                    <div class="row">
                        <div>
                            <label>Code</label>
                            <input name="code" value="<?= htmlspecialchars((string)$editBranch['code'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div>
                            <label>Name</label>
                            <input name="name" value="<?= htmlspecialchars((string)$editBranch['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div style="margin-top:10px">
                        <label>Address</label>
                        <input name="address" value="<?= htmlspecialchars((string)($editBranch['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div style="margin-top:10px">
                        <label>Status</label>
                        <select name="is_active">
                            <option value="1" <?= !empty($editBranch['is_active'])?'selected':'' ?>>Active</option>
                            <option value="0" <?= empty($editBranch['is_active'])?'selected':'' ?>>Disabled</option>
                        </select>
                    </div>
                    <div style="margin-top:10px; display:flex; gap:8px">
                        <button class="btn" type="submit">Save changes</button>
                        <a class="btn" href="/admin/branches" style="background:#6b7280">Cancel</a>
                    </div>
                </form>
                <?php else: ?>
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
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
