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
        .muted{ color:var(--muted); }
        .btn{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); text-decoration:none; font-size:12px; cursor:pointer; }
        .btn:hover{ background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn.primary{ border-color: color-mix(in oklab, var(--accent) 35%, var(--border)); background: color-mix(in oklab, var(--accent) 10%, transparent); }
        .badge{ background:color-mix(in oklab, var(--accent) 12%, transparent); color:var(--text); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); padding:4px 8px; border-radius:999px; font-size:12px; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">Purchase Orders</div>
    <p class="muted" style="margin-top:-4px;">Canvassing-approved requests ready for PO issuance.</p>
        <table style="margin-top:12px;">
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Branch</th>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Needed By</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($approved)): ?>
                <?php foreach ($approved as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$r['request_id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['branch_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['item_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['quantity'] ?? '1'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)($r['unit'] ?? 'pcs'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['needed_by'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge">Canvassing Approved</span></td>
                        <td>
                            <a class="btn primary" href="/procurement/po/create?request_id=<?= urlencode((string)$r['request_id']) ?>">Create PO</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="muted">No approved requests at the moment.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="h1" style="margin-top:20px;">Existing POs</div>
        <table style="margin-top:12px;">
            <thead>
                <tr>
                    <th>PO Number</th>
                    <th>Request ID</th>
                    <th>Branch</th>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>PO Status</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($pos)): ?>
                <?php foreach ($pos as $po): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$po['po_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$po['request_id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($po['branch_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($po['item_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($po['quantity'] ?? '1'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)($po['unit'] ?? 'pcs'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge"><?= htmlspecialchars((string)($po['po_status'] ?? 'issued'), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string)($po['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <a class="btn" href="/procurement/po/create?request_id=<?= urlencode((string)$po['request_id']) ?>">View/Download</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="muted">No POs yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </main>
</div>
</body>
</html>
