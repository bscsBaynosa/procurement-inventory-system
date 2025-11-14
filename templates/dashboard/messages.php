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
        <?php if (isset($_GET['created'])): $c = (int)$_GET['created']; $s = isset($_GET['skipped']) ? (int)$_GET['skipped'] : 0; ?>
            <div class="card" style="border-color:#86efac; background:color-mix(in oklab, var(--card) 90%, #86efac); margin-bottom:12px;">
                <strong><?= $c ?></strong> purchase request<?= $c===1?'':'s' ?> submitted successfully.
                <?php if ($s > 0): ?>
                    <span style="color:#065f46; margin-left:8px;">Note: <?= $s ?> item<?= $s===1?' was':'s were' ?> skipped because a PR is already in process.</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="grid">
            <div class="card" style="overflow:auto;">
                <table>
                    <thead><tr><th>From</th><th>Subject</th><th>Date</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if (!empty($inbox)): foreach ($inbox as $m): ?>
                        <tr class="<?= !empty($m['is_read']) ? '' : 'unread' ?>">
                            <td><?= htmlspecialchars((string)$m['from_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><a href="/inbox/view?id=<?= (int)$m['id'] ?>" style="text-decoration:none;color:inherit;"><?= htmlspecialchars((string)$m['subject'], ENT_QUOTES, 'UTF-8') ?></a></td>
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
                <form method="POST" action="/admin/messages" style="display:grid; gap:10px;" enctype="multipart/form-data">
                    <div>
                        <label>To</label>
                        <div id="chips-to" class="chips" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:6px;border:1px solid var(--border);border-radius:10px;min-height:44px;background:var(--card)">
                            <!-- chips + input will render here -->
                        </div>
                        <div class="muted" style="font-size:12px;margin-top:6px;">Type a name and press Enter to add. Click a chip’s × to remove.</div>
                    </div>
                    <div>
                        <label>Subject</label>
                        <input name="subject" required placeholder="Subject" value="<?= htmlspecialchars((string)($prefill_subject ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                    </div>
                    <?php $prefAttName = isset($prefill_attachment_name) ? (string)$prefill_attachment_name : ''; $prefAttPath = isset($prefill_attachment_path) ? (string)$prefill_attachment_path : ''; ?>
                    <?php if ($prefAttName !== '' && $prefAttPath !== ''): ?>
                        <div>
                            <label>Attachment</label>
                            <div class="card" style="padding:10px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                <div><strong>Will attach:</strong> <span class="mono"><?= htmlspecialchars($prefAttName, ENT_QUOTES, 'UTF-8') ?></span></div>
                                <button type="button" class="btn muted" onclick="(function(f){ f.querySelector('[name=attach_name]').value=''; f.querySelector('[name=attach_path]').value=''; f.querySelector('[data-pref-att]').remove(); })(this.closest('form'));">Remove</button>
                            </div>
                            <input type="hidden" name="attach_name" value="<?= htmlspecialchars($prefAttName, ENT_QUOTES, 'UTF-8') ?>" data-pref-att />
                            <input type="hidden" name="attach_path" value="<?= htmlspecialchars($prefAttPath, ENT_QUOTES, 'UTF-8') ?>" data-pref-att />
                        </div>
                    <?php endif; ?>
                    <?php $prefAttName2 = isset($prefill_attachment_name2) ? (string)$prefill_attachment_name2 : ''; $prefAttPath2 = isset($prefill_attachment_path2) ? (string)$prefill_attachment_path2 : ''; ?>
                    <?php if ($prefAttName2 !== '' && $prefAttPath2 !== ''): ?>
                        <div>
                            <label>Attachment 2</label>
                            <div class="card" style="padding:10px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                <div><strong>Will attach:</strong> <span class="mono"><?= htmlspecialchars($prefAttName2, ENT_QUOTES, 'UTF-8') ?></span></div>
                                <button type="button" class="btn muted" onclick="(function(f){ var n=f.querySelector('[name=attach_name2]'); var p=f.querySelector('[name=attach_path2]'); if(n) n.value=''; if(p) p.value=''; var el=f.querySelector('[data-pref-att2]'); if(el) el.remove(); })(this.closest('form'));"><span>Remove</span></button>
                            </div>
                            <input type="hidden" name="attach_name2" value="<?= htmlspecialchars($prefAttName2, ENT_QUOTES, 'UTF-8') ?>" data-pref-att2 />
                            <input type="hidden" name="attach_path2" value="<?= htmlspecialchars($prefAttPath2, ENT_QUOTES, 'UTF-8') ?>" data-pref-att2 />
                        </div>
                    <?php endif; ?>
                    <div>
                        <label>Message</label>
                        <textarea name="body" rows="10" required placeholder="Write your message..."></textarea>
                    </div>
                    <div>
                        <label>Attachment (optional)</label>
                        <input type="file" name="attachment" />
                    </div>
                                        <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button class="btn" type="submit">Send</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<script>
(function(){
    const users = <?php
        $list = [];
        foreach ($users as $u) {
                $list[] = [
                        'id' => (int)$u['user_id'],
                        'name' => (string)$u['full_name'],
                        'role' => (string)$u['role'],
                ];
        }
        echo json_encode($list);
    ?>;
    const prefillSingle = <?= isset($prefill_to) && (int)$prefill_to > 0 ? (int)$prefill_to : 'null' ?>;
    const prefillList = <?php echo json_encode(isset($prefill_to_list) && is_array($prefill_to_list) ? array_values(array_map('intval', $prefill_to_list)) : []); ?>;

    const el = document.getElementById('chips-to');
    if (!el) return;
    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = 'Add recipient...';
    input.style.flex = '1';
    input.style.minWidth = '160px';
    input.style.border = '0';
    input.style.outline = 'none';
    input.style.background = 'transparent';
    const menu = document.createElement('div');
    menu.style.position = 'absolute';
    menu.style.background = 'var(--card)';
    menu.style.border = '1px solid var(--border)';
    menu.style.borderRadius = '8px';
    menu.style.marginTop = '2px';
    menu.style.minWidth = '260px';
    menu.style.maxHeight = '180px';
    menu.style.overflowY = 'auto';
    menu.style.display = 'none';
    let selected = new Map(); // id -> {id,name}

    function addChip(user){
        if (selected.has(user.id)) return;
        selected.set(user.id, user);
        const chip = document.createElement('span');
        chip.textContent = user.name + ' (' + user.role + ')';
        chip.style.display = 'inline-flex';
        chip.style.alignItems = 'center';
        chip.style.gap = '6px';
        chip.style.padding = '6px 8px';
        chip.style.border = '1px solid var(--border)';
        chip.style.borderRadius = '999px';
        chip.style.background = 'color-mix(in oklab, var(--accent) 10%, transparent)';
        const x = document.createElement('button');
        x.type = 'button';
        x.textContent = '×';
        x.style.background = 'transparent';
        x.style.border = '0';
        x.style.cursor = 'pointer';
        x.onclick = () => {
            selected.delete(user.id);
            chip.remove();
            // Remove corresponding hidden input
            const hid = el.querySelector('input[type=hidden][data-recipient="'+user.id+'"]');
            if (hid) hid.remove();
        };
        chip.appendChild(x);
        // Hidden input
        const hid = document.createElement('input');
        hid.type = 'hidden';
        hid.name = 'to[]';
        hid.value = String(user.id);
        hid.setAttribute('data-recipient', String(user.id));
        el.insertBefore(chip, input);
        el.appendChild(hid);
    }

    function filterUsers(q){
        const t = q.trim().toLowerCase();
        if (!t) return users.slice(0, 10);
        return users.filter(u => u.name.toLowerCase().includes(t) || String(u.id).includes(t) || (u.role||'').toLowerCase().includes(t)).slice(0, 10);
    }

    function openMenu(items){
        menu.innerHTML = '';
        items.forEach(u => {
            if (selected.has(u.id)) return;
            const row = document.createElement('div');
            row.textContent = u.name + ' (' + u.role + ')';
            row.style.padding = '8px 10px';
            row.style.cursor = 'pointer';
            row.onmouseenter = () => { row.style.background = 'color-mix(in oklab, var(--accent) 8%, transparent)'; };
            row.onmouseleave = () => { row.style.background = 'transparent'; };
            row.onclick = () => { addChip(u); menu.style.display='none'; input.value=''; };
            menu.appendChild(row);
        });
        if (menu.childElementCount > 0) {
            const rect = input.getBoundingClientRect();
            menu.style.left = '0px';
            el.appendChild(menu);
            menu.style.display = 'block';
        } else {
            menu.style.display = 'none';
        }
    }

    input.addEventListener('input', () => openMenu(filterUsers(input.value)) );
    input.addEventListener('focus', () => openMenu(filterUsers(input.value)) );
    input.addEventListener('blur', () => { setTimeout(()=> menu.style.display='none', 150); });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && input.value.trim() !== '') {
            e.preventDefault();
            const first = filterUsers(input.value)[0];
            if (first) { addChip(first); input.value=''; menu.style.display='none'; }
        } else if (e.key === 'Backspace' && input.value === '') {
            // Remove last chip quickly
            const chips = el.querySelectorAll('span');
            if (chips.length > 0) {
                const last = chips[chips.length - 1];
                const hid = el.querySelector('input[type=hidden][data-recipient]:last-of-type');
                if (hid) { selected.delete(parseInt(hid.getAttribute('data-recipient')||'0',10)); hid.remove(); }
                last.remove();
            }
        }
    });

    el.style.position = 'relative';
    el.appendChild(input);

    // Prefill selections
    const want = new Set();
    if (prefillSingle) want.add(prefillSingle);
    (prefillList||[]).forEach(id => want.add(id));
    if (want.size > 0) {
        users.forEach(u => { if (want.has(u.id)) addChip(u); });
    }
})();
</script>
</body>
</html>
