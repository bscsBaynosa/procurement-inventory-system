<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Create Account</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/main.css" />
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <style>
        :root {
            --bg-gradient: radial-gradient(1000px 600px at 15% 10%, #1b6b39 0%, #0d4f2a 35%, #062e19 60%, #0b0b0b 100%);
            --accent: #dc2626; /* match Sign in accent (admin/red) */
        }
        body{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background: var(--bg-gradient); color:#e5e7eb; min-height:100vh; }
        .hero { padding: 36px 16px; display:flex; align-items:center; justify-content:center; min-height: 100vh; }
        .hero-inner { max-width: 720px; width:100%; background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)); border:1px solid rgba(255,255,255,.08); border-radius:16px; padding: 22px; box-shadow: 0 30px 80px rgba(0,0,0,.35); }

        .signin-card { background: rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-radius: 14px; padding: 18px; color:#0f172a; display:flex; flex-direction:column; }
        .signin-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 8px; }
        .signin-title { color:#f8fafc; font-weight:800; font-size: 18px; }
        .signin-sub { color:#cbd5e1; font-size: 13px; }
        .signin { background: linear-gradient(180deg, rgba(255,255,255,.85), rgba(255,255,255,.75)); border:1px solid rgba(15,23,42,.06); border-radius: 12px; padding: 16px; display:flex; flex-direction:column; overflow:auto; max-height: 70vh; }

        .form-group { margin-bottom: 10px; }
        .form-group label { display:block; font-weight:600; margin-bottom:8px; color:#111827; }
        .form-group input, .form-group select { width:100%; height:48px; padding:12px 14px; border:1px solid #e5e7eb; border-radius:10px; font-size:16px; font-family: inherit; background:#fff; color:#111; }

        .grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
        @media (max-width: 760px){ .grid{ grid-template-columns: 1fr; } }

        .btn { display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border-radius:10px; text-decoration:none; font-weight:700; border:0; cursor:pointer; transition: transform .12s ease, filter .2s ease, box-shadow .2s ease; min-width:160px; height:48px; font-size:16px; }
        .btn:active { transform: translateY(1px); }
        .btn-primary { background: var(--accent); color:#fff; box-shadow: 0 10px 30px rgba(220,38,38,.35); width:100%; }
        .link { color:#2563eb; text-decoration:none; font-weight:700; }
    </style>
    </head>
<body>
    <section class="hero">
        <div class="hero-inner">
            <div class="signin-card">
                <div class="signin-header">
                    <div>
                        <div class="signin-title">Create account</div>
                        <div class="signin-sub">Enter your details to continue.</div>
                    </div>
                    <a href="/" class="link">Back to sign in</a>
                </div>
                <div class="signin">
                    <?php if (!empty($error)): ?>
                        <div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px;border-radius:10px;margin-bottom:8px;font-weight:600;">
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($success)): ?>
                        <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin-bottom:8px;font-weight:600;">
                            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="/signup">
                        <div class="grid">
                            <div class="form-group">
                                <label for="company">Company Name</label>
                                <input id="company" name="company" required />
                            </div>
                            <div class="form-group">
                                <label for="category">Category</label>
                                <input id="category" name="category" placeholder="e.g., Office Supplies" required />
                            </div>
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input id="username" name="username" required />
                            </div>
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input id="email" name="email" type="email" required />
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label for="contact">Contact Number (optional)</label>
                                <input id="contact" name="contact" />
                            </div>
                        </div>
                        <div style="margin-top:14px;display:flex;gap:10px;align-items:center;justify-content:center;">
                            <button class="btn btn-primary" type="submit">Sign Up</button>
                        </div>
                        <div style="text-align:center;margin-top:14px;">
                            <a href="/" class="link">Already have an account? Sign in</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
    <script>
        document.body.setAttribute('data-role', 'admin');
    </script>
</body>
</html>
