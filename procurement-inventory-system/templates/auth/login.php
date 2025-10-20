<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Procurement and Inventory System</title>
    <link rel="stylesheet" href="/css/main.css">
</head>
<body>
    <div class="container">
        <h1>Login</h1>
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
                    <option value="custodian">Custodian</option>
                    <option value="procurement_manager">Procurement Manager</option>
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