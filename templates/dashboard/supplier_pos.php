<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier • Purchase Orders</title>
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
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; cursor:pointer; }
        .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Purchase Orders</h2>
        <div class="card">
            <table>
                <thead><tr><th>PR</th><th>PO Number</th><th>Status</th><th>PDF</th><th>Respond / Receipt</th><th>Logistics</th></tr></thead>
                <tbody>
                    <?php if (!empty($pos)): foreach ($pos as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$p['pr_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$p['po_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$p['status'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if (!empty($p['pdf_path'])): ?>
                                    <a class="btn muted" href="/po/download?id=<?= (int)$p['id'] ?>">Download</a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" action="/supplier/po/respond" style="display:grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr)); gap:8px; align-items:end;">
                                    <input type="hidden" name="po_id" value="<?= (int)$p['id'] ?>" />
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Supplier Terms</label>
                                        <input name="supplier_terms" placeholder="e.g., 30 days, COD" />
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Payment Method</label>
                                        <select name="payment_method" required>
                                            <option value="Downpayment">Downpayment</option>
                                            <option value="COD">COD</option>
                                            <option value="Check">Check</option>
                                            <option value="After Delivery">After Delivery</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Delivery Option</label>
                                        <select name="delivery_option" required>
                                            <option value="Third-party Courier">Third-party Courier</option>
                                            <option value="Pick-up">Pick-up</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Received By</label>
                                        <input name="receiver_name" placeholder="Receiver name" />
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Date Received</label>
                                        <input name="received_date" type="date" />
                                    </div>
                                    <div style="grid-column:1/-1;">
                                        <label style="font-size:12px;color:var(--muted)">Message / Deal Details</label>
                                        <input name="message" placeholder="e.g., extra 5% discount if 10+ units" />
                                    </div>
                                    <div>
                                        <button class="btn" type="submit">Submit</button>
                                    </div>
                                </form>
                            </td>
                            <td>
                                <form method="POST" action="/supplier/po/logistics" style="display:grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr)); gap:8px; align-items:end;">
                                    <input type="hidden" name="po_id" value="<?= (int)$p['id'] ?>" />
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Status</label>
                                        <select name="logistics_status" required>
                                            <option value="waiting_for_courier">Waiting for Courier</option>
                                            <option value="in_transit">In Transit</option>
                                            <option value="delivered">Delivered</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="font-size:12px;color:var(--muted)">Notes</label>
                                        <input name="logistics_notes" placeholder="Optional notes" />
                                    </div>
                                    <div>
                                        <button class="btn" type="submit">Update</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="muted">No POs received yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
