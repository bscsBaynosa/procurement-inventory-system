<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • Announcements</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
    <?php require __DIR__ . '/../layouts/_theme.php'; ?>
    <style>
        :root{ --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --accent:#22c55e; }
        html[data-theme="dark"]{ --bg:#0b0b0b; --card:#0f172a; --text:#e2e8f0; --muted:#94a3b8; --border:#1f2937; --accent:#22c55e; }
        body{ margin:0; background:var(--bg); color:var(--text); font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
        .layout{ display:grid; grid-template-columns: 240px 1fr; min-height:100vh; }
        .content{ padding:18px 20px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; }
        .muted{ color: var(--muted); }
        label{ display:block; font-size:13px; color:var(--muted); margin:8px 0 6px; }
        input[type=text], textarea{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:var(--card); color:var(--text); font-family: inherit; }
        textarea{ min-height:120px; resize:vertical; }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; cursor:pointer; }
        table{ width:100%; border-collapse: collapse; background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        th, td{ padding:12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .row-actions form{ display:inline; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">Announcements</div>
        <?php if (!empty($created)): ?>
            <div class="card" style="border-left:4px solid #22c55e; margin-bottom:10px;">Announcement sent successfully to Admin Assistants and Procurement.</div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="card" style="border-left:4px solid #dc2626; margin-bottom:10px;">Error: <?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:16px;">
            <div style="font-weight:700; margin-bottom:8px;">Create New Announcement</div>
            <form method="post" action="/admin/announcements">
                <label for="topic">Topic</label>
                <input type="text" id="topic" name="topic" required maxlength="255" />
                <label for="content">Content</label>
                <textarea id="content" name="content" required placeholder="Write your announcement... (recipients: Admin Assistants and Procurement)"></textarea>
                <div style="margin-top:10px; display:flex; gap:8px;">
                    <button class="btn" type="submit">Publish Announcement</button>
                    <span class="muted">Recipients: Admin Assistants and Procurement</span>
                </div>
            </form>
        </div>

        <div class="card">
            <div style="font-weight:700; margin-bottom:8px;">Recent Announcements</div>
            <table>
                <thead>
                    <tr><th style="width:28%">Topic</th><th>Content</th><th style="width:180px">Created</th><th style="width:80px">Actions</th></tr>
                </thead>
                <tbody>
                <?php if (!empty($announcements)): foreach ($announcements as $a): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$a['topic'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="muted" style="white-space:pre-wrap;"><?= nl2br(htmlspecialchars((string)$a['content'], ENT_QUOTES, 'UTF-8')) ?></td>
                        <td><?= htmlspecialchars((string)$a['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="row-actions">
                            <form method="post" action="/admin/announcements/delete" onsubmit="return confirm('Delete this announcement?');">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>" />
                                <button type="submit" class="btn" style="background:#ef4444;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="muted">No announcements yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
