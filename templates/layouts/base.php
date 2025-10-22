<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement and Inventory System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/main.css">
</head>
<body>
    <header>
        <h1>Procurement and Inventory System</h1>
        <nav>
            <ul>
                <li><a href="/">Home</a></li>
                <li><a href="/auth/login">Login</a></li>
            </ul>
        </nav>
    </header>
    <main>
        <div class="container">
            {{ content }}
        </div>
    </main>
    <footer>
        <p>&copy; <?php echo date("Y"); ?> Procurement and Inventory System. All rights reserved.</p>
    </footer>
    <script src="/js/main.js"></script>
</body>
</html>