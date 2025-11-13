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
	// Prefer lowercase services path explicitly to avoid case conflicts
	$ns = substr($class, 4); // strip 'App\'
	$parts = explode('\\', $ns);
	if (isset($parts[0]) && strtolower($parts[0]) === 'services') {
		$tail = implode('/', array_slice($parts, 1));
		$candidateLower = __DIR__ . '/../src/services/' . $tail . '.php';
		if (is_file($candidateLower)) {
			require_once $candidateLower;
			return;
		}
		// Intentionally do NOT load src/Services/* to prevent duplicate class conflicts
	}
	// Default fallback for other namespaces (Controllers, Models, etc.)
	$relative = str_replace('\\', '/', $ns);
	$candidate = __DIR__ . '/../src/' . $relative . '.php';
	if (is_file($candidate)) {
		require_once $candidate;
	}
});

// On some hosts with case-sensitive filesystems and Composer classmaps, a stale
// src/Services/* path can shadow the canonical src/services/* files. Preload
// critical Services classes from the lowercase path to guarantee the correct
// implementation is defined before Composer tries to autoload them.
try {
	if (!class_exists(\App\Services\InventoryService::class, false)) {
		$svc = __DIR__ . '/../src/services/InventoryService.php';
		if (is_file($svc)) { require_once $svc; }
	}
} catch (\Throwable $e) { /* ignore */ }

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
if (!class_exists(\App\Controllers\SupplierController::class) && file_exists(__DIR__ . '/../src/Controllers/SupplierController.php')) {
	require_once __DIR__ . '/../src/Controllers/SupplierController.php';
}

// Lazy-load DB connections in services; don't force-connect on every request
// to allow the landing page to render even if the DB is momentarily unavailable.

use App\Controllers\AuthController;
use App\Controllers\CustodianController;
use App\Controllers\ProcurementController;
use App\Controllers\AdminController;
use App\Controllers\SupplierController;
use App\Setup\Installer;

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

// Canonical redirect disabled: allow access via herokuapp.com and any custom domains.

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
$supplier = new SupplierController();

