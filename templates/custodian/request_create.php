<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Assistant • Purchase Request</title>
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
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; max-width:820px; }
        label{ display:block; font-size:13px; color:var(--muted); margin:8px 0 6px; }
        input[type="text"], input[type="number"], input[type="date"], select, textarea{ width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid var(--border); border-radius:10px; font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .row{ display:flex; gap:10px; }
        .btn{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-weight:700; font-size:14px; line-height:1; height:40px; padding:0 14px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; transition:all .15s ease; cursor:pointer; }
        .btn.primary{ background:var(--accent); color:#fff; border:0; }
        .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">Create Purchase Request</div>
        <?php if (!empty($_GET['created'])): ?>
            <div class="card" style="border-color:#86efac; background:color-mix(in oklab, var(--card) 92%, #86efac); margin-bottom:12px;">Request submitted. You can also download the PDF.</div>
        <?php endif; ?>
        <div class="card">
            <form method="POST" action="/admin-assistant/requests">
                <?php if (!empty($pr_preview)): ?>
                    <div style="margin-bottom:10px; font-size:13px; color:var(--muted);">Next Requisition ID (preview): <strong style="color:var(--text); font-weight:800;"><?= htmlspecialchars((string)$pr_preview, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <?php endif; ?>
                <label for="item_id">Item</label>
                <select id="item_id" name="item_id" required>
                    <option value="">Select item…</option>
                    <?php if (!empty($items)): foreach ($items as $it): ?>
                        <option value="<?= (int)$it['item_id'] ?>"><?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; endif; ?>
                </select>

                <div class="row">
                    <div style="flex:1;">
                        <label for="quantity">Quantity</label>
                        <input id="quantity" name="quantity" type="number" min="1" value="1" required>
                    </div>
                    <div style="width:160px;">
                        <label for="unit">Unit</label>
                        <input id="unit" name="unit" type="text" value="pcs">
                    </div>
                    <div style="width:220px;">
                        <label for="request_type">Type</label>
                        <select id="request_type" name="request_type">
                            <option value="purchase_order">Purchase Order</option>
                            <option value="job_order">Job Order</option>
                        </select>
                    </div>
                </div>

                <label for="priority">Priority (1-5)</label>
                <select id="priority" name="priority">
                    <option value="1">1 - Highest</option>
                    <option value="2">2</option>
                    <option value="3" selected>3 - Normal</option>
                    <option value="4">4</option>
                    <option value="5">5 - Lowest</option>
                </select>

                <div class="row">
                    <div style="flex:1;">
                        <label for="needed_by">Needed By</label>
                        <input id="needed_by" name="needed_by" type="date">
                    </div>
                </div>

                <label for="justification">Justification</label>
                <textarea id="justification" name="justification" rows="4" placeholder="Why is this needed?" required></textarea>

                <div style="margin-top:12px; display:flex; gap:8px;">
                    <button class="btn primary" type="submit">Submit for Approval</button>
                    <button class="btn muted" type="submit" name="download_pdf" value="1">Submit & Download PDF</button>
                    <a class="btn muted" href="/dashboard">Back</a>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>
