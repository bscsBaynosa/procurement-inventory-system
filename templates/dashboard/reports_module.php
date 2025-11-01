<?php
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$cats = $categories ?? ['Office Supplies','Medical Equipments','Medicines','Machines','Electronics','Appliances'];
$month = $month ?? date('Y-m');
$items = $items ?? [];
$recentConsumption = $recent_consumption ?? [];
$recentInventory = $recent_inventory ?? [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reports</title>
  <link rel="stylesheet" href="/css/main.css" />
  <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
  <?php require __DIR__ . '/../layouts/_theme.php'; ?>
  <style>
    body{margin:0;font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
    .layout{display:grid;grid-template-columns:240px 1fr;min-height:100vh}
    .sidebar{background:#fff;border-right:1px solid var(--border);padding:18px 12px;position:sticky;top:0;height:100vh}
    html[data-theme="dark"] .sidebar{background:#0f172a}
    .content{padding:18px 20px}
    .h1{font-size:22px;font-weight:800;margin:6px 0 12px}
    .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:12px}
    .tabs{display:flex;gap:8px;margin-bottom:10px}
    .tabs a{padding:10px 14px;border-radius:10px;border:1px solid var(--border);text-decoration:none;color:inherit}
    .tabs a.active{background:#22c55e;color:#fff;border-color:#22c55e}
    label{display:block;font-size:13px;color:var(--muted);margin:6px 0 4px}
    input,select{padding:10px 12px;border:1px solid var(--border);border-radius:10px}
    .row{display:flex;gap:10px;flex-wrap:wrap}
    .btn{font-weight:700;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:#fff}
    .btn.primary{background:#22c55e;border-color:#22c55e;color:#fff}
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
  <main class="content">
    <div class="h1">Reports</div>
    <div class="tabs">
      <a href="#consumption" class="active" onclick="showTab(event,'consumption')">Consumption Report</a>
      <a href="#inventory" onclick="showTab(event,'inventory')">Inventory Report</a>
      <a href="/admin-assistant/reports/archives" style="margin-left:auto">View Archived</a>
    </div>

    <section id="tab-consumption" class="card">
      <div style="font-weight:700;margin-bottom:8px">Generate Consumption Report</div>
      <form method="GET" action="/admin-assistant/reports/consumption" class="row">
        <div style="min-width:220px">
          <label>Category (optional if Item selected)</label>
          <select name="category" id="cons-cat">
            <option value="">All</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="min-width:320px">
          <label>Item (optional)</label>
          <select name="item_id" id="cons-item">
            <option value="">All Items</option>
          </select>
        </div>
        <div>
          <label>Month</label>
          <input name="month" type="month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div style="align-self:flex-end">
          <button class="btn primary" type="submit" name="download" value="1">Download</button>
        </div>
      </form>
      <p style="color:var(--muted);font-size:13px;margin-top:8px">Tip: Leave Item ID empty to include all items in the selected category; set it to generate a single-item consumption report.</p>
      <div style="margin-top:12px">
        <div style="font-weight:700;margin:8px 0">Recent Consumption Reports</div>
        <table style="width:100%;border-collapse:collapse">
          <thead>
            <tr>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Category</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Prepared By</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Prepared At</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">File</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recentConsumption): foreach ($recentConsumption as $r): ?>
              <tr>
                <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars((string)($r['category'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars((string)($r['prepared_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$r['prepared_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars((string)$r['file_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:8px;border-bottom:1px solid var(--border)">
                  <a class="btn muted" href="/admin-assistant/reports/download?id=<?= (int)$r['id'] ?>">Download</a>
                  <form method="POST" action="/admin-assistant/reports/archive" style="display:inline">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" />
                    <button class="btn muted" type="submit">Archive</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5" style="padding:8px;color:var(--muted)">No reports yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section id="tab-inventory" class="card" style="display:none">
      <div style="font-weight:700;margin-bottom:8px">Generate Inventory Report</div>
      <form method="GET" action="/admin-assistant/reports/inventory" class="row">
        <div style="min-width:220px">
          <label>Category</label>
          <select name="category" id="inv-cat">
            <?php foreach ($cats as $c): ?>
              <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Month</label>
          <input name="month" type="month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>" />
        </div>
        <div style="align-self:flex-end">
          <button class="btn primary" type="submit" name="download" value="1">Download</button>
        </div>
      </form>
      <p style="color:var(--muted);font-size:13px;margin-top:8px">Inventory Report summarizes the entire category; the Snapshot shows current stocks.</p>
      <div style="margin-top:12px">
        <div style="font-weight:700;margin:8px 0">Recent Inventory Reports</div>
        <table style="width:100%;border-collapse:collapse">
          <thead>
            <tr>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Category</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Prepared By</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Prepared At</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">File</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recentInventory): foreach ($recentInventory as $r): ?>
              <tr>
                <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars((string)($r['category'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars((string)($r['prepared_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$r['prepared_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars((string)$r['file_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:8px;border-bottom:1px solid var(--border)">
                  <a class="btn muted" href="/admin-assistant/reports/download?id=<?= (int)$r['id'] ?>">Download</a>
                  <form method="POST" action="/admin-assistant/reports/archive" style="display:inline">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" />
                    <button class="btn muted" type="submit">Archive</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5" style="padding:8px;color:var(--muted)">No reports yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>
<script>
function showTab(ev, id){ ev.preventDefault();
  document.querySelector('#tab-consumption').style.display = (id==='consumption')?'block':'none';
  document.querySelector('#tab-inventory').style.display = (id==='inventory')?'block':'none';
  const links = document.querySelectorAll('.tabs a');
  links.forEach(a=>a.classList.remove('active'));
  if(id==='consumption') links[0].classList.add('active'); else links[1].classList.add('active');
}
// Dependent Item list for Consumption
(function(){
  const items = <?php echo json_encode(array_map(function($it){ return [
    'id'=>(int)($it['item_id']??0),
    'name'=>(string)($it['name']??''),
    'category'=>(string)($it['category']??'')
  ]; }, $items), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const catSel = document.getElementById('cons-cat');
  const itemSel = document.getElementById('cons-item');
  function render(){
    const cat = catSel.value || '';
    const filtered = items.filter(it => !cat || it.category.toLowerCase()===cat.toLowerCase());
    // Preserve current selection
    const current = itemSel.value;
    itemSel.innerHTML = '';
    const optAll = document.createElement('option'); optAll.value=''; optAll.textContent='All Items'; itemSel.appendChild(optAll);
    filtered.forEach(it => { const o=document.createElement('option'); o.value=String(it.id); o.textContent=`${it.name} (ID ${it.id})`; itemSel.appendChild(o); });
    // Try to restore selection
    if ([...itemSel.options].some(o=>o.value===current)) { itemSel.value = current; }
  }
  if (catSel && itemSel) { catSel.addEventListener('change', render); render(); }
})();
</script>
</body>
</html>
