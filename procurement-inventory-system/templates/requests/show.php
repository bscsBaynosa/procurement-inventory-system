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
        <?php if (!empty($request)): ?>
            <div class="request-details">
                <p><strong>ID:</strong> <?= htmlspecialchars($request['request_id'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Item:</strong> <?= htmlspecialchars($request['item_id'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Quantity:</strong> <?= htmlspecialchars($request['quantity'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Status:</strong> <?= htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Date Requested:</strong> <?= htmlspecialchars($request['created_at'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Requested By:</strong> <?= htmlspecialchars($request['requested_by'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Justification:</strong> <?= htmlspecialchars($request['justification'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <section class="request-history">
                <h2>Status History</h2>
                <?php if (!empty($history)): ?>
                    <ul>
                        <?php foreach ($history as $event): ?>
                            <li>
                                <strong><?= htmlspecialchars($event['performed_at'], ENT_QUOTES, 'UTF-8') ?>:</strong>
                                <?= htmlspecialchars($event['old_status'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?> →
                                <?= htmlspecialchars($event['new_status'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($event['notes'])): ?>
                                    — <?= htmlspecialchars($event['notes'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No history recorded for this request yet.</p>
                <?php endif; ?>
            </section>
            <a href="/public/index.php" class="btn">Back to Requests</a>
        <?php else: ?>
            <p>No purchase request found.</p>
            <a href="/public/index.php" class="btn">Back to Requests</a>
        <?php endif; ?>
    </div>
</body>
</html>