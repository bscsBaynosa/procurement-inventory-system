<?php
    
    $root = realpath(__DIR__ . '/../../');
    $publicDir = $root . DIRECTORY_SEPARATOR . 'public';
    $iconCandidates = [
        $root . DIRECTORY_SEPARATOR . 'logo.png',
        $publicDir . DIRECTORY_SEPARATOR . 'logo.png',
        $publicDir . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logo.png',
    ];
    $faviconHref = null;
    foreach ($iconCandidates as $cand) {
        if (is_file($cand)) {
            $data = @file_get_contents($cand);
            if ($data !== false) {
                $faviconHref = 'data:image/png;base64,' . base64_encode($data);
                break;
            }
        }
    }
?>      
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement and Inventory System</title>
    <?php if (!empty($faviconHref)): ?>
        <link rel="icon" type="image/png" href="<?= htmlspecialchars($faviconHref, ENT_QUOTES) ?>">
        <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconHref, ENT_QUOTES) ?>">
    <?php endif; ?>
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