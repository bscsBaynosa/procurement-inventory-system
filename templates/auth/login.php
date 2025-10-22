<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Procurement and Inventory System</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php
        // Consistent favicon across pages
        $root = realpath(__DIR__ . '/../../');
        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'logo.png',
            $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'logo.png',
            $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logo.png',
        ];
        foreach ($candidates as $cand) {
            if (is_file($cand)) { $data = @file_get_contents($cand); if ($data!==false){
                echo '<link rel="icon" type="image/png" href="data:image/png;base64,' . base64_encode($data) . '">';
                echo '<link rel="apple-touch-icon" href="data:image/png;base64,' . base64_encode($data) . '">';
                break;
            }}
        }
    ?>
</head>
<body>
    <div class="container">
        <h1>Login</h1>
        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form action="/auth/login" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="role">Login as:</label>
                <select id="role" name="role" required>
                    <?php $selectedRole = $selectedRole ?? null; ?>
                    <option value="custodian" <?= ($selectedRole === 'custodian') ? 'selected' : '' ?>>Custodian</option>
                    <option value="procurement_manager" <?= ($selectedRole === 'procurement_manager') ? 'selected' : '' ?>>Procurement Manager</option>
                    <option value="admin" <?= ($selectedRole === 'admin') ? 'selected' : '' ?>>Administrator</option>
                </select>
            </div>
            <button type="submit">Login</button>
        </form>
        <div class="footer">
            <p>&copy; 2023 Procurement and Inventory System</p>
        </div>
    </div>
</body>
</html>