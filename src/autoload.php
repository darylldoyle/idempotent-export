<?php
/**
 * PSR-4-style autoloader for the IdempotentExport namespace.
 */

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'IdempotentExport\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);
