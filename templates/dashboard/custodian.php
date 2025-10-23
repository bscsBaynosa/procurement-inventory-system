<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custodian Dashboard</title>
    <link rel="stylesheet" href="/css/main.css">
    <?php require __DIR__ . '/../layouts/_favicon.php'; ?>
</head>
<body>
    <div class="container">
        <header>
            <h1>Custodian Dashboard</h1>
            <nav>
                <ul>
                    <li><a href="/dashboard/custodian.php">Home</a></li>
                    <li><a href="/inventory/index.php">Inventory</a></li>
                    <li><a href="/requests/create.php">Create Request</a></li>
                    <li><a href="/auth/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <section class="inventory-status">
                <h2>Inventory Status</h2>
                <div class="status-summary">
                    <div class="status-item">
                        <h3>Good Condition</h3>
                        <p id="good-condition-count"><?= htmlspecialchars($inventoryStats['good'] ?? 0, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="status-item">
                        <h3>For Repair</h3>
                        <p id="repair-count"><?= htmlspecialchars($inventoryStats['for_repair'] ?? 0, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="status-item">
                        <h3>For Replacement</h3>
                        <p id="replacement-count"><?= htmlspecialchars($inventoryStats['for_replacement'] ?? 0, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="status-item">
                        <h3>Total Items</h3>
                        <p id="total-count"><?= htmlspecialchars($inventoryStats['total'] ?? 0, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </section>

            <section class="pending-requests">
                <h2>Pending Requests</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Item</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="pending-requests-table">
                        <?php if (!empty($pendingRequests)): ?>
                            <?php foreach ($pendingRequests as $request): ?>
                                <tr>
                                    <td><?= htmlspecialchars($request['request_id'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($request['item_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <form action="/custodian/request/follow-up" method="post">
                                            <input type="hidden" name="request_id" value="<?= htmlspecialchars($request['request_id'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit">Follow Up</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">No pending requests</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="generate-report">
                <h2>Generate Inventory Report</h2>
                <form id="report-form">
                    <label for="start-date">Start Date:</label>
                    <input type="date" id="start-date" name="start_date" required>
                    <label for="end-date">End Date:</label>
                    <input type="date" id="end-date" name="end_date" required>
                    <button type="submit">Generate Report</button>
                </form>
            </section>
        </main>

        <footer>
            <p>&copy; <?php echo date("Y"); ?> Procurement and Inventory System</p>
        </footer>
    </div>

    <script src="/js/main.js"></script>
</body>
</html>