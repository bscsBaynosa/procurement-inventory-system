<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custodian • Create Purchase Request</title>
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
        .row{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
        .field{ display:flex; flex-direction:column; gap:6px; }
        input, select, textarea { padding:10px 12px; border:1px solid var(--border); border-radius:10px; font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .btn { background:var(--accent); color:#fff; border:0; padding:0 14px; height:40px; border-radius:10px; font-weight:700; cursor:pointer; }
        .btn.secondary{ background:transparent; color:var(--text); border:1px solid var(--border); }
        @media (max-width: 900px){ .layout{ grid-template-columns: 1fr; } .sidebar{ position:relative; height:auto;} .row{ grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">Create Purchase Request</div>
        <div class="card">
            <form action="/custodian/requests" method="POST" style="display:grid; gap:10px;">
                <div class="row">
                    <div class="field"><label>Item</label>
                        <select name="item_id" required>
                            <option value="">Select item</option>
                            <?php foreach (($items ?? []) as $it): ?>
                                <option value="<?= (int)$it['item_id'] ?>"><?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label>Request type</label>
                        <select name="request_type">
                            <option value="purchase_order">Purchase Order</option>
                            <option value="job_order">Job Order</option>
                            <option value="equipment_request">Equipment Request</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="field"><label>Quantity</label><input type="number" name="quantity" min="1" value="1" required></div>
                    <div class="field"><label>Unit</label><input name="unit" value="pcs"></div>
                </div>
                <div class="row">
                    <div class="field"><label>Needed by</label><input type="date" name="needed_by"></div>
                    <div class="field"><label>Justification</label><input name="justification" placeholder="Reason / description" required></div>
                </div>
                <div style="display:flex; gap:10px;">
                    <button class="btn" type="submit" name="action" value="submit">Submit for approval</button>
                    <button class="btn secondary" type="submit" name="action" value="pdf">Generate PDF</button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>
