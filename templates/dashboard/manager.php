<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <title>Procurement Manager Dashboard</title>
</head>
<body>
    <div class="container">
        <header>
            <h1>Procurement Manager Dashboard</h1>
            <nav>
                <ul>
                    <li><a href="/public/index.php">Home</a></li>
                    <li><a href="/public/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <section>
                <h2>Purchase Requests</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Branch</th>
                            <th>Item</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($requests)): ?>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td><?= htmlspecialchars($request['request_id'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($request['branch_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($request['item_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <a href="/manager/requests/<?= htmlspecialchars($request['request_id'], ENT_QUOTES, 'UTF-8') ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5">No purchase requests found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>