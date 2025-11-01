<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Admin Assistant • Review Purchase Requisition</title>
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
		table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
		th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
		th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
		.btn{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-weight:700; font-size:14px; line-height:1; height:40px; padding:0 14px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; transition:all .15s ease; cursor:pointer; }
		.btn.primary{ background:var(--accent); color:#fff; border:0; }
		.btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
		input[type="number"], input[type="text"], select, textarea{ width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid var(--border); border-radius:10px; }
	</style>
	</head>
<body>
<div class="layout">
	<?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
	<main class="content">
		<div class="h1">Review Purchase Requisition</div>
		<div class="card">
			<form method="POST" action="/admin-assistant/requests/submit">
				<table>
					<thead>
						<tr><th>Item</th><th>Current Stock</th><th>Request Qty</th><th>Unit</th></tr>
					</thead>
					<tbody>
						<?php if (!empty($cart)): foreach ($cart as $i => $it): ?>
							<tr>
								<td>
									<?= htmlspecialchars((string)$it['name'], ENT_QUOTES, 'UTF-8') ?>
									<input type="hidden" name="items[<?= $i ?>][item_id]" value="<?= (int)$it['item_id'] ?>" />
								</td>
								<td><?= (int)($it['quantity'] ?? 0) ?> <?= htmlspecialchars((string)($it['unit'] ?? 'pcs'), ENT_QUOTES, 'UTF-8') ?></td>
								<td style="width:140px;"><input type="number" name="items[<?= $i ?>][quantity]" min="1" value="<?= max(1,(int)($it['req_qty'] ?? 1)) ?>" /></td>
								<td style="width:280px; display:flex; gap:8px; align-items:center;">
									<input type="text" name="items[<?= $i ?>][unit]" value="<?= htmlspecialchars((string)($it['unit'] ?? 'pcs'), ENT_QUOTES, 'UTF-8') ?>" style="width:120px;" />
									<form method="POST" action="/admin-assistant/requests/cart-remove" onsubmit="return confirm('Remove this item from the request?');">
										<input type="hidden" name="item_id" value="<?= (int)$it['item_id'] ?>" />
										<button class="btn muted" type="submit">Remove</button>
									</form>
								</td>
							</tr>
						<?php endforeach; else: ?>
							<tr><td colspan="4" style="color:var(--muted)">Your cart is empty. Go back to Inventory to select low-stock items.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:12px;">
					<div>
						<label>Justification</label>
						<textarea name="justification" rows="3" placeholder="Optional note (e.g., monthly replenishment)"></textarea>
					</div>
					<div>
						<label>Needed By</label>
						<input type="date" name="needed_by" />
					</div>
				</div>
				<div style="margin-top:12px; display:flex; gap:8px;">
					<a href="/admin-assistant/inventory" class="btn muted">Back to Inventory</a>
					<button class="btn primary" type="submit">Submit Purchase Requisition(s)</button>
				</div>
			</form>
		</div>
	</main>
</div>
</body>
</html>
