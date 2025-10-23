<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages</title>
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
        .grid{ display:grid; grid-template-columns: 1fr 420px; gap:12px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        textarea, input, select{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; font:inherit; }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
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
            <a href="/admin/messages" class="active">🟢 Messages</a>
            <a href="/settings">🟢 Settings</a>
            <a href="/logout">🟢 Logout</a>
        </nav>
    </aside>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Messages</h2>
        <div class="grid">
            <div class="card">
                <table>
                    <thead><tr><th>From</th><th>Subject</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php if (!empty($inbox)): foreach ($inbox as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$m['from_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$m['subject'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$m['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="3" style="color:#64748b">No messages.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h3 style="margin-top:0">Send Message</h3>
                <form method="POST" action="/admin/messages">
                    <div style="margin-bottom:8px;">
                        <label>To</label>
                        <select name="to" required>
                            <option value="">— Select recipient —</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int)$u['user_id'] ?>"><?= htmlspecialchars($u['full_name'] . ' (' . $u['role'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:8px;">
                        <label>Subject</label>
                        <input name="subject" required />
                    </div>
                    <div style="margin-bottom:8px;">
                        <label>Message</label>
                        <textarea name="body" rows="6" required></textarea>
                    </div>
                    <button class="btn" type="submit">Send</button>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
