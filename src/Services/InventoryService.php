<?php
// Case-shim: ensure the canonical lowercase service is used on case-sensitive hosts.
// This file intentionally declares no classes.
if (!class_exists(\App\Services\InventoryService::class, false)) {
	$canonical = __DIR__ . '/../services/InventoryService.php';
	if (is_file($canonical)) {
		require_once $canonical;
	}
}
return;
