<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Assistant • Inventory</title>
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
    .grid{ display:grid; grid-template-columns: 360px 1fr; gap:14px; }
    .grid.single{ grid-template-columns: 1fr; }
        @media (max-width: 980px){ .layout{ grid-template-columns: 1fr; } .sidebar{ position:relative;height:auto; } .grid{ grid-template-columns: 1fr; } }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        label{ display:block; font-size:13px; color:var(--muted); margin:8px 0 6px; }
        input[type="text"], input[type="number"], select, textarea{ width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid var(--border); border-radius:10px; font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .row{ display:flex; gap:10px; }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .actions form{ display:inline; }
        .btn{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-weight:700; font-size:14px; line-height:1; height:40px; padding:0 14px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; transition:all .15s ease; cursor:pointer; }
        .btn.primary{ background:var(--accent); color:#fff; border:0; }
        .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">Inventory</div>
        <?php $sel = $selected_category ?? ''; $cats = $categories ?? []; ?>
        <div class="card" style="margin-bottom:12px;">
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <div style="font-weight:700;">Categories:</div>
                <?php foreach ($cats as $c): $active = (strcasecmp($sel,$c)===0); ?>
                    <a class="btn <?= $active?'primary':'muted' ?>" href="/admin-assistant/inventory?category=<?= rawurlencode($c) ?>"><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
                <a class="btn muted" href="/admin-assistant/inventory">All</a>
                <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
                    <form method="GET" action="/admin-assistant/inventory" style="display:flex; gap:6px; align-items:center;">
                        <input type="hidden" name="category" value="<?= htmlspecialchars($sel, ENT_QUOTES, 'UTF-8') ?>" />
                        <input name="q" placeholder="Search name…" value="<?= htmlspecialchars((string)($search ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                        <select name="status">
                            <option value="">All</option>
                            <option value="low" <?= (($filter_status ?? '')==='low'?'selected':'') ?>>Low stock</option>
                            <option value="ok" <?= (($filter_status ?? '')==='ok'?'selected':'') ?>>OK</option>
                        </select>
                        <button class="btn muted" type="submit">Filter</button>
                    </form>
                    <a class="btn muted" href="/admin-assistant/requests/review">Proceed to Purchase Requisition (<?= (int)($cart_count ?? 0) ?>)</a>
                </div>
            </div>
        </div>
        <?php $role = $_SESSION['role'] ?? ''; if ($role === 'custodian') $role = 'admin_assistant'; ?>
        <div class="grid <?= ($role === 'admin_assistant') ? 'single' : '' ?>">
            <?php if ($role !== 'admin_assistant'): ?>
            <div class="card">
                <div style="font-weight:700; margin-bottom:8px;">Add Item</div>
                <form method="POST" action="/admin-assistant/inventory">
                    <label for="name">Item Name</label>
                    <input id="name" name="name" type="text" required>

                    <label for="category">Category</label>
                    <input id="category" name="category" type="text" placeholder="e.g., Laptop, Aircon" required>

                    <div class="row">
                        <div style="flex:1;">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <option value="good">Good</option>
                                <option value="for_repair">For Repair</option>
                                <option value="for_replacement">For Replacement</option>
                            </select>
                        </div>
                        <div style="width:120px;">
                            <label for="quantity">Qty</label>
                            <input id="quantity" name="quantity" type="number" min="1" value="1" required>
                        </div>
                        <div style="width:120px;">
                            <label for="unit">Unit</label>
                            <input id="unit" name="unit" type="text" value="pcs">
                        </div>
                    </div>
                    <div style="margin-top:10px; display:flex; gap:8px;">
                        <button class="btn primary" type="submit">Add Item</button>
                        <a href="/dashboard" class="btn muted">Back</a>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            <div class="card">
                <div style="font-weight:700; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                    <div>Items <?= $sel!==''? ('• ' . htmlspecialchars($sel, ENT_QUOTES, 'UTF-8')):'' ?></div>
                    <?php if ($sel!==''): ?>
                        <div style="display:flex; gap:8px;">
                            <a class="btn muted" href="/admin-assistant/reports/inventory?category=<?= rawurlencode($sel) ?>&download=1">Download Inventory Report</a>
                            <a class="btn muted" href="/admin-assistant/reports/consumption?category=<?= rawurlencode($sel) ?>&download=1">Download Consumption Report</a>
                        </div>
                    <?php endif; ?>
                </div>
                <form method="POST" action="/admin-assistant/inventory/cart-add">
                <table>
                    <thead>
                    <tr><th></th><th>Name</th><th>Category</th><th>Stocks</th><th>Status</th><th>Unit</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($items)): foreach ($items as $it): 
                        $qty = (int)($it['quantity'] ?? 0);
                        $min = isset($it['minimum_quantity']) ? (int)$it['minimum_quantity'] : 0;
                        $isLow = $qty <= $min && $min > 0; ?>
                        <tr>
                            <td><?php if ($isLow): ?><input type="checkbox" name="item_ids[]" value="<?= (int)$it['item_id'] ?>" /><?php endif; ?></td>
                            <td><?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($it['category'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:8px;">
                                    <span style="width:10px;height:10px;border-radius:999px;background:<?= $isLow?'#ef4444':'#22c55e' ?>;border:1px solid var(--border);"></span>
                                    <?= $qty ?> <?= htmlspecialchars((string)($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($min>0): ?>
                                        <span class="muted" style="font-size:12px;">(min <?= $min ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string)($it['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="actions">
                                <?php if (($selected_category ?? '') !== ''): ?>
                                    <form method="POST" action="/admin-assistant/inventory/update-stock" style="display:inline-flex; gap:6px; align-items:center;">
                                        <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>" />
                                        <input type="hidden" name="category" value="<?= htmlspecialchars((string)$selected_category, ENT_QUOTES, 'UTF-8') ?>" />
                                        <input name="new_count" type="number" min="0" value="<?= $qty ?>" style="width:110px;" />
                                        <button class="btn muted" type="submit">Save Count</button>
                                    </form>
                                    <form method="POST" action="/admin-assistant/inventory/update-meta" style="display:inline-flex; gap:6px; align-items:center; margin-top:6px;">
                                        <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>" />
                                        <input type="hidden" name="category" value="<?= htmlspecialchars((string)$selected_category, ENT_QUOTES, 'UTF-8') ?>" />
                                        <input name="minimum_quantity" type="number" min="0" value="<?= (int)$min ?>" title="Minimum quantity" placeholder="Min" style="width:95px;" />
                                        <input name="unit" type="text" value="<?= htmlspecialchars((string)($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" title="Unit" placeholder="Unit" style="width:95px;" />
                                        <select name="status" title="Status" style="height:36px;">
                                            <?php $st = (string)($it['status'] ?? 'good'); ?>
                                            <option value="good" <?= $st==='good'?'selected':'' ?>>Good</option>
                                            <option value="for_repair" <?= $st==='for_repair'?'selected':'' ?>>For Repair</option>
                                            <option value="for_replacement" <?= $st==='for_replacement'?'selected':'' ?>>For Replacement</option>
                                            <option value="retired" <?= $st==='retired'?'selected':'' ?>>Retired</option>
                                        </select>
                                        <button class="btn muted" type="submit">Save Meta</button>
                                    </form>
                                    <?php if ($isLow): ?>
                                        <span class="muted" style="font-size:12px;">Low — select at left to request</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">Select a category to edit stocks</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" style="color:var(--muted)">No items yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <div style="margin-top:10px; display:flex; gap:8px;">
                    <button class="btn muted" type="submit">Add selected low‑stock items</button>
                    <a class="btn primary" href="/admin-assistant/requests/review">Proceed to Purchase Requisition (<?= (int)($cart_count ?? 0) ?>)</a>
                </div>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
