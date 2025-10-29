<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Supplier Signup</title>
    <link rel="stylesheet" href="/css/main.css" />
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <style>
        body{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background:#0b0b0b; color:#e5e7eb; }
        .wrap{ max-width:700px; margin:40px auto; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); border-radius:14px; padding:18px; }
        label{ display:block; margin:8px 0 6px; color:#cbd5e1; }
        input, select{ width:100%; padding:10px 12px; border:1px solid #334155; border-radius:10px; background:#0f172a; color:#e2e8f0; }
        .row{ display:flex; gap:10px; }
        .btn{ background:#22c55e; color:#fff; border:0; padding:10px 14px; border-radius:10px; font-weight:700; }
        .muted{ color:#94a3b8; }
    </style>
</head>
<body>
<div class="wrap">
    <h2 style="margin:0 0 8px 0;">Supplier Signup</h2>
    <p class="muted" style="margin:0 0 12px 0;">Create your supplier account. A temporary password will be emailed to you.</p>
    <?php if (!empty($error)): ?>
        <div style="background:#7f1d1d;color:#fecaca;border:1px solid #ef4444;padding:10px;border-radius:10px; margin-bottom:10px;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div style="background:#064e3b;color:#a7f3d0;border:1px solid #10b981;padding:10px;border-radius:10px; margin-bottom:10px;"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="POST" action="/signup">
        <label>Company Name</label>
        <input name="company" required />
        <label>Category</label>
        <input name="category" placeholder="e.g., Office Supplies" required />
        <div class="row">
            <div style="flex:1;">
                <label>Username</label>
                <input name="username" required />
            </div>
            <div style="flex:1;">
                <label>Email</label>
                <input name="email" type="email" required />
            </div>
        </div>
        <label>Contact Number (optional)</label>
        <input name="contact" />
        <div style="margin-top:12px;display:flex;gap:10px;align-items:center;">
            <button class="btn" type="submit">Create Account</button>
            <a class="muted" href="/">Back to Sign in</a>
        </div>
    </form>
</div>
</body>
</html>
