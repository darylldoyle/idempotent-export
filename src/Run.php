<?php

namespace IdempotentExport;

use IdempotentExport\Exporter\Comments as CommentsExporter;
use IdempotentExport\Exporter\Options as OptionsExporter;
use IdempotentExport\Exporter\Posts as PostsExporter;
use IdempotentExport\Exporter\Terms as TermsExporter;
use IdempotentExport\Exporter\Users as UsersExporter;

/**
 * Top-level orchestrator. Validates inputs, sets up shared services, runs
 * each entity exporter in a fixed order, writes the manifest and exits.
 */
class Run {

	/**
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function execute( array $args, array $assoc_args ) {
		$dryRun = ! empty( $assoc_args['dry-run'] );
		$quiet  = ! empty( $assoc_args['quiet'] );

		// Multisite: require --blog-id, switch.
		$blogId = $this->resolveBlogId( $assoc_args );

		$filters   = Filters::fromCliArgs( $assoc_args );
		$batchSize = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 500;
		$sleepUs   = isset( $assoc_args['inter-batch-sleep-ms'] ) ? max( 0, (int) $assoc_args['inter-batch-sleep-ms'] ) * 1000 : 0;

		if ( $dryRun ) {
			$outputDir = isset( $args[0] ) ? (string) $args[0] : sys_get_temp_dir() . '/idempotent-export-dryrun';
		} else {
			$outputDir = Output::resolve( $args, $assoc_args );
		}

		$logger = new Logger( $dryRun ? null : ( $outputDir . '/errors.log' ) );
		$logger->open();

		$writer   = new Writer( $outputDir, $dryRun );
		$encoder  = new Encoder( $logger );
		$manifest = new Manifest();
		$manifest->captureSource( $blogId );
		$manifest->captureFilters( $filters );

		$this->announceStart( $outputDir, $dryRun, $blogId );

		wp_suspend_cache_addition( true );
		$started = microtime( true );

		$shared = array( $writer, $logger, $encoder, $filters, $manifest, $batchSize, $sleepUs, $quiet );

		// Fixed order. Posts and comments are typically the heaviest; users/options are cheap.
		( new PostsExporter( ...$shared ) )->run();
		( new TermsExporter( ...$shared ) )->run();
		( new UsersExporter( ...$shared ) )->run();
		( new CommentsExporter( ...$shared ) )->run();
		( new OptionsExporter( ...$shared ) )->run();

		// Write manifest last so its counts reflect the run.
		$writer->writeRoot( 'manifest.json', $manifest->build( $logger->skips() ) );

		$elapsed = microtime( true ) - $started;
		$this->announceFinish( $writer, $logger, $manifest, $outputDir, $elapsed );

		$logger->close();

		if ( is_multisite() && null !== $blogId ) {
			restore_current_blog();
		}

		// Dry-run always exits zero. A clean run also exits zero. Skips => non-zero.
		if ( $dryRun ) {
			return;
		}
		if ( $logger->skipCount() > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * @param array $assoc_args
	 * @return int|null
	 */
	private function resolveBlogId( array $assoc_args ) {
		if ( ! is_multisite() ) {
			if ( ! empty( $assoc_args['blog-id'] ) ) {
				\WP_CLI::warning( '--blog-id ignored on single-site install.' );
			}
			return null;
		}
		if ( empty( $assoc_args['blog-id'] ) ) {
			\WP_CLI::error( 'Multisite detected: --blog-id=<id> is required.' );
		}
		$id = (int) $assoc_args['blog-id'];
		if ( $id < 1 ) {
			\WP_CLI::error( 'Invalid --blog-id.' );
		}
		switch_to_blog( $id );
		return $id;
	}

	/**
	 * @param string   $outputDir
	 * @param bool     $dryRun
	 * @param int|null $blogId
	 */
	private function announceStart( $outputDir, $dryRun, $blogId ) {
		$bits = array();
		if ( $dryRun ) {
			$bits[] = 'dry-run';
		}
		if ( null !== $blogId ) {
			$bits[] = "blog={$blogId}";
		}
		if ( null !== Output::vipEnv() ) {
			$bits[] = 'vip=' . Output::vipEnv();
		}
		$suffix = $bits ? ' (' . implode( ', ', $bits ) . ')' : '';
		\WP_CLI::log( "idempotent-export -> {$outputDir}{$suffix}" );
	}

	/**
	 * @param Writer   $writer
	 * @param Logger   $logger
	 * @param Manifest $manifest
	 * @param string   $outputDir
	 * @param float    $elapsed
	 */
	private function announceFinish( Writer $writer, Logger $logger, Manifest $manifest, $outputDir, $elapsed ) {
		$counts = $manifest->counts();
		$size   = $writer->isDryRun() ? 0 : $this->dirSize( $outputDir );

		if ( $writer->isDryRun() ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry-run summary:' );
			foreach ( $counts as $type => $n ) {
				\WP_CLI::log( sprintf( '  %-10s %d', $type, $n ) );
			}
			\WP_CLI::log( '' );
			\WP_CLI::log( 'First entity per type:' );
			foreach ( $writer->samples() as $type => $sample ) {
				\WP_CLI::log( "--- {$type} ---" );
				\WP_CLI::log( Json::encode( $sample ) );
			}
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Written: %d posts, %d terms, %d users, %d comments, %d options. Skipped: %d. Warnings: %d. Elapsed: %.2fs. Size: %s.',
				$counts['posts'],
				$counts['terms'],
				$counts['users'],
				$counts['comments'],
				$counts['options'],
				$logger->skipCount(),
				$logger->warnCount(),
				$elapsed,
				size_format( $size, 2 )
			)
		);
	}

	/**
	 * Recursively sum file sizes under $dir.
	 *
	 * @param string $dir
	 * @return int
	 */
	private function dirSize( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$bytes = 0;
		$iter  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iter as $file ) {
			if ( $file->isFile() ) {
				$bytes += $file->getSize();
			}
		}
		return $bytes;
	}
}
