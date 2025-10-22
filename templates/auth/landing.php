<?php
    // Resolve POCC logo: prefer repo-root logo.png; fall back to public/img/* or public/*
    $root = realpath(__DIR__ . '/../../'); // project root
    $publicDir = $root . DIRECTORY_SEPARATOR . 'public';
    $logoSrc = null; // can be /path or data URI

    // 1) Repo root: logo.png (not web-accessible) -> embed as data URI
    $rootLogo = $root . DIRECTORY_SEPARATOR . 'logo.png';
    if (file_exists($rootLogo)) {
        $data = @file_get_contents($rootLogo);
        if ($data !== false) {
            $logoSrc = 'data:image/png;base64,' . base64_encode($data);
        }
    }

    // 2) Public candidates if no root logo or failed to read
    if (!$logoSrc) {
        $logoCandidates = [
            'img' . DIRECTORY_SEPARATOR . 'logo.png',
            'img' . DIRECTORY_SEPARATOR . 'logo.svg',
            'img' . DIRECTORY_SEPARATOR . 'pocc-logo.png',
            'img' . DIRECTORY_SEPARATOR . 'pocc-logo.svg',
            'logo.png', // directly under public
            'logo.svg',
        ];
        foreach ($logoCandidates as $rel) {
            $abs = $publicDir . DIRECTORY_SEPARATOR . $rel;
            if (file_exists($abs)) { $logoSrc = '/' . str_replace('\\', '/', $rel); break; }
        }
    }
