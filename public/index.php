<?php
// Front controller & tiny router for the app

declare(strict_types=1);

// Composer autoload
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
	require_once $autoload;
}

// Ensure DB connection is bootstrapped (returns a PDO instance)
require_once __DIR__ . '/../src/config/database.php';

use App\Controllers\AuthController;
use App\Controllers\CustodianController;
use App\Controllers\ProcurementController;
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

// Routes
if ($method === 'GET' && ($path === '/' || $path === '/login')) {
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