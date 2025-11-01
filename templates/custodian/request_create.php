<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Assistant • Review Purchase Requisition</title>
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
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        label{ display:block; font-size:13px; color:var(--muted); margin:8px 0 6px; }
        input[type="text"], input[type="number"], input[type="date"], select, textarea{ width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid var(--border); border-radius:10px; }
        .btn{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-weight:700; font-size:14px; line-height:1; height:40px; padding:0 14px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; transition:all .15s ease; cursor:pointer; }
        .btn.primary{ background:var(--accent); color:#fff; border:0; }
        .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">Review Purchase Requisition</div>
        <div class="card">
            <form method="POST" action="/admin-assistant/requests/submit" id="prForm">
                <div style="margin-bottom:10px; display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
                    <div style="min-width:320px;">
                        <label for="add_item">Add Item</label>
                        <select id="add_item">
                            <option value="">Select item…</option>
                            <?php if (!empty($items)): foreach ($items as $it): ?>
                                <option value="<?= (int)$it['item_id'] ?>" data-name="<?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?>" data-qty="<?= (int)($it['quantity'] ?? 0) ?>" data-unit="<?= htmlspecialchars((string)($it['unit'] ?? 'pcs'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>
                    <div style="width:140px;">
                        <label for="add_qty">Qty</label>
                        <input id="add_qty" type="number" min="1" value="1" />
                    </div>
                    <div style="width:180px;">
                        <label for="add_unit">Unit</label>
                        <input id="add_unit" type="text" value="pcs" />
                    </div>
                    <div>
                        <button class="btn muted" type="button" id="btnAddRow">Add to List</button>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr><th>Item</th><th>Current Stock</th><th>Request Qty</th><th>Unit</th><th></th></tr>
                    </thead>
                    <tbody id="rows">
                        <!-- dynamic rows go here -->
                    </tbody>
                </table>
                <?php if (!empty($pr_preview)): ?>
                    <div style="margin-top:10px; font-size:13px; color:var(--muted);">Next Requisition ID (preview): <strong style="color:var(--text); font-weight:800;\"><?= htmlspecialchars((string)$pr_preview, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <?php endif; ?>

                <?php
                // Build low-stock suggestions
                $low = [];
                if (!empty($items)) {
                    foreach ($items as $it) {
                        $qty = (int)($it['quantity'] ?? 0);
                        $min = isset($it['minimum_quantity']) ? (int)$it['minimum_quantity'] : 0;
                        $maint = isset($it['maintaining_quantity']) ? (int)$it['maintaining_quantity'] : 0;
                        $halfMaint = $maint > 0 ? (int)floor($maint * 0.5) : 0;
                        $threshold = max($min, $halfMaint);
                        if ($threshold > 0 && $qty <= $threshold) { $low[] = $it; }
                    }
                }
                ?>
                <?php if (!empty($low)): ?>
                <div style="margin-top:12px;">
                    <div style="font-weight:700; margin-bottom:6px; color:var(--muted);">Low on stock suggestions</div>
                    <div style="max-height:220px; overflow:auto; border:1px solid var(--border); border-radius:12px;">
                        <table style="border:none; border-radius:0;">
                            <thead>
                                <tr><th>Item</th><th>Current</th><th>Threshold</th><th>Unit</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($low as $li): 
                                    $qty = (int)($li['quantity'] ?? 0);
                                    $min = isset($li['minimum_quantity']) ? (int)$li['minimum_quantity'] : 0;
                                    $maint = isset($li['maintaining_quantity']) ? (int)$li['maintaining_quantity'] : 0;
                                    $halfMaint = $maint > 0 ? (int)floor($maint * 0.5) : 0;
                                    $threshold = max($min, $halfMaint);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$li['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= $qty ?></td>
                                    <td><?= (int)$threshold ?></td>
                                    <td><?= htmlspecialchars((string)($li['unit'] ?? 'pcs'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><button class="btn muted" type="button" data-add-low
                                               data-id="<?= (int)$li['item_id'] ?>"
                                               data-name="<?= htmlspecialchars((string)$li['name'], ENT_QUOTES, 'UTF-8') ?>"
                                               data-qty="<?= $qty ?>"
                                               data-unit="<?= htmlspecialchars((string)($li['unit'] ?? 'pcs'), ENT_QUOTES, 'UTF-8') ?>">Add</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:12px;">
                    <div>
                        <label for="justification">Justification</label>
                        <textarea id="justification" name="justification" rows="3" placeholder="Optional note (e.g., monthly replenishment)"></textarea>
                    </div>
                    <div>
                        <label for="needed_by">Needed By</label>
                        <input type="date" id="needed_by" name="needed_by" />
                    </div>
                </div>
                <div style="margin-top:12px; display:flex; gap:8px;">
                    <a href="/admin-assistant/inventory" class="btn muted">Back to Inventory</a>
                    <button class="btn primary" id="btnSubmit" type="submit" disabled>Submit Purchase Requisition(s)</button>
                </div>
            </form>
        </div>
    </main>
</div>
<script>
(function(){
    var rows = document.getElementById('rows');
    var btnAdd = document.getElementById('btnAddRow');
    var addSel = document.getElementById('add_item');
    var addQty = document.getElementById('add_qty');
    var addUnit = document.getElementById('add_unit');
    var btnSubmit = document.getElementById('btnSubmit');
    var idx = 0;

    function updateSubmitState(){
        btnSubmit.disabled = rows.children.length === 0;
    }

    function addRow(item){
        var tr = document.createElement('tr');
        tr.innerHTML = ''+
            '<td>'+escapeHtml(item.name)+'<input type="hidden" name="items['+idx+'][item_id]" value="'+item.id+'"></td>'+
            '<td>'+item.stock+' '+escapeHtml(item.unit)+'</td>'+
            '<td style="width:140px;"><input type="number" name="items['+idx+'][quantity]" min="1" value="'+item.reqQty+'" required></td>'+
            '<td style="width:220px;"><input type="text" name="items['+idx+'][unit]" value="'+escapeHtml(item.unit)+'"></td>'+
            '<td><button class="btn muted" type="button" data-remove>Remove</button></td>';
        rows.appendChild(tr);
        idx++;
        updateSubmitState();
    }

    function escapeHtml(s){
        return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    if (btnAdd){
        btnAdd.addEventListener('click', function(){
            var opt = addSel && addSel.options[addSel.selectedIndex];
            if (!opt || !opt.value) return;
            var item = {
                id: opt.value,
                name: opt.getAttribute('data-name') || opt.textContent,
                stock: opt.getAttribute('data-qty') || '—',
                unit: addUnit.value || (opt.getAttribute('data-unit') || 'pcs'),
                reqQty: Math.max(1, parseInt(addQty.value || '1', 10))
            };
            addRow(item);
        });
    }

    rows.addEventListener('click', function(e){
        var t = e.target;
        if (t && t.getAttribute('data-remove') !== null){
            var tr = t.closest('tr'); if (tr) tr.remove();
            updateSubmitState();
        }
    });

    // Add from low-stock suggestions
    document.querySelectorAll('[data-add-low]').forEach(function(btn){
        btn.addEventListener('click', function(){
            var item = {
                id: btn.getAttribute('data-id'),
                name: btn.getAttribute('data-name'),
                stock: btn.getAttribute('data-qty') || '—',
                unit: btn.getAttribute('data-unit') || 'pcs',
                reqQty: 1
            };
            addRow(item);
        });
    });

    updateSubmitState();
})();
</script>
</body>
</html>
