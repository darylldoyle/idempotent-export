<?php

namespace IdempotentExport;

/**
 * Deterministic JSON encoding: sorted associative keys, list ordering preserved,
 * two-space indent, UTF-8 safe, throws on serialisation failure.
 */
class Json {

	const FLAGS = JSON_UNESCAPED_UNICODE
		| JSON_UNESCAPED_SLASHES
		| JSON_INVALID_UTF8_SUBSTITUTE
		| JSON_THROW_ON_ERROR
		| JSON_PRETTY_PRINT;

	/**
	 * Recursively sort associative-array keys (case-sensitive ASCII order)
	 * while preserving the order of numerically-indexed lists.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	public static function sortKeys( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$isList = self::isList( $data );
		$out    = array();
		foreach ( $data as $k => $v ) {
			$out[ $k ] = self::sortKeys( $v );
		}
		if ( ! $isList ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	/**
	 * @param array $arr
	 * @return bool
	 */
	private static function isList( array $arr ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}
		$i = 0;
		foreach ( $arr as $k => $_ ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Encode with deterministic key order and a two-space indent.
	 * Throws JsonException on failure.
	 *
	 * @param mixed $data
	 * @return string
	 */
	public static function encode( $data ) {
		$json = json_encode( self::sortKeys( $data ), self::FLAGS );
		// PHP's JSON_PRETTY_PRINT uses 4-space indents. Halve them.
		$json = preg_replace_callback(
			'/^( +)/m',
			static function ( $m ) {
				return str_repeat( ' ', (int) ( strlen( $m[0] ) / 2 ) );
			},
			$json
		);
		return $json . "\n";
	}
}
