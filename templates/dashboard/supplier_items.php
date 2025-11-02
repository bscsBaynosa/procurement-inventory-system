<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier • Items</title>
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
    .grid{ display:grid; grid-template-columns: 420px 1fr; gap:14px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
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
        <h2 style="margin:0 0 12px 0;">Items Listing</h2>
        <div class="grid">
            <div class="card">
                <form method="POST" action="/supplier/items">
                    <label>Product name</label>
                    <input name="name" placeholder="Bond paper" required />
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="e.g., A4 size white"></textarea>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <div>
                            <label>Package label</label>
                            <select name="package_label">
                                <option value="rim">rim</option>
                                <option value="box">box</option>
                                <option value="pack">pack</option>
                                <option value="bundle">bundle</option>
                                <option value="unit">unit</option>
                            </select>
                        </div>
                        <div>
                            <label>Pieces per package</label>
                            <input name="pieces_per_package" type="number" min="1" value="500" />
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; margin-top:10px;">
                        <div style="flex:1;">
                            <label>Price (per package)</label>
                            <input name="price" type="number" step="0.01" min="0" value="0" required />
                        </div>
                        <div style="width:140px;">
                            <label>Unit</label>
                            <input name="unit" value="pcs" />
                        </div>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:10px;">
                        <button class="btn" type="submit">Add Item</button>
                        <a href="/dashboard" class="btn muted">Back</a>
                    </div>
                </form>
            </div>
            <div class="card">
                <table>
                    <thead><tr><th>Name</th><th>Description</th><th>Package</th><th>Price</th><th>Unit</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!empty($items)): foreach ($items as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($it['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php
                                    $pp = (int)($it['pieces_per_package'] ?? 1);
                                    $unit = htmlspecialchars((string)($it['unit'] ?? 'pcs'), ENT_QUOTES, 'UTF-8');
                                    $pl = htmlspecialchars((string)($it['package_label'] ?? 'pack'), ENT_QUOTES, 'UTF-8');
                                    // Example: 500 pcs per 1 rim
                                    echo $pp . ' ' . $unit . ' per 1 ' . $pl;
                                ?>
                            </td>
                            <td><?= number_format((float)$it['price'], 2) ?></td>
                            <td><?= htmlspecialchars((string)$it['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <button class="btn muted" onclick="toggleEditRow(<?= (int)$it['id'] ?>)" type="button">Edit</button>
                                <form method="POST" action="/supplier/items/delete" onsubmit="return confirm('Delete this item?');" style="display:inline-block; margin-left:6px;">
                                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>" />
                                    <button class="btn muted" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <tr id="edit-row-<?= (int)$it['id'] ?>" style="display:none; background:color-mix(in oklab, var(--card) 92%, var(--bg));">
                            <td colspan="6">
                                <form method="POST" action="/supplier/items/update" style="display:grid; grid-template-columns: 1.2fr 1fr 1fr 0.8fr 0.8fr 0.6fr auto; gap:8px; align-items:end;">
                                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>" />
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Name</label>
                                        <input name="name" value="<?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?>" />
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Description</label>
                                        <input name="description" value="<?= htmlspecialchars((string)($it['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Pieces per package</label>
                                        <input name="pieces_per_package" type="number" min="1" value="<?= (int)($it['pieces_per_package'] ?? 1) ?>" />
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Package label</label>
                                        <select name="package_label">
                                            <?php $opts=['rim','box','pack','bundle','unit']; $cur=(string)($it['package_label'] ?? 'pack'); foreach($opts as $o): ?>
                                                <option value="<?= $o ?>" <?= $cur===$o?'selected':'' ?>><?= $o ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Price (per package)</label>
                                        <input name="price" type="number" step="0.01" min="0" value="<?= number_format((float)$it['price'], 2, '.', '') ?>" />
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Unit</label>
                                        <input name="unit" value="<?= htmlspecialchars((string)$it['unit'], ENT_QUOTES, 'UTF-8') ?>" />
                                    </div>
                                    <div style="display:flex; gap:6px;">
                                        <button class="btn" type="submit">Save</button>
                                        <button class="btn muted" type="button" onclick="toggleEditRow(<?= (int)$it['id'] ?>)">Cancel</button>
                                    </div>
                                </form>
                                <?php $tid = (int)$it['id']; $tiersFor = $tiers[$tid] ?? []; ?>
                                <div style="margin-top:12px;">
                                    <strong>Price tiers</strong>
                                    <div class="muted" style="font-size:12px;margin:4px 0 8px;">Add quantity-based price breaks by number of packages.</div>
                                    <table style="width:100%; border:1px solid var(--border); border-radius:8px; overflow:hidden;">
                                        <thead><tr><th style="width:180px;">Min packages</th><th style="width:180px;">Max packages</th><th style="width:220px;">Price per package</th><th>Note</th><th style="width:120px;">Actions</th></tr></thead>
                                        <tbody>
                                            <?php if ($tiersFor): foreach ($tiersFor as $t): ?>
                                                <tr>
                                                    <td><?= (int)$t['min_packages'] ?></td>
                                                    <td><?= $t['max_packages'] !== null ? (int)$t['max_packages'] : '—' ?></td>
                                                    <td>₱ <?= number_format((float)$t['price_per_package'], 2) ?></td>
                                                    <td><?= htmlspecialchars((string)($t['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td>
                                                        <form method="POST" action="/supplier/items/tiers/delete" onsubmit="return confirm('Delete this tier?');">
                                                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>" />
                                                            <button class="btn muted" type="submit">Delete</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; else: ?>
                                                <tr><td colspan="5" class="muted">No tiers yet.</td></tr>
                                            <?php endif; ?>
                                            <tr>
                                                <form method="POST" action="/supplier/items/tiers/add">
                                                    <input type="hidden" name="supplier_item_id" value="<?= (int)$it['id'] ?>" />
                                                    <td><input type="number" name="min_packages" min="1" value="1" /></td>
                                                    <td><input type="number" name="max_packages" min="1" placeholder="(blank = no max)" /></td>
                                                    <td><input type="number" name="price_per_package" min="0" step="0.01" value="0" /></td>
                                                    <td><input type="text" name="note" placeholder="e.g., wholesale" /></td>
                                                    <td><button class="btn" type="submit">Add tier</button></td>
                                                </form>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" style="color:#64748b;">No items yet. Add your first product on the left.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
<script>
function toggleEditRow(id){
    var row = document.getElementById('edit-row-'+id);
    if(!row) return;
    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
}
</script>
</html>
