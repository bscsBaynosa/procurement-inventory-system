<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Request • History</title>
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
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn{ background:var(--accent); color:#fff; border:0; padding:8px 10px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
        .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Purchase Request • History</h2>
        <div class="card">
            <table>
                <thead><tr><th>PR No.</th><th>File</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (!empty($rows)): foreach ($rows as $r): ?>
                    <tr>
                        <td class="mono"><?= htmlspecialchars((string)$r['pr_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono">
                            <?= $r['attachment_name'] ? htmlspecialchars((string)$r['attachment_name'], ENT_QUOTES, 'UTF-8') : '<span style="color:#64748b">No PDF yet</span>' ?>
                        </td>
                        <td><?= htmlspecialchars((string)$r['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if (!empty($r['message_id'])): ?>
                                <a class="btn" href="/inbox/download?id=<?= (int)$r['message_id'] ?>">Download</a>
                            <?php else: ?>
                                <a class="btn" href="/admin-assistant/requests/history/generate?pr=<?= urlencode((string)$r['pr_number']) ?>">Generate PDF</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" style="color:#64748b">No history yet. Submit a Purchase Request to generate a PDF.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
