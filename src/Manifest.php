<?php

namespace IdempotentExport;

/**
 * Builds the top-level manifest.json. Holds source metadata, auto-increment
 * snapshot, counts and skip list. The only non-deterministic field is
 * exported_at.
 */
class Manifest {

	const SCHEMA_VERSION = '1.0.0';

	/** @var array */
	private $source = array();

	/** @var array<string,int> */
	private $counts = array(
		'comments' => 0,
		'options'  => 0,
		'posts'    => 0,
		'terms'    => 0,
		'users'    => 0,
	);

	/** @var array */
	private $filters = array();

	/**
	 * Snapshot source/site metadata. Called once before exporters run.
	 *
	 * @param int|null $blogId
	 */
	public function captureSource( $blogId ) {
		global $wpdb;

		$this->source = array(
			'auto_increment' => $this->fetchAutoIncrements(),
			'blog_id'        => null === $blogId ? null : (int) $blogId,
			'is_multisite'   => is_multisite(),
			'site_url'       => get_site_url(),
			'wp_version'     => get_bloginfo( 'version' ),
		);
	}

	/**
	 * @param Filters $filters
	 */
	public function captureFilters( Filters $filters ) {
		$this->filters = $filters->toManifest();
	}

	/**
	 * @param string $type
	 * @param int    $delta
	 */
	public function bumpCount( $type, $delta = 1 ) {
		if ( ! isset( $this->counts[ $type ] ) ) {
			$this->counts[ $type ] = 0;
		}
		$this->counts[ $type ] += (int) $delta;
	}

	/**
	 * @return array<string,int>
	 */
	public function counts() {
		return $this->counts;
	}

	/**
	 * @param array $skips
	 * @return array
	 */
	public function build( array $skips ) {
		usort(
			$skips,
			static function ( $a, $b ) {
				$cmp = strcmp( (string) $a['type'], (string) $b['type'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return strnatcmp( (string) $a['id'], (string) $b['id'] );
			}
		);

		return array(
			'counts'          => $this->counts,
			'exported_at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'filters_applied' => $this->filters,
			'schema_version'  => self::SCHEMA_VERSION,
			'skipped'         => $skips,
			'source'          => $this->source,
		);
	}

	/**
	 * @return array<string,int>
	 */
	private function fetchAutoIncrements() {
		global $wpdb;

		// term_taxonomy has its own sequence, and an importer preserving term IDs has
		// to raise both or a term created after the migration reuses a migrated ttid.
		$map = array(
			'posts'         => $wpdb->posts,
			'terms'         => $wpdb->terms,
			'term_taxonomy' => $wpdb->term_taxonomy,
			'users'         => $wpdb->users,
			'comments'      => $wpdb->comments,
		);
		$tables = array_values( $map );
		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$sql = "SELECT TABLE_NAME, AUTO_INCREMENT
		        FROM information_schema.tables
		        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $tables ), ARRAY_A );
		$byName = array();
		foreach ( (array) $rows as $r ) {
			$byName[ $r['TABLE_NAME'] ] = (int) $r['AUTO_INCREMENT'];
		}
		$out = array();
		foreach ( $map as $logical => $tableName ) {
			$out[ $logical ] = isset( $byName[ $tableName ] ) ? $byName[ $tableName ] : 0;
		}
		return $out;
	}
}
