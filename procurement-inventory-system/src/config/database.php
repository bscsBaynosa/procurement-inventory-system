<?php

/**
 * Bootstrap the shared PDO instance for legacy scripts that include this file directly.
 *
 * When using Composer's autoloader, prefer calling Connection::resolve() instead of relying
 * on the global $pdo variable.
 *
 * @var PDO $pdo
 */

$connectionClass = 'App\\Database\\Connection';

if (!class_exists($connectionClass)) {
    $autoLoader = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (file_exists($autoLoader)) {
        require_once $autoLoader;
    }
}

if (!class_exists($connectionClass)) {
    throw new \RuntimeException('Autoloader failed to load App\\Database\\Connection.');
}

/** @var PDO $pdo */
$pdo = $connectionClass::resolve();

return $pdo;