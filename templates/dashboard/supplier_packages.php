<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier • Packages</title>
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
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; margin-bottom:12px; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        input, textarea, select{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
        .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Package Deals</h2>
        <div class="card">
            <form method="POST" action="/supplier/packages">
                <div style="display:grid; grid-template-columns: 1.2fr 1fr 0.6fr; gap:10px; align-items:end;">
                    <div>
                        <label>Name</label>
                        <input name="name" placeholder="Starter Office Bundle" required />
                    </div>
                    <div>
                        <label>Description</label>
                        <input name="description" placeholder="Paper + Pens + Folders" />
                    </div>
                    <div>
                        <label>Package Price</label>
                        <input type="number" name="price" min="0" step="0.01" value="0" />
                    </div>
                </div>
                <div style="margin-top:10px;display:flex;gap:10px;">
                    <button class="btn" type="submit">Add Package</button>
                    <a href="/dashboard" class="btn muted">Back</a>
                </div>
            </form>
        </div>
        <div class="card">
            <table>
                <thead><tr><th>Package</th><th>Description</th><th>Price</th><th>Items</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (!empty($packages)): foreach ($packages as $p): $pid=(int)$p['id']; ?>
                        <tr id="pkg-<?= $pid ?>">
                            <td><?= htmlspecialchars((string)$p['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($p['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>₱ <?= number_format((float)$p['price'], 2) ?></td>
                            <td>
                                <?php $rows = $byPkg[$pid] ?? []; if ($rows): ?>
                                    <ul style="margin:0 0 0 18px;">
                                        <?php foreach ($rows as $r): ?>
                                            <li>
                                                <?= htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8') ?> × <?= (int)$r['quantity'] ?>
                                                <form method="POST" action="/supplier/packages/items/delete" onsubmit="return confirm('Remove this item from package?');" style="display:inline-block; margin-left:6px;">
                                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" />
                                                    <button class="btn muted" type="submit">Remove</button>
                                                </form>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="muted">No items yet.</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn muted" onclick="toggleEdit(<?= $pid ?>)" type="button">Edit</button>
                                <form method="POST" action="/supplier/packages/delete" onsubmit="return confirm('Delete this package?');" style="display:inline-block; margin-left:6px;">
                                    <input type="hidden" name="id" value="<?= $pid ?>" />
                                    <button class="btn muted" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <tr id="pkg-edit-<?= $pid ?>" style="display:none; background:color-mix(in oklab, var(--card) 92%, var(--bg));">
                            <td colspan="5">
                                <form method="POST" action="/supplier/packages/update" style="display:grid; grid-template-columns: 1.2fr 1fr 0.6fr auto; gap:8px; align-items:end;">
                                    <input type="hidden" name="id" value="<?= $pid ?>" />
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Name</label>
                                        <input name="name" value="<?= htmlspecialchars((string)$p['name'], ENT_QUOTES, 'UTF-8') ?>" />
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Description</label>
                                        <input name="description" value="<?= htmlspecialchars((string)($p['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Package Price</label>
                                        <input name="price" type="number" min="0" step="0.01" value="<?= number_format((float)$p['price'], 2, '.', '') ?>" />
                                    </div>
                                    <div style="display:flex; gap:6px;">
                                        <button class="btn" type="submit">Save</button>
                                        <button class="btn muted" type="button" onclick="toggleEdit(<?= $pid ?>)">Cancel</button>
                                    </div>
                                </form>
                                <div style="margin-top:12px;">
                                    <strong>Add items to this package</strong>
                                    <form method="POST" action="/supplier/packages/items/add" style="display:grid; grid-template-columns: 1.4fr 0.6fr 0.4fr auto; gap:8px; align-items:end;">
                                        <input type="hidden" name="package_id" value="<?= $pid ?>" />
                                        <div>
                                            <label style="font-size:12px;color:var(--muted)">Item</label>
                                            <select name="supplier_item_id">
                                                <?php foreach ($items as $it): ?>
                                                    <option value="<?= (int)$it['id'] ?>"><?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="font-size:12px;color:var(--muted)">Quantity</label>
                                            <input type="number" name="quantity" min="1" value="1" />
                                        </div>
                                        <div></div>
                                        <div><button class="btn" type="submit">Add Item</button></div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="muted">No packages yet. Create your first bundle above.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
<script>
function toggleEdit(id){
    var row = document.getElementById('pkg-edit-'+id);
    if(!row) return;
    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
}
</script>
</html>
