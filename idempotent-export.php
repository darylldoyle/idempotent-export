<?php
/**
 * Plugin Name: WP Idempotent Export
 * Description: WP-CLI command that exports a single WordPress site as a deterministic tree of JSON files.
 * Version:     1.0.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/src/autoload.php';

WP_CLI::add_command( 'idempotent-export', \IdempotentExport\Command::class );
