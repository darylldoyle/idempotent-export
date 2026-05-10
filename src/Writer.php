<?php

namespace IdempotentExport;

/**
 * Writes JSON files into the output tree, creating directories as needed.
 *
 * In dry-run mode all writes are no-ops; the Writer still tracks the first
 * entity per type so Run can print samples.
 */
class Writer {

	/** @var string */
	private $root;

	/** @var bool */
	private $dryRun;

	/** @var array<string, array> First entity per logical type, captured in dry-run. */
	private $samples = array();

	/**
	 * @param string $root
	 * @param bool   $dryRun
	 */
	public function __construct( $root, $dryRun ) {
		$this->root   = rtrim( $root, '/\\' );
		$this->dryRun = (bool) $dryRun;
	}

	/**
	 * Write one entity. Returns true on success, false on JSON encode failure
	 * (caller is expected to record a skip on the logger in that case).
	 *
	 * @param string $type            Logical type, used for dry-run sampling ('post', 'term', ...).
	 * @param string $relativePath    Path beneath the root, forward slashes.
	 * @param array  $data
	 * @return bool
	 */
	public function write( $type, $relativePath, array $data ) {
		try {
			$json = Json::encode( $data );
		} catch ( \JsonException $e ) {
			return false;
		}

		if ( $this->dryRun ) {
			if ( ! isset( $this->samples[ $type ] ) ) {
				$this->samples[ $type ] = $data;
			}
			return true;
		}

		$abs = $this->root . '/' . ltrim( $relativePath, '/' );
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			\WP_CLI::error( "Could not create directory: {$dir}" );
		}
		if ( false === file_put_contents( $abs, $json ) ) {
			\WP_CLI::error( "Could not write file: {$abs}" );
		}
		return true;
	}

	/**
	 * Write a literal file at a root-relative path. Used for manifest.json.
	 *
	 * @param string $relativePath
	 * @param array  $data
	 */
	public function writeRoot( $relativePath, array $data ) {
		if ( $this->dryRun ) {
			return;
		}
		$json = Json::encode( $data );
		$abs  = $this->root . '/' . ltrim( $relativePath, '/' );
		$dir  = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			\WP_CLI::error( "Could not create directory: {$dir}" );
		}
		if ( false === file_put_contents( $abs, $json ) ) {
			\WP_CLI::error( "Could not write file: {$abs}" );
		}
	}

	/**
	 * @return array<string, array>
	 */
	public function samples() {
		return $this->samples;
	}

	/**
	 * @return bool
	 */
	public function isDryRun() {
		return $this->dryRun;
	}

	/**
	 * @return string
	 */
	public function root() {
		return $this->root;
	}
}
