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
                    $itemsForGrid[] = [ 'label' => ($r['item_name'] ?? 'Item') . ' × ' . (string)($r['quantity'] ?? 0) . ' ' . (string)($r['unit'] ?? ''), 'key' => $nm ];
                }
            ?>

            <div style="margin-top:14px;">
                <strong>Supplier Quotes Snapshot</strong>
                <div class="muted" style="font-size:12px;margin:6px 0 10px;">Cheapest price per item is highlighted automatically among the selected suppliers.</div>
                <div style="overflow:auto;">
                    <table id="priceMatrix">
                        <thead>
                            <tr>
                                <th style="min-width:320px;">Item</th>
                                <?php foreach ($supMap as $sid => $name): ?>
                                    <th style="min-width:140px;" data-supplier-id="<?= (int)$sid ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($itemsForGrid as $it): $k = (string)$it['key']; ?>
                            <tr data-item-key="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>">
                                <td><?= htmlspecialchars((string)$it['label'], ENT_QUOTES, 'UTF-8') ?></td>
                                <?php foreach ($supMap as $sid => $name): $p = isset($prices[$sid][$k]) ? (float)$prices[$sid][$k] : null; ?>
                                    <td data-supplier-id="<?= (int)$sid ?>" data-price="<?= $p !== null ? number_format($p, 2, '.', '') : '' ?>">
                                        <?= $p !== null ? ('₱ ' . number_format($p, 2)) : '—' ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="totalsSummary" class="totals"></div>
            </div>
            <div style="margin-top:12px; display:flex; gap:8px;">
                <button class="btn primary" type="submit">Generate and Send for Admin Approval</button>
                <a class="btn" href="/manager/requests">Cancel</a>
            </div>
        </form>
        <script>
            (function(){
                const awardSel = document.getElementById('awardedSelect');
                if (awardSel) {
                    awardSel.addEventListener('change', function(){ this.dataset.userSet = '1'; });
                }
                function recalc() {
                    const selected = new Set(Array.from(document.querySelectorAll('.supplier-choice:checked')).map(cb => cb.getAttribute('data-supplier-id')));
                    const table = document.getElementById('priceMatrix');
                    if (!table) return;
                    // Dim unselected supplier columns
                    const headers = table.querySelectorAll('thead th[data-supplier-id]');
                    headers.forEach(th => {
                        const sid = th.getAttribute('data-supplier-id');
                        if (selected.size === 0 || !selected.has(sid)) th.classList.add('dim'); else th.classList.remove('dim');
                    });
                    const rows = table.querySelectorAll('tbody tr');
                    const totals = {};
                    rows.forEach(tr => {
                        // Clear previous best/dim
                        tr.querySelectorAll('td[data-supplier-id]').forEach(td => { td.classList.remove('best'); td.classList.remove('dim'); });
                        // Gather prices only for selected suppliers
                        let min = Infinity;
                        const tds = Array.from(tr.querySelectorAll('td[data-supplier-id]'));
                        tds.forEach(td => {
                            const sid = td.getAttribute('data-supplier-id');
                            const priceStr = td.getAttribute('data-price');
                            const price = priceStr === '' ? NaN : parseFloat(priceStr);
                            if (selected.size > 0 && !selected.has(sid)) { td.classList.add('dim'); }
                            if (selected.size > 0 && selected.has(sid) && !isNaN(price)) { if (price < min) min = price; }
                            if (selected.size > 0 && selected.has(sid) && !isNaN(price)) { totals[sid] = (totals[sid] || 0) + price; }
                        });
                        if (min !== Infinity) {
                            tds.forEach(td => {
                                const sid = td.getAttribute('data-supplier-id');
                                const priceStr = td.getAttribute('data-price');
                                const price = priceStr === '' ? NaN : parseFloat(priceStr);
                                if (selected.has(sid) && !isNaN(price) && Math.abs(price - min) < 1e-9) { td.classList.add('best'); }
                            });
                        }
                    });
                    // Disable unselected options in Awarded select
                    if (awardSel) {
                        Array.from(awardSel.options).forEach(opt => {
                            const val = opt.value;
                            if (!val) { opt.disabled = false; return; }
                            opt.disabled = (selected.size > 0 && !selected.has(val));
                        });
                        // If currently selected option is disabled (no longer selected as supplier), clear it
                        if (awardSel.value && awardSel.options[awardSel.selectedIndex] && awardSel.options[awardSel.selectedIndex].disabled) {
                            awardSel.value = '';
                        }
                        // Auto-pick cheapest total if user hasn't set manually
                        let cheapestSid = null, cheapestTotal = Infinity;
                        Object.keys(totals).forEach(sid => { if (totals[sid] < cheapestTotal) { cheapestTotal = totals[sid]; cheapestSid = sid; } });
                        if (cheapestSid && (!awardSel.dataset.userSet || awardSel.value === '' || !selected.has(awardSel.value))) {
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
                document.querySelectorAll('.supplier-choice').forEach(cb => cb.addEventListener('change', recalc));
                recalc();
                document.getElementById('canvassForm').addEventListener('submit', function(e){
                    const checked = document.querySelectorAll('.supplier-choice:checked').length;
                    if (checked < 3 || checked > 5) {
                        e.preventDefault();
                        alert('Please select 3–5 suppliers.');
                        return;
                    }
                    // Validate awarded_to is among selected if provided
                    if (awardSel && awardSel.value) {
                        const selSet = new Set(Array.from(document.querySelectorAll('.supplier-choice:checked')).map(cb => cb.value));
                        if (!selSet.has(awardSel.value)) {
                            e.preventDefault();
                            alert('Awarded vendor must be one of the selected suppliers.');
                            return;
                        }
                    }
                });
            })();
        </script>
    </main>
</div>
</body>
</html>
