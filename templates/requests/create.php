<?php


session_start();
require_once '../../src/controllers/ProcurementController.php';

$procurementController = new ProcurementController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestData = [
        'item_name' => $_POST['item_name'],
        'quantity' => $_POST['quantity'],
        'branch' => $_POST['branch'],
        'request_type' => $_POST['request_type'],
        'description' => $_POST['description'],
    ];

    $result = $procurementController->createPurchaseRequest($requestData);
    if ($result) {
        header('Location: /templates/requests/show.php?id=' . $result);
        exit;
    } else {
        $error = "Failed to create purchase request.";
    }
}

$branches = ['Quezon City', 'Manila', 'Sto Tomas Batangas', 'San Fernando City La Union', 'Dasmariñas City Cavite'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Purchase Request</title>
    <link rel="stylesheet" href="/public/css/main.css">
</head>
<body>
    <div class="container">
        <h1>Create Purchase Request</h1>
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form action="" method="POST">
            <div class="form-group">
                <label for="item_name">Item Name:</label>
                <input type="text" id="item_name" name="item_name" required>
            </div>
            <div class="form-group">
                <label for="quantity">Quantity:</label>
                <input type="number" id="quantity" name="quantity" required>
            </div>
            <div class="form-group">
                <label for="branch">Branch:</label>
                <select id="branch" name="branch" required>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= htmlspecialchars($branch) ?>"><?= htmlspecialchars($branch) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="request_type">Request Type:</label>
                <select id="request_type" name="request_type" required>
                    <option value="Job Order">Job Order</option>
                    <option value="Purchase Order">Purchase Order</option>
                </select>
            </div>
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea id="description" name="description" required></textarea>
            </div>
            <button type="submit">Submit Request</button>
        </form>
    </div>
</body>
</html>