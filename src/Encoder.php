<?php

namespace IdempotentExport;

/**
 * Handles the meta-value pipeline: maybe-unserialize containers, cast objects
 * down to arrays. Warns the Logger on lossy transforms.
 *
 * Values are taken verbatim from $wpdb. Stored data is *not* slashed — slashing
 * is only WordPress's convention for data on its way *into* the insert/update
 * APIs — so unslashing on the way out would destroy real backslashes.
 */
class Encoder {

	/**
	 * How deep a decoded value may nest and still be written to the snapshot.
	 *
	 * Json::encode() and the importer's json_decode() both work to PHP's default
	 * limit of 512, and a meta value sits several levels down inside its entity
	 * (entity -> meta -> key -> values -> value). Accepting a value at the full 512
	 * would therefore still blow the limit once wrapped, so leave clear headroom.
	 */
	const MAX_VALUE_DEPTH = 500;

	/** @var Logger */
	private $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Decode a raw stored value (as returned by $wpdb) into a JSON-safe value.
	 *
	 * Only containers are unserialized. A serialized scalar (`i:0;`, `b:0;`,
	 * `d:1.5;`) is left as its stored string: WordPress re-serializes arrays and
	 * objects on write but not scalars, so unwrapping one here would silently
	 * change what the destination stores.
	 *
	 * @param string     $entityType
	 * @param int|string $entityId
	 * @param string     $key         Field name for diagnostics (e.g. meta key, option name).
	 * @param string     $raw         The stored value.
	 * @return mixed
	 */
	public function decodeStored( $entityType, $entityId, $key, $raw ) {
		if ( ! is_string( $raw ) || ! self::looksSerialized( $raw ) ) {
			return $raw;
		}

		// Detect & report objects without losing the structure.
		$value = $this->tryUnserialize( $raw );
		if ( $value instanceof DecodeFailure ) {
			$this->logger->warn(
				$entityType,
				$entityId,
				"key={$key}: failed to unserialize, keeping raw string"
			);
			return $raw;
		}

		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return $raw;
		}

		$value = $this->castObjectsRecursive( $value, $entityType, $entityId, $key );

		// A value json_encode cannot represent (nesting past its depth limit, INF/NAN
		// from a float cast) would otherwise throw and take the whole entity out of
		// the export with it. The raw string always encodes, so fall back to it.
		if ( ! self::isEncodable( $value ) ) {
			$this->logger->warn(
				$entityType,
				$entityId,
				"key={$key}: not representable as JSON, keeping raw string"
			);
			return $raw;
		}

		return $value;
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
	 * Can json_encode represent this value at the depth Json::encode will use?
	 *
	 * @param mixed $value
	 * @return bool
	 */
	private static function isEncodable( $value ) {
		return false !== json_encode( $value, 0, self::MAX_VALUE_DEPTH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
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
