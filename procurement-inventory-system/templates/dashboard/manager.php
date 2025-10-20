<?php
session_start();
require_once '../../src/controllers/ProcurementController.php';

$procurementController = new ProcurementController();
$requests = $procurementController->getAllPurchaseRequests();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/main.css">
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
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($request['id']); ?></td>
                                <td><?php echo htmlspecialchars($request['branch']); ?></td>
                                <td><?php echo htmlspecialchars($request['item']); ?></td>
                                <td><?php echo htmlspecialchars($request['status']); ?></td>
                                <td>
                                    <a href="/public/requests/show.php?id=<?php echo $request['id']; ?>">View</a>
                                    <a href="/public/requests/edit.php?id=<?php echo $request['id']; ?>">Edit</a>
                                    <a href="/public/requests/followup.php?id=<?php echo $request['id']; ?>">Follow Up</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>