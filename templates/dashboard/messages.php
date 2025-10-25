<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages</title>
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
        .brand{ font-weight:800; padding:6px 10px; }
        .nav{ margin-top:14px; display:flex; flex-direction:column; gap:6px; }
        .nav a{ padding:10px 12px; color:var(--text); text-decoration:none; border-radius:10px; }
        .nav a:hover{ background:var(--bg); }
        .nav a.active{ background: color-mix(in oklab, var(--accent) 10%, transparent); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); }
        .content{ padding:18px 20px; }
    .grid{ display:grid; grid-template-columns: 380px 1fr; gap:12px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
    textarea, input, select{ width:100%; max-width:100%; min-width:0; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; font:inherit; }
    .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
    .nav a{ display:flex; align-items:center; gap:10px; padding:10px 12px; color:var(--text); text-decoration:none; border-radius:10px; }
    .nav a:hover{ background:var(--bg); }
    .nav a.active{ background: color-mix(in oklab, var(--accent) 10%, transparent); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); }
    .nav svg{ width:18px; height:18px; fill: var(--accent); }
    @media (max-width: 900px){ .layout{ grid-template-columns: 1fr; } .grid{ grid-template-columns: 1fr; } }
    @media (max-width: 1100px){ .grid{ grid-template-columns: 1fr; } }

    tr.unread td{ font-weight:700; background: color-mix(in oklab, var(--accent) 6%, var(--card)); }
    .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <h2 style="margin:0 0 12px 0;">Messages</h2>
        <div class="grid">
            <div class="card" style="overflow:auto;">
                <table>
                    <thead><tr><th>From</th><th>Subject</th><th>Date</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if (!empty($inbox)): foreach ($inbox as $m): ?>
                        <tr class="<?= !empty($m['is_read']) ? '' : 'unread' ?>">
                            <td><?= htmlspecialchars((string)$m['from_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$m['subject'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$m['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if (empty($m['is_read'])): ?>
                                    <form method="POST" action="/admin/messages/mark-read" style="display:inline;">
                                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>" />
                                        <button type="submit" class="btn muted">Mark as read</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">Read</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="3" style="color:#64748b">No messages.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h3 style="margin-top:0">Compose</h3>
                <form method="POST" action="/admin/messages" style="display:grid; gap:10px;">
                    <div>
                        <label>To</label>
                        <select name="to" required>
                            <option value="">— Select recipient —</option>
                            <?php $pf = isset($prefill_to) ? (int)$prefill_to : 0; foreach ($users as $u): ?>
                                <option value="<?= (int)$u['user_id'] ?>" <?= ($pf === (int)$u['user_id'] ? 'selected' : '') ?>><?= htmlspecialchars($u['full_name'] . ' (' . $u['role'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Subject</label>
                        <input name="subject" required placeholder="Subject" value="<?= htmlspecialchars((string)($prefill_subject ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                    </div>
                    <div>
                        <label>Message</label>
                        <textarea name="body" rows="10" required placeholder="Write your message..."></textarea>
                    </div>
                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button class="btn" type="submit">Send</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
