<?php

namespace IdempotentExport;

/**
 * Handles the meta-value pipeline: unslash, maybe-unserialize, cast objects
 * down to arrays. Warns the Logger on lossy transforms.
 */
class Encoder {

	/** @var Logger */
	private $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Decode a raw stored value (as returned by $wpdb) into a JSON-safe value.
	 *
	 * @param string     $entityType
	 * @param int|string $entityId
	 * @param string     $key         Field name for diagnostics (e.g. meta key, option name).
	 * @param string     $raw         The stored value, slashed.
	 * @return mixed
	 */
	public function decodeStored( $entityType, $entityId, $key, $raw ) {
		$unslashed = wp_unslash( $raw );

		if ( ! is_string( $unslashed ) || ! self::looksSerialized( $unslashed ) ) {
			return $unslashed;
		}

		// Detect & report objects without losing the structure.
		$value = $this->tryUnserialize( $unslashed );
		if ( $value instanceof DecodeFailure ) {
			$this->logger->warn(
				$entityType,
				$entityId,
				"key={$key}: failed to unserialize, keeping raw string"
			);
			return $unslashed;
		}

		return $this->castObjectsRecursive( $value, $entityType, $entityId, $key );
	}

	/**
	 * @param mixed       $value
	 * @param string      $entityType
	 * @param int|string  $entityId
	 * @param string      $key
	 * @return mixed
	 */
	private function castObjectsRecursive( $value, $entityType, $entityId, $key ) {
		if ( is_object( $value ) ) {
			$this->logger->warn(
				$entityType,
				$entityId,
				"key={$key}: object cast to array"
			);
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->castObjectsRecursive( $v, $entityType, $entityId, $key );
			}
		}
		return $value;
	}

	/**
	 * @param string $value
	 * @return mixed Returns DecodeFailure sentinel on error.
	 */
	private function tryUnserialize( $value ) {
		// allowed_classes=false avoids instantiating arbitrary user classes (unserialize
		// gadget surface) and gives us a consistent __PHP_Incomplete_Class shape that
		// the recursive object cast then flattens to an associative array.
		//
		// A custom error handler is installed (rather than relying on @) because PHPUnit's
		// strict mode bypasses the silence operator for E_NOTICE/E_WARNING.
		set_error_handler( static function () { return true; } );
		try {
			$result = unserialize( $value, array( 'allowed_classes' => false ) );
		} finally {
			restore_error_handler();
		}
		if ( false === $result && 'b:0;' !== rtrim( $value ) ) {
			return new DecodeFailure();
		}
		return $result;
	}

	/**
	 * Mirror of WP's is_serialized() but without loading WP if not yet bootstrapped.
	 * Keeps things tight in tests.
	 *
	 * @param string $data
	 * @return bool
	 */
	private static function looksSerialized( $data ) {
		if ( function_exists( 'is_serialized' ) ) {
			return is_serialized( $data );
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 ) {
			return false;
		}
		if ( ':' !== $data[1] ) {
			return false;
		}
		return (bool) preg_match( '/^[adObis]:/', $data );
	}
}
