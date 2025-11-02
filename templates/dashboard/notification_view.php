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
            <?php if (!empty($message['attachment_name']) && !empty($message['attachment_path'])): ?>
                <div style="margin-top:10px;">
                    <a class="btn" href="/inbox/download?id=<?= (int)$message['id'] ?>">Download Attachment: <?= htmlspecialchars((string)$message['attachment_name'], ENT_QUOTES, 'UTF-8') ?></a>
                </div>
            <?php endif; ?>
            <?php
                // If the subject contains a request id (e.g., "New Purchase Request #123"),
                // offer a direct link to the PR details page.
                $requestId = 0;
                if (preg_match('/#(\d+)/', (string)($message['subject'] ?? ''), $m)) {
                    $requestId = (int)$m[1];
                }
                $prNumber = null;
                if (preg_match('/PR\s*([0-9\-]+)/i', (string)($message['subject'] ?? ''), $mm)) {
                    $prNumber = $mm[1];
                }
                $poNumber = null;
                if (preg_match('/PO\s*([0-9A-Za-z\-]+)/i', (string)($message['subject'] ?? ''), $mpo)) {
                    $poNumber = $mpo[1];
                }
            ?>
            <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
                <?php if ($requestId > 0): ?>
                    <a class="btn" href="/requests/view?request_id=<?= (int)$requestId ?>">View Request Details</a>
                <?php endif; ?>
                <a class="btn primary" href="/admin/messages?subject=Re:%20<?= rawurlencode((string)$message['subject']) ?>&to=<?= (int)$message['sender_id'] ?>">Reply / Forward</a>
                <a class="btn muted" href="/inbox">Back to Inbox</a>
            </div>

            <?php if (($_SESSION['role'] ?? null) === 'admin' && stripos((string)$message['subject'], 'Canvassing For Approval') !== false && !empty($prNumber)): ?>
                <div class="card" style="margin-top:14px;">
                    <h3 style="margin-top:0;">Canvassing Approval</h3>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <form method="POST" action="/admin/canvassing/approve" onsubmit="return confirm('Approve canvassing for PR <?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>?');">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>" />
                            <input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>" />
                            <button class="btn primary" type="submit">Approve Canvassing</button>
                        </form>
                        <form method="POST" action="/admin/canvassing/reject" onsubmit="return confirm('Reject canvassing for PR <?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>?');" style="display:flex; gap:8px; align-items:center;">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>" />
                            <input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>" />
                            <input type="text" name="notes" placeholder="Reason (optional)" style="min-width:240px;" />
                            <button class="btn danger" type="submit">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (($_SESSION['role'] ?? null) === 'admin' && stripos((string)$message['subject'], 'For Admin Approval') !== false && !empty($prNumber)): ?>
                <div class="card" style="margin-top:14px;">
                    <h3 style="margin-top:0;">Purchase Request Approval</h3>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <form method="POST" action="/admin/pr/approve" onsubmit="return confirm('Approve PR <?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>?');">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>" />
                            <input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>" />
                            <button class="btn primary" type="submit">Approve PR</button>
                        </form>
                        <form method="POST" action="/admin/pr/reject" onsubmit="return confirm('Reject PR <?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>?');" style="display:flex; gap:8px; align-items:center;">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>" />
                            <input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>" />
                            <input type="text" name="notes" placeholder="Reason (optional)" style="min-width:240px;" />
                            <button class="btn danger" type="submit">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (($_SESSION['role'] ?? null) === 'admin' && stripos((string)$message['subject'], 'PO For Approval') !== false && !empty($prNumber)): ?>
                <div class="card" style="margin-top:14px;">
                    <h3 style="margin-top:0;">Purchase Order Approval</h3>
                    <div class="muted" style="font-size:12px;margin:4px 0 8px;">PR <?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?><?= $poNumber? ' • PO ' . htmlspecialchars((string)$poNumber, ENT_QUOTES, 'UTF-8') : '' ?></div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <form method="POST" action="/admin/po/approve" onsubmit="return confirm('Approve PO for PR <?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>?');">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>" />
                            <button class="btn primary" type="submit">Approve PO</button>
                        </form>
                        <form method="POST" action="/admin/po/reject" onsubmit="return confirm('Reject PO for PR <?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>?');" style="display:flex; gap:8px; align-items:center;">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>" />
                            <input type="text" name="reason" placeholder="Reason (optional)" style="min-width:240px;" />
                            <button class="btn danger" type="submit">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (($_SESSION['role'] ?? null) === 'admin_assistant' && stripos((string)$message['subject'], 'Revision Proposed') !== false && !empty($prNumber)): ?>
                <div class="card" style="margin-top:14px;">
                    <h3 style="margin-top:0;">Revision Proposed</h3>
                    <p class="muted" style="margin-top:0;">The Admin proposed revised quantities for PR <?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>. You can accept or provide justification.</p>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-start;">
                        <form method="POST" action="/assistant/pr/revision/accept" onsubmit="return confirm('Accept the proposed revision for PR <?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>?');">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>" />
                            <input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>" />
                            <button class="btn primary" type="submit">Accept Revision</button>
                        </form>
                        <form method="POST" action="/assistant/pr/revision/justify" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <input type="hidden" name="pr_number" value="<?= htmlspecialchars((string)$prNumber, ENT_QUOTES, 'UTF-8') ?>" />
                            <input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>" />
                            <textarea name="notes" placeholder="Provide justification" style="min-width:300px; min-height:40px;"></textarea>
                            <button class="btn" type="submit">Send Justification</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
