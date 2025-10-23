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

// Gracefully handle accidental GET to /auth/login by redirecting to /login
if ($method === 'GET' && $path === '/auth/login') {
	header('Location: /login');
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
	$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
	// Read SETUP_TOKEN robustly from multiple sources to avoid env propagation quirks
	$expected = getenv('SETUP_TOKEN');
	if (!is_string($expected) || $expected === '') {
		$expected = $_ENV['SETUP_TOKEN'] ?? ($_SERVER['SETUP_TOKEN'] ?? '');
	}
	if ($expected === '' || !hash_equals($expected, (string)$token)) {
		http_response_code(403);
		$reason = $expected === '' ? 'missing' : 'mismatch';
		$forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
		$scheme = $forwarded !== '' ? explode(',', $forwarded)[0] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
		$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$sample = $scheme . '://' . $host . '/setup?token=YOUR_TOKEN';
		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><html><head><meta charset="utf-8"><title>Forbidden</title>';
		echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px;background:#0b0b0b;color:#e5e7eb} .box{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:18px;max-width:860px} code{background:#0b0b0b;padding:2px 6px;border-radius:6px} a{color:#22c55e;text-decoration:none}</style>';
		echo '</head><body><div class="box">';
		echo '<h2>Forbidden (setup token ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . ')</h2>';
		echo '<p>The setup route is protected. To run it:</p>';
		echo '<ol>';
		echo '<li>Set a config var named <code>SETUP_TOKEN</code> in Heroku for this app.</li>';
		echo '<li>Open <code>' . htmlspecialchars($sample, ENT_QUOTES, 'UTF-8') . '</code> with your exact token value.</li>';
		echo '</ol>';
		echo '<p>Tips:</p><ul>';
		echo '<li>Paste the token without quotes and without spaces.</li>';
		echo '<li>Use simple letters/numbers (e.g., 32 hex chars) to avoid URL-encoding issues.</li>';
		echo '<li>Make sure you are visiting the correct app domain shown above.</li>';
		echo '</ul>';
		echo '</div></body></html>';
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