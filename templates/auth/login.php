<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - Procurement and Inventory System</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
</head>
<body>
    <div class="container">
        <h1>Sign in</h1>
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
            <button type="submit">Login</button>
        </form>
        <div style="margin-top:10px; display:flex; gap:16px; align-items:center; flex-wrap:wrap; font-size:12.5px;">
            <a href="/signup" style="font-weight:600;">Supplier? Sign up</a>
            <a href="/auth/forgot" style="font-weight:600;">Forgot password?</a>
        </div>
        <div class="footer">
            <p>&copy; 2023 Procurement and Inventory System</p>
        </div>
    </div>
</body>
</html>