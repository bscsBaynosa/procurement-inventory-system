<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement • Create PO</title>
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
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; cursor:pointer; }
        .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Create Purchase Order • PR <?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $flashError = $_SESSION['flash_error'] ?? null; unset($_SESSION['flash_error']);
        if (!$flashError && isset($_GET['error']) && $_GET['error'] !== '') { $flashError = (string)$_GET['error']; }
        if (!empty($flashError)): ?>
            <div style="margin:4px 0 12px; padding:10px 12px; border:1px solid #ef444466; background:color-mix(in oklab, #ef4444 10%, transparent); border-radius:10px;">
                <?= htmlspecialchars((string)$flashError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
    <form method="POST" action="/procurement/po/create" class="card" onsubmit="return validateItems();">
            <input type="hidden" name="pr_number" value="<?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?>" />
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div>
                    <label>PO Number <span style="color:#dc2626;">*</span></label>
                    <input name="po_number_display" readonly style="background:#f1f5f9;" value="<?= isset($po_next) ? htmlspecialchars((string)$po_next, ENT_QUOTES, 'UTF-8') : '' ?>" />
                    <input type="hidden" name="po_number" value="<?= isset($po_next) ? htmlspecialchars((string)$po_next, ENT_QUOTES, 'UTF-8') : '' ?>" />
                    <div style="font-size:12px;color:var(--muted);margin-top:4px;">Auto-generated (YYYYNNN).</div>
                </div>
                <div>
                    <label>Supplier (Vendor) <span style="color:#dc2626;">*</span></label>
                    <select name="supplier_id" required>
                        <option value="">-- choose supplier --</option>
                        <?php $prefSupplier = $prefill['supplier_id'] ?? null; foreach ($suppliers as $s): $sid=(int)$s['user_id']; ?>
                            <option value="<?= $sid ?>" <?= ($prefSupplier && $prefSupplier === $sid) ? 'selected' : '' ?>><?= htmlspecialchars((string)$s['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Vendor Name (override)</label>
                    <input name="vendor_name" placeholder="If different from Supplier profile" value="<?= htmlspecialchars((string)($prefill['vendor_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div>
                    <label>Vendor Address</label>
                    <textarea name="vendor_address" rows="3"></textarea>
                </div>
                <div>
                    <label>VAT/TIN</label>
                    <input name="vendor_tin" />
                </div>
                <div>
                    <label>Center <span style="color:#dc2626;">*</span></label>
                    <input name="center" required value="<?= htmlspecialchars((string)($prefill['center'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div>
                    <label>Reference</label>
                    <input name="reference" />
                </div>
                <div>
                    <label>Terms of Payment <span style="color:#dc2626;">*</span></label>
                    <input name="terms" required placeholder="e.g., 30 days, COD, etc." value="<?= htmlspecialchars((string)($prefill['terms'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div>
                    <label>Deliver To</label>
                    <input name="deliver_to" value="MHI Bldg., New York St., Brgy. Immaculate Concepcion, Cubao, Quezon City" />
                </div>
                <div>
                    <label>Look For</label>
                    <input name="look_for" value="<?= htmlspecialchars((string)($_SESSION['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div>
                    <label>Finance Officer <span style="color:#dc2626;">*</span></label>
                    <input name="finance_officer" required placeholder="Finance Officer Name" />
                </div>
                <div>
                    <label>Admin Name <span style="color:#dc2626;">*</span></label>
                    <input name="admin_name" required placeholder="Administrator Name" value="<?= htmlspecialchars((string)($prefill['admin_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                </div>
                <div style="grid-column:1/-1;">
                    <label>Notes & Instructions</label>
                    <textarea name="notes" rows="3"></textarea>
                </div>
                <div>
                    <label>Discount (optional)</label>
                    <input name="discount" type="number" step="0.01" min="0" value="0" oninput="recalcAll()" />
                </div>
            </div>
            <div style="margin-top:14px;">
                <strong>Items (Generated from PR <?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?>)</strong>
                <table>
                    <thead><tr><th>Description</th><th style="width:12%;">Unit</th><th style="width:12%;">Qty</th><th style="width:16%;">Unit Price</th><th style="width:16%;">Line Total</th><th style="width:8%;"></th></tr></thead>
                    <tbody id="poItems">
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><input name="item_desc[]" value="<?= htmlspecialchars((string)($r['item_name'] ?? $r['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" /></td>
                                <td><input name="item_unit[]" value="<?= htmlspecialchars((string)($r['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" /></td>
                                <td><input name="item_qty[]" type="number" min="1" value="<?= (int)($r['quantity'] ?? $r['qty'] ?? 1) ?>" oninput="recalc(this)" /></td>
                                <td><input name="item_price[]" type="number" step="0.01" min="0" value="<?= isset($r['prefill_price']) ? number_format((float)$r['prefill_price'],2,'.','') : (isset($r['unit_price']) ? number_format((float)$r['unit_price'],2,'.','') : '0.00') ?>" oninput="recalc(this)" /></td>
                                <td class="line-total">₱ 0.00</td>
                                <td><button class="btn muted" type="button" onclick="removeRow(this)">Remove</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="4" style="text-align:right;">Discount:</td><td id="discountCell">₱ 0.00</td><td></td></tr>
                        <tr><td colspan="4" style="text-align:right;font-weight:700;">Total:</td><td id="grandTotal" style="font-weight:700;">₱ 0.00</td><td></td></tr>
                    </tfoot>
                </table>
                <div style="margin-top:10px;"><button class="btn muted" type="button" onclick="addRow()">Add Item</button></div>
            </div>
            <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn" type="submit">Save &amp; Send for Admin Approval</button>
                <button class="btn muted" type="button" onclick="window.print()">Print Draft</button>
                <a class="btn muted" href="/manager/requests">Cancel</a>
            </div>
        </form>
    </main>
</div>
</body>
<script>
function removeRow(btn){ const tr = btn.closest('tr'); tr.parentNode.removeChild(tr); recalcAll(); }
function addRow(){
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input name="item_desc[]" /></td>
        <td><input name="item_unit[]" /></td>
        <td><input name="item_qty[]" type="number" min="1" value="1" oninput="recalc(this)" /></td>
        <td><input name="item_price[]" type="number" step="0.01" min="0" value="0" oninput="recalc(this)" /></td>
        <td class="line-total">₱ 0.00</td>
        <td><button class="btn muted" type="button" onclick="removeRow(this)">Remove</button></td>
    `;
    document.getElementById('poItems').appendChild(tr);
}
function recalc(input){
    const tr = input.closest('tr');
    const qty = parseFloat(tr.querySelector('input[name="item_qty[]"]').value || '0');
    const price = parseFloat(tr.querySelector('input[name="item_price[]"]').value || '0');
    const total = qty * price;
    tr.querySelector('.line-total').textContent = '₱ ' + total.toFixed(2);
    recalcAll();
}
function recalcAll(){
    let sum = 0;
    document.querySelectorAll('#poItems tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('input[name="item_qty[]"]').value || '0');
        const price = parseFloat(tr.querySelector('input[name="item_price[]"]').value || '0');
        sum += qty * price;
    });
    const discInput = document.querySelector('input[name="discount"]');
    const discount = discInput ? parseFloat(discInput.value || '0') : 0;
    const net = Math.max(0, sum - discount);
    const discountCell = document.getElementById('discountCell');
    if (discountCell) discountCell.textContent = '₱ ' + discount.toFixed(2);
    document.getElementById('grandTotal').textContent = '₱ ' + net.toFixed(2);
}
function validateItems(){
    const rows = document.querySelectorAll('#poItems tr');
    const required = ['center','terms','finance_officer','admin_name'];
    for (const id of required){
        const el = document.querySelector('[name="'+id+'"]');
        if (!el || !el.value.trim()){ alert('Missing required field: '+id.replace('_',' ')); return false; }
    }
    if (rows.length === 0) { alert('Add at least one item.'); return false; }
    return true;
}
recalcAll();
</script>
</html>
