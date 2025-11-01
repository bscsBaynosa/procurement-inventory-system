<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager • Requests</title>
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
        .brand{ display:flex; align-items:center; gap:10px; font-weight:800; padding:6px 10px; }
        .nav{ margin-top:14px; display:flex; flex-direction:column; gap:6px; }
        .nav a{ display:flex; align-items:center; gap:10px; padding:10px 12px; color:var(--text); text-decoration:none; border-radius:10px; }
        .nav a:hover{ background:var(--bg); }
        .nav a.active{ background: color-mix(in oklab, var(--accent) 10%, transparent); color: var(--text); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); }
        .nav svg{ width:18px; height:18px; fill: var(--accent); }
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; }
        .grid{ display:grid; grid-template-columns: repeat(auto-fill,minmax(260px,1fr)); gap:12px; margin-bottom:14px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:12px; }
        .card h3{ margin:0 0 6px; font-size:14px; color:var(--muted); font-weight:600; }
        .stats{ display:flex; gap:8px; flex-wrap:wrap; font-size:12px; color:var(--muted); }
        .badge{ background:color-mix(in oklab, var(--accent) 12%, transparent); color:var(--text); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); padding:4px 8px; border-radius:999px; }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .muted{ color:var(--muted); }
        .actions{ display:flex; gap:8px; align-items:center; }
        .btn{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); text-decoration:none; font-size:12px; cursor:pointer; }
        .btn:hover{ background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn.primary{ border-color: color-mix(in oklab, var(--accent) 35%, var(--border)); background: color-mix(in oklab, var(--accent) 10%, transparent); }
        select.inline{ padding:6px 8px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">Manager Overview</div>
        <?php if (!empty($branchStats)): ?>
            <div class="grid">
                <?php foreach ($branchStats as $b): ?>
                    <div class="card">
                        <h3><?= htmlspecialchars((string)($b['name'] ?? 'Branch'), ENT_QUOTES, 'UTF-8') ?></h3>
                        <div class="stats">
                            <span class="badge">Total: <?= (int)($b['total'] ?? 0) ?></span>
                            <span class="badge">Good: <?= (int)($b['good'] ?? 0) ?></span>
                            <span class="badge">For Repair: <?= (int)($b['for_repair'] ?? 0) ?></span>
                            <span class="badge">For Replacement: <?= (int)($b['for_replacement'] ?? 0) ?></span>
                            <span class="badge">Retired: <?= (int)($b['retired'] ?? 0) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="h1" style="margin-top:12px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
            <span>Purchase Requests</span>
            <span style="display:flex; gap:8px;">
                <a class="btn" href="/manager/requests">Open Grouped View</a>
                <a class="btn" href="/manager/requests/history">History</a>
            </span>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="nowrap">PR ID</th>
                    <th>Branch</th>
                    <th>Items Requested</th>
                    <th class="nowrap">Submission date</th>
                    <th class="nowrap">Submitted By</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($groups)): ?>
                    <?php foreach ($groups as $g): ?>
                        <?php 
                            $status = (string)($g['status'] ?? 'pending');
                            $labelMap = [
                                'pending'=>'For Admin Approval',
                                'approved'=>'Approved',
                                'canvassing_submitted'=>'Canvassing Submitted',
                                'canvassing_approved'=>'Canvassing Approved',
                                'canvassing_rejected'=>'Canvassing Rejected',
                                'rejected'=>'Rejected',
                                'in_progress'=>'In Progress',
                                'completed'=>'Completed',
                                'cancelled'=>'Cancelled'
                            ];
                            $statusLabel = $labelMap[$status] ?? $status;
                        ?>
                        <tr>
                            <td class="mono"><?= htmlspecialchars((string)$g['pr_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($g['branch_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><pre style="margin:0;white-space:pre-wrap;line-height:1.3;max-height:3.2em;overflow:hidden;"><?= htmlspecialchars((string)($g['items_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td>
                            <td class="nowrap"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)($g['min_created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($g['requested_by_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <div class="actions">
                                    <form action="/manager/requests/update-group-status" method="POST" style="display:inline-flex; gap:6px; align-items:center;">
                                        <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$g['pr_number'], ENT_QUOTES, 'UTF-8') ?>" />
                                        <select class="inline" name="status">
                                            <?php foreach ($labelMap as $val => $lab): ?>
                                                <option value="<?= $val ?>" <?= ($status===$val?'selected':'') ?>><?= $lab ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn primary" type="submit">Update</button>
                                    </form>
                                    <a class="btn" href="/manager/requests/view?pr=<?= urlencode((string)$g['pr_number']) ?>">View</a>
                                    <a class="btn" href="/manager/requests/download?pr=<?= urlencode((string)$g['pr_number']) ?>">Download PDF</a>
                                    <?php if (!empty($g['requested_by_id'])): ?>
                                        <a class="btn" href="/admin/messages?to=<?= urlencode((string)$g['requested_by_id']) ?>&subject=<?= urlencode('Regarding PR ' . (string)$g['pr_number'] . ' - ' . $statusLabel) ?>">Message</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="muted">No purchase requests found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </main>
</div>
</body>
</html>