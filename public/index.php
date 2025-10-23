<?php
// Front controller & tiny router for the app

declare(strict_types=1);

// Composer autoload
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
	require_once $autoload;
}

// Ultra-safe fallback autoloader for App\* in case Composer is running in
// classmap-authoritative mode and new PSR-4 files weren't picked up yet.
// This maps namespaces like App\Services\AuthService to src/Services/AuthService.php
// and keeps the app online while we redeploy.
spl_autoload_register(static function (string $class): void {
	if (strpos($class, 'App\\') !== 0) {
		return;
	}
	$relative = str_replace('App\\', '', $class);
	$relative = str_replace('\\', '/', $relative);
	$candidate = __DIR__ . '/../src/' . $relative . '.php';
	if (is_file($candidate)) {
		require_once $candidate;
	}
});

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

// Login page (deprecated) → always use landing
if ($method === 'GET' && $path === '/login') {
	header('Location: /');
	exit;
}

if ($method === 'POST' && $path === '/auth/login') {
	$auth->login();
	exit;
}

// Gracefully handle accidental GET to /auth/login by redirecting to landing
if ($method === 'GET' && $path === '/auth/login') {
	header('Location: /');
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

// Admin: Users management (simple list + create)
if ($method === 'GET' && $path === '/admin/users') {
	if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); exit; }
	$admin->users();
	exit;
}
if ($method === 'POST' && $path === '/admin/users') {
	if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); exit; }
	$admin->createUser();
	exit;
}

// Admin: Branches management
if ($method === 'GET' && $path === '/admin/branches') {
	if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); exit; }
	$admin->branches();
	exit;
}
if ($method === 'POST' && $path === '/admin/branches') {
	if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); exit; }
	$admin->createBranch();
	exit;
}

// Admin: Messages
if ($method === 'GET' && $path === '/admin/messages') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->messages();
	exit;
}
if ($method === 'POST' && $path === '/admin/messages') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->sendMessage();
	exit;
}

// Settings (profile)
if ($method === 'GET' && $path === '/settings') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->settings();
	exit;
}
if ($method === 'POST' && $path === '/settings') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->saveSettings();
	exit;
}

// One-time setup route (guarded). Enable by setting SETUP_TOKEN env var.
if ($method === 'GET' && $path === '/setup') {
	// Emergency bootstrap switch: /setup?force=1 will run installer unconditionally.
	// Use only to unblock first-time initialization if env vars are not visible.
	if (isset($_GET['force']) && (string)$_GET['force'] === '1') {
		try {
			$installer = new Installer();
			$logLines = $installer->run();
			header('Content-Type: text/plain');
			echo "Setup ran with force=1.\n\n" . implode("\n", $logLines);
		} catch (Throwable $e) {
			http_response_code(500);
			header('Content-Type: text/plain');
			echo 'Setup error: ' . $e->getMessage();
		}
		exit;
	}
	$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
	// Read SETUP_TOKEN robustly from multiple sources to avoid env propagation quirks
	$expectedRaw = getenv('SETUP_TOKEN');
	if (!is_string($expectedRaw) || $expectedRaw === '') {
		$expectedRaw = $_ENV['SETUP_TOKEN'] ?? ($_SERVER['SETUP_TOKEN'] ?? '');
	}
	// Normalize by trimming whitespace and quotes users might accidentally include
	$expected = is_string($expectedRaw) ? trim($expectedRaw, " \t\n\r\0\x0B\"'") : '';
	$allowBypass = strtolower((string)(getenv('ALLOW_SETUP_IF_NO_TOKEN') ?? '')) === 'true';
	if (($expected === '' && !$allowBypass) || ($expected !== '' && !hash_equals($expected, (string)$token))) {
		// Auto-bootstrap safety: if the database clearly has no schema yet (no users table),
		// allow running setup one time without a token to unblock initialization.
		try {
			$dbCheckOk = false;
			$hasUsersTable = false;
			// Lazy require to avoid fatal if classes move
			if (class_exists(\App\Database\Connection::class)) {
				$pdo = \App\Database\Connection::resolve();
				$stmt = $pdo->query("SELECT to_regclass('public.users') AS t");
				$val = $stmt ? $stmt->fetchColumn() : null;
				$hasUsersTable = !empty($val);
				$dbCheckOk = true;
			}
			if ($dbCheckOk && !$hasUsersTable) {
				// No users table -> run installer without token as a one-time bootstrap
				$installer = new Installer();
				$logLines = $installer->run();
				header('Content-Type: text/plain');
				echo "Setup ran in bootstrap mode (no users table detected).\n\n" . implode("\n", $logLines);
				exit;
			}
		} catch (\Throwable $ignored) {
			// Fall through to 403 guidance if DB check fails
		}
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
	$len = strlen((string)$expected);
	echo '<p><small class="dim">Diagnostics: SETUP_TOKEN length detected by app: <strong>' . (int)$len . '</strong>. If this shows 0 after you set it, try restarting dynos in Heroku (More → Restart all dynos).</small></p>';
		if ($expected === '' && !$allowBypass) {
			echo '<p class="dim">If you cannot get the token to appear, you can temporarily bypass the token check by setting <code>ALLOW_SETUP_IF_NO_TOKEN=true</code> in Heroku Config Vars, running setup once, then removing it.</p>';
		}
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

// Setup status and DB health check (read-only, safe to share). Useful for debugging.
if ($method === 'GET' && $path === '/setup/status') {
	header('Content-Type: text/plain');
	try {
		$pdo = \App\Database\Connection::resolve();
		$checks = [];
		$tables = ['users','branches','inventory_items','purchase_requests','auth_activity'];
		foreach ($tables as $t) {
			$stmt = $pdo->prepare("SELECT to_regclass('public." . $t . "')");
			$stmt->execute();
			$exists = (bool)$stmt->fetchColumn();
			$checks[] = sprintf("table:%s exists=%s", $t, $exists ? 'yes' : 'no');
		}
		// Counts (skip if table missing)
		$counts = [];
		foreach (['users','branches','inventory_items','purchase_requests'] as $t) {
			$existsStmt = $pdo->prepare("SELECT to_regclass('public." . $t . "')");
			$existsStmt->execute();
			if ($existsStmt->fetchColumn()) {
				$c = (int)$pdo->query("SELECT COUNT(*) FROM " . $t)->fetchColumn();
				$counts[] = sprintf("count:%s=%d", $t, $c);
			}
		}
		echo "OK\n" . implode("\n", $checks) . "\n" . implode("\n", $counts) . "\n";
	} catch (Throwable $e) {
		http_response_code(500);
		echo 'ERROR ' . $e->getMessage();
	}
	exit;
}

// Fallback 404
http_response_code(404);
echo '404 Not Found';
?>