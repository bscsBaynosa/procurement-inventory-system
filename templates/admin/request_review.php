<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • Review PR <?= htmlspecialchars((string)$pr, ENT_QUOTES, 'UTF-8') ?></title>
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
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn{ display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:10px; border:1px solid var(--border); background:var(--card); color:var(--text); text-decoration:none; font-size:13px; cursor:pointer; }
        .btn.primary{ background: var(--accent); color:#fff; border:0; }
        .btn.danger{ background:#dc2626; color:#fff; border:0; }
        input.qty{ width:90px; padding:6px 8px; border:1px solid var(--border); border-radius:8px; }
        .row{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <span>Admin • Review PR <?= htmlspecialchars((string)$pr, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="row">
                <a class="btn" href="/admin/requests">Back to Requests</a>
                <a class="btn" href="/manager/requests/download?pr=<?= urlencode((string)$pr) ?>">Download PDF</a>
            </span>
        </div>

        <form method="POST" action="/admin/pr/revise">
            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$pr, ENT_QUOTES, 'UTF-8') ?>" />
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th>Unit</th>
                        <th>Requested Qty</th>
                        <th>Revise Qty</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="mono"><?= (int)$r['request_id'] ?></td>
                        <td><?= htmlspecialchars((string)($r['item_name'] ?? 'Item'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)($r['quantity'] ?? 0) ?></td>
                        <td>
                            <input type="hidden" name="request_id[]" value="<?= (int)$r['request_id'] ?>" />
                            <input class="qty" type="number" min="0" name="quantity[]" value="<?= (int)($r['quantity'] ?? 0) ?>" />
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <button class="btn" type="submit">Revise</button>
                <form method="POST" action="/admin/pr/approve" onsubmit="return confirm('Approve PR <?= htmlspecialchars((string)$pr, ENT_QUOTES, 'UTF-8') ?>?');">
                    <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$pr, ENT_QUOTES, 'UTF-8') ?>" />
                    <button class="btn primary" type="submit">Approve</button>
                </form>
                <form method="POST" action="/admin/pr/reject" onsubmit="return confirm('Reject PR <?= htmlspecialchars((string)$pr, ENT_QUOTES, 'UTF-8') ?>?');" style="display:inline-flex; gap:8px; align-items:center;">
                    <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$pr, ENT_QUOTES, 'UTF-8') ?>" />
                    <input type="text" name="notes" placeholder="Reason (optional)" style="min-width:260px; padding:8px 10px; border:1px solid var(--border); border-radius:8px;" />
                    <button class="btn danger" type="submit">Reject</button>
                </form>
            </div>
        </form>
    </main>
</div>
</body>
</html>
