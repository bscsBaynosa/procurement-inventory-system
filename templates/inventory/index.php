<?php
session_start();
require_once '../../src/controllers/CustodianController.php';
require_once '../../src/controllers/ProcurementController.php';

$custodianController = new CustodianController();
$procurementController = new ProcurementController();

$inventoryItems = $custodianController->getInventoryItems();
$pendingRequests = $custodianController->getPendingRequests();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/public/css/main.css">
    <title>Inventory Management</title>
</head>
<body>
    <div class="container">
        <h1>Inventory Management Dashboard</h1>
        <div class="inventory-summary">
            <h2>Inventory Summary</h2>
            <p>Total Items: <?php echo count($inventoryItems); ?></p>
            <p>Items in Good Condition: <?php echo $custodianController->countItemsByStatus('good'); ?></p>
            <p>Items for Repair: <?php echo $custodianController->countItemsByStatus('repair'); ?></p>
            <p>Items for Replacement: <?php echo $custodianController->countItemsByStatus('replacement'); ?></p>
        </div>

        <div class="pending-requests">
            <h2>Pending Requests</h2>
            <ul>
                <?php foreach ($pendingRequests as $request): ?>
                    <li>
                        <a href="/templates/requests/show.php?id=<?php echo $request['id']; ?>">
                            Request ID: <?php echo $request['id']; ?> - Status: <?php echo $request['status']; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="inventory-list">
            <h2>Inventory Items</h2>
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventoryItems as $item): ?>
                        <tr>
                            <td><?php echo $item['name']; ?></td>
                            <td><?php echo $item['category']; ?></td>
                            <td><?php echo $item['status']; ?></td>
                            <td>
                                <a href="/templates/inventory/create.php?id=<?php echo $item['id']; ?>">Edit</a>
                                <a href="/src/controllers/CustodianController.php?action=toggleStatus&id=<?php echo $item['id']; ?>">Toggle Status</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="generate-report">
            <h2>Generate Inventory Report</h2>
            <form action="/src/services/InventoryService.php" method="POST">
                <label for="start_date">Start Date:</label>
                <input type="date" name="start_date" required>
                <label for="end_date">End Date:</label>
                <input type="date" name="end_date" required>
                <button type="submit">Generate Report</button>
            </form>
        </div>

        <div class="profile-settings">
            <h2>Profile Settings</h2>
            <a href="/templates/auth/login.php?action=logout">Logout</a>
        </div>
    </div>
    <script src="/public/js/main.js"></script>
</body>
</html>