<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement • Create RFP</title>
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
        input, textarea, select{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; }
        table{ width:100%; border-collapse:collapse; }
        th, td{ padding:10px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 14px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; text-align:center; min-height:42px; }
        .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
        .muted{ color:var(--muted); }
    </style>
    <script>
        function addParticular(){
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input name="particular_desc[]" placeholder="Description" /></td>
                <td style="width:20%;"><input name="particular_amount[]" type="number" step="0.01" min="0" value="0" oninput="recalcTotal()" /></td>
                <td style="width:8%;"><button class="btn muted" type="button" onclick="removeRow(this)">Remove</button></td>
            `;
            document.getElementById('rfpRows').appendChild(tr);
            recalcTotal();
        }
        function removeRow(btn){ const tr = btn.closest('tr'); tr.parentNode.removeChild(tr); recalcTotal(); }
        function recalcTotal(){
            let sum = 0;
            document.querySelectorAll('input[name="particular_amount[]"]').forEach(inp => { const v = parseFloat(inp.value || '0'); if (!isNaN(v)) sum += v; });
            document.getElementById('grandTotal').textContent = '₱ ' + sum.toFixed(2);
        }
        function validateRfp(){
            const payTo = document.querySelector('input[name="pay_to"]').value.trim();
            const rows = document.querySelectorAll('#rfpRows tr');
            if (payTo === '') { alert('Pay To is required.'); return false; }
            if (rows.length === 0) { alert('Add at least one particular.'); return false; }
            return true;
        }
        window.addEventListener('DOMContentLoaded', recalcTotal);
    </script>
    </head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Create Request For Payment<?= !empty($rfp['pr_number']) ? (' • PR ' . htmlspecialchars((string)$rfp['pr_number'], ENT_QUOTES, 'UTF-8')) : '' ?></h2>
        <form class="card" method="POST" action="/procurement/rfp/create" onsubmit="return validateRfp();">
            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)($rfp['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
            <input type="hidden" name="po_id" value="<?= (int)($rfp['po_id'] ?? 0) ?>" />
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div>
                    <label>PO Number (optional)</label>
                    <input name="po_number" value="<?= htmlspecialchars((string)($rfp['po_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g., 2025-001" />
                    <div class="muted" style="font-size:12px;margin-top:4px;">Leave blank if RFP is not tied to a PO.</div>
                </div>
                <div>
                    <label>Pay To</label>
                    <input name="pay_to" required value="<?= htmlspecialchars((string)($rfp['pay_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Vendor / Person" />
                </div>
                <div>
                    <label>Center / Cost Center</label>
                    <input name="center" value="<?= htmlspecialchars((string)($rfp['center'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div>
                    <label>Date Requested</label>
                    <input type="date" name="date_requested" value="<?= htmlspecialchars((string)($rfp['date_requested'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div>
                    <label>Date Needed</label>
                    <input type="date" name="date_needed" value="<?= htmlspecialchars((string)($rfp['date_needed'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div>
                    <label>Nature of Payment</label>
                    <select name="nature">
                        <?php $nature = (string)($rfp['nature'] ?? 'payment_to_supplier'); ?>
                        <option value="payment_to_supplier" <?= $nature==='payment_to_supplier'?'selected':'' ?>>Payment to Supplier</option>
                        <option value="reimbursement" <?= $nature==='reimbursement'?'selected':'' ?>>Reimbursement</option>
                        <option value="petty_cash" <?= $nature==='petty_cash'?'selected':'' ?>>Petty Cash</option>
                        <option value="others" <?= $nature==='others'?'selected':'' ?>>Others</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:14px;">
                <strong>Particulars</strong>
                <table>
                    <thead><tr><th>Description</th><th style="width:20%;">Amount</th><th style="width:8%;"></th></tr></thead>
                    <tbody id="rfpRows">
                        <?php foreach (($rfp['particulars'] ?? []) as $row): ?>
                            <tr>
                                <td><input name="particular_desc[]" value="<?= htmlspecialchars((string)($row['desc'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" /></td>
                                <td><input name="particular_amount[]" type="number" step="0.01" min="0" value="<?= htmlspecialchars((string)($row['amount'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" oninput="recalcTotal()" /></td>
                                <td><button class="btn muted" type="button" onclick="removeRow(this)">Remove</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td style="text-align:right;font-weight:700;">Grand Total:</td><td id="grandTotal" style="font-weight:700;">₱ 0.00</td><td></td></tr>
                    </tfoot>
                </table>
                <div style="margin-top:10px;"><button class="btn muted" type="button" onclick="addParticular()">Add Particular</button></div>
            </div>

            <div style="margin-top:12px; display:flex; flex-wrap:wrap; gap:10px;">
                <button class="btn muted" type="submit" name="action" value="generate" formtarget="_blank" title="Generate the PDF in a new tab without sending to Admin yet">Generate PDF</button>
                <button class="btn" type="submit" name="action" value="send_admin" title="Generate PDF and send it to Admin for approval">Send for Admin Approval</button>
                <?php if (!empty($rfp['po_id'])): ?>
                    <a class="btn muted" href="/procurement/po/view?id=<?= (int)$rfp['po_id'] ?>">Back to PO</a>
                <?php else: ?>
                    <a class="btn muted" href="/procurement/pos">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </main>
</div>
</body>
</html>