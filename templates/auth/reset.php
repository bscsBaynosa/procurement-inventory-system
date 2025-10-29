<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reset Password</title>
    <link rel="stylesheet" href="/css/main.css" />
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <style>
        body{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background:#0b0b0b; color:#e2e8f0; margin:0; }
        .wrap{ min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
        .card{ width:100%; max-width:520px; background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:18px; box-shadow: 0 30px 80px rgba(0,0,0,.35); }
        .inner{ background:linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.82)); border:1px solid rgba(15,23,42,.06); border-radius:12px; padding:16px; color:#0f172a; }
        label{ display:block; font-weight:700; margin:8px 0 6px 0; }
        input{ width:100%; height:48px; padding:12px 14px; border-radius:10px; border:1px solid #e5e7eb; }
        .btn{ height:48px; border:0; border-radius:10px; background:#2563eb; color:#fff; font-weight:800; padding:0 16px; width:100%; }
        .muted{ color:#64748b; }
        .error{ background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:10px; border-radius:10px; margin-bottom:8px; font-weight:700; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div style="color:#e5e7eb; font-weight:800; margin-bottom:8px;">Reset your password</div>
            <div class="inner">
                <?php if (!empty($error)): ?>
                    <div class="error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="POST" action="/auth/reset">
                    <input type="hidden" name="token" value="<?= htmlspecialchars((string)($token ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                    <label for="pw">New password</label>
                    <input id="pw" name="password" type="password" required />
                    <label for="pw2">Confirm password</label>
                    <input id="pw2" name="confirm" type="password" required />
                    <div style="margin-top:12px;">
                        <button class="btn" type="submit">Update password</button>
                    </div>
                </form>
                <p class="muted" style="margin-top:12px;">After updating, you can sign in using your new password.</p>
            </div>
        </div>
    </div>
</body>
</html>
