<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message</title>
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
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        .btn{ font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-weight:700; font-size:14px; line-height:1; height:40px; padding:0 14px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; transition:all .15s ease; cursor:pointer; }
        .btn.primary{ background:var(--accent); color:#fff; border:0; }
        .btn.muted{ background:transparent; color:var(--muted); border:1px solid var(--border); }
        .danger{ background:#dc2626; color:#fff; border:0; }
        textarea, input{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h2 style="margin:0"><?= htmlspecialchars((string)$message['subject'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div style="display:flex;gap:8px;">
                    <?php if (empty($message['is_read'])): ?>
                    <form method="POST" action="/admin/messages/mark-read">
                        <input type="hidden" name="id" value="<?= (int)$message['id'] ?>" />
                        <button class="btn muted" type="submit">Mark as read</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="muted" style="margin:6px 0 12px;">From <?= htmlspecialchars((string)$message['from_name'], ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars((string)$message['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
            <div style="white-space:pre-wrap;"><?= nl2br(htmlspecialchars((string)$message['body'], ENT_QUOTES, 'UTF-8')) ?></div>
            <?php
                // If the subject contains a request id (e.g., "New Purchase Request #123"),
                // offer a direct link to the PR details page.
                $requestId = 0;
                if (preg_match('/#(\d+)/', (string)($message['subject'] ?? ''), $m)) {
                    $requestId = (int)$m[1];
                }
            ?>
            <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
                <?php if ($requestId > 0): ?>
                    <a class="btn" href="/requests/view?request_id=<?= (int)$requestId ?>">View Request Details</a>
                <?php endif; ?>
                <a class="btn primary" href="/admin/messages?subject=Re:%20<?= rawurlencode((string)$message['subject']) ?>&to=<?= (int)$message['sender_id'] ?>">Reply / Forward</a>
                <a class="btn muted" href="/inbox">Back to Inbox</a>
            </div>
        </div>
    </main>
</div>
</body>
</html>
