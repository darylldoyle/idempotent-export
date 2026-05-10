<?php

namespace IdempotentExport;

/**
 * Collects skip/warn events. Writes one line per event to errors.log, and
 * keeps the skip list for embedding in manifest.json.
 *
 * Determinism: callers must emit events in stable order. The Logger does not
 * sort; it preserves insertion order so that re-runs over unchanged data
 * produce byte-identical errors.log.
 */
class Logger {

	const SEV_SKIP = 'skip';
	const SEV_WARN = 'warn';

	/** @var string|null Path to errors.log; null in dry-run. */
	private $logPath;

	/** @var resource|null Open handle to errors.log. */
	private $handle = null;

	/** @var array<int, array{type:string,id:int|string,reason:string}> */
	private $skips = array();

	/** @var int */
	private $warnCount = 0;

	/**
	 * @param string|null $logPath  Null disables file writes (dry-run).
	 */
	public function __construct( $logPath ) {
		$this->logPath = $logPath;
	}

	public function open() {
		if ( null === $this->logPath ) {
			return;
		}
		$h = fopen( $this->logPath, 'wb' );
		if ( false === $h ) {
			\WP_CLI::error( "Could not open {$this->logPath} for writing." );
		}
		$this->handle = $h;
	}

	public function close() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Record a skip: the entity is excluded from output.
	 *
	 * @param string     $type
	 * @param int|string $id
	 * @param string     $reason
	 */
	public function skip( $type, $id, $reason ) {
		$this->skips[] = array(
			'id'     => $id,
			'reason' => $reason,
			'type'   => $type,
		);
		$this->write( self::SEV_SKIP, $type, $id, $reason );
	}

	/**
	 * Record a warning: the entity is still exported, but something noteworthy happened.
	 *
	 * @param string     $type
	 * @param int|string $id
	 * @param string     $reason
	 */
	public function warn( $type, $id, $reason ) {
		++$this->warnCount;
		$this->write( self::SEV_WARN, $type, $id, $reason );
	}

	/**
	 * @return array<int, array{type:string,id:int|string,reason:string}>
	 */
	public function skips() {
		return $this->skips;
	}

	/**
	 * @return int
	 */
	public function skipCount() {
		return count( $this->skips );
	}

	/**
	 * @return int
	 */
	public function warnCount() {
		return $this->warnCount;
	}

	/**
	 * @param string     $severity
	 * @param string     $type
	 * @param int|string $id
	 * @param string     $reason
	 */
	private function write( $severity, $type, $id, $reason ) {
		if ( ! is_resource( $this->handle ) ) {
			return;
		}
		$line = sprintf(
			"%s\ttype=%s\tid=%s\t%s\n",
			$severity,
			$type,
			(string) $id,
			str_replace( array( "\n", "\r", "\t" ), ' ', $reason )
		);
		fwrite( $this->handle, $line );
	}
}