// Minimal crash shield to avoid blank pages in production: wraps a controller call
// and shows a simple error page if an uncaught exception bubbles up. Disable or
// tighten messaging later if needed.
$safeRun = static function (callable $fn): void {
	try { $fn(); }
	catch (\Throwable $e) {
		http_response_code(500);
		header('Content-Type: text/html; charset=utf-8');
		$msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
		$file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
		$line = (int)$e->getLine();
		// Also log to server error log for post-mortem
		error_log('[app-error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
		echo '<!doctype html><html><head><meta charset="utf-8"><title>Application Error</title>';
		echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px;background:#0b0b0b;color:#e5e7eb} .box{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:18px;max-width:860px} code{background:#0b0b0b;padding:2px 6px;border-radius:6px} a{color:#22c55e;text-decoration:none}</style>';
		echo '</head><body><div class="box">';
		echo '<h2>Something went wrong</h2>';
		echo '<p>An unexpected error occurred while rendering this page.</p>';
		echo '<p><small>Reason: ' . $msg . '</small></p>';
		echo '<p><small>Location: <code>' . $file . '</code> on line <code>' . $line . '</code></small></p>';
		echo '<p>Diagnostics:</p><ul>';
		echo '<li><a href="/setup/status">Setup Status</a> (DB connectivity)</li>';
		echo '<li><a href="/inbox">Inbox</a> (if dashboard fails)</li>';
		echo '</ul>';
		echo '</div></body></html>';
	}
};

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

// Forgot password shortcuts
if ($method === 'GET' && $path === '/forgot') {
	$auth->showForgot();
	exit;
}
if ($method === 'POST' && $path === '/auth/forgot/verify') {
	$auth->verifyOtp();
	exit;
}
if ($method === 'POST' && $path === '/auth/forgot/resend') {
	$auth->resendOtp();
	exit;
}
if ($method === 'POST' && $path === '/auth/forgot') {
	$auth->requestOtp();
	exit;
}
if ($method === 'GET' && $path === '/reset-password') {
	header('Location: /forgot');
	exit;
}
if ($method === 'POST' && $path === '/auth/reset') {
	header('Location: /auth/forgot');
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

// Supplier signup
if ($method === 'GET' && $path === '/signup') {
	$auth->showSupplierSignup();
	exit;
}
if ($method === 'POST' && $path === '/signup') {
	$auth->signupSupplier();
	exit;
}

if ($method === 'GET' && $path === '/dashboard') {
	$role = $_SESSION['role'] ?? null;
	if ($role === 'custodian' || $role === 'admin_assistant') {
		$safeRun(static function() use ($custodian){ $custodian->dashboard(); });
		exit;
	}
	if ($role === 'procurement_manager' || $role === 'procurement') {
		$safeRun(static function() use ($manager){ $manager->index(); });
		exit;
	}
	if ($role === 'admin') {
		$safeRun(static function() use ($admin){ $admin->dashboard(); });
		exit;
	}
	if ($role === 'supplier') {
		$safeRun(static function() use ($supplier){ $supplier->dashboard(); });
		exit;
	}
	header('Location: /login');
	exit;
}

// Supplier: Items Listing
if ($method === 'GET' && $path === '/supplier/items') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->itemsPage();
	exit;
}
if ($method === 'POST' && $path === '/supplier/items') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->itemsCreate();
	exit;
}
if ($method === 'POST' && $path === '/supplier/items/update') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->itemsUpdate();
	exit;
}
if ($method === 'POST' && $path === '/supplier/items/delete') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->itemsDelete();
	exit;
}
// Supplier: Item price tiers
if ($method === 'POST' && $path === '/supplier/items/tiers/add') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->addPriceTier();
	exit;
}
if ($method === 'POST' && $path === '/supplier/items/tiers/delete') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->deletePriceTier();
	exit;
}
// Supplier: Packages
if ($method === 'GET' && $path === '/supplier/packages') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->packagesPage();
	exit;
}
if ($method === 'POST' && $path === '/supplier/packages') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->packagesCreate();
	exit;
}
if ($method === 'POST' && $path === '/supplier/packages/update') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->packagesUpdate();
	exit;
}
if ($method === 'POST' && $path === '/supplier/packages/delete') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->packagesDelete();
	exit;
}
if ($method === 'POST' && $path === '/supplier/packages/items/add') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->packageAddItem();
	exit;
}
if ($method === 'POST' && $path === '/supplier/packages/items/delete') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->packageRemoveItem();
	exit;
}

// Manager: Purchase Requests actions
if ($method === 'GET' && $path === '/manager/requests') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->viewRequests(); });
	exit;
}
if ($method === 'GET' && $path === '/manager/requests/history') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->requestsHistory(); });
	exit;
}
if ($method === 'GET' && $path === '/manager/requests/completed') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->completedRequisitions(); });
	exit;
}
if ($method === 'POST' && $path === '/manager/requests/update-status') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->updateRequestStatus(); });
	exit;
}
if ($method === 'POST' && $path === '/manager/requests/update-group-status') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->updateGroupStatus(); });
	exit;
}
if ($method === 'POST' && $path === '/manager/requests/archive') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->archiveGroup(); });
	exit;
}
if ($method === 'POST' && $path === '/manager/requests/restore') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->restoreGroup(); });
	exit;
}
if ($method === 'GET' && $path === '/manager/requests/canvass') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->canvass(); });
	exit;
}
if ($method === 'POST' && $path === '/manager/requests/canvass') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->canvassSubmit(); });
	exit;
}
if ($method === 'POST' && $path === '/manager/requests/canvass/preview') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->canvassPreview(); });
	exit;
}
// Canvassing AJAX endpoints (quotes fetch and store generated canvass)
if ($method === 'POST' && $path === '/manager/requests/canvass/quotes') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { http_response_code(403); exit; }
	$safeRun(static function() use ($manager){ $manager->canvassQuotesApi(); });
	exit;
}
// New: per-item quotes by item_id (preferred)
if ($method === 'POST' && $path === '/manager/requests/canvass/quotes-by-id') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { http_response_code(403); exit; }
	$safeRun(static function() use ($manager){ $manager->canvassQuotesByIdApi(); });
	exit;
}
// New: per-item quotes for a single row (item_id + supplier_ids) from supplier_quotes
if ($method === 'POST' && $path === '/manager/requests/canvass/item-quotes') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { http_response_code(403); exit; }
	$safeRun(static function() use ($manager){ $manager->canvassItemQuotesApi(); });
	exit;
}
if ($method === 'POST' && $path === '/manager/requests/canvass/store') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { http_response_code(403); exit; }
	$safeRun(static function() use ($manager){ $manager->canvassStore(); });
	exit;
}
if ($method === 'GET' && $path === '/manager/requests/view') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->viewGroup(); });
	exit;
}
if ($method === 'GET' && $path === '/manager/requests/download') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->downloadGroup(); });
	exit;
}
if ($method === 'POST' && $path === '/manager/requests/send-for-approval') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->sendForAdminApproval(); });
	exit;
}

