<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Assistant • Reports</title>
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
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-weight:700; font-size:14px; line-height:1; height:40px; padding:0 14px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; transition:all .15s ease; cursor:pointer; }
        .btn.primary{ background:var(--accent); color:#fff; border:0; }
        .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
        input, select{ height:40px; border-radius:10px; border:1px solid var(--border); padding:0 10px; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">Reports</div>
        <div class="card" style="margin-bottom:12px;">
            <form method="GET" action="/admin-assistant/reports" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <select name="type">
                    <option value="">All Types</option>
                    <option value="inventory" <?= (($filter_type ?? '')==='inventory'?'selected':'') ?>>Inventory</option>
                    <option value="consumption" <?= (($filter_type ?? '')==='consumption'?'selected':'') ?>>Consumption</option>
                </select>
                <input name="category" placeholder="Category (optional)" value="<?= htmlspecialchars((string)($filter_category ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                <input name="month" placeholder="Month (YYYY-MM)" value="<?= htmlspecialchars((string)($_GET['month'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                <button class="btn muted" type="submit">Filter</button>
                <a class="btn muted" href="/admin-assistant/reports">Reset</a>
            </form>
        </div>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Type</th><th>Category</th><th>Prepared By</th><th>Prepared At</th><th>File</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($reports)): foreach ($reports as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$r['report_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($r['category'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($r['prepared_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$r['prepared_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$r['file_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><a class="btn muted" href="/admin-assistant/reports/download?id=<?= (int)$r['id'] ?>">Download</a></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" style="color:var(--muted)">No reports yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
