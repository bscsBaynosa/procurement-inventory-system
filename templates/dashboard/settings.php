<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings</title>
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
    .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:20px; max-width:920px; }
        label{ display:block; font-weight:600; margin-bottom:6px; }
        input{ width:100%; padding:12px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; font:inherit; }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
        .row{ display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
        .toggle{ display:inline-flex; align-items:center; gap:8px; color:#64748b; }
    @media (max-width: 1024px){ .layout{ grid-template-columns:1fr; } .sidebar{ position:relative; height:auto; } .row{ grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
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
                <div class="row" style="margin-bottom:12px;">
                    <div>
                        <label>Username</label>
                        <input value="<?= htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" disabled />
                    </div>
                    <div>
                        <label>Full name</label>
                        <input name="full_name" value="<?= htmlspecialchars((string)($user['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required />
                    </div>
                </div>
                <div style="margin-top:10px; margin-bottom:12px;">
                    <label>Email</label>
                    <input name="email" type="email" value="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div style="margin-top:10px; margin-bottom:12px;">
                    <label>Change password (optional)</label>
                    <div class="row">
                        <div>
                            <label>Current password</label>
                            <input name="current_password" type="password" autocomplete="current-password" />
                        </div>
                        <div></div>
                        <div>
                            <label>New password</label>
                            <input name="new_password" type="password" autocomplete="new-password" />
                        </div>
                        <div>
                            <label>Re-type new password</label>
                            <input name="confirm_password" type="password" autocomplete="new-password" />
                        </div>
                    </div>
                    <div style="color:#64748b; font-size:12px; margin-top:6px;">To change your password, fill in all three fields.</div>
                </div>
                <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <span class="toggle" style="color:var(--text); font-weight:700;">Dark Mode</span>
                        <label class="switch"><input type="checkbox" class="theme-toggle" id="modeToggle"><span class="slider"></span></label>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <a class="btn" href="/logout" style="background:#0ea5e9;">Log out</a>
                        <button class="btn" type="submit">Save</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="card" style="margin-top:12px;">
            <h3 style="margin-top:0">Test email delivery</h3>
            <form method="POST" action="/settings/test-email" style="display:grid; gap:10px;">
                <div>
                    <label>Send to (default: your email)</label>
                    <input name="to" type="email" placeholder="you@example.com" />
                </div>
                <div>
                    <button class="btn" type="submit">Send test email</button>
                </div>
            </form>
            <p class="muted">Uses SMTP if configured (SMTP_HOST/PORT/SECURE/USER/PASS), otherwise falls back to mail().</p>
        </div>
    </main>
</div>
<script>
    bindThemeToggle('#modeToggle');
</script>
</body>
</html>