?>
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

        body { font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg-gradient); color: var(--text); min-height:100vh; }
        .navbar { display:flex; align-items:center; justify-content:space-between; padding: 16px 28px; background: rgba(15, 23, 42, .15); backdrop-filter: blur(6px); position:sticky; top:0; z-index:10; border-bottom: 1px solid rgba(255,255,255,.06); }
    .brand { display:flex; align-items:center; gap:12px; font-weight:800; color:#e2e8f0; }
    .brand .logo { width:36px; height:36px; border-radius:8px; background:#065f46; display:grid; place-items:center; color:#fff; font-weight:800; box-shadow: inset 0 0 0 2px rgba(255,255,255,.15); overflow:hidden; }
    .brand .logo img { width:100%; height:100%; object-fit:cover; display:block; }
        .brand small { display:block; font-weight:600; color:#a7f3d0; }
        .nav-links a { color:#e5e7eb; text-decoration:none; margin-left:20px; font-weight:600; opacity:.9; transition:opacity .2s ease; }
        .nav-links a:hover { opacity:1; }

        .hero { padding: 56px 28px; }
        .hero-inner { max-width: 1200px; margin: 0 auto; background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)); border:1px solid rgba(255,255,255,.08); border-radius:16px; padding: 40px; display:grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items:center; box-shadow: 0 30px 80px rgba(0,0,0,.35); }
        @media (max-width: 900px) { .hero-inner { grid-template-columns: 1fr; } }

        .hero-graphic { display:grid; grid-template-rows: 1fr auto; gap: 20px; }
        .bars { display:flex; align-items:flex-end; gap: 24px; height: 320px; }
        .bar { width: 96px; background: linear-gradient(180deg, rgba(16,185,129,.9), rgba(6,78,59,.95)); border-radius:14px; box-shadow: 0 10px 30px rgba(0,0,0,.35); transform-origin: bottom; animation: grow 1.2s ease var(--d,0s) both; }
        .bar:nth-child(1) { height: 88%; --d:.1s }
        .bar:nth-child(2) { height: 52%; --d:.2s }
        .bar:nth-child(3) { height: 76%; --d:.3s }
        @keyframes grow { from { transform: scaleY(.2); opacity:.4 } to { transform: scaleY(1); opacity:1 } }

        .cta { display:flex; gap:12px; }
        .btn { display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border-radius:10px; text-decoration:none; font-weight:700; border:0; cursor:pointer; transition: transform .12s ease, filter .2s ease, box-shadow .2s ease; }
        .btn:active { transform: translateY(1px); }
        .btn-primary { background: var(--accent); color:#fff; box-shadow: 0 10px 30px color-mix(in oklab, var(--accent) 50%, #000 50%); }
        .btn-primary:hover { filter: brightness(1.05); box-shadow: 0 14px 36px color-mix(in oklab, var(--accent) 60%, #000 40%); }
        .btn-outline { background: transparent; color: #e5e7eb; border:2px solid rgba(255,255,255,.7); }
        .btn-outline:hover { background: rgba(255,255,255,.08); }

        .headline { color:#f1f5f9; font-size: clamp(28px, 4vw, 56px); line-height:1.05; margin: 0 0 12px 0; font-weight:800; text-shadow: 0 10px 30px rgba(0,0,0,.35); }
        .subhead { color: #cbd5e1; font-size: 16px; max-width: 620px; }

        /* Modal */
        .modal-backdrop { position:fixed; inset:0; background: rgba(0,0,0,.55); display:none; align-items:center; justify-content:center; padding: 20px; z-index: 20; }
        .modal { width: 100%; max-width: 980px; background: var(--surface); border-radius: 16px; overflow:hidden; box-shadow: 0 30px 90px rgba(0,0,0,.5); transform: translateY(10px) scale(.98); opacity:0; transition: transform .18s ease, opacity .18s ease; }
        .modal.show { transform: translateY(0) scale(1); opacity:1; }
        .modal header { background: linear-gradient(90deg, var(--accent-600), var(--accent)); color: #fff; padding:16px 20px; text-align:left; }
        .modal .content { display:grid; grid-template-columns: 1.2fr .9fr; gap: 0; }
        @media (max-width: 900px) { .modal .content { grid-template-columns: 1fr; } }
        .brand-panel { position:relative; padding: 28px; background: linear-gradient(160deg, rgba(37,99,235,0.08), rgba(6,95,70,0.06)); border-right: 1px solid #eef2ff; }
        .brand-panel .panel-inner { background: linear-gradient(180deg, rgba(255,255,255,.7), rgba(255,255,255,.45)); border: 1px solid rgba(15,23,42,.08); border-radius: 14px; padding: 20px; height: 100%; display:flex; flex-direction:column; justify-content:center; }
        .brand-panel .logo-wrap { display:flex; align-items:center; gap:12px; margin-bottom: 10px; }
        .brand-panel .logo-badge { width:48px; height:48px; border-radius:10px; background: #065f46; display:grid; place-items:center; font-weight:800; color:#fff; overflow:hidden; box-shadow: inset 0 0 0 2px rgba(255,255,255,.25); }
        .brand-panel .logo-badge img { width:100%; height:100%; object-fit:cover; display:block; }
        .brand-panel h3 { margin: 0 0 6px 0; color:#0f172a; font-weight:800; }
        .brand-panel p { margin: 0 0 8px 0; color:#334155; }
        .brand-panel ul { margin: 10px 0 0 18px; color:#475569; }
        .signin { background:#ffffff; padding: 22px; border-radius: 0 16px 16px 0; border-left: 1px solid #eef2ff; }
        @media (max-width: 900px) { .signin { border-radius: 0 0 16px 16px; border-left:0; border-top:1px solid #eef2ff; } .brand-panel { border-right:0; } }
        .signin h4 { margin: 0 0 10px 0; }
        .form-row { display:flex; gap:12px; }
        .form-group { margin-bottom: 10px; display:block; }
        .form-group label { display:block; font-weight:600; margin-bottom:6px; color:#111827; }
        .form-group input, .form-group select { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font: inherit; background:#fff; color:#111; }
        .roles { display:flex; gap:10px; margin: 8px 0 12px 0; }
        .chip { padding:8px 10px; border-radius:999px; border:1px solid #e5e7eb; background:#fff; cursor:pointer; transition: all .15s ease; color:#111; }
        .chip input { display:none; }
        .chip.active { border-color: var(--accent); color: var(--accent); box-shadow: 0 6px 14px color-mix(in oklab, var(--accent) 35%, #000 65%); }
        .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top: 6px; }
        .close-x { background:transparent; border:0; font-size:18px; cursor:pointer; color:#fff; }
    </style>
    </head>
<body>
    <nav class="navbar">
        <div class="brand">
            <div class="logo">
                <?php if ($logoSrc): ?>
                    <img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" alt="POCC logo" />
                <?php else: ?>
                    POCC
                <?php endif; ?>
            </div>
            <div>
                Philippine Oncology Center Corporation<br>
                <small>Procurement & Inventory System</small>
            </div>
        </div>
        <div class="nav-links">
            <a href="#" onclick="openModal();return false;">Login</a>
            <a href="#about">About us</a>
            <a href="#contact">Contact Us</a>
        </div>
    </nav>

    <section class="hero">
        <div class="hero-inner">
            <div class="hero-graphic">
                <div class="bars">
                    <div class="bar"></div>
                    <div class="bar"></div>
                    <div class="bar"></div>
                </div>
                <div class="cta">
                    <button class="btn btn-primary" onclick="openModal()">Get Started</button>
                    <a class="btn btn-outline" href="#about">Learn more</a>
                </div>
            </div>
            <div>
                <h1 class="headline">Procurement and Inventory System</h1>
                <p class="subhead">
                    Simplify your workflow with an all‑in‑one system that automates purchase requests,
                    tracks inventory in real time, and reduces manual errors. Faster approvals and
                    organized records make managing supplies accurate and hassle‑free.
                </p>
            </div>
        </div>
    </section>

    <!-- Get Started / Sign in Modal -->
    <div class="modal-backdrop" id="modal">
        <div class="modal" id="modalCard" role="dialog" aria-modal="true" aria-labelledby="modal-title">
            <header>
                <div style="display:flex; align-items:center; justify-content:space-between;">
                    <strong id="modal-title">Welcome to POCC Procurement & Inventory</strong>
                    <button class="close-x" onclick="closeModal()">✕</button>
                </div>
            </header>
            <div class="content">
                <div class="brand-panel">
                    <div class="panel-inner">
                        <div class="logo-wrap">
                            <div class="logo-badge">
                                <?php if ($logoSrc): ?>
                                    <img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" alt="POCC logo"/>
                                <?php else: ?>
                                    POCC
                                <?php endif; ?>
                            </div>
                            <div style="font-weight:800; color:#0f172a;">Philippine Oncology Center Corporation</div>
                        </div>
                        <h3>Streamlined purchasing. Clear inventory.</h3>
                        <p>Approve requests faster, keep stock levels accurate, and export clean PDF records—
                           all in one secure system.</p>
                        <ul>
                            <li>Custodians: track and request supplies</li>
                            <li>Managers: review, approve, and monitor</li>
                            <li>Admins: configure branches and users</li>
                        </ul>
                    </div>
                </div>
                <div class="signin">
                    <h4>Sign in</h4>
                    <form action="/auth/login" method="POST" onsubmit="syncRoleToSelect()">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input id="username" name="username" required />
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" required />
                        </div>
                        <div class="form-group">
                            <label>Sign in as</label>
                            <div class="roles" id="roleChips">
                                <label class="chip active"><input type="radio" name="role_chip" value="procurement_manager" checked> Procurement Manager</label>
                                <label class="chip"><input type="radio" name="role_chip" value="custodian"> Custodian</label>
                                <label class="chip"><input type="radio" name="role_chip" value="admin"> Admin</label>
                            </div>
                            <!-- real field submitted -->
                            <select id="roleSelect" name="role" style="display:none">
                                <option value="procurement_manager" selected>Procurement Manager</option>
                                <option value="custodian">Custodian</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn btn-outline" onclick="window.location.href='/login'">Full login page</button>
                            <button type="submit" class="btn btn-primary">Sign in</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('modal');
        const modalCard = document.getElementById('modalCard');
        function openModal(){ modal.style.display = 'flex'; requestAnimationFrame(()=> modalCard.classList.add('show')); }
        function closeModal(){ modalCard.classList.remove('show'); setTimeout(()=> modal.style.display = 'none', 150); }

        const chips = document.getElementById('roleChips');
        chips.addEventListener('click', (e) => {
            const label = e.target.closest('label.chip');
            if (!label) return;
            [...chips.querySelectorAll('.chip')].forEach(c => c.classList.remove('active'));
            label.classList.add('active');
            const value = label.querySelector('input').value;
            const select = document.getElementById('roleSelect');
            select.value = value;
            // Update page accent theme based on role
            document.body.setAttribute('data-role', value);
        });

        function syncRoleToSelect(){
            // Ensure selected chip syncs to hidden select before submit
            const active = document.querySelector('#roleChips .chip.active input');
            if (active) document.getElementById('roleSelect').value = active.value;
        }
        // Initialize default role theme
        document.body.setAttribute('data-role', 'procurement_manager');
    </script>
</body>
</html>
