<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custodian • Requests</title>
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
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; display:flex; justify-content:space-between; align-items:center; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn { background:var(--accent); color:#fff; border:0; padding:0 14px; height:40px; border-radius:10px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; }
        .btn.secondary{ background:transparent; color:var(--text); border:1px solid var(--border); }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">
            <span>Purchase Requests</span>
            <a class="btn" href="/custodian/requests/create">Create Request</a>
        </div>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($requests)): foreach ($requests as $r): ?>
                    <tr>
                        <td><?= (int)$r['request_id'] ?></td>
                        <td><?= htmlspecialchars((string)($r['item_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)($r['quantity'] ?? 0) ?> <?= htmlspecialchars((string)($r['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" style="color:var(--muted)">No requests.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
