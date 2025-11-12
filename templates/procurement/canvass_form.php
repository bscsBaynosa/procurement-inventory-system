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
            <p>Select 3–5 suppliers to include in the canvassing sheet.</p>
            <div class="grid">
                <?php foreach ($suppliers as $s): ?>
                    <label style="display:flex; align-items:center; gap:8px; border:1px solid var(--border); border-radius:10px; padding:8px;">
                        <input type="checkbox" name="suppliers[]" value="<?= (int)$s['user_id'] ?>" class="supplier-choice" data-supplier-id="<?= (int)$s['user_id'] ?>" />
                        <span><?= htmlspecialchars((string)$s['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <label style="display:flex; gap:8px; align-items:center;">
                    <span style="min-width:120px;">Awarded Vendor (optional)</span>
                    <select name="awarded_to" id="awardedSelect">
                        <option value="">— Select —</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int)$s['user_id'] ?>"><?= htmlspecialchars((string)$s['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <small class="muted">Tip: pick among the selected suppliers. Unselected vendors are disabled automatically.</small>
            </div>

            <?php
                // Build a supplier id -> name map for headers
                $supMap = [];
                foreach ($suppliers as $s) { $supMap[(int)$s['user_id']] = (string)$s['full_name']; }
                // Item key normalize (lower)
                $itemsForGrid = [];
                foreach ($rows as $r) {
                    $nm = strtolower(trim((string)($r['item_name'] ?? '')));
                    $itemsForGrid[] = [
                        'label' => ($r['item_name'] ?? 'Item') . ' × ' . (string)($r['quantity'] ?? 0) . ' ' . (string)($r['unit'] ?? ''),
                        'key' => $nm,
                        'id' => (int)($r['item_id'] ?? 0),
                    ];
                }
            ?>

            <div style="margin-top:14px;">
                <strong>Supplier Quotes Snapshot</strong>
                <div class="muted" style="font-size:12px;margin:6px 0 10px;">Cheapest price per item is highlighted automatically among the selected suppliers. You can override suppliers per item using the selector in each row.</div>
                <div style="overflow:auto;">
                    <table id="priceMatrix">
                        <thead>
                            <tr>
                                <th style="min-width:320px;">Item</th>
                                <th style="min-width:200px;">Suppliers for this item</th>
                                <?php foreach ($supMap as $sid => $name): ?>
                                    <th style="min-width:140px;" data-supplier-id="<?= (int)$sid ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></th>
                                <?php endforeach; ?>
                                <th style="min-width:180px;">Awarded To</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($itemsForGrid as $it): $k = (string)$it['key']; $iid = (int)$it['id']; ?>
                            <tr data-item-key="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" data-item-id="<?= (int)$iid ?>">
                                <td><?= htmlspecialchars((string)$it['label'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <select name="item_suppliers[<?= $iid > 0 ? (int)$iid : htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>][]" class="per-item-select" multiple size="3" style="width:100%">
                                        <?php foreach ($suppliers as $s): ?>
                                            <option value="<?= (int)$s['user_id'] ?>"><?= htmlspecialchars((string)$s['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div style="font-size:11px;color:var(--muted);margin-top:4px;">Pick 3–5 for this item; empty = use global selection.</div>
                                </td>
                                <?php foreach ($supMap as $sid => $name): $p = isset($prices[$sid][$k]) ? (float)$prices[$sid][$k] : null; ?>
                                    <td data-supplier-id="<?= (int)$sid ?>" data-price="<?= $p !== null ? number_format($p, 2, '.', '') : '' ?>">
                                        <?= $p !== null ? ('₱ ' . number_format($p, 2)) : '—' ?>
                                    </td>
                                <?php endforeach; ?>
                                <td>
                                    <select name="item_award[<?= $iid > 0 ? (int)$iid : htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>]" class="award-per-item" data-item-key="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" data-item-id="<?= (int)$iid ?>">
                                        <option value="">— Select —</option>
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
                <div id="totalsSummary" class="totals"></div>
            </div>
            <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                <button id="btnPreview" class="btn" type="button" title="Generate and preview canvassing PDF in a new tab">Generate Canvass Sheet</button>
                <button id="btnSend" class="btn primary" type="submit" disabled>Send for Admin Approval</button>
                <a class="btn" href="/manager/requests">Cancel</a>
            </div>
        </form>
        <script>
            (function(){
                const awardSel = document.getElementById('awardedSelect');
                if (awardSel) {
                    awardSel.addEventListener('change', function(){ this.dataset.userSet = '1'; });
                }
                const form = document.getElementById('canvassForm');
                const btnPreview = document.getElementById('btnPreview');
                const btnSend = document.getElementById('btnSend');
                const canvassIdField = document.getElementById('canvassIdField');
                const originalAction = form.getAttribute('action');
                const originalTarget = form.getAttribute('target');
                const prNumber = (new URLSearchParams(window.location.search)).get('pr') || (document.querySelector('input[name="pr_number"]').value);

                function selectedSupplierIds() {
                    return Array.from(document.querySelectorAll('.supplier-choice:checked')).map(cb => cb.getAttribute('data-supplier-id'));
                }

                function getItemIds() {
                    return Array.from(document.querySelectorAll('#priceMatrix tbody tr'))
                        .map(tr => Number(tr.getAttribute('data-item-id')))
                        .filter(v => !Number.isNaN(v) && v > 0);
                }
                function getItemKeysNoId() {
                    return Array.from(document.querySelectorAll('#priceMatrix tbody tr'))
                        .filter(tr => {
                            const id = Number(tr.getAttribute('data-item-id'));
                            return Number.isNaN(id) || id <= 0;
                        })
                        .map(tr => tr.getAttribute('data-item-key'))
                        .filter(k => k && k.trim() !== '');
                }

                async function fetchQuotes() {
                    const suppliers = selectedSupplierIds();
                    const itemIds = getItemIds();
                    const itemKeys = getItemKeysNoId();
                    if (!suppliers.length || (!itemIds.length && !itemKeys.length)) return;
                    const table = document.getElementById('priceMatrix');
                    if (!table) return;
                    const applyById = (data) => {
                        if (!data || !data.prices) return;
                        const rows = table.querySelectorAll('tbody tr');
                        rows.forEach(tr => {
                            const iid = tr.getAttribute('data-item-id');
                            tr.querySelectorAll('td[data-supplier-id]').forEach(td => {
                                const sid = td.getAttribute('data-supplier-id');
                                const p = (data.prices[sid] && data.prices[sid][iid] != null) ? Number(data.prices[sid][iid]) : null;
                                if (p != null && !Number.isNaN(p)) {
                                    td.setAttribute('data-price', p.toFixed(2));
                                    td.textContent = '₱ ' + p.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                } else if (!td.dataset.userEdited && (iid && Number(iid) > 0)) {
                                    td.setAttribute('data-price', '');
                                    td.textContent = '—';
                                }
                            });
                        });
                    };
                    const applyByKey = (data) => {
                        if (!data || !data.prices) return;
                        const rows = table.querySelectorAll('tbody tr');
                        rows.forEach(tr => {
                            const key = tr.getAttribute('data-item-key');
                            const iid = Number(tr.getAttribute('data-item-id'));
                            if (!key || iid > 0) return; // only for no-id rows
                            tr.querySelectorAll('td[data-supplier-id]').forEach(td => {
                                const sid = td.getAttribute('data-supplier-id');
                                const p = (data.prices[sid] && data.prices[sid][key] != null) ? Number(data.prices[sid][key]) : null;
                                if (p != null && !Number.isNaN(p)) {
                                    td.setAttribute('data-price', p.toFixed(2));
                                    td.textContent = '₱ ' + p.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                } else if (!td.dataset.userEdited) {
                                    td.setAttribute('data-price', '');
                                    td.textContent = '—';
                                }
                            });
                        });
                    };
                    try {
                        const promises = [];
                        if (itemIds.length) {
                            promises.push(
                                fetch('/manager/requests/canvass/quotes-by-id', {
                                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ pr_number: prNumber, item_ids: itemIds, suppliers: suppliers.map(Number) })
                                }).then(r => r.ok ? r.json() : null).then(applyById)
                            );
                        }
                        if (itemKeys.length) {
                            promises.push(
                                fetch('/manager/requests/canvass/quotes', {
                                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ pr_number: prNumber, item_keys: itemKeys, suppliers: suppliers.map(Number) })
                                }).then(r => r.ok ? r.json() : null).then(applyByKey)
                            );
                        }
                        await Promise.all(promises);
                        recalc();
                    } catch (e) { /* ignore */ }
                }

                // Manual price update on double-click: prompt and update cell, mark as userEdited
                document.addEventListener('dblclick', function(ev){
                    const td = ev.target.closest('td[data-supplier-id]');
                    if (!td) return;
                    const current = td.getAttribute('data-price');
                    const val = prompt('Enter price per item (numbers only):', current || '');
                    if (val === null) return;
                    const num = Number(String(val).replace(/[,\s]/g,''));
                    if (Number.isNaN(num) || num < 0) { alert('Invalid number'); return; }
                    td.setAttribute('data-price', num.toFixed(2));
                    td.textContent = '₱ ' + num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    td.dataset.userEdited = '1';
                    recalc();
                });
                function recalc() {
                    const globalSelected = new Set(Array.from(document.querySelectorAll('.supplier-choice:checked')).map(cb => cb.getAttribute('data-supplier-id')));
                    const table = document.getElementById('priceMatrix');
                    if (!table) return;
                    // Dim unselected supplier columns
                    const headers = table.querySelectorAll('thead th[data-supplier-id]');
                    headers.forEach(th => {
                        const sid = th.getAttribute('data-supplier-id');
                        if (globalSelected.size === 0 || !globalSelected.has(sid)) th.classList.add('dim'); else th.classList.remove('dim');
                    });
                    const rows = table.querySelectorAll('tbody tr');
                    const totals = {};
                    rows.forEach(tr => {
                        // Determine per-row selected suppliers (fallback to global)
                        const perSel = tr.querySelector('select.per-item-select');
                        const rowSelected = new Set(Array.from(perSel ? perSel.selectedOptions : []).map(o => o.value));
                        const activeSet = rowSelected.size > 0 ? rowSelected : globalSelected;
                        // Clear previous best/dim
                        tr.querySelectorAll('td[data-supplier-id]').forEach(td => { td.classList.remove('best'); td.classList.remove('dim'); });
                        // Gather prices only for selected suppliers
                        let min = Infinity;
                        let minSid = null;
                        const tds = Array.from(tr.querySelectorAll('td[data-supplier-id]'));
                        tds.forEach(td => {
                            const sid = td.getAttribute('data-supplier-id');
                            const priceStr = td.getAttribute('data-price');
                            const price = priceStr === '' ? NaN : parseFloat(priceStr);
                            if (activeSet.size > 0 && !activeSet.has(sid)) { td.classList.add('dim'); }
                            if (activeSet.size > 0 && activeSet.has(sid) && !isNaN(price)) { if (price < min) { min = price; minSid = sid; } }
                            if (activeSet.size > 0 && activeSet.has(sid) && !isNaN(price)) { totals[sid] = (totals[sid] || 0) + price; }
                        });
                        if (min !== Infinity) {
                            tds.forEach(td => {
                                const sid = td.getAttribute('data-supplier-id');
                                const priceStr = td.getAttribute('data-price');
                                const price = priceStr === '' ? NaN : parseFloat(priceStr);
                                if (activeSet.has(sid) && !isNaN(price) && Math.abs(price - min) < 1e-9) { td.classList.add('best'); }
                            });
                        }
                        // Sync per-item award select
                        const awardSel = tr.querySelector('select.award-per-item');
                        if (awardSel) {
                            // Disable options not in selected set
                            Array.from(awardSel.options).forEach(opt => {
                                if (!opt.value) { opt.disabled = false; return; }
                                opt.disabled = (activeSet.size > 0 && !activeSet.has(opt.value));
                            });
                            // If current choice invalid (not selected supplier), clear
                            if (awardSel.value && awardSel.options[awardSel.selectedIndex] && awardSel.options[awardSel.selectedIndex].disabled) {
                                awardSel.value = '';
                            }
                            // Auto-pick cheapest for this row if user hasn't set manually
                            if (!awardSel.dataset.userSet) {
                                if (minSid && activeSet.has(minSid)) { awardSel.value = minSid; }
                                else { awardSel.value = ''; }
                            }
                        }
                    });
                    // Disable unselected options in Awarded select
                    if (awardSel) {
                        Array.from(awardSel.options).forEach(opt => {
                            const val = opt.value;
                            if (!val) { opt.disabled = false; return; }
                            opt.disabled = (globalSelected.size > 0 && !globalSelected.has(val));
                        });
                        // If currently selected option is disabled (no longer selected as supplier), clear it
                        if (awardSel.value && awardSel.options[awardSel.selectedIndex] && awardSel.options[awardSel.selectedIndex].disabled) {
                            awardSel.value = '';
                        }
                        // Auto-pick cheapest total if user hasn't set manually
                        let cheapestSid = null, cheapestTotal = Infinity;
                        Object.keys(totals).forEach(sid => { if (totals[sid] < cheapestTotal) { cheapestTotal = totals[sid]; cheapestSid = sid; } });
                        if (cheapestSid && (!awardSel.dataset.userSet || awardSel.value === '' || !globalSelected.has(awardSel.value))) {
                            awardSel.value = cheapestSid;
                        }
                    }
                    // Render totals summary pills
                    const sumEl = document.getElementById('totalsSummary');
                    if (sumEl) {
                        sumEl.innerHTML = '';
                        // find min among selected
                        let minVal = Infinity; Object.values(totals).forEach(v => { if (v < minVal) minVal = v; });
                        const headerMap = {}; // supplier id -> name from table headers
                        headers.forEach(th => { headerMap[th.getAttribute('data-supplier-id')] = th.textContent.trim(); });
                        Object.keys(totals).sort((a,b)=>totals[a]-totals[b]).forEach(sid => {
                            const val = totals[sid];
                            const pill = document.createElement('span');
                            pill.className = 'total-pill' + (Math.abs(val - minVal) < 1e-9 ? ' best' : '');
                            pill.textContent = (headerMap[sid] || ('Supplier ' + sid)) + ': ₱ ' + val.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                            sumEl.appendChild(pill);
                        });
                    }
                }
                document.querySelectorAll('.supplier-choice').forEach(cb => cb.addEventListener('change', function(){ fetchQuotes(); recalc(); }));
                // Track manual changes on per-item award selects
                document.querySelectorAll('select.award-per-item').forEach(sel => sel.addEventListener('change', function(){ this.dataset.userSet = '1'; }));
                document.querySelectorAll('select.per-item-select').forEach(sel => sel.addEventListener('change', function(){ recalc(); }));
                // Initial fetch and calc
                fetchQuotes().then(()=>recalc());
                // Preview flow: submit to preview endpoint in a new tab and enable Send button
                btnPreview.addEventListener('click', function(){
                    // Basic validation before preview
                    const checked = document.querySelectorAll('.supplier-choice:checked').length;
                    if (checked < 3 || checked > 5) { alert('Please select 3–5 suppliers.'); return; }
                    // Validate per-item overrides: if present, ensure 3–5 per item
                    let bad = false;
                    document.querySelectorAll('select.per-item-select').forEach(sel => {
                        const cnt = Array.from(sel.selectedOptions).length;
                        if (cnt !== 0 && (cnt < 3 || cnt > 5)) { bad = true; }
                    });
                    if (bad) { alert('Each item override must have 3–5 suppliers selected or leave empty to use global selection.'); return; }
                    // Ensure awarded vendor validity (if chosen)
                    if (awardSel && awardSel.value) {
                        const selSet = new Set(Array.from(document.querySelectorAll('.supplier-choice:checked')).map(cb => cb.value));
                        if (!selSet.has(awardSel.value)) { alert('Awarded vendor must be one of the selected suppliers.'); return; }
                    }
                    // Ensure per-item awards (if any) are from selected suppliers; else auto will handle in backend
                    // Build selections per item and awards
                    const selections = {};
                    document.querySelectorAll('#priceMatrix tbody tr').forEach(tr => {
                        const iid = tr.getAttribute('data-item-id');
                        const sel = tr.querySelector('select.per-item-select');
                        const vals = sel ? Array.from(sel.selectedOptions).map(o=>Number(o.value)) : [];
                        if (vals.length) selections[iid] = vals;
                    });
                    const awards = {};
                    document.querySelectorAll('select.award-per-item').forEach(sel => {
                        const iid = sel.getAttribute('data-item-id');
                        if (sel.value) awards[iid] = Number(sel.value);
                    });
                    const suppliers = selectedSupplierIds().map(Number);
                    // Persist canvass to DB before preview (returns canvass_id)
                    fetch('/manager/requests/canvass/store', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ pr_number: prNumber, selections, awards, suppliers })
                    }).then(r=>r.json()).then(js => {
                        if (js && js.canvass_id) { canvassIdField.value = js.canvass_id; }
                        // Submit to preview
                        form.setAttribute('action', '/manager/requests/canvass/preview');
                        form.setAttribute('target', '_blank');
                        form.submit();
                        // Restore and enable send
                        form.setAttribute('action', originalAction);
                        if (originalTarget !== null) { form.setAttribute('target', originalTarget); } else { form.removeAttribute('target'); }
                        btnSend.disabled = false;
                    }).catch(()=>{
                        alert('Failed to persist canvass. Please try again.');
                    });
                });
                document.getElementById('canvassForm').addEventListener('submit', function(e){
                    const checked = document.querySelectorAll('.supplier-choice:checked').length;
                    if (checked < 3 || checked > 5) {
                        e.preventDefault();
                        alert('Please select 3–5 suppliers.');
                        return;
                    }
                    // Per-item overrides validation
                    let bad = false;
                    document.querySelectorAll('select.per-item-select').forEach(sel => {
                        const cnt = Array.from(sel.selectedOptions).length;
                        if (cnt !== 0 && (cnt < 3 || cnt > 5)) { bad = true; }
                    });
                    if (bad) { e.preventDefault(); alert('Each item override must have 3–5 suppliers selected or leave empty.'); return; }
                    // Validate awarded_to is among selected if provided
                    if (awardSel && awardSel.value) {
                        const selSet = new Set(Array.from(document.querySelectorAll('.supplier-choice:checked')).map(cb => cb.value));
                        if (!selSet.has(awardSel.value)) {
                            e.preventDefault();
                            alert('Awarded vendor must be one of the selected suppliers.');
                            return;
                        }
                    }
                    // Enforce preview has been clicked client-side
                    if (btnSend.disabled) {
                        e.preventDefault();
                        alert('Please click Generate to preview the PDF before sending for approval.');
                        return;
                    }
                    // Ensure canvass id exists
                    if (!canvassIdField.value) {
                        e.preventDefault();
                        alert('Please click Generate Canvass Sheet first.');
                        return;
                    }
                });
            })();
        </script>
    </main>
</div>
</body>
</html>
