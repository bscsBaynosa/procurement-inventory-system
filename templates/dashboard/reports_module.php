<?php
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$cats = $categories ?? ['Office Supplies','Medical Equipments','Medicines','Machines','Electronics','Appliances'];
$month = $month ?? date('Y-m');
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
          <select name="category">
            <option value="">All</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="min-width:220px">
          <label>Item ID (optional)</label>
          <input name="item_id" type="number" placeholder="e.g., 123" />
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
    </section>

    <section id="tab-inventory" class="card" style="display:none">
      <div style="font-weight:700;margin-bottom:8px">Generate Inventory Report</div>
      <form method="GET" action="/admin-assistant/reports/inventory" class="row">
        <div style="min-width:220px">
          <label>Category</label>
          <select name="category">
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
</script>
</body>
</html>
