<?php /* Favicon include centralized */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Procurement & Inventory System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/main.css" />
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <style>
        /* Theme variables */
        :root {
            --bg-gradient: radial-gradient(1000px 600px at 15% 10%, #1b6b39 0%, #0d4f2a 35%, #062e19 60%, #0b0b0b 100%);
            --surface: #ffffff;
            --text: #0f172a;
            --muted: #94a3b8;
            --accent: #2563eb;   /* default accent (blue for procurement manager) */
            --accent-600: #1e4bb5;
        }
        /* Role themes change only the accent; background remains green/black gradient */
        body[data-role="procurement_manager"] { --accent:#2563eb; --accent-600:#1e4bb5; }
        body[data-role="custodian"] { --accent:#ea7a17; --accent-600:#c86613; }
        body[data-role="admin"] { --accent:#dc2626; --accent-600:#b91c1c; }

    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg-gradient); color: var(--text); min-height:100vh; }
        .navbar { display:flex; align-items:center; justify-content:space-between; padding: 12px 22px; background: rgba(15, 23, 42, .15); backdrop-filter: blur(6px); position:sticky; top:0; z-index:10; border-bottom: 1px solid rgba(255,255,255,.06); }
        .brand { display:flex; align-items:center; gap:10px; font-weight:800; color:#e2e8f0; }
        .brand small { display:block; font-weight:600; color:#a7f3d0; }
        .nav-links { display:flex; align-items:center; gap: 12px; }
        .nav-links a { color:#e5e7eb; text-decoration:none; margin-left:0; padding:8px 12px; font-weight:600; opacity:.9; transition:opacity .2s ease; border-radius:10px; }
        .nav-links a:hover { opacity:1; background: rgba(255,255,255,.06); }
        .menu-toggle { display:none; background:transparent; color:#e5e7eb; border:1px solid rgba(255,255,255,.25); padding:8px 10px; border-radius:10px; font-weight:700; }
        @media (max-width: 760px){
            .menu-toggle { display:inline-flex; }
            .nav-links { position: absolute; top:56px; right:12px; left:12px; flex-direction:column; background: rgba(2,6,23,.85); border:1px solid rgba(255,255,255,.08); padding:12px; border-radius:12px; display:none; }
            .nav-links.open { display:flex; }
            .nav-links a { width:100%; }
        }

    .hero { padding: 36px 22px; display:flex; align-items:center; justify-content:center; min-height: calc(100vh - 80px); }
    .hero-inner { max-width: 1100px; width: 100%; margin: 0 auto; background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)); border:1px solid rgba(255,255,255,.08); border-radius:16px; padding: 28px; display:grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items:start; box-shadow: 0 30px 80px rgba(0,0,0,.35); }
    @media (max-width: 900px) { 
        .hero { display:block; min-height:auto; padding: 22px 16px; }
        .hero-inner { grid-template-columns: 1fr; }
        .right-col { order: -1; } /* show sign-in first on phones */
        .left-col { padding:16px; }
    }

    .left-col { display:flex; flex-direction:column; justify-content:center; gap: 14px; padding:24px; background: linear-gradient(160deg, rgba(37,99,235,0.12), rgba(6,95,70,0.10)); border: 1px solid rgba(255,255,255,.10); border-radius: 12px; }

    .cta { display:flex; gap:10px; }
        .btn { display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border-radius:10px; text-decoration:none; font-weight:700; border:0; cursor:pointer; transition: transform .12s ease, filter .2s ease, box-shadow .2s ease; }
        .btn:active { transform: translateY(1px); }
        .btn-primary { background: var(--accent); color:#fff; box-shadow: 0 10px 30px color-mix(in oklab, var(--accent) 50%, #000 50%); }
        .btn-primary:hover { filter: brightness(1.05); box-shadow: 0 14px 36px color-mix(in oklab, var(--accent) 60%, #000 40%); }
        .btn-outline { background: transparent; color: #e5e7eb; border:2px solid rgba(255,255,255,.7); }
        .btn-outline:hover { background: rgba(255,255,255,.08); }

    .headline { color:#f1f5f9; font-size: clamp(32px, 5vw, 64px); line-height:1.05; margin: 0 0 10px 0; font-weight:800; text-shadow: 0 10px 30px rgba(0,0,0,.35); }
    .subhead { color: #cbd5e1; font-size: 15px; max-width: 640px; }

        /* Inline sign-in glass card */
    .signin-card { background: rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-radius: 14px; padding: 18px; color:#0f172a; display:flex; flex-direction:column; }
        .signin-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 8px; }
        .signin-title { color:#f8fafc; font-weight:800; font-size: 18px; }
        .signin-sub { color:#cbd5e1; font-size: 13px; }
    .signin { background: linear-gradient(180deg, rgba(255,255,255,.85), rgba(255,255,255,.75)); border:1px solid rgba(15,23,42,.06); border-radius: 12px; padding: 16px; display:flex; flex-direction:column; overflow:hidden; }
        .form-row { display:flex; flex-direction:column; gap:12px; }
        .form-group { margin-bottom: 10px; display:block; }
        .form-group label { display:block; font-weight:600; margin-bottom:8px; color:#111827; }
        .form-group input, .form-group select { width:100%; max-width:100%; height:48px; padding:12px 14px; border:1px solid #e5e7eb; border-radius:10px; font-size:16px; font-family: inherit; background:#fff; color:#111; display:block; }
        /* Password visibility toggle */
    .password-wrap{ position:relative; }
    .password-wrap input{ padding-right:96px; }
    .pw-toggle{ position:absolute; right:8px; top:50%; transform:translateY(-50%); height:38px; padding:0 12px; border-radius:10px; border:1px solid #e5e7eb; background:#f8fafc; color:#0f172a; font-weight:700; cursor:pointer; }
    .roles { display:flex; gap:10px; margin: 6px 0 10px 0; flex-wrap: wrap; }
    .chip { padding:10px 12px; border-radius:10px; border:1.5px solid #e5e7eb; background:#fff; cursor:pointer; transition: all .15s ease; color:#111; width: 150px; height:44px; display:inline-flex; justify-content:center; align-items:center; font-weight:600; text-align:center; }
        .chip input { display:none; }
        .chip.active { border-color: var(--accent); color: var(--accent); box-shadow: 0 6px 14px color-mix(in oklab, var(--accent) 35%, #000 65%); }
    /* Uniform button sizing and centered primary action */
    .btn{ min-width:160px; height:48px; font-size:16px; }
    .signin-actions { display:flex; gap:10px; justify-content:center; align-items:center; margin-top: 10px; }
    .signin-actions .btn { width:100%; max-width:100%; }

    /* Responsive: inputs already stacked; widen tap targets further on small screens */
    @media (max-width: 700px) { .btn{ height:50px; } }
    /* Sections */
    .section { padding: 30px 22px; }
    .section-inner { max-width: 1100px; margin: 0 auto; }
    .glass { background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)); border:1px solid rgba(255,255,255,.08); border-radius:16px; box-shadow: 0 30px 80px rgba(0,0,0,.25); }
    .about-grid { display:grid; grid-template-columns: 1.2fr 1fr; gap: 22px; padding: 22px; }
    .about-grid h2 { color:#e2e8f0; margin:10px 0; font-size: clamp(22px, 3vw, 32px); }
    .about-grid p { color:#cbd5e1; }
    @media (max-width: 900px){ .about-grid { grid-template-columns: 1fr; } }
    .cards { display:grid; grid-template-columns: repeat(4, 1fr); gap: 14px; padding: 22px; }
    .card { background: rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12); border-radius:12px; padding:16px; color:#e5e7eb; }
    .card h3 { margin:8px 0; font-size:18px; }
    .card p { color:#cbd5e1; font-size:14px; }
    @media (max-width: 1100px){ .cards { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px){ .cards { grid-template-columns: 1fr; } }
    .muted { color:#a7f3d0; font-weight:700; font-size:12px; letter-spacing:.08em; text-transform:uppercase; }
    .contact { display:grid; grid-template-columns: 1fr 1fr; gap:16px; padding:22px; }
    @media (max-width: 800px){ .contact { grid-template-columns: 1fr; } }
    </style>
    </head>
<body>
    <nav class="navbar">
        <div class="brand">
            <div>
                Philippine Oncology Center Corporation<br>
                <small>Procurement & Inventory System</small>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            <button class="menu-toggle" type="button" onclick="toggleMenu()">Menu</button>
            <div class="nav-links" id="navLinks">
                <a href="#about" onclick="closeMenu()">About</a>
                <a href="#learn" onclick="closeMenu()">Learn More</a>
                <a href="#contact" onclick="closeMenu()">Contact</a>
            </div>
        </div>
    </nav>

    <section class="hero">
        <div class="hero-inner">
            <div class="left-col">
                <h1 class="headline">Procurement & Inventory Management System</h1>
                <p class="subhead">
                    Simplify your workflow with an all‑in‑one system that automates purchase requests,
                    tracks inventory in real time, and reduces manual errors. Faster approvals and
                    organized records make managing supplies accurate and hassle‑free.
                </p>
                
                    <div class="cta">
                        <a class="btn btn-outline" href="#about">Learn more</a>
                    </div>
            </div>
            <div class="right-col">
                <!-- Inline glass sign-in -->
                    <div class="signin-card" id="signin">
                    <div class="signin-header">
                        <div>
                            <div class="signin-title">Sign in</div>
                            <div class="signin-sub">Enter your credentials to continue.</div>
                        </div>
                    </div>
                    <div class="signin">
                        <?php if (!empty($error)): ?>
                            <div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px;border-radius:10px;margin-bottom:8px;font-weight:600;">
                                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                        <form action="/auth/login" method="POST">
                            <input type="hidden" name="from" value="landing" />
                            <div class="form-row">
                                <div class="form-group" style="flex:1;">
                                    <label for="username">Username</label>
                                    <input id="username" name="username" required />
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label for="password">Password</label>
                                    <div class="password-wrap">
                                        <input id="password" name="password" type="password" required />
                                        <button class="pw-toggle" type="button" onclick="togglePassword()" aria-controls="password" aria-label="Show password">Show</button>
                                    </div>
                                </div>
                            </div>
                            <div class="signin-actions">
                                <button type="submit" class="btn btn-primary">Sign in</button>
                            </div>
                            <div style="text-align:center;margin-top:14px;">
                                <a href="/signup" style="color:#2563eb;text-decoration:none;font-weight:700;">Supplier? Create an account</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="section">
        <div class="section-inner glass about-grid">
            <div>
                <div class="muted">About Us</div>
                <h2>Built for hospital procurement and stock stewardship</h2>
                <p>
                    This lightweight system helps the Philippine Oncology Center Corporation streamline purchasing,
                    track stock accurately across branches, and reduce delays due to manual hand‑offs. It centralizes
                    requests, approvals, and inventory updates so teams can focus on patient care.
                </p>
                <p>
                    Admins manage users and branches, managers review and approve purchase requests, and custodians
                    keep inventory counts up to date—all in one place.
                </p>
                <div class="cta" style="margin-top:10px;">
                    <a class="btn btn-outline" href="#learn">Learn more</a>
                </div>
            </div>
            <div class="card" style="background: rgba(4,120,87,.18); border-color: rgba(16,185,129,.35);">
                <h3 style="margin-top:0;">At a glance</h3>
                <ul style="margin:8px 0 0 18px; color:#e2e8f0;">
                    <li>Role‑based access: Admin, Manager, Custodian</li>
                    <li>Branch‑aware tracking and reporting</li>
                    <li>Fast approvals and audit‑ready records</li>
                    <li>Dark mode and mobile‑friendly UI</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Learn More Section -->
    <section id="learn" class="section">
        <div class="section-inner glass">
            <div class="cards">
                <div class="card">
                    <h3>Streamlined Approvals</h3>
                    <p>Requests move from submission to approval with clear statuses and fewer bottlenecks.</p>
                </div>
                <div class="card">
                    <h3>Real‑time Inventory</h3>
                    <p>Custodians log stock movements so managers always see accurate counts per branch.</p>
                </div>
                <div class="card">
                    <h3>Branch Management</h3>
                    <p>Manage POCC branches centrally and assign custodians to the right locations.</p>
                </div>
                <div class="card">
                    <h3>Audit‑ready PDFs</h3>
                    <p>Generate consistent, printable reports and requests for compliance and archiving.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="section" style="padding-bottom:60px;">
        <div class="section-inner glass contact">
            <div>
                <div class="muted">Contact</div>
                <h2 style="color:#e2e8f0; margin:8px 0 10px 0;">Get in touch</h2>
                <p style="color:#cbd5e1;">For access requests or technical support, please contact the system administrator.</p>
            </div>
            <div class="card" style="background: rgba(255,255,255,.06);">
                <p style="margin:6px 0; color:#cbd5e1;">Email: admin@pocc.local</p>
                <p style="margin:6px 0; color:#cbd5e1;">Hours: Mon–Fri, 9:00 AM – 5:00 PM</p>
            </div>
        </div>
    </section>

    <script>
        function toggleMenu(){ document.getElementById('navLinks').classList.toggle('open'); }
        function closeMenu(){ document.getElementById('navLinks').classList.remove('open'); }
        // remove role chips; keep password toggle only
        function togglePassword(){
            const input = document.getElementById('password');
            const btn = document.querySelector('.pw-toggle');
            if (!input || !btn) return;
            const isPw = input.type === 'password';
            input.type = isPw ? 'text' : 'password';
            btn.textContent = isPw ? 'Hide' : 'Show';
            btn.setAttribute('aria-label', isPw ? 'Hide password' : 'Show password');
        }
        // Initialize default accent
        document.body.setAttribute('data-role', 'admin');
    </script>
</body>
</html>
