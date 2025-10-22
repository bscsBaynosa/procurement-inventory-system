<?php
// Front controller & tiny router for the app

declare(strict_types=1);

// Composer autoload
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
	require_once $autoload;
}

// Fallback: if Composer autoload didn't register our controllers for any reason
// (e.g., case sensitivity issues during deploy), explicitly require them.
// This keeps the app online while we investigate autoload on the next deploy.
if (!class_exists(\App\Controllers\BaseController::class) && file_exists(__DIR__ . '/../src/Controllers/BaseController.php')) {
	require_once __DIR__ . '/../src/Controllers/BaseController.php';
}
if (!class_exists(\App\Controllers\AuthController::class) && file_exists(__DIR__ . '/../src/Controllers/AuthController.php')) {
	require_once __DIR__ . '/../src/Controllers/AuthController.php';
}
if (!class_exists(\App\Controllers\CustodianController::class) && file_exists(__DIR__ . '/../src/Controllers/CustodianController.php')) {
	require_once __DIR__ . '/../src/Controllers/CustodianController.php';
}
if (!class_exists(\App\Controllers\ProcurementController::class) && file_exists(__DIR__ . '/../src/Controllers/ProcurementController.php')) {
	require_once __DIR__ . '/../src/Controllers/ProcurementController.php';
}
if (!class_exists(\App\Controllers\AdminController::class) && file_exists(__DIR__ . '/../src/Controllers/AdminController.php')) {
	require_once __DIR__ . '/../src/Controllers/AdminController.php';
}

// Lazy-load DB connections in services; don't force-connect on every request
// to allow the landing page to render even if the DB is momentarily unavailable.

use App\Controllers\AuthController;
use App\Controllers\CustodianController;
use App\Controllers\ProcurementController;
use App\Controllers\AdminController;
use App\Setup\Installer;

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Normalize trailing slashes (except root)
if ($path !== '/' && substr($path, -1) === '/') {
	$path = rtrim($path, '/');
}

$auth = new AuthController();
$custodian = new CustodianController();
$manager = new ProcurementController();
$admin = new AdminController();

// Routes
// Landing page first (role selection)
if ($method === 'GET' && $path === '/') {
	$auth->showLanding();
	exit;
}

// Login page
if ($method === 'GET' && $path === '/login') {
	$auth->showLoginForm();
	exit;
}

if ($method === 'POST' && $path === '/auth/login') {
	$auth->login();
	exit;
}

if ($method === 'GET' && $path === '/logout') {
	$auth->logout();
	exit;
}

if ($method === 'GET' && $path === '/dashboard') {
	$role = $_SESSION['role'] ?? null;
	if ($role === 'custodian') {
		$custodian->dashboard();
		exit;
	}
	if ($role === 'procurement_manager') {
		$manager->index();
		exit;
	}
	if ($role === 'admin') {
		$admin->dashboard();
		exit;
	}
	header('Location: /login');
	exit;
}

// One-time setup route (guarded). Enable by setting SETUP_TOKEN env var.
if ($method === 'GET' && $path === '/setup') {
	$token = $_GET['token'] ?? '';
	$expected = getenv('SETUP_TOKEN') ?: '';
	if ($expected === '' || !hash_equals($expected, (string)$token)) {
		http_response_code(403);
		echo 'Forbidden';
		exit;
	}

	try {
		$installer = new Installer();
		$logLines = $installer->run();
		header('Content-Type: text/plain');
		echo implode("\n", $logLines);
	} catch (Throwable $e) {
		http_response_code(500);
		header('Content-Type: text/plain');
		echo 'Setup error: ' . $e->getMessage();
	}
	exit;
}

// Fallback 404
http_response_code(404);
echo '404 Not Found';
?>