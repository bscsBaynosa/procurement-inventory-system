<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement • Canvassing</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <?php require __DIR__ . '/../layouts/_theme.php'; ?>
    <style>
        :root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#22c55e; --control-h:36px; }
        html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#22c55e; --control-h:36px; }
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
    .layout{ display:grid; grid-template-columns: 240px 1fr; min-height:100vh; }
        .sidebar{ background:#fff; border-right:1px solid var(--border); padding:18px 12px; position:sticky; top:0; height:100vh; }
        html[data-theme="dark"] .sidebar{ background:#0f172a; }
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; margin-bottom:12px; }
        .btn{ display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 12px; height:var(--control-h); min-width:120px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); text-decoration:none; font-size:12px; cursor:pointer; }
        .btn.primary{ border-color: color-mix(in oklab, var(--accent) 35%, var(--border)); background: color-mix(in oklab, var(--accent) 10%, transparent); }
        select{ padding:0 10px; height:var(--control-h); border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); }
    .grid{ display:grid; grid-template-columns: repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
        ul{ margin:4px 0 0 18px; }
        pre{ margin:0; white-space:pre-wrap; }
        table{ width:100%; border-collapse:separate; border-spacing:0; background:var(--card); border:1px solid var(--border); border-radius:12px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); position:sticky; top:0; z-index:1; }
        thead th:first-child, tbody td:first-child{ position:sticky; left:0; background:color-mix(in oklab, var(--card) 96%, var(--bg)); z-index:2; }
        thead th:first-child{ z-index:3; }
        tbody tr:nth-child(odd) td{ background:color-mix(in oklab, var(--card) 98%, var(--bg)); }
        .dim{ opacity:0.45; }
        .best{ background:color-mix(in oklab, var(--accent) 12%, transparent); border-left:3px solid var(--accent); }
        .totals{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
        .total-pill{ padding:8px 10px; border:1px solid var(--border); border-radius:999px; background:color-mix(in oklab, var(--card) 96%, var(--bg)); font-size:13px; }
        .total-pill.best{ border-color:color-mix(in oklab, var(--accent) 40%, var(--border)); background:color-mix(in oklab, var(--accent) 10%, transparent); }
    /* Supplier checkbox chips */
    .supplier-box label{ border:1px solid var(--border); background:color-mix(in oklab, var(--card) 96%, var(--bg)); border-radius:10px; padding:8px 10px; cursor:pointer; }
    .supplier-box input{ width:16px; height:16px; }
    .quotes-cell div{ padding:6px 8px; border-radius:8px; border:1px solid var(--border); margin-bottom:6px; background:color-mix(in oklab, var(--card) 98%, var(--bg)); }
        /* Responsive improvements */
        @media (max-width: 1200px) {
            .layout{ grid-template-columns: 1fr; }
            .sidebar{ display:none; }
            .content{ padding:12px; }
            th, td{ font-size:13px; padding:10px; }
            .supplier-box{ grid-template-columns: repeat(auto-fill,minmax(200px,1fr)) !important; }
        }
        @media (max-width: 720px) {
            .supplier-box{ grid-template-columns: 1fr !important; }
            .btn{ min-width: unset; width: 100%; }
        }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">
            <span>Proceed with Canvassing • PR <?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?></span>
            <a class="btn" href="/manager/requests">Back</a>
        </div>

        <div class="card">
            <strong>Items</strong>
            <pre><?php foreach ($rows as $r) { echo htmlspecialchars(($r['item_name'] ?? 'Item') . ' × ' . (string)($r['quantity'] ?? 0) . ' ' . (string)($r['unit'] ?? '')) . "\n"; } ?></pre>
        </div>

        <form action="/manager/requests/canvass" method="POST" class="card" id="canvassForm">
            <input type="hidden" name="pr_number" value="<?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?>" />
            <input type="hidden" name="canvass_id" id="canvassIdField" value="" />
            <!-- Per-item canvassing only: global supplier picker and global award removed by design -->

            <?php
                // Build item rows for table
                $supMap = [];
                foreach ($suppliers as $s) { $supMap[(int)$s['user_id']] = (string)$s['full_name']; }
                $itemsForGrid = [];
                foreach ($rows as $r) {
                    $itemsForGrid[] = [
                        'label' => ($r['item_name'] ?? 'Item') . ' × ' . (string)($r['quantity'] ?? 0) . ' ' . (string)($r['unit'] ?? ''),
                        'name'  => (string)($r['item_name'] ?? ''),
                        'id' => (int)($r['item_id'] ?? 0),
                    ];
                }
            ?>
            <script>
                // Supplier id -> name map for rendering
                const SUP_NAMES = <?= json_encode($supMap, JSON_UNESCAPED_UNICODE) ?>;
            </script>

            <div style="margin-top:14px;">
                <strong>Per-Item Supplier Quotes</strong>
                <div class="muted" style="font-size:12px;margin:6px 0 10px;">Select 3–5 suppliers per item; prices load independently from stored quotes. Cheapest price per row auto-highlights.</div>
                <div style="overflow:auto;">
                    <table id="itemQuotesTable">
                        <thead>
                            <tr>
                                <th style="min-width:320px;">Item</th>
                                <th style="min-width:240px;">Suppliers (per item)</th>
                                <th style="min-width:200px;">Quotes</th>
                                <th style="min-width:160px;">Awarded To</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($itemsForGrid as $it): $iid = (int)$it['id']; ?>
                            <tr data-item-id="<?= $iid ?>" data-item-label="<?= htmlspecialchars((string)$it['label'], ENT_QUOTES, 'UTF-8') ?>" data-item-name="<?= htmlspecialchars((string)($it['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <td><?= htmlspecialchars((string)$it['label'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="supplier-box" data-item-id="<?= $iid ?>" style="display:grid; grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap:6px;">
                                        <?php
                                            $iid0 = (int)$it['id'];
                                            $eligibleList = isset($eligible[$iid0]) ? (array)$eligible[$iid0] : array_map(fn($x)=> (int)$x['user_id'], $suppliers);
                                            foreach ($suppliers as $s): $sid0 = (int)$s['user_id'];
                                                if (!in_array($sid0, $eligibleList, true)) continue; ?>
                                                <label style="display:flex; align-items:center; gap:6px;">
                                                    <input type="checkbox" class="supplier-choice-row" name="item_suppliers[<?= $iid ?>][]" value="<?= $sid0 ?>" data-item-id="<?= $iid ?>" />
                                                    <span><?= htmlspecialchars((string)$s['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                    </div>
                                    <div class="hint-<?= $iid ?>" style="font-size:11px;color:var(--muted);margin-top:4px;">Select 3–5 suppliers.</div>
                                </td>
                                <td class="quotes-cell" data-item-id="<?= $iid ?>" style="font-size:13px; line-height:1.4;">
                                    <em style="color:var(--muted);">No quotes yet</em>
                                </td>
                                <td>
                                    <select class="award-select" name="item_award[<?= $iid ?>]" data-item-id="<?= $iid ?>" style="width:100%;">
                                        <option value="">— Auto —</option>
                                        <?php foreach ($suppliers as $s): ?>
                                            <option value="<?= (int)$s['user_id'] ?>"><?= htmlspecialchars((string)$s['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                <button id="btnPreview" class="btn" type="button" title="Generate and preview canvassing PDF in a new tab">Generate Canvass Sheet</button>
                <button id="btnSend" class="btn primary" type="submit" disabled>Send for Admin Approval</button>
                <a class="btn" href="/manager/requests">Cancel</a>
            </div>
        </form>
        <script>
            (function(){
                const form = document.getElementById('canvassForm');
                const btnPreview = document.getElementById('btnPreview');
                const btnSend = document.getElementById('btnSend');
                const canvassIdField = document.getElementById('canvassIdField');
                const originalAction = form.getAttribute('action');
                const originalTarget = form.getAttribute('target');
                const prNumber = (new URLSearchParams(window.location.search)).get('pr') || (document.querySelector('input[name="pr_number"]').value);

                function getSelectedSuppliersForItem(itemId) {
                    return Array.from(document.querySelectorAll('.supplier-choice-row[data-item-id="' + itemId + '"]:checked')).map(cb => Number(cb.value));
                }

                async function loadQuotesForItem(itemId, supplierIds) {
                    // Allow fetching with >=1 selection for visibility; Generate remains gated at 3–5
                    if (!supplierIds || supplierIds.length < 1) return;
                    try {
                        const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
                        const itemName = row ? (row.getAttribute('data-item-name') || '') : '';
                        const res = await fetch('/manager/requests/canvass/item-quotes', {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ item_id: Number(itemId) || 0, item_name: itemName, supplier_ids: supplierIds.map(Number) })
                        });
                        if (!res.ok) return;
                        const data = await res.json();
                        const cell = document.querySelector(`.quotes-cell[data-item-id="${itemId}"]`);
                        if (!cell) return;
                        if (!data.prices || Object.keys(data.prices).length === 0) { cell.innerHTML = '<em style="color:var(--muted);">No quotes</em>'; return; }
                        let min = null; let minSid = null;
                        const frag = document.createDocumentFragment();
                        Object.keys(data.prices).sort((a,b)=>data.prices[a]-data.prices[b]).forEach(sid => {
                            const p = Number(data.prices[sid]);
                            if (min === null || p < min - 1e-9) { min = p; minSid = sid; }
                        });
                        Object.keys(data.prices).forEach(sid => {
                            const p = Number(data.prices[sid]);
                            const div = document.createElement('div');
                            const nm = SUP_NAMES && SUP_NAMES[sid] ? SUP_NAMES[sid] : ('Supplier ' + sid);
                            div.textContent = nm + ': ₱ ' + p.toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
                            div.dataset.supplierId = sid;
                            if (minSid && sid === String(minSid)) { div.style.background = 'color-mix(in oklab, var(--accent) 12%, transparent)'; div.style.borderLeft = '3px solid var(--accent)'; }
                            frag.appendChild(div);
                        });
                        cell.innerHTML = '';
                        cell.appendChild(frag);
                        // Auto award if user hasn't chosen
                        const awardSel = document.querySelector(`.award-select[data-item-id="${itemId}"]`);
                        if (awardSel && !awardSel.dataset.userSet) { awardSel.value = minSid || ''; }
                        // Filter award options to selected suppliers only
                        if (awardSel) {
                            Array.from(awardSel.options).forEach(opt => {
                                if (!opt.value) { opt.disabled = false; return; }
                                opt.disabled = !supplierIds.includes(Number(opt.value));
                                if (opt.disabled && awardSel.value === opt.value) { awardSel.value = ''; }
                            });
                        }
                    } catch (e) { /* ignore */ }
                }

                // Bind change events on per-item supplier checkboxes
                document.querySelectorAll('.supplier-choice-row').forEach(cb => {
                    cb.addEventListener('change', function(){
                        const itemId = this.dataset.itemId;
                        const values = getSelectedSuppliersForItem(itemId);
                        if (values.length > 5) { this.checked = false; alert('Select at most 5 suppliers for this item.'); return; }
                        const hint = document.querySelector('.hint-' + itemId);
                        if (hint) { hint.textContent = values.length < 3 ? 'Select 3–5 suppliers.' : ' '; }
                        if (values.length >= 3) { loadQuotesForItem(itemId, values); }
                        // Also re-filter award options to only selected ones
                        const awardSel = document.querySelector('.award-select[data-item-id="' + itemId + '"]');
                        if (awardSel) {
                            Array.from(awardSel.options).forEach(opt => {
                                if (!opt.value) { opt.disabled = false; return; }
                                opt.disabled = !values.includes(Number(opt.value));
                                if (opt.disabled && awardSel.value === opt.value) { awardSel.value = ''; }
                            });
                        }
                        updateGenerateState();
                    });
                });
                // Track manual award selection
                document.querySelectorAll('.award-select').forEach(sel => sel.addEventListener('change', function(){ this.dataset.userSet = '1'; }));

                function updateGenerateState(){
                    let ok = true;
                    document.querySelectorAll('.supplier-box').forEach(box => {
                        const cnt = box.querySelectorAll('.supplier-choice-row:checked').length;
                        if (cnt < 3 || cnt > 5) { ok = false; }
                    });
                    btnPreview.disabled = !ok;
                    btnPreview.title = ok ? '' : 'Select 3–5 suppliers for each item to enable preview';
                }
                updateGenerateState();

                // Remove legacy global-matrix handlers (no longer used). All logic is per-item via loadQuotesForItem.
                // Ensure initial state reflects current selections.
                updateGenerateState();
                // Preview flow: submit to preview endpoint in a new tab and enable Send button
                btnPreview.addEventListener('click', function(){
                    // Validate each item selection has 3–5 suppliers
                    const selections = {}; let bad = false;
                    document.querySelectorAll('.supplier-box').forEach(box => {
                        const itemId = box.getAttribute('data-item-id');
                        const vals = Array.from(box.querySelectorAll('.supplier-choice-row:checked')).map(cb=>Number(cb.value));
                        if (vals.length < 3 || vals.length > 5) { bad = true; }
                        selections[itemId] = vals;
                    });
                    updateGenerateState();
                    if (bad) { alert('Each item must have 3–5 suppliers selected.'); return; }
                    // Build awards map
                    const awards = {};
                    document.querySelectorAll('.award-select').forEach(sel => { if (sel.value) { awards[sel.dataset.itemId] = Number(sel.value); } });
                    // Union of suppliers across items (for storage compatibility)
                    const union = new Set(); Object.values(selections).forEach(arr => arr.forEach(v => union.add(v)));
                    const suppliers = Array.from(union.values());
                    fetch('/manager/requests/canvass/store', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ pr_number: prNumber, selections, awards, suppliers })
                    }).then(r=>r.json()).then(js => {
                        if (js && js.canvass_id) { canvassIdField.value = js.canvass_id; }
                        // Inject suppliers[] hidden inputs for preview compatibility
                        // Clear previous
                        Array.from(document.querySelectorAll('input[name="suppliers[]"]')).forEach(n=>n.remove());
                        suppliers.forEach(sid => {
                            const inp = document.createElement('input');
                            inp.type = 'hidden'; inp.name = 'suppliers[]'; inp.value = String(sid);
                            form.appendChild(inp);
                        });
                        form.setAttribute('action', '/manager/requests/canvass/preview');
                        form.setAttribute('target', '_blank');
                        form.submit();
                        form.setAttribute('action', originalAction);
                        if (originalTarget !== null) { form.setAttribute('target', originalTarget); } else { form.removeAttribute('target'); }
                        btnSend.disabled = false;
                    }).catch(()=>{ alert('Failed to persist canvass.'); });
                });
                document.getElementById('canvassForm').addEventListener('submit', function(e){
                    // Validate per-item selections
                    let bad = false;
                    document.querySelectorAll('.supplier-box').forEach(box => {
                        const cnt = box.querySelectorAll('.supplier-choice-row:checked').length;
                        if (cnt < 3 || cnt > 5) { bad = true; }
                    });
                    if (bad) { e.preventDefault(); alert('Each item must have 3–5 suppliers.'); return; }
                    if (btnSend.disabled) { e.preventDefault(); alert('Please click Generate first.'); return; }
                    if (!canvassIdField.value) { e.preventDefault(); alert('Missing canvass id. Click Generate.'); return; }
                });
            })();
        </script>
    </main>
</div>
</body>
</html>