// Procurement: Purchase Order creation
if ($method === 'GET' && $path === '/procurement/po/create') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->poCreate(); });
	exit;
}
if ($method === 'POST' && $path === '/procurement/po/create') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->poSubmit(); });
	exit;
}

// Procurement: Official PO PDF preview (server-rendered) — supports POST and GET
if ($method === 'POST' && $path === '/procurement/po/preview') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->poPreview(); });
	exit;
}
if ($method === 'GET' && $path === '/procurement/po/preview') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->poPreview(); });
	exit;
}

// Procurement: Direct download of official PO PDF by id or number
if ($method === 'GET' && $path === '/procurement/po/download') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->poDownload(); });
	exit;
}

// Procurement: Send PO to Supplier (attaches official PDF)
if ($method === 'POST' && $path === '/procurement/po/send') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->poSendToSupplier(); });
	exit;
}

// Procurement: Purchase Orders list (new consolidated view)
if ($method === 'GET' && $path === '/procurement/pos') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->poList(); });
	exit;
}

// Procurement: Purchase Order detail view
if ($method === 'GET' && $path === '/procurement/po/view') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->poView(); });
	exit;
}

// Procurement: Export/Regenerate PO PDF
if ($method === 'GET' && $path === '/procurement/po/export') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$safeRun(static function() use ($manager){ $manager->poExport(); });
	exit;
}

// Procurement: RFP (Request For Payment)
if ($method === 'GET' && $path === '/procurement/rfp/create') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$manager->rfpCreate();
	exit;
}
if ($method === 'POST' && $path === '/procurement/rfp/create') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['procurement_manager','procurement','admin'], true)) { header('Location: /login'); exit; }
	$manager->rfpSubmit();
	exit;
}

// Admin: PO approval/rejection
if ($method === 'POST' && $path === '/admin/po/approve') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') { header('Location: /login'); exit; }
	$admin->approvePO();
	exit;
}
if ($method === 'POST' && $path === '/admin/po/reject') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') { header('Location: /login'); exit; }
	$admin->rejectPO();
	exit;
}

// Admin: RFP approval/rejection
if ($method === 'POST' && $path === '/admin/rfp/approve') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) { header('Location: /login'); exit; }
	$admin->approveRFP();
	exit;
}
if ($method === 'POST' && $path === '/admin/rfp/reject') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) { header('Location: /login'); exit; }
	$admin->rejectRFP();
	exit;
}

// Supplier: POs list and response
if ($method === 'GET' && $path === '/supplier/pos') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->posPage();
	exit;
}
if ($method === 'POST' && $path === '/supplier/po/respond') {
	if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') { header('Location: /login'); exit; }
	$supplier->poRespond();
	exit;
}
// Legacy generate PO route removed in favor of unified PR-group → PO flow

// Legacy Procurement PO list/create routes removed; use /procurement/po/create (GET/POST) with pr=...

// Inbox (all messages) for all roles
if ($method === 'GET' && $path === '/notifications') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->notifications();
	exit;
}
if ($method === 'GET' && $path === '/notifications/view') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->viewNotification();
	exit;
}
// New friendly inbox routes
if ($method === 'GET' && $path === '/inbox') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->notifications();
	exit;
}
if ($method === 'GET' && $path === '/inbox/view') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->viewNotification();
	exit;
}
if ($method === 'GET' && $path === '/inbox/download') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->downloadMessageAttachment();
	exit;
}
if ($method === 'GET' && $path === '/inbox/preview') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->previewMessageAttachment();
	exit;
}

// Secure PO PDF download
if ($method === 'GET' && $path === '/po/download') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$supplier->downloadPO();
	exit;
}

// Unified Request details view
if ($method === 'GET' && $path === '/requests/view') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->viewRequest();
	exit;
}

// Admin Assistant (legacy: custodian) — Inventory
if ($method === 'GET' && $path === '/custodian/inventory') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->inventoryPage();
	exit;
}
// Admin Assistant alias routes (friendly naming)
if ($method === 'GET' && $path === '/admin-assistant/inventory') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->inventoryPage();
	exit;
}
if ($method === 'POST' && $path === '/admin-assistant/inventory') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->inventoryCreate();
	exit;
}
if ($method === 'POST' && $path === '/admin-assistant/inventory/update') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->inventoryUpdate();
	exit;
}
if ($method === 'POST' && $path === '/admin-assistant/inventory/delete') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->inventoryDelete();
	exit;
}
// Stock-only updates for Admin Assistant (records consumption)
if ($method === 'POST' && $path === '/admin-assistant/inventory/update-stock') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->updateStock();
	exit;
}
// Meta updates for Admin Assistant (unit, status, minimum_quantity)
if ($method === 'POST' && $path === '/admin-assistant/inventory/update-meta') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->updateMeta();
	exit;
}
// Add selected low-stock items to PR cart
if ($method === 'POST' && $path === '/admin-assistant/inventory/cart-add') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->addToCart();
	exit;
}
if ($method === 'POST' && $path === '/custodian/inventory') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->inventoryCreate();
	exit;
}
if ($method === 'POST' && $path === '/custodian/inventory/update') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->inventoryUpdate();
	exit;
}
if ($method === 'POST' && $path === '/custodian/inventory/delete') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->inventoryDelete();
	exit;
}

// Admin Assistant (legacy: custodian) — Purchase Request
if ($method === 'GET' && $path === '/custodian/requests/new') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->newRequest();
	exit;
}
if ($method === 'POST' && $path === '/custodian/requests') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->createRequest();
	exit;
}

// Admin Assistant alias routes (purchase requests)
if ($method === 'GET' && $path === '/admin-assistant/requests/new') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->newRequest();
	exit;
}
// Admin Assistant: Purchase Request history (PR PDFs)
if ($method === 'GET' && $path === '/admin-assistant/requests/history') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->requestsHistory();
	exit;
}
// Admin Assistant: Generate PDF for an existing PR (backfill)
if ($method === 'GET' && $path === '/admin-assistant/requests/history/generate') {
	$custodian->generatePrPdf();
	exit;
}
if ($method === 'POST' && $path === '/admin-assistant/requests') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->createRequest();
	exit;
}
// Review and submit multi-item PRs from cart
if ($method === 'GET' && $path === '/admin-assistant/requests/review') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->reviewCart();
	exit;
}
if ($method === 'POST' && $path === '/admin-assistant/requests/submit') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->submitCart();
	exit;
}
if ($method === 'POST' && $path === '/admin-assistant/requests/cart-remove') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->cartRemove();
	exit;
}

// Inventory & Consumption report downloads
if ($method === 'GET' && $path === '/admin-assistant/reports/inventory') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->inventoryReport();
	exit;
}
if ($method === 'GET' && $path === '/admin-assistant/reports/consumption') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->consumptionReport();
	exit;
}
// Reports module (two submodules: Consumption & Inventory)
if ($method === 'GET' && $path === '/admin-assistant/reports') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->reportsModule();
	exit;
}
// Archived reports list
if ($method === 'GET' && $path === '/admin-assistant/reports/archives') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	// Force archived filter by default when visiting this route
	if (!isset($_GET['show'])) { $_GET['show'] = 'archived'; }
	$custodian->reportsList();
	exit;
}
if ($method === 'GET' && $path === '/admin-assistant/reports/download') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->downloadReport();
	exit;
}
// Archive/Restore actions
if ($method === 'POST' && $path === '/admin-assistant/reports/archive') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->archiveReport();
	exit;
}
if ($method === 'POST' && $path === '/admin-assistant/reports/restore') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['custodian','admin','admin_assistant'], true)) { header('Location: /login'); exit; }
	$custodian->restoreReport();
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
if ($method === 'POST' && $path === '/admin/users/update') {
	if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); exit; }
	$admin->updateUser();
	exit;
}
if ($method === 'POST' && $path === '/admin/users/delete') {
	if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); exit; }
	$admin->deleteUser();
	exit;
}
if ($method === 'POST' && $path === '/admin/users/reset-password') {
	if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); exit; }
	$admin->resetUserPassword();
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
if ($method === 'POST' && $path === '/admin/branches/update') {
	if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); exit; }
	$admin->updateBranch();
	exit;
}
if ($method === 'POST' && $path === '/admin/branches/delete') {
	if (($_SESSION['role'] ?? null) !== 'admin') { header('Location: /login'); exit; }
	$admin->deleteBranch();
	exit;
}
// Removed UI for seeding branches; seeding handled by Installer only.

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
if ($method === 'POST' && $path === '/admin/messages/mark-read') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->markMessageRead();
	exit;
}
if ($method === 'POST' && $path === '/admin/messages/delete') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->deleteMessage();
	exit;
}

// Admin: Canvassing approval actions
if ($method === 'POST' && $path === '/admin/canvassing/approve') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->approveCanvassing();
	exit;
}
if ($method === 'POST' && $path === '/admin/canvassing/reject') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->rejectCanvassing();
	exit;
}

// Admin: PR approval actions (pre-canvassing)
if ($method === 'POST' && $path === '/admin/pr/approve') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->approvePR();
	exit;
}
if ($method === 'POST' && $path === '/admin/pr/reject') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->rejectPR();
	exit;
}
// Admin: PR revision recheck request
if ($method === 'POST' && $path === '/admin/pr/recheck') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->recheckRevision();
	exit;
}

// Admin: Grouped PRs view and status updates
if ($method === 'GET' && $path === '/admin/requests') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->viewRequestsAdmin();
	exit;
}
if ($method === 'POST' && $path === '/admin/requests/update-group-status') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->adminUpdateGroupStatus();
	exit;
}
if ($method === 'GET' && $path === '/admin/requests/history') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->viewRequestsHistoryAdmin();
	exit;
}
if ($method === 'GET' && $path === '/admin/requests/review') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->reviewRequestGroup();
	exit;
}
if ($method === 'POST' && $path === '/admin/pr/revise') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->revisePR();
	exit;
}
// Admin Assistant: revision responses
if ($method === 'POST' && $path === '/assistant/pr/revision/accept') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin_assistant','custodian','admin'], true)) { header('Location: /login'); exit; }
	$admin->acceptRevision();
	exit;
}
if ($method === 'POST' && $path === '/assistant/pr/revision/justify') {
	if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin_assistant','custodian','admin'], true)) { header('Location: /login'); exit; }
	$admin->justifyRevision();
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
// Settings: send test email (SMTP test)
if ($method === 'POST' && $path === '/settings/test-email') {
	if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
	$admin->sendTestEmail();
	exit;
}

// Admin: Announcements
if ($method === 'GET' && $path === '/admin/announcements') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->announcements();
	exit;
}
if ($method === 'POST' && $path === '/admin/announcements') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->createAnnouncement();
	exit;
}
if ($method === 'POST' && $path === '/admin/announcements/delete') {
	if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'admin')) { header('Location: /login'); exit; }
	$admin->deleteAnnouncement();
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
		// DB meta: size, connections, server version (best-effort)
		$meta = [];
		try {
			$sizePretty = $pdo->query("SELECT pg_size_pretty(pg_database_size(current_database()))")->fetchColumn();
			$sizeBytes = (string)$pdo->query("SELECT pg_database_size(current_database())")->fetchColumn();
			$meta[] = 'db_size=' . ($sizePretty ?: 'unknown') . ' (' . $sizeBytes . ' bytes)';
		} catch (\Throwable $e) { /* ignore */ }
		try {
			$maxCon = (string)$pdo->query("SHOW max_connections")->fetchColumn();
			// Count active connections to this DB
			$activeCon = (int)$pdo->query("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database()")
								  ->fetchColumn();
			$meta[] = 'connections=' . $activeCon . '/' . ($maxCon !== '' ? $maxCon : 'unknown');
		} catch (\Throwable $e) { /* ignore */ }
		try {
			$ver = (string)$pdo->query("SELECT version()")->fetchColumn();
			$meta[] = 'server_version=' . ($ver ?: 'unknown');
		} catch (\Throwable $e) { /* ignore */ }
		echo "OK\n" . implode("\n", $checks) . "\n" . implode("\n", $counts) . "\n" . implode("\n", $meta) . "\n";
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