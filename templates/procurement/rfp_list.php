<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Procurement • RFP Eligible POs</title>
    <link rel="stylesheet" href="/css/main.css" />
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <?php require __DIR__ . '/../layouts/_theme.php'; ?>
    <style>
        /* Use global palette variables; keep accent consistent with main theme */
        :root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#22c55e; }
        html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#22c55e; }
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .layout{ display:grid; grid-template-columns:240px 1fr; min-height:100vh; }
        .content{ padding:18px 20px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse:collapse; }
        th,td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab,var(--card) 92%, var(--bg)); }
        /* Remove local .btn overrides; rely on global .btn.primary from main.css for solid green buttons */
        .muted{ color:var(--muted); }
        .badge{ background:color-mix(in oklab,var(--accent) 12%, transparent); border:1px solid color-mix(in oklab,var(--accent) 35%, var(--border)); padding:2px 6px; border-radius:999px; font-size:11px; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <h2 style="margin:0;">RFP Eligible Purchase Orders</h2>
                <a class="btn" href="/procurement/pos">Back to POs</a>
            </div>
            <p class="muted" style="margin:10px 0 16px; font-size:13px;">List of Purchase Orders whose terms are fully agreed and can proceed to Request For Payment. Click Generate to open the RFP form prefilled.</p>
            <table>
                <thead><tr><th>Created</th><th>PR</th><th>PO Number</th><th>Status</th><th>Terms</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (!empty($rows)): foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$r['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono"><?= htmlspecialchars(\App\Services\IdService::format('PR', (string)$r['pr_number']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono"><?= htmlspecialchars((string)$r['po_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge"><?= htmlspecialchars(ucwords(str_replace('_',' ', (string)($r['status'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string)($r['terms_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><a class="btn primary" href="/procurement/rfp/create?po=<?= (int)$r['id'] ?>">Generate</a></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;padding:18px;">No eligible POs yet. Agree to supplier terms first.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
