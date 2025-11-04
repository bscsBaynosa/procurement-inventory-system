<?php
// Procurement PR group view: shows group meta and items, with actions to download and send for admin approval.
if (!isset($rows) && isset($request) && is_array($request)) {
    // Fallback shape if controller passes different vars
    $rows = [];
}
$pr = isset($pr) ? (string)$pr : (isset($rows[0]['pr_number']) ? (string)$rows[0]['pr_number'] : '');
$branch = isset($rows[0]['branch_name']) ? (string)$rows[0]['branch_name'] : '';
$preparedAt = isset($rows[0]['created_at']) ? (string)$rows[0]['created_at'] : '';
$requestedBy = isset($rows[0]['requested_by_name']) ? (string)$rows[0]['requested_by_name'] : '';
$status = isset($rows[0]['status']) ? (string)$rows[0]['status'] : '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>PR <?= htmlspecialchars($pr) ?> • Procurement</title>
  <link rel="icon" href="/img/pocc-logo.svg">
  <link rel="stylesheet" href="/css/main.css">
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 16px; background:#f8fafc; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; max-width: 1100px; margin: 0 auto; }
    .muted { color: #64748b; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #e5e7eb; padding: 10px; }
    th { background: #f1f5f9; text-align: left; }
    .actions { display: flex; gap: 12px; margin-top: 12px; }
    .btn { display: inline-flex; align-items:center; gap:8px; font-weight:600; font-size:14px; padding: 10px 16px; border-radius: 10px; text-decoration: none; cursor: pointer; border: 1px solid transparent; }
    .btn.primary { background: #16a34a; color: #fff; }
    .btn.primary:hover { background: #15803d; }
    .btn.secondary { background: #0ea5e9; color: #fff; }
    .btn.secondary:hover { background: #0284c7; }
    .btn.ghost { background: #ffffff; color: #0f172a; border-color: #cbd5e1; }
    .btn.ghost:hover { background:#f8fafc; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
    .label { font-size: 12px; color: #6b7280; }
    .value { font-weight: 600; }
  </style>
</head>
<body>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <div>
        <div class="muted">Purchase Requisition No.</div>
        <div style="font-size:20px;font-weight:800;">PR <?= htmlspecialchars($pr) ?></div>
      </div>
      <div class="actions">
        <a class="btn ghost" href="/manager/requests">← Back to Requests</a>
        <a class="btn primary" href="/manager/requests/download?pr=<?= rawurlencode($pr) ?>" target="_blank" rel="noopener">Download PDF</a>
        <form method="post" action="/manager/requests/send-for-approval" onsubmit="return confirm('Send this PR to Admin for approval?');" style="margin:0;">
          <input type="hidden" name="pr_number" value="<?= htmlspecialchars($pr) ?>">
          <button type="submit" class="btn secondary">Send for Admin Approval</button>
        </form>
      </div>
    </div>

    <?php $preparedAtFmt = $preparedAt ? date('Y-m-d', strtotime($preparedAt)) : ''; ?>
    <div class="grid">
      <div><div class="label">Requesting Section</div><div class="value"><?= htmlspecialchars($branch) ?></div></div>
      <div><div class="label">Prepared On</div><div class="value"><?= htmlspecialchars($preparedAtFmt) ?></div></div>
      <div><div class="label">Requisition By</div><div class="value"><?= htmlspecialchars($requestedBy) ?></div></div>
      <div><div class="label">Status</div><div class="value"><?= htmlspecialchars(ucwords(str_replace('_',' ', $status))) ?></div></div>
    </div>

    <table>
      <thead>
        <tr>
          <th style="width:60%;">Description</th>
          <th style="width:12%;">Unit</th>
          <th style="width:12%;">Quantity</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars((string)($r['item_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['unit'] ?? '')) ?></td>
            <td><?= (int)($r['quantity'] ?? 0) ?></td>
            <td><?= htmlspecialchars((string)($r['revision_notes'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
