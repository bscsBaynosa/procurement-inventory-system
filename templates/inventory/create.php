<?php
// create.php - Form for creating new inventory items

require_once '../../src/controllers/CustodianController.php';

$custodianController = new CustodianController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemName = $_POST['item_name'];
    $category = $_POST['category'];
    $status = $_POST['status'];
    $quantity = $_POST['quantity'];

    $result = $custodianController->createInventoryItem($itemName, $category, $status, $quantity);

    if ($result) {
        header('Location: index.php?success=Item created successfully');
        exit;
    } else {
        $error = 'Failed to create item. Please try again.';
    }
}

$categories = ['Officeware', 'Desktop', 'Laptop', 'Aircon', 'Other'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/main.css">
    <title>Create Inventory Item</title>
</head>
<body>
    <div class="container">
        <h1>Create New Inventory Item</h1>
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form action="create.php" method="POST">
            <label for="item_name">Item Name:</label>
            <input type="text" id="item_name" name="item_name" required>

            <label for="category">Category:</label>
            <select id="category" name="category" required>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="status">Status:</label>
            <select id="status" name="status" required>
                <option value="Good">Good</option>
                <option value="For Repair">For Repair</option>
                <option value="For Replacement">For Replacement</option>
            </select>

            <label for="quantity">Quantity:</label>
            <input type="number" id="quantity" name="quantity" required min="1">

            <button type="submit">Create Item</button>
        </form>
    </div>
</body>
</html>