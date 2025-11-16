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
        .table-scroll{ width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:14px; background:var(--card); border:1px solid var(--border); }
        .table-scroll table{ width:100%; border-collapse: collapse; background:transparent; min-width:1000px; }
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
            <span>
                <?php $show = (string)($filters['show'] ?? 'active'); ?>
                <?php if ($show === 'archived'): ?>
                    <a class="btn" href="/procurement/pos">View Active</a>
                <?php else: ?>
                    <a class="btn" href="/procurement/pos?show=archived">View Archives</a>
                <?php endif; ?>
            </span>
        </div>
        <?php if (isset($_GET['archived']) && (string)$_GET['archived'] === '1'): ?>
            <div style="margin:8px 0 14px;padding:10px 14px;border:1px solid #16a34a;border-radius:10px;background:color-mix(in oklab,#16a34a 12%, transparent);font-size:13px;">
                PO archived successfully. You can view it under <a href="/procurement/pos?show=archived" style="color:#16a34a;text-decoration:none;font-weight:600;">Archives</a>.
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['restored']) && (string)$_GET['restored'] === '1'): ?>
            <div style="margin:8px 0 14px;padding:10px 14px;border:1px solid #2563eb;border-radius:10px;background:color-mix(in oklab,#2563eb 14%, transparent);font-size:13px;">
                PO restored successfully. It now appears back in the Active list.
            </div>
        <?php endif; ?>
        <form class="filters" method="GET" action="/procurement/pos">
            <input type="hidden" name="show" value="<?= htmlspecialchars((string)($filters['show'] ?? 'active'), ENT_QUOTES, 'UTF-8') ?>" />
            <label>Status
                <?php $statuses = ['', 'submitted','po_admin_approved','sent_to_supplier','po_rejected','supplier_response_submitted','draft']; ?>
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
            <label>Branch
                <select name="branch">
                    <option value="">All</option>
                    <?php foreach (($branches ?? []) as $b): $sel=((int)($filters['branch'] ?? 0)===(int)$b['branch_id']); ?>
                        <option value="<?= (int)$b['branch_id'] ?>" <?= $sel?'selected':'' ?>><?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>From
                <input type="date" name="from" value="<?= htmlspecialchars((string)($filters['from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </label>
            <label>To
                <input type="date" name="to" value="<?= htmlspecialchars((string)($filters['to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            </label>
            <button class="btn" type="submit">Apply</button>
        </form>

        <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th class="nowrap">Date</th>
                    <th class="nowrap">PR</th>
                    <th class="nowrap">PO Number</th>
                    <th>Supplier</th>
                    <th class="nowrap">Status</th>
                    <th class="nowrap">Branch</th>
                    <th class="nowrap">Total</th>
                    <th>PDF / Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($pos)): foreach ($pos as $p): ?>
                    <tr class="expandable-row" data-expand-url="/procurement/po/view?id=<?= (int)$p['id'] ?>&partial=1" data-expand-columns="8">
                        <td class="nowrap"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$p['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono"><?= htmlspecialchars(\App\Services\IdService::format('PR', (string)$p['pr_number']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="mono"><?= htmlspecialchars((string)$p['po_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$p['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge"><?= htmlspecialchars(ucwords(str_replace('_',' ', (string)$p['status'])), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td class="nowrap"><?= htmlspecialchars((string)($p['branch_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="nowrap">₱ <?= number_format((float)($p['total'] ?? 0), 2) ?></td>
                        <td>
                            <?php if (!empty($p['pdf_path'])): ?>
                                <a class="btn" href="/procurement/po/download?id=<?= (int)$p['id'] ?>">Download</a>
                                <?php if ((string)($p['status'] ?? '') === 'po_admin_approved' && isset($_SESSION['role']) && in_array((string)$_SESSION['role'], ['procurement_manager','procurement'], true)): ?>
                                    <form method="POST" action="/procurement/po/send" style="display:inline;margin-left:6px;">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>" />
                                        <button type="submit" class="btn primary">Send</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                            <?php
                                $role = $_SESSION['role'] ?? '';
                                $stPO = (string)($p['status'] ?? '');
                                // Negotiation states where procurement can act
                                $canNegotiate = in_array($stPO, ['supplier_response_submitted','terms_counter_proposed'], true) && in_array($role, ['procurement','procurement_manager'], true);
                            ?>
                            <?php if ($canNegotiate): ?>
                                <a href="/procurement/po/view?id=<?= (int)$p['id'] ?>" class="btn" style="margin-left:6px;" title="Open full PO view to review terms">Review Terms</a>
                                <form method="POST" action="/procurement/po/terms/agree" style="display:inline;margin-left:6px;">
                                    <input type="hidden" name="po_id" value="<?= (int)$p['id'] ?>" />
                                    <button type="submit" class="btn primary" title="Agree to supplier's submitted terms">Agree</button>
                                </form>
                                <form method="POST" action="/procurement/po/terms/propose" style="display:inline;margin-left:6px;">
                                    <input type="hidden" name="po_id" value="<?= (int)$p['id'] ?>" />
                                    <input type="hidden" name="proposal" value="" />
                                    <button type="submit" class="btn" title="Send a counter proposal (edit inside PO view for details)">Counter</button>
                                </form>
                            <?php endif; ?>
                            <?php if (isset($_SESSION['role']) && (string)$_SESSION['role'] === 'admin'): ?>
                                <?php $st = (string)($p['status'] ?? ''); $canAdminAct = ($st !== 'po_admin_approved' && $st !== 'po_rejected'); ?>
                                <?php if ($canAdminAct && (string)($filters['show'] ?? 'active') !== 'archived'): ?>
                                    <form method="POST" action="/admin/po/approve" style="display:inline;margin-left:6px;">
                                        <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$p['pr_number'], ENT_QUOTES, 'UTF-8') ?>" />
                                        <input type="hidden" name="po_id" value="<?= (int)$p['id'] ?>" />
                                        <button type="submit" class="btn" title="Approve this PO">Approve</button>
                                    </form>
                                    <form method="POST" action="/admin/po/reject" class="po-reject-form" style="display:inline;margin-left:6px;">
                                        <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$p['pr_number'], ENT_QUOTES, 'UTF-8') ?>" />
                                        <input type="hidden" name="po_id" value="<?= (int)$p['id'] ?>" />
                                        <input type="hidden" name="reason" value="" />
                                        <button type="submit" class="btn" title="Reject this PO">Reject</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((string)($filters['show'] ?? 'active') !== 'archived'): ?>
                                    <form method="POST" action="/admin/po/archive" style="display:inline;margin-left:6px;">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>" />
                                        <button type="submit" class="btn" title="Archive this PO">Archive</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="/admin/po/restore" style="display:inline;margin-left:6px;">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>" />
                                        <button type="submit" class="btn" title="Restore this PO">Restore</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="8" class="muted" style="padding:24px;text-align:center;">
                            <?php if ($show === 'archived'): ?>
                                No archived purchase orders yet.
                                <div style="margin-top:6px;font-size:12px;">Archive a PO from the Active view to see it here.</div>
                                <div style="margin-top:10px;"><a class="btn" href="/procurement/pos">Go to Active List</a></div>
                            <?php else: ?>
                                No purchase orders match your current filters.
                                <div style="margin-top:6px;font-size:12px;">Try clearing filters or check <a href="/procurement/pos?show=archived" style="color:inherit;text-decoration:underline;">Archives</a>.</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </main>
</div>
</body>
<script>
// Prompt for reject reason before submitting
document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest) return;
    var form = t.closest('form.po-reject-form');
    if (form && t.tagName === 'BUTTON') {
        e.preventDefault();
        var msg = prompt('Enter reason for rejection (optional):', '');
        if (msg === null) return; // cancelled
        form.querySelector('input[name="reason"]').value = msg || '';
        form.submit();
    }
});
</script>
</html>
