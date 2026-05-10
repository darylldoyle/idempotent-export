<?php

namespace IdempotentExport;

/**
 * Holds CLI filter state: post-type restriction, date window, revision opt-in.
 */
class Filters {

	/**
	 * Allowed post types, or null for all discovered types (still excludes revisions/nav menus by default).
	 *
	 * @var string[]|null
	 */
	public $post_types = null;

	/** @var string|null Lower bound in 'Y-m-d H:i:s' UTC, or null. */
	public $since_gmt = null;

	/** @var string|null Upper bound (exclusive) in 'Y-m-d H:i:s' UTC, or null. */
	public $until_gmt = null;

	/** @var bool */
	public $include_revisions = false;

	/**
	 * @param array $assoc_args
	 * @return self
	 */
	public static function fromCliArgs( array $assoc_args ) {
		$f = new self();

		if ( ! empty( $assoc_args['post-type'] ) ) {
			$types = array_filter( array_map( 'trim', explode( ',', $assoc_args['post-type'] ) ) );
			$f->post_types = array_values( array_unique( $types ) );
		}

		if ( ! empty( $assoc_args['since'] ) ) {
			$f->since_gmt = self::normaliseDate( $assoc_args['since'] );
		}

		if ( ! empty( $assoc_args['until'] ) ) {
			$f->until_gmt = self::normaliseDate( $assoc_args['until'] );
		}

		$f->include_revisions = ! empty( $assoc_args['include-revisions'] );

		return $f;
	}

	/**
	 * Normalise a CLI date input to a 'Y-m-d H:i:s' UTC string.
	 * Accepts ISO 8601 or YYYY-MM-DD.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function normaliseDate( $value ) {
		$ts = strtotime( $value );
		if ( false === $ts ) {
			\WP_CLI::error( "Invalid date value: {$value}" );
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * @param string $post_type
	 * @return bool
	 */
	public function postTypeAllowed( $post_type ) {
		// Hard exclusion: classic menu items are out of scope.
		if ( 'nav_menu_item' === $post_type ) {
			return false;
		}
		if ( 'revision' === $post_type && ! $this->include_revisions ) {
			return false;
		}
		if ( null !== $this->post_types && ! in_array( $post_type, $this->post_types, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * @param string $taxonomy
	 * @return bool
	 */
	public function taxonomyAllowed( $taxonomy ) {
		// Hard exclusion: classic menus.
		return 'nav_menu' !== $taxonomy;
	}

	/**
	 * Test a stored 'Y-m-d H:i:s' GMT date against the configured window.
	 *
	 * @param string $date_gmt
	 * @return bool
	 */
	public function dateInRange( $date_gmt ) {
		if ( null !== $this->since_gmt && $date_gmt < $this->since_gmt ) {
			return false;
		}
		if ( null !== $this->until_gmt && $date_gmt >= $this->until_gmt ) {
			return false;
		}
		return true;
	}

	/**
	 * @return array
	 */
	public function toManifest() {
		return array(
			'include_revisions' => $this->include_revisions,
			'post_types'        => $this->post_types,
			'since'             => $this->since_gmt,
			'until'             => $this->until_gmt,
		);
	}
}
