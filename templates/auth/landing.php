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
        :root {
            --bg: #f3f4f6;
            --surface: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --brand: #2D6CDF;
            --brand-600: #2559b6;
            --accent: #f59e0b;
        }
        body { font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg); color: var(--text); }
        .navbar { display:flex; align-items:center; justify-content:space-between; padding: 16px 28px; background:#e5e7eb; position:sticky; top:0; z-index:10; }
        .brand { display:flex; align-items:center; gap:12px; font-weight:800; }
        .brand .logo { width:36px; height:36px; border-radius:8px; background:#4b5563; display:grid; place-items:center; color:#fff; font-weight:800; }
        .brand small { display:block; font-weight:600; color:#374151; }
        .nav-links a { color:#111827; text-decoration:none; margin-left:20px; font-weight:600; }
        .nav-links a:hover { color: var(--brand); }

        .hero { padding: 56px 28px; }
        .hero-inner { max-width: 1200px; margin: 0 auto; background:#e5e7eb; border-radius:16px; padding: 40px; display:grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items:center; }
        @media (max-width: 900px) { .hero-inner { grid-template-columns: 1fr; } }

        .hero-graphic { display:grid; grid-template-rows: 1fr auto; gap: 20px; }
        .bars { display:flex; align-items:flex-end; gap: 24px; height: 320px; }
        .bar { width: 96px; background:#6b7280; border-radius:10px; opacity:.9; }
        .bar:nth-child(1) { height: 88%; }
        .bar:nth-child(2) { height: 52%; }
        .bar:nth-child(3) { height: 76%; }
        .cta { display:flex; gap:12px; }
        .btn { display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border-radius:10px; text-decoration:none; font-weight:700; border:0; cursor:pointer; }
        .btn-primary { background: var(--accent); color:#111; }
        .btn-primary:hover { filter: brightness(0.95); }
        .btn-outline { background: transparent; color: var(--brand); border:2px solid var(--brand); }
        .btn-outline:hover { background: var(--brand); color:#fff; }

        .headline { font-size: clamp(28px, 4vw, 56px); line-height:1.05; margin: 0 0 12px 0; font-weight:800; }
        .subhead { color: var(--muted); font-size: 16px; max-width: 620px; }

        /* Modal */
        .modal-backdrop { position:fixed; inset:0; background: rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; padding: 20px; z-index: 20; }
        .modal { width: 100%; max-width: 980px; background: var(--surface); border-radius: 14px; overflow:hidden; box-shadow: 0 20px 80px rgba(0,0,0,.25); }
        .modal header { background: #f3f4f6; color: #111827; padding:16px 20px; text-align:left; }
        .modal .content { display:grid; grid-template-columns: 1.2fr .9fr; gap: 24px; padding: 22px; }
        @media (max-width: 900px) { .modal .content { grid-template-columns: 1fr; } }
        .how { padding-right: 10px; }
        .how h3 { margin-top:0; font-weight:800; }
        .how ul { margin: 12px 0 0 18px; color: var(--muted); }
        .signin { background:#f9fafb; padding: 16px; border-radius: 12px; }
        .signin h4 { margin: 0 0 10px 0; }
        .form-row { display:flex; gap:12px; }
        .form-group { margin-bottom: 10px; display:block; }
        .form-group label { display:block; font-weight:600; margin-bottom:6px; }
        .form-group input, .form-group select { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font: inherit; }
        .roles { display:flex; gap:10px; margin: 8px 0 12px 0; }
        .chip { padding:8px 10px; border-radius:999px; border:1px solid #e5e7eb; background:#fff; cursor:pointer; }
        .chip input { display:none; }
        .chip.active, .chip:hover { border-color: var(--brand); color: var(--brand); }
        .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top: 6px; }
        .close-x { background:transparent; border:0; font-size:18px; cursor:pointer; }
    </style>
    </head>
<body>
    <nav class="navbar">
        <div class="brand">
            <div class="logo">POCC</div>
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
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
            <header>
                <div style="display:flex; align-items:center; justify-content:space-between;">
                    <strong id="modal-title">Welcome to POCC Procurement & Inventory</strong>
                    <button class="close-x" onclick="closeModal()">✕</button>
                </div>
            </header>
            <div class="content">
                <div class="how">
                    <h3>How it works</h3>
                    <ul>
                        <li>Custodians manage inventory and submit purchase requests.</li>
                        <li>Procurement managers review, approve, and track requests.</li>
                        <li>Admins configure branches, users, and audit records.</li>
                        <li>Automated logs and PDF exports keep records clean and shareable.</li>
                    </ul>
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
        function openModal(){ modal.style.display = 'flex'; }
        function closeModal(){ modal.style.display = 'none'; }

        const chips = document.getElementById('roleChips');
        chips.addEventListener('click', (e) => {
            const label = e.target.closest('label.chip');
            if (!label) return;
            [...chips.querySelectorAll('.chip')].forEach(c => c.classList.remove('active'));
            label.classList.add('active');
            const value = label.querySelector('input').value;
            const select = document.getElementById('roleSelect');
            select.value = value;
        });

        function syncRoleToSelect(){
            // Ensure selected chip syncs to hidden select before submit
            const active = document.querySelector('#roleChips .chip.active input');
            if (active) document.getElementById('roleSelect').value = active.value;
        }
    </script>
</body>
</html>
