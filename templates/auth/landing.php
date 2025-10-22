<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Welcome - Procurement & Inventory System</title>
    <link rel="stylesheet" href="/css/main.css" />
    <style>
        .role-grid { display:flex; gap:1rem; flex-wrap:wrap; margin-top:1.5rem; }
        .role-card { border:1px solid #ddd; padding:1rem; border-radius:8px; min-width:240px; text-align:center; }
        .role-card a { display:inline-block; margin-top:.75rem; padding:.5rem 1rem; background:#2d6cdf; color:#fff; text-decoration:none; border-radius:4px; }
    </style>
    </head>
<body>
    <div class="container">
        <h1>Procurement & Inventory System</h1>
        <p>Select how you want to sign in:</p>
        <div class="role-grid">
            <div class="role-card">
                <h3>Procurement Manager</h3>
                <p>Review and process purchase requests.</p>
                <a href="/login?role=procurement_manager">Continue</a>
            </div>
            <div class="role-card">
                <h3>Custodian</h3>
                <p>Manage inventory and submit requests.</p>
                <a href="/login?role=custodian">Continue</a>
            </div>
            <div class="role-card">
                <h3>Administrator</h3>
                <p>System-wide administration and setup.</p>
                <a href="/login?role=admin">Continue</a>
            </div>
        </div>
    </div>
</body>
</html>
