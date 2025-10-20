<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custodian Dashboard</title>
    <link rel="stylesheet" href="/css/main.css">
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
                        <p id="good-condition-count">0</p>
                    </div>
                    <div class="status-item">
                        <h3>For Repair</h3>
                        <p id="repair-count">0</p>
                    </div>
                    <div class="status-item">
                        <h3>For Replacement</h3>
                        <p id="replacement-count">0</p>
                    </div>
                    <div class="status-item">
                        <h3>Total Items</h3>
                        <p id="total-count">0</p>
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
                        <!-- Pending requests will be populated here -->
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