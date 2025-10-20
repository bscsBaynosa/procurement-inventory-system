<?php
// show.php - Displays the details of a specific purchase request

require_once '../../src/models/PurchaseRequest.php';
require_once '../../src/controllers/ProcurementController.php';

$purchaseRequestId = $_GET['id'] ?? null;

if ($purchaseRequestId) {
    $procurementController = new ProcurementController();
    $purchaseRequest = $procurementController->getPurchaseRequestById($purchaseRequestId);
} else {
    // Redirect or show an error if no ID is provided
    header('Location: /public/index.php');
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Request Details</title>
    <link rel="stylesheet" href="/public/css/main.css">
</head>
<body>
    <div class="container">
        <h1>Purchase Request Details</h1>
        <?php if ($purchaseRequest): ?>
            <div class="request-details">
                <p><strong>ID:</strong> <?= htmlspecialchars($purchaseRequest->id) ?></p>
                <p><strong>Item:</strong> <?= htmlspecialchars($purchaseRequest->item_name) ?></p>
                <p><strong>Quantity:</strong> <?= htmlspecialchars($purchaseRequest->quantity) ?></p>
                <p><strong>Status:</strong> <?= htmlspecialchars($purchaseRequest->status) ?></p>
                <p><strong>Date Requested:</strong> <?= htmlspecialchars($purchaseRequest->date_requested) ?></p>
                <p><strong>Requested By:</strong> <?= htmlspecialchars($purchaseRequest->requested_by) ?></p>
                <p><strong>Notes:</strong> <?= htmlspecialchars($purchaseRequest->notes) ?></p>
            </div>
            <a href="/public/index.php" class="btn">Back to Requests</a>
        <?php else: ?>
            <p>No purchase request found.</p>
            <a href="/public/index.php" class="btn">Back to Requests</a>
        <?php endif; ?>
    </div>
</body>
</html>