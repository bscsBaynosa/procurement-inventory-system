<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • PR History</title>
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
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1" style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
            <span>Admin • PR History</span>
            <span style="display:flex; gap:8px;">
                <a class="btn" href="/admin/requests">Back to Requests</a>
                <a class="btn" href="/dashboard">Dashboard</a>
            </span>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="nowrap">PR ID</th>
                    <th>Branch</th>
                    <th>Items Requested</th>
                    <th class="nowrap">Created</th>
                    <th class="nowrap">Submitted By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($groups)): ?>
                    <?php foreach ($groups as $g): ?>
                        <tr>
                            <td class="mono"><?= htmlspecialchars((string)$g['pr_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($g['branch_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><pre style="margin:0;white-space:pre-wrap;line-height:1.3;max-height:3.2em;overflow:hidden;">&ZeroWidthSpace;<?= htmlspecialchars((string)($g['items_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td>
                            <td class="nowrap"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)($g['min_created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($g['requested_by_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="muted">No archived PRs.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </main>
</div>
</body>
</html>
