<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement • PO View</title>
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
        .grid{ display:grid; grid-template-columns: repeat(2, minmax(260px, 1fr)); gap:10px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:14px; }
        .muted{ color:var(--muted); }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn{ display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 12px; height:var(--control-h); min-width:90px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); text-decoration:none; font-size:12px; cursor:pointer; }
        .btn.primary{ border-color: color-mix(in oklab, var(--accent) 35%, var(--border)); background: color-mix(in oklab, var(--accent) 10%, transparent); }
        .badge{ background:color-mix(in oklab, var(--accent) 12%, transparent); color:var(--text); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); padding:2px 6px; border-radius:999px; font-size:11px; }
        .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">
            <span>PO <?= htmlspecialchars((string)$po['po_number'], ENT_QUOTES, 'UTF-8') ?></span>
            <div style="display:flex; gap:8px;">
                <a class="btn" href="/procurement/pos">Back to list</a>
                <?php if (!empty($po['pdf_path'])): ?>
                    <a class="btn primary" href="/po/download?id=<?= (int)$po['id'] ?>">Download PDF</a>
                <?php endif; ?>
                <a class="btn" href="/procurement/rfp/create?po=<?= (int)$po['id'] ?>">Generate RFP</a>
            </div>
        </div>

        <div class="grid" style="margin-bottom:12px;">
            <div class="card">
                <div class="muted">PR Number</div>
                <div class="mono"><?= htmlspecialchars((string)$po['pr_number'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="muted" style="margin-top:8px;">Supplier</div>
                <div><?= htmlspecialchars((string)$po['supplier_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php if (!empty($po['vendor_name'])): ?>
                <div class="muted" style="margin-top:8px;">Vendor</div>
                <div><?= htmlspecialchars((string)$po['vendor_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="muted">Status</div>
                <div><span class="badge"><?= htmlspecialchars(ucwords(str_replace('_',' ', (string)$po['status'])), ENT_QUOTES, 'UTF-8') ?></span></div>
                <div class="muted" style="margin-top:8px;">Created</div>
                <div><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$po['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="muted" style="margin-top:8px;">Total</div>
                <div>₱ <?= number_format((float)($po['total'] ?? 0), 2) ?></div>
            </div>
        </div>

        <div class="card" style="margin-bottom:12px;">
            <div class="muted">Meta</div>
            <div style="display:grid; grid-template-columns: repeat(3, minmax(220px,1fr)); gap:10px; margin-top:6px;">
                <div><strong>Center:</strong> <?= htmlspecialchars((string)($po['center'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Reference:</strong> <?= htmlspecialchars((string)($po['reference'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Look For:</strong> <?= htmlspecialchars((string)($po['look_for'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div style="grid-column: 1 / -1;"><strong>Deliver To:</strong> <?= htmlspecialchars((string)($po['deliver_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div style="grid-column: 1 / -1;"><strong>Terms:</strong> <?= nl2br(htmlspecialchars((string)($po['terms'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
                <div style="grid-column: 1 / -1;"><strong>Notes:</strong> <?= nl2br(htmlspecialchars((string)($po['notes'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
            </div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Unit</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): foreach ($items as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$it['description'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$it['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$it['qty'] ?></td>
                            <td>₱ <?= number_format((float)$it['unit_price'], 2) ?></td>
                            <td>₱ <?= number_format((float)$it['line_total'], 2) ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="muted">No items</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
