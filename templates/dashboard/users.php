<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin • Users</title>
    
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
        .brand{ display:flex; align-items:center; gap:10px; font-weight:800; padding:6px 10px; }
        .nav{ margin-top:14px; display:flex; flex-direction:column; gap:6px; }
    .nav a{ display:flex; align-items:center; gap:10px; padding:10px 12px; color:var(--text); text-decoration:none; border-radius:10px; }
        .nav a:hover{ background:var(--bg); }
        .nav a.active{ background: color-mix(in oklab, var(--accent) 10%, transparent); color: var(--text); border:1px solid color-mix(in oklab, var(--accent) 35%, var(--border)); }
    .nav svg{ width:18px; height:18px; fill: var(--accent); }
        .content{ padding:18px 20px; }
        .h1{ font-weight:800; font-size:22px; margin: 6px 0 12px; }
    .grid{ display:grid; grid-template-columns: 1fr minmax(380px, 520px); gap:12px; }
        .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; font-size:14px; }
        th{ color:var(--muted); background:color-mix(in oklab, var(--card) 92%, var(--bg)); }
        .muted{ color:var(--muted); }
        .ok{ background:#ecfdf5; color:#166534; padding:10px 12px; border:1px solid #a7f3d0; border-radius:10px; margin-bottom:10px; }
        .err{ background:#fef2f2; color:#991b1b; padding:10px 12px; border:1px solid #fecaca; border-radius:10px; margin-bottom:10px; }
        input, select{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; color:#111; font:inherit; }
        html[data-theme="dark"] input, html[data-theme="dark"] select{ background:#0b0b0b; color:#e5e7eb; }
        .btn{ background:var(--accent); color:#fff; border:0; padding:10px 12px; border-radius:10px; font-weight:700; text-decoration:none; display:inline-block; }
    .row{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    .row3{ display:grid; grid-template-columns: 1fr 1fr auto; gap:10px; align-items:end; }
    @media (max-width: 1300px){ .row3{ grid-template-columns: 1fr 1fr; } }
    @media (max-width: 1100px){ .grid{ grid-template-columns: 1fr; } .row, .row3{ grid-template-columns: 1fr; } }
    @media (max-width: 900px){ .layout{ grid-template-columns: 1fr; } }
        .mb8{ margin-bottom:8px; }
    </style>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/../layouts/_sidebar.php'; ?>
    <main class="content">
        <div class="h1">Users</div>
        <div class="grid">
            <div class="card">
                <?php if (!empty($created)): ?>
                <div class="ok">User created successfully.</div>
                <?php endif; ?>
                <?php if (!empty($error)): ?>
                <div class="err">Error: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Branch</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($users)): foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$u['user_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$u['role'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($u['email'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($u['branch_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($u['is_active']) ? 'Active' : 'Disabled' ?></td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap">
                                    <a class="btn" href="/admin/users?edit=<?= (int)$u['user_id'] ?>" style="background:#10b981">Edit</a>
                                    <form method="POST" action="/admin/users/reset-password" onsubmit="return confirm('Reset password to surname for this user?');">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                        <button class="btn" type="submit" style="background:#3b82f6">Reset</button>
                                    </form>
                                    <form method="POST" action="/admin/users/delete" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                        <button class="btn" type="submit" style="background:#ef4444">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="muted">No users yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <?php if (!empty($editUser)): ?>
                <h3 style="margin-top:0">Edit User</h3>
                <form method="POST" action="/admin/users/update">
                    <input type="hidden" name="user_id" value="<?= (int)$editUser['user_id'] ?>">
                    <div class="row mb8">
                        <div>
                            <label>First name</label>
                            <input name="first_name" value="<?= htmlspecialchars((string)$editUser['first_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div>
                            <label>Last name</label>
                            <input name="last_name" value="<?= htmlspecialchars((string)$editUser['last_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="row mb8">
                        <div>
                            <label>Email</label>
                            <input name="email" type="email" value="<?= htmlspecialchars((string)($editUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <label>Username</label>
                            <input value="<?= htmlspecialchars((string)$editUser['username'], ENT_QUOTES, 'UTF-8') ?>" disabled>
                        </div>
                    </div>
                    <div class="row3 mb8">
                        <div>
                            <label>Role</label>
                            <select name="role" id="edit_role" required>
                                <?php $roles=['admin','admin_assistant','procurement','supplier']; foreach ($roles as $r): ?>
                                    <option value="<?= $r ?>" <?= ($editUser['role']===$r?'selected':'') ?>><?= ($r==='admin_assistant'?'Admin Assistant':ucwords(str_replace('_',' ', $r))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Branch</label>
                            <select name="branch_id" id="edit_branch">
                                <option value="">— None —</option>
                                <?php $list = $branches_for_edit ?? $branches ?? []; foreach ($list as $b): ?>
                                    <option value="<?= (int)$b['branch_id'] ?>" <?= ((int)$editUser['branch_id']===(int)$b['branch_id']?'selected':'') ?>><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Status</label>
                            <select name="is_active">
                                <option value="1" <?= !empty($editUser['is_active'])?'selected':'' ?>>Active</option>
                                <option value="0" <?= empty($editUser['is_active'])?'selected':'' ?>>Disabled</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <button class="btn" type="submit">Save changes</button>
                        <a class="btn" href="/admin/users" style="background:#6b7280">Cancel</a>
                    </div>
                </form>
                <p class="muted">Use the "Reset" action in the list to set the user's password to their surname.</p>
                <?php else: ?>
                <h3 style="margin-top:0">Create User</h3>
                <form method="POST" action="/admin/users" onsubmit="return confirmAdminPassword(event)">
                    <div class="row mb8">
                        <div>
                            <label>Username</label>
                            <input name="username" required>
                        </div>
                        <div>
                            <label>First name</label>
                            <input name="first_name" required>
                        </div>
                    </div>
                    <div class="row mb8">
                        <div>
                            <label>Last name</label>
                            <input name="last_name" required>
                        </div>
                        <div>
                            <label>Email</label>
                            <input name="email" type="email">
                        </div>
                    </div>
                    <div class="row mb8">
                        <div>
                            <label>Password</label>
                            <input name="password" type="password" required>
                        </div>
                        <div>
                            <label>Role</label>
                            <select name="role" id="create_role" required>
                                <option value="admin">Admin</option>
                                <option value="admin_assistant">Admin Assistant</option>
                                <option value="procurement">Procurement</option>
                                <option value="supplier">Supplier</option>
                            </select>
                        </div>
                    </div>
                    <div class="row3 mb8">
                        <div>
                            <label>Branch</label>
                            <select name="branch_id" id="create_branch">
                                <option value="">— None —</option>
                                <?php $bs = $branches_unassigned ?? $branches ?? []; foreach ($bs as $b): ?>
                                    <option value="<?= (int)$b['branch_id'] ?>"><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>&nbsp;</label>
                            <button class="btn" type="submit" style="width:100%">Create</button>
                        </div>
                        <div></div>
                    </div>
                </form>
                <p class="muted">New users are created active. Password can be reset anytime to the user's surname from the list.</p>
                <script>
                function toggleBranchSelect(roleId, branchSelectId){
                    const roleSel = document.getElementById(roleId);
                    const branchSel = document.getElementById(branchSelectId);
                    if (!roleSel || !branchSel) return;
                    const needBranch = roleSel.value === 'admin_assistant';
                    branchSel.disabled = !needBranch;
                    branchSel.parentElement.style.display = needBranch ? '' : 'none';
                    if (!needBranch) { branchSel.value = ''; }
                }
                function setupRoleBranchWiring(){
                    toggleBranchSelect('create_role','create_branch');
                    const cr = document.getElementById('create_role'); if (cr) cr.addEventListener('change', () => toggleBranchSelect('create_role','create_branch'));
                    toggleBranchSelect('edit_role','edit_branch');
                    const er = document.getElementById('edit_role'); if (er) er.addEventListener('change', () => toggleBranchSelect('edit_role','edit_branch'));
                }
                function confirmAdminPassword(e){
                    const pass = prompt('Confirm your admin password to proceed with account creation:');
                    if (pass === null) { e.preventDefault(); return false; }
                    const form = e.target;
                    let hidden = form.querySelector('input[name="admin_password_confirm"]');
                    if (!hidden){ hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='admin_password_confirm'; form.appendChild(hidden); }
                    hidden.value = pass;
                    return true;
                }
                document.addEventListener('DOMContentLoaded', setupRoleBranchWiring);
                </script>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
