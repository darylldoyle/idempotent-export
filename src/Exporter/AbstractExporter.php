<?php

namespace IdempotentExport\Exporter;

use IdempotentExport\Encoder;
use IdempotentExport\Filters;
use IdempotentExport\Logger;
use IdempotentExport\Manifest;
use IdempotentExport\Writer;

abstract class AbstractExporter {

	/** @var Writer */
	protected $writer;

	/** @var Logger */
	protected $logger;

	/** @var Encoder */
	protected $encoder;

	/** @var Filters */
	protected $filters;

	/** @var Manifest */
	protected $manifest;

	/** @var int */
	protected $batchSize;

	/** @var int */
	protected $sleepUs;

	/** @var bool */
	protected $quiet;

	public function __construct(
		Writer $writer,
		Logger $logger,
		Encoder $encoder,
		Filters $filters,
		Manifest $manifest,
		$batchSize,
		$sleepUs,
		$quiet
	) {
		$this->writer    = $writer;
		$this->logger    = $logger;
		$this->encoder   = $encoder;
		$this->filters   = $filters;
		$this->manifest  = $manifest;
		$this->batchSize = max( 1, (int) $batchSize );
		$this->sleepUs   = max( 0, (int) $sleepUs );
		$this->quiet     = (bool) $quiet;
	}

	abstract public function run();

	/**
	 * Pause between batches if a sleep was requested.
	 */
	protected function interBatchSleep() {
		if ( $this->sleepUs > 0 ) {
			usleep( $this->sleepUs );
		}
	}

	/**
	 * Extract YYYY and MM from a 'Y-m-d H:i:s' GMT timestamp.
	 * Falls back to '0000'/'00' if the timestamp is empty or malformed.
	 *
	 * @param string $dateGmt
	 * @return array{0:string,1:string}
	 */
	protected function shardFromDate( $dateGmt ) {
		if ( ! is_string( $dateGmt ) || strlen( $dateGmt ) < 7 ) {
			return array( '0000', '00' );
		}
		$year  = substr( $dateGmt, 0, 4 );
		$month = substr( $dateGmt, 5, 2 );
		if ( ! ctype_digit( $year ) || ! ctype_digit( $month ) ) {
			return array( '0000', '00' );
		}
		return array( $year, $month );
	}

	/**
	 * Apply wp_unslash to every string in a row.
	 *
	 * @param array $row
	 * @return array
	 */
	protected function unslashRow( array $row ) {
		foreach ( $row as $k => $v ) {
			if ( is_string( $v ) ) {
				$row[ $k ] = wp_unslash( $v );
			}
		}
		return $row;
	}

	/**
	 * @param string $message
	 * @param int    $count
	 * @return object  Either a cli progress bar or a NoOp.
	 */
	protected function progress( $message, $count ) {
		if ( $this->quiet ) {
			return new \WP_CLI\NoOp();
		}
		return \WP_CLI\Utils\make_progress_bar( $message, max( 1, (int) $count ) );
	}
}
