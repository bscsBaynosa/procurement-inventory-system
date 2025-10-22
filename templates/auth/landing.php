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

    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg-gradient); color: var(--text); min-height:100vh; }
        .navbar { display:flex; align-items:center; justify-content:space-between; padding: 12px 22px; background: rgba(15, 23, 42, .15); backdrop-filter: blur(6px); position:sticky; top:0; z-index:10; border-bottom: 1px solid rgba(255,255,255,.06); }
        .brand { display:flex; align-items:center; gap:10px; font-weight:800; color:#e2e8f0; }
        .brand small { display:block; font-weight:600; color:#a7f3d0; }
        .nav-links a { color:#e5e7eb; text-decoration:none; margin-left:20px; font-weight:600; opacity:.9; transition:opacity .2s ease; }
        .nav-links a:hover { opacity:1; }

    .hero { padding: 36px 22px; }
    .hero-inner { max-width: 1200px; margin: 0 auto; background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)); border:1px solid rgba(255,255,255,.08); border-radius:16px; padding: 28px; display:grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items:stretch; box-shadow: 0 30px 80px rgba(0,0,0,.35); }
    @media (max-width: 900px) { 
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
    .signin-card { background: rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-radius: 14px; padding: 18px; color:#0f172a; height:100%; display:flex; flex-direction:column; }
        .signin-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 8px; }
        .signin-title { color:#f8fafc; font-weight:800; font-size: 18px; }
        .signin-sub { color:#cbd5e1; font-size: 13px; }
    .signin { background: linear-gradient(180deg, rgba(255,255,255,.85), rgba(255,255,255,.75)); border:1px solid rgba(15,23,42,.06); border-radius: 12px; padding: 14px; flex:1; display:flex; flex-direction:column; overflow:hidden; }
        .form-row { display:flex; gap:12px; }
        .form-group { margin-bottom: 10px; display:block; }
        .form-group label { display:block; font-weight:600; margin-bottom:6px; color:#111827; }
        .form-group input, .form-group select { width:100%; max-width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font: inherit; background:#fff; color:#111; display:block; }
    .roles { display:flex; gap:10px; margin: 6px 0 10px 0; flex-wrap: wrap; }
    .chip { padding:10px 12px; border-radius:10px; border:1.5px solid #e5e7eb; background:#fff; cursor:pointer; transition: all .15s ease; color:#111; min-width: 130px; display:inline-flex; justify-content:center; align-items:center; font-weight:600; }
        .chip input { display:none; }
        .chip.active { border-color: var(--accent); color: var(--accent); box-shadow: 0 6px 14px color-mix(in oklab, var(--accent) 35%, #000 65%); }
    .signin-actions { display:flex; gap:10px; justify-content:flex-end; align-items:center; margin-top: 6px; }

    /* Responsive: stack username/password vertically on narrow screens to avoid overflow */
    @media (max-width: 700px) { .form-row { flex-direction: column; } }
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
        <div class="nav-links">
            <a href="#about">About us</a>
            <a href="#contact">Contact Us</a>
        </div>
    </nav>

    <section class="hero">
        <div class="hero-inner">
            <div class="left-col">
                <h1 class="headline">Procurement and Inventory System</h1>
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
                            <div class="signin-sub">Choose your role, then enter your credentials.</div>
                        </div>
                    </div>
                    <div class="signin">
                        <form action="/auth/login" method="POST" onsubmit="syncRoleToSelect()">
                            <div class="form-row">
                                <div class="form-group" style="flex:1;">
                                    <label for="username">Username</label>
                                    <input id="username" name="username" required />
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label for="password">Password</label>
                                    <input id="password" name="password" type="password" required />
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Sign in as</label>
                                <div class="roles" id="roleChips">
                                    <label class="chip active"><input type="radio" name="role_chip" value="procurement_manager" checked> Manager</label>
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
                            <div class="form-group" id="roleInfo" aria-live="polite" style="margin-top:6px;">
                                <!-- dynamic description goes here -->
                            </div>
                            <div class="signin-actions">
                                <button type="submit" class="btn btn-primary" style="min-width:120px;">Sign in</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
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
            // Update role description
            updateRoleInfo(value);
        });

        function syncRoleToSelect(){
            // Ensure selected chip syncs to hidden select before submit
            const active = document.querySelector('#roleChips .chip.active input');
            if (active) document.getElementById('roleSelect').value = active.value;
        }
        // Dynamic role descriptions
        const roleDescriptions = {
            procurement_manager: `
                <strong>Manager</strong>
                <ul style="margin:6px 0 0 18px;color:#475569;">
                    <li>Review and approve purchase requests</li>
                    <li>Track supplier performance and budgets</li>
                    <li>Monitor inventory health across branches</li>
                </ul>
            `,
            custodian: `
                <strong>Custodian</strong>
                <ul style="margin:6px 0 0 18px;color:#475569;">
                    <li>Log stock in/out and current quantities</li>
                    <li>Submit purchase requests when low</li>
                    <li>Maintain accurate item records</li>
                </ul>
            `,
            admin: `
                <strong>Admin</strong>
                <ul style="margin:6px 0 0 18px;color:#475569;">
                    <li>Manage users, roles, and branches</li>
                    <li>Configure system settings and access</li>
                    <li>Audit logs and compliance reports</li>
                </ul>
            `,
        };
        function updateRoleInfo(role){
            const el = document.getElementById('roleInfo');
            el.innerHTML = roleDescriptions[role] || '';
        }
        // Initialize default
        document.body.setAttribute('data-role', 'procurement_manager');
        updateRoleInfo('procurement_manager');
    </script>
</body>
</html>
