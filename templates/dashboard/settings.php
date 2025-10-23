<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings</title>
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
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; max-width:720px; }
        input{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; font:inherit; }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
        .row{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
        .toggle{ display:flex; align-items:center; gap:6px; color:#64748b; }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">🟢 POCC</div>
        <nav class="nav">
            <a href="/dashboard">🟢 Dashboard</a>
            <a href="/admin/users">🟢 Users</a>
            <a href="/admin/branches">🟢 Branches</a>
            <a href="/admin/messages">🟢 Messages</a>
            <a href="/settings" class="active">🟢 Settings</a>
            <a href="/logout">🟢 Logout</a>
        </nav>
    </aside>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Account Settings</h2>
        <?php if (!empty($_GET['saved'])): ?>
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#166534;padding:10px;border-radius:10px;margin-bottom:8px;">Saved.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['error'])): ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px;border-radius:10px;margin-bottom:8px;">Error: <?= htmlspecialchars((string)$_GET['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="card">
            <form method="POST" action="/settings">
                <div class="row">
                    <div>
                        <label>Username</label>
                        <input value="<?= htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" disabled />
                    </div>
                    <div>
                        <label>Full name</label>
                        <input name="full_name" value="<?= htmlspecialchars((string)($user['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required />
                    </div>
                </div>
                <div style="margin-top:10px">
                    <label>Email</label>
                    <input name="email" type="email" value="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div style="margin-top:10px">
                    <label>Change password (optional)</label>
                    <input name="password" type="password" />
                </div>
                <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;">
                    <label class="toggle"><input type="checkbox" id="modeToggle"> Dark mode</label>
                    <button class="btn" type="submit">Save</button>
                </div>
            </form>
        </div>
    </main>
</div>
<script>
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
