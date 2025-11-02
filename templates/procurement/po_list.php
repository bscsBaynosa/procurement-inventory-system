<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement • Purchase Orders</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <?php require __DIR__ . '/../layouts/_theme.php'; ?>
    <style>
        :root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#22c55e; --control-h: 36px; }
        html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#22c55e; --control-h: 36px; }
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .layout{ display:grid; grid-template-columns: 240px 1fr; min-height:100vh; }
        .sidebar{ background:#fff; border-right:1px solid var(--border); padding:18px 12px; position:sticky; top:0; height:100vh; }
        html[data-theme="dark"] .sidebar{ background:#0f172a; }
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .filters{ display:grid; grid-template-columns: 200px 280px 100px; gap:8px; align-items:center; margin-bottom:10px; }
        select{ padding:0 10px; height:var(--control-h); border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; vertical-align:middle; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn{ display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 12px; height:var(--control-h); min-width:90px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); text-decoration:none; font-size:12px; cursor:pointer; }
        .btn.primary{ border-color: color-mix(in oklab, var(--accent) 35%, var(--border)); background: color-mix(in oklab, var(--accent) 10%, transparent); }
        .muted{ color:var(--muted); }
        .badge{ background:color-mix(in oklab, var(--accent) 12%, transparent); color:var(--text); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); padding:2px 6px; border-radius:999px; font-size:11px; }
        .nowrap{ white-space:nowrap; }
        .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">
            <span>Purchase Orders</span>
        </div>
        <form class="filters" method="GET" action="/procurement/pos">
            <label>Status
                <?php $statuses = ['', 'submitted','po_admin_approved','po_rejected','supplier_response_submitted','draft']; ?>
                <select name="status">
                    <option value="">All</option>
                    <?php foreach ($statuses as $s): $sel=((string)($filters['status'] ?? '')===(string)$s); ?>
                        <?php if ($s===''): continue; endif; ?>
                        <option value="<?= htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8') ?>" <?= $sel?'selected':'' ?>><?= htmlspecialchars(ucwords(str_replace('_',' ', $s)), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Supplier
                <select name="supplier">
                    <option value="">All</option>
                    <?php foreach (($suppliers ?? []) as $sup): $sel=((int)($filters['supplier'] ?? 0)===(int)$sup['user_id']); ?>
                        <option value="<?= (int)$sup['user_id'] ?>" <?= $sel?'selected':'' ?>><?= htmlspecialchars((string)$sup['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn" type="submit">Apply</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th class="nowrap">Date</th>
                    <th class="nowrap">PR</th>
                    <th class="nowrap">PO Number</th>
                    <th>Supplier</th>
                    <th class="nowrap">Status</th>
                    <th class="nowrap">Total</th>
                    <th>PDF</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($pos)): foreach ($pos as $p): ?>
                    <tr>
                        <td class="nowrap"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$p['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono"><?= htmlspecialchars((string)$p['pr_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono"><?= htmlspecialchars((string)$p['po_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$p['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge"><?= htmlspecialchars(ucwords(str_replace('_',' ', (string)$p['status'])), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td class="nowrap">₱ <?= number_format((float)($p['total'] ?? 0), 2) ?></td>
                        <td>
                            <?php if (!empty($p['pdf_path'])): ?>
                                <a class="btn" href="/po/download?id=<?= (int)$p['id'] ?>">Download</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="muted">No purchase orders found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </main>
</div>
</body>
</html>
