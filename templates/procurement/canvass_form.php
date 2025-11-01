<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement • Canvassing</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <?php require __DIR__ . '/../layouts/_theme.php'; ?>
    <style>
        :root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#22c55e; --control-h:36px; }
        html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#22c55e; --control-h:36px; }
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .layout{ display:grid; grid-template-columns: 240px 1fr; min-height:100vh; }
        .sidebar{ background:#fff; border-right:1px solid var(--border); padding:18px 12px; position:sticky; top:0; height:100vh; }
        html[data-theme="dark"] .sidebar{ background:#0f172a; }
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; margin-bottom:12px; }
        .btn{ display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 12px; height:var(--control-h); min-width:120px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); text-decoration:none; font-size:12px; cursor:pointer; }
        .btn.primary{ border-color: color-mix(in oklab, var(--accent) 35%, var(--border)); background: color-mix(in oklab, var(--accent) 10%, transparent); }
        select{ padding:0 10px; height:var(--control-h); border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); }
        .grid{ display:grid; grid-template-columns: repeat(auto-fill,minmax(240px,1fr)); gap:10px; }
        ul{ margin:4px 0 0 18px; }
        pre{ margin:0; white-space:pre-wrap; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">
            <span>Proceed with Canvassing • PR <?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?></span>
            <a class="btn" href="/manager/requests">Back</a>
        </div>

        <div class="card">
            <strong>Items</strong>
            <pre><?php foreach ($rows as $r) { echo htmlspecialchars(($r['item_name'] ?? 'Item') . ' × ' . (string)($r['quantity'] ?? 0) . ' ' . (string)($r['unit'] ?? '')) . "\n"; } ?></pre>
        </div>

        <form action="/manager/requests/canvass" method="POST" class="card">
            <input type="hidden" name="pr_number" value="<?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?>" />
            <p>Select 3–5 suppliers to include in the canvassing sheet.</p>
            <div class="grid">
                <?php foreach ($suppliers as $s): ?>
                    <label style="display:flex; align-items:center; gap:8px; border:1px solid var(--border); border-radius:10px; padding:8px;">
                        <input type="checkbox" name="suppliers[]" value="<?= (int)$s['user_id'] ?>" />
                        <span><?= htmlspecialchars((string)$s['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:12px; display:flex; gap:8px;">
                <button class="btn primary" type="submit">Generate and Send for Admin Approval</button>
                <a class="btn" href="/manager/requests">Cancel</a>
            </div>
        </form>
    </main>
</div>
</body>
</html>
