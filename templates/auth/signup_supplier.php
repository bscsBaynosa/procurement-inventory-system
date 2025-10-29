<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Create Account</title>
    <link rel="stylesheet" href="/css/main.css" />
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <style>
        body{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background:#0b0b0b; color:#e5e7eb; }
        .wrap{ max-width:880px; margin:40px auto; }
        .card{ background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14); border-radius:14px; padding:18px; box-shadow: 0 30px 80px rgba(0,0,0,.35); }
        .head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
        .title{ font-weight:800; font-size:22px; }
        label{ display:block; margin:8px 0 6px; color:#cbd5e1; }
        input, select{ width:100%; padding:12px 14px; border:1px solid #334155; border-radius:10px; background:#0f172a; color:#e2e8f0; }
        .grid{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
        .row{ display:flex; gap:12px; }
        .btn{ background:#22c55e; color:#fff; border:0; padding:12px 16px; border-radius:10px; font-weight:700; }
        .muted{ color:#94a3b8; }
        @media (max-width: 860px){ .grid{ grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap card">
    <div class="head">
        <div class="title">Create your account</div>
        <a href="/" class="muted" style="text-decoration:none;">Back to sign in</a>
    </div>
    <?php if (!empty($error)): ?>
        <div style="background:#7f1d1d;color:#fecaca;border:1px solid #ef4444;padding:10px;border-radius:10px; margin-bottom:10px;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div style="background:#064e3b;color:#a7f3d0;border:1px solid #10b981;padding:10px;border-radius:10px; margin-bottom:10px;"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="POST" action="/signup">
        <div class="grid">
            <div>
                <label>Company Name</label>
                <input name="company" required />
            </div>
            <div>
                <label>Category</label>
                <input name="category" placeholder="e.g., Office Supplies" required />
            </div>
            <div>
                <label>Username</label>
                <input name="username" required />
            </div>
            <div>
                <label>Email</label>
                <input name="email" type="email" required />
            </div>
            <div style="grid-column: 1 / -1;">
                <label>Contact Number (optional)</label>
                <input name="contact" />
            </div>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;align-items:center;justify-content:flex-end;">
            <button class="btn" type="submit">Sign Up</button>
        </div>
    </form>
</div>
</body>
</html>
