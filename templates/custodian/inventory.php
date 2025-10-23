<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custodian • Inventory</title>
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
        <div class="grid">
            <div class="card">
                <div style="font-weight:700; margin-bottom:8px;">Add Item</div>
                <form method="POST" action="/custodian/inventory">
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
            <div class="card">
                <div style="font-weight:700; margin-bottom:8px;">Items</div>
                <table>
                    <thead>
                    <tr><th>Name</th><th>Category</th><th>Status</th><th>Qty</th><th>Unit</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($items)): foreach ($items as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($it['category'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($it['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)($it['quantity'] ?? 0) ?></td>
                            <td><?= htmlspecialchars((string)($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="actions">
                                <form method="POST" action="/custodian/inventory/update" style="display:inline-flex; gap:6px; align-items:center;">
                                    <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                                    <select name="status">
                                        <option value="good" <?= ($it['status']==='good'?'selected':'') ?>>Good</option>
                                        <option value="for_repair" <?= ($it['status']==='for_repair'?'selected':'') ?>>For Repair</option>
                                        <option value="for_replacement" <?= ($it['status']==='for_replacement'?'selected':'') ?>>For Replacement</option>
                                    </select>
                                    <button class="btn muted" type="submit">Update</button>
                                </form>
                                <form method="POST" action="/custodian/inventory/delete" onsubmit="return confirm('Delete this item?');">
                                    <input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>">
                                    <button class="btn muted" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" style="color:var(--muted)">No items yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
