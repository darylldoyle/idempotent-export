<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Loads the production autoloader, registers the test autoloader,
 * declares the WordPress and WP-CLI shims required by the exporter,
 * and installs a default in-memory $wpdb so individual tests can
 * trivially override it.
 */

require __DIR__ . '/../src/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'IdempotentExport\\Tests\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

require __DIR__ . '/Support/WpFunctions.php';
require __DIR__ . '/Support/WpCli.php';

\IdempotentExport\Tests\Support\WpCli::install();
