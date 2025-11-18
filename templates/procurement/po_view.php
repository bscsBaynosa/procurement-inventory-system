<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement • PO View</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <?php require __DIR__ . '/../layouts/_theme.php'; ?>
    <style>
        :root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#22c55e; --control-h: 36px; }
        html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#22c55e; --control-h: 36px; }
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .layout{ display:grid; grid-template-columns: 240px 1fr; min-height:100vh; }
        .sidebar{ background:#fff; border-right:1px solid var(--border); padding:18px 12px; position:sticky; top:0; height:100vh; }
        html[data-theme="dark"] .sidebar{ background:#0f172a; }
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .grid{ display:grid; grid-template-columns: repeat(2, minmax(260px, 1fr)); gap:10px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:14px; }
        .muted{ color:var(--muted); }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .btn{ display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 12px; height:var(--control-h); min-width:90px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text); text-decoration:none; font-size:12px; cursor:pointer; }
        .btn.primary{ border-color: color-mix(in oklab, var(--accent) 35%, var(--border)); background: color-mix(in oklab, var(--accent) 10%, transparent); }
        .badge{ background:color-mix(in oklab, var(--accent) 12%, transparent); color:var(--text); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); padding:2px 6px; border-radius:999px; font-size:11px; }
        .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
            <div class="h1">
            <span>PO <?= htmlspecialchars((string)$po['po_number'], ENT_QUOTES, 'UTF-8') ?></span>
            <div style="display:flex; gap:8px;">
                <a class="btn" href="/procurement/pos">Back to list</a>
                <?php if (!empty($po['pdf_path'])): ?>
                    <a class="btn primary" href="/procurement/po/download?id=<?= (int)$po['id'] ?>" target="_blank" rel="noopener">Download PDF</a>
                <?php endif; ?>
                <a class="btn" href="/procurement/po/export?id=<?= (int)$po['id'] ?>" title="Regenerate & Export fresh PDF" target="_blank" rel="noopener">Export PDF</a>
                <?php $termsStatus = (string)($po['terms_status'] ?? ''); ?>
                <?php if ($termsStatus === 'agreed'): ?>
                    <a class="btn" href="/procurement/rfp/create?po=<?= (int)$po['id'] ?>">Generate RFP</a>
                <?php else: ?>
                    <span class="btn" style="opacity:.6;cursor:not-allowed;" title="Available after terms are agreed">Generate RFP</span>
                <?php endif; ?>
                    <?php if ((string)($po['status'] ?? '') === 'po_admin_approved'): ?>
                        <form method="POST" action="/procurement/po/send" style="display:inline;">
                            <input type="hidden" name="id" value="<?= (int)$po['id'] ?>" />
                            <button type="submit" class="btn primary" title="Send approved PO to supplier">Send to Supplier</button>
                        </form>
                    <?php endif; ?>
                    <?php if (($_SESSION['role'] ?? null) === 'admin' && !in_array((string)($po['status'] ?? ''), ['po_admin_approved','po_rejected'], true)): ?>
                        <form method="POST" action="/admin/po/approve" style="display:inline;" onsubmit="return confirm('Approve this PO?');">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$po['pr_number'], ENT_QUOTES, 'UTF-8') ?>" />
                            <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>" />
                            <button type="submit" class="btn">Approve</button>
                        </form>
                        <form method="POST" action="/admin/po/reject" style="display:inline;" onsubmit="return confirm('Reject this PO?');">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$po['pr_number'], ENT_QUOTES, 'UTF-8') ?>" />
                            <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>" />
                            <input type="hidden" name="reason" value="Rejected from PO view" />
                            <button type="submit" class="btn">Reject</button>
                        </form>
                    <?php endif; ?>
            </div>
        </div>

        <?php
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);
        $flashError = $_SESSION['flash_error'] ?? null; unset($_SESSION['flash_error']);
        if ($flashSuccess): ?>
            <div style="margin:4px 0 12px; padding:10px 12px; border:1px solid #16a34a66; background:color-mix(in oklab, #22c55e 10%, transparent); border-radius:10px;">
                <?= htmlspecialchars((string)$flashSuccess, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; if ($flashError): ?>
            <div style="margin:4px 0 12px; padding:10px 12px; border:1px solid #ef444466; background:color-mix(in oklab, #ef4444 10%, transparent); border-radius:10px;">
                <?= htmlspecialchars((string)$flashError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="grid" style="margin-bottom:12px;">
            <div class="card">
                <div class="muted">PR Number</div>
                <div class="mono"><?= htmlspecialchars(\App\Services\IdService::format('PR', (string)$po['pr_number']), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="muted" style="margin-top:8px;">Supplier</div>
                <div><?= htmlspecialchars((string)$po['supplier_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php if (!empty($po['vendor_name'])): ?>
                <div class="muted" style="margin-top:8px;">Vendor</div>
                <div><?= htmlspecialchars((string)$po['vendor_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="muted">Status</div>
                <div><span class="badge"><?= htmlspecialchars(ucwords(str_replace('_',' ', (string)$po['status'])), ENT_QUOTES, 'UTF-8') ?></span></div>
                <div class="muted" style="margin-top:8px;">Created</div>
                <div><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$po['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="muted" style="margin-top:8px;">Total</div>
                <div>
                    <?php $discount = isset($po['discount']) ? (float)$po['discount'] : 0; $total = (float)($po['total'] ?? 0); ?>
                    <?php if ($discount > 0): ?>
                        <span style="font-size:12px;color:var(--muted);">Gross: ₱ <?= number_format($total + $discount, 2) ?></span><br>
                        <span style="font-weight:700;">Net: ₱ <?= number_format($total, 2) ?></span><br>
                        <span style="font-size:12px;color:var(--muted);">Discount: ₱ <?= number_format($discount, 2) ?></span>
                    <?php else: ?>
                        ₱ <?= number_format($total, 2) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:12px;">
            <div class="muted">Meta</div>
            <div style="display:grid; grid-template-columns: repeat(3, minmax(220px,1fr)); gap:10px; margin-top:6px;">
                <div><strong>Center:</strong> <?= htmlspecialchars((string)($po['center'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Reference:</strong> <?= htmlspecialchars((string)($po['reference'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Look For:</strong> <?= htmlspecialchars((string)($po['look_for'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div style="grid-column: 1 / -1;"><strong>Deliver To:</strong> <?= htmlspecialchars((string)($po['deliver_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <div style="grid-column: 1 / -1;"><strong>Terms:</strong> <?= nl2br(htmlspecialchars((string)($po['terms'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
                <div style="grid-column: 1 / -1;"><strong>Notes:</strong> <?= nl2br(htmlspecialchars((string)($po['notes'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
            </div>
        </div>

        <div class="card" style="margin-bottom:12px;">
            <div class="muted">Terms & Logistics</div>
            <div style="display:grid; grid-template-columns: repeat(2, minmax(240px,1fr)); gap:10px; margin-top:6px;">
                <div>
                    <strong>Supplier Terms</strong>
                    <div class="muted" style="margin-top:4px; white-space:pre-line;">
                        <?= htmlspecialchars((string)($po['supplier_terms'] ?? ''), ENT_QUOTES, 'UTF-8') !== '' ? nl2br(htmlspecialchars((string)$po['supplier_terms'], ENT_QUOTES, 'UTF-8')) : '<span class="muted">—</span>' ?>
                    </div>
                </div>
                <div>
                    <strong>Procurement Proposal</strong>
                <?php $termsStatus = (string)($po['terms_status'] ?? ''); ?>
                <div style="margin-top:10px;font-size:12px;line-height:1.4;">
                    <?php if (in_array($status, ['po_admin_approved','sent_to_supplier'], true) && $termsStatus === ''): ?>
                        <div style="padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:color-mix(in oklab, var(--card) 92%, var(--bg));">
                            <strong>Awaiting Supplier Terms.</strong> Supplier must submit Terms of Payment before Procurement can accept or counter. Shipment cannot begin yet.
                        </div>
                    <?php elseif ($status === 'supplier_response_submitted' && $termsStatus !== 'agreed'): ?>
                        <div style="padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:color-mix(in oklab, var(--accent) 6%, var(--card));">
                            <strong>Supplier Terms Received.</strong> Review and either Agree or send a Counter Proposal. Shipment will be unlocked after acceptance.
                        </div>
                    <?php elseif ($status === 'terms_counter_proposed' && $termsStatus === 'counter_proposed'): ?>
                        <div style="padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:color-mix(in oklab, var(--card) 92%, var(--bg));">
                            <strong>Counter Proposal Sent.</strong> Waiting for supplier to respond. Shipment remains locked until agreement.
                        </div>
                    <?php elseif ($termsStatus === 'agreed'): ?>
                        <div style="padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:color-mix(in oklab, var(--accent) 8%, var(--card));">
                            <strong>Terms Agreed.</strong> Supplier may now proceed with shipment. Logistics updates will appear below as they are posted.
                        </div>
                    <?php endif; ?>
                </div>
                    <div class="muted" style="margin-top:4px; white-space:pre-line;">
                        <?= htmlspecialchars((string)($po['procurement_terms'] ?? ''), ENT_QUOTES, 'UTF-8') !== '' ? nl2br(htmlspecialchars((string)$po['procurement_terms'], ENT_QUOTES, 'UTF-8')) : '<span class="muted">—</span>' ?>
                    </div>
                </div>
                <div>
                    <strong>Terms Status:</strong>
                    <span class="badge" style="margin-left:6px;"><?= htmlspecialchars(ucwords(str_replace('_',' ', (string)($po['terms_status'] ?? '—'))), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div>
                    <strong>Logistics Status:</strong>
                    <span class="badge" style="margin-left:6px;"><?= htmlspecialchars(ucwords(str_replace('_',' ', (string)($po['logistics_status'] ?? '—'))), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($po['logistics_notes'])): ?>
                        <div class="muted" style="margin-top:4px;">Notes: <?= nl2br(htmlspecialchars((string)$po['logistics_notes'], ENT_QUOTES, 'UTF-8')) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php $role = $_SESSION['role'] ?? ''; $status = (string)($po['status'] ?? ''); ?>
            <?php if (in_array($role, ['procurement','procurement_manager'], true) && in_array($status, ['supplier_response_submitted','terms_counter_proposed'], true)): ?>
                <div style="display:flex; gap:10px; align-items:flex-start; margin-top:10px;">
                    <form method="POST" action="/procurement/po/terms/agree">
                        <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>" />
                        <button class="btn primary" type="submit">Agree to Supplier Terms</button>
                    </form>
                    <form method="POST" action="/procurement/po/terms/propose" style="display:grid; grid-template-columns: 1fr auto; gap:8px; min-width:420px;">
                        <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>" />
                        <textarea name="proposal" placeholder="Propose changes (payment terms, delivery, etc.)" style="grid-column:1/-1; min-height:60px;"></textarea>
                        <button class="btn" type="submit">Send Counter Proposal</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (in_array($role, ['procurement','procurement_manager'], true) && strtolower((string)($po['logistics_status'] ?? '')) === 'delivered'): ?>
                <div style="margin-top:10px;">
                    <?php if (!empty($po['gate_pass_path']) && is_file((string)$po['gate_pass_path'])): ?>
                        <span class="badge">Gate Pass generated</span>
                    <?php else: ?>
                        <form method="POST" action="/procurement/po/gatepass" onsubmit="return confirm('Generate Gate Pass now?');" style="display:inline;" target="_blank">
                            <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>" />
                            <button class="btn primary" type="submit">Generate Gate Pass</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Unit</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): foreach ($items as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$it['description'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$it['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$it['qty'] ?></td>
                            <td>₱ <?= number_format((float)$it['unit_price'], 2) ?></td>
                            <td>₱ <?= number_format((float)$it['line_total'], 2) ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="muted">No items</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
