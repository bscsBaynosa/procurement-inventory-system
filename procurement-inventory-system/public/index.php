<?php
require_once '../src/config/database.php';
require_once '../src/routes/web.php';

// Start the session
session_start();

// Initialize the application
$app = new App();

// Load the routes
$app->loadRoutes();

// Handle the request
$app->run();
?>