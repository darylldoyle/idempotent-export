<?php

namespace IdempotentExport;

/**
 * Resolves and validates the output directory.
 *
 * - On VIP, falls back to wp-content/uploads/private/idempotent-export-<timestamp>
 *   if no path is supplied, and requires the resolved path to live under
 *   the writable uploads tree.
 * - On non-VIP, the path is required (otherwise WP_CLI::error).
 * - A non-empty existing dir requires --force.
 */
class Output {

	/**
	 * @param array $args
	 * @param array $assoc_args
	 * @return string Absolute path of the output dir, already created.
	 */
	public static function resolve( array $args, array $assoc_args ) {
		$path = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $path ) {
			if ( ! self::isVip() ) {
				\WP_CLI::error( 'output-dir is required.' );
			}
			$path = self::defaultVipPath();
		}

		$path = self::makeAbsolute( $path );

		if ( self::isVip() ) {
			self::assertVipWritable( $path );
		}

		self::ensureUsableDir( $path, ! empty( $assoc_args['force'] ) );

		return $path;
	}

	/**
	 * @return bool
	 */
	public static function isVip() {
		return defined( 'VIP_GO_APP_ENVIRONMENT' ) || defined( 'WPCOM_IS_VIP_ENV' );
	}

	/**
	 * @return string|null
	 */
	public static function vipEnv() {
		if ( defined( 'VIP_GO_APP_ENVIRONMENT' ) ) {
			return (string) constant( 'VIP_GO_APP_ENVIRONMENT' );
		}
		return null;
	}

	/**
	 * @return string
	 */
	private static function defaultVipPath() {
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return $base . '/private/idempotent-export-' . gmdate( 'YmdHis' );
	}

	/**
	 * @param string $path
	 */
	private static function assertVipWritable( $path ) {
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		$base    = rtrim( $base, '/\\' );
		if ( '' === $base || 0 !== strpos( $path, $base ) ) {
			\WP_CLI::error(
				"On VIP the output dir must live under the uploads directory ({$base}). Got: {$path}"
			);
		}
	}

	/**
	 * @param string $path
	 * @return string
	 */
	private static function makeAbsolute( $path ) {
		if ( '' !== $path && ( '/' === $path[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) ) ) {
			return rtrim( $path, '/\\' );
		}
		$cwd = getcwd();
		return rtrim( $cwd . '/' . $path, '/\\' );
	}

	/**
	 * @param string $path
	 * @param bool   $force
	 */
	private static function ensureUsableDir( $path, $force ) {
		if ( ! file_exists( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				\WP_CLI::error( "Could not create output dir: {$path}" );
			}
			return;
		}
		if ( ! is_dir( $path ) ) {
			\WP_CLI::error( "Output path is not a directory: {$path}" );
		}
		$entries = @scandir( $path );
		if ( false === $entries ) {
			\WP_CLI::error( "Could not read output dir: {$path}" );
		}
		$nonEmpty = array_values(
			array_filter(
				$entries,
				static function ( $e ) {
					return '.' !== $e && '..' !== $e;
				}
			)
		);
		if ( $nonEmpty && ! $force ) {
			\WP_CLI::error( "Output dir is not empty: {$path}. Re-run with --force to overwrite." );
		}
	}
}
