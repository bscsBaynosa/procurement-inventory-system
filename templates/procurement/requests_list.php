<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement • Purchase Requests</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <?php require __DIR__ . '/../layouts/_theme.php'; ?>
    <style>
        :root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#2563eb; }
        html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#2563eb; }
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .layout{ display:grid; grid-template-columns: 240px 1fr; min-height:100vh; }
        .sidebar{ background:#fff; border-right:1px solid var(--border); padding:18px 12px; position:sticky; top:0; height:100vh; }
        html[data-theme="dark"] .sidebar{ background:#0f172a; }
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .toolbar{ display:flex; gap:8px; align-items:center; }
        .btn{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); text-decoration:none; font-size:12px; cursor:pointer; }
        .btn.primary{ border-color: color-mix(in oklab, var(--accent) 35%, var(--border)); background: color-mix(in oklab, var(--accent) 10%, transparent); }
        .filters{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
        select, input[type="date"]{ padding:6px 8px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; vertical-align:top; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .muted{ color:var(--muted); }
        .actions{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
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
            <span>Purchase Requests</span>
            <div class="toolbar">
                <a class="btn" href="/manager/requests/history">History</a>
            </div>
        </div>

        <form class="filters" method="GET" action="/manager/requests">
            <label>Branch
                <select name="branch">
                    <option value="">All</option>
                    <?php 
                    $pdo = \App\Database\Connection::resolve();
                    $bs = $pdo->query('SELECT branch_id, name FROM branches ORDER BY name ASC')->fetchAll();
                    foreach ($bs as $b): $sel = (isset($filters['branch']) && (int)$filters['branch'] === (int)$b['branch_id']); ?>
                        <option value="<?= (int)$b['branch_id'] ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <?php $statuses = [''=>'All','pending'=>'For Admin Approval','approved'=>'Approved','rejected'=>'Rejected','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled']; ?>
                <select name="status">
                    <?php foreach ($statuses as $val => $label): $sel = (string)($filters['status'] ?? '') === (string)$val; ?>
                        <option value="<?= htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Sort by
                <select name="sort">
                    <?php $sorts=['date'=>'Submission date','branch'=>'Branch','status'=>'Status']; foreach ($sorts as $k=>$v): $sel=(($filters['sort']??'date')===$k); ?>
                        <option value="<?= $k ?>" <?= $sel?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Order
                <select name="order">
                    <?php $orders=['desc'=>'Desc','asc'=>'Asc']; foreach ($orders as $k=>$v): $sel=(($filters['order']??'desc')===$k); ?>
                        <option value="<?= $k ?>" <?= $sel?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn" type="submit">Apply</button>
        </form>

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
                            $labelMap = ['pending'=>'For Admin Approval','approved'=>'Approved','rejected'=>'Rejected','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];
                            $statusLabel = $labelMap[$status] ?? $status;
                            $canProcess = ($status === 'approved');
                        ?>
                        <tr>
                            <td class="mono"><?= htmlspecialchars((string)$g['pr_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($g['branch_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><pre style="margin:0;white-space:pre-wrap;line-height:1.3;"><?= htmlspecialchars((string)($g['items_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td>
                            <td class="nowrap"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)($g['min_created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($g['requested_by_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <div class="actions">
                                    <form action="/manager/requests/update-group-status" method="POST" style="display:inline-flex; gap:6px; align-items:center;">
                                        <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$g['pr_number'], ENT_QUOTES, 'UTF-8') ?>" />
                                        <select name="status">
                                            <?php foreach ($labelMap as $val=>$lab): ?>
                                                <option value="<?= $val ?>" <?= ($status===$val?'selected':'') ?>><?= $lab ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn" type="submit">Update</button>
                                    </form>
                                    <a class="btn" href="/manager/requests/view?pr=<?= urlencode((string)$g['pr_number']) ?>">View</a>
                                    <a class="btn" href="/manager/requests/download?pr=<?= urlencode((string)$g['pr_number']) ?>">Download PDF</a>
                                    <form action="/manager/requests/archive" method="POST" onsubmit="return confirm('Archive this Purchase Request?');" style="display:inline;">
                                        <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$g['pr_number'], ENT_QUOTES, 'UTF-8') ?>" />
                                        <button class="btn" type="submit">Archive</button>
                                    </form>
                                    <?php if (!$canProcess): ?>
                                        <form action="/manager/requests/send-for-approval" method="POST" style="display:inline;">
                                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$g['pr_number'], ENT_QUOTES, 'UTF-8') ?>" />
                                            <button class="btn primary" type="submit" title="Send to Admin for approval with attached PR PDF">Send for Admin Approval</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="muted">No purchase requests found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </main>
</div>
</body>
</html>
