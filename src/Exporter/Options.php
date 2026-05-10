<?php

namespace IdempotentExport\Exporter;

class Options extends AbstractExporter {

	public function run() {
		global $wpdb;

		$transientPattern     = $wpdb->esc_like( '_transient_' ) . '%';
		$siteTransientPattern = $wpdb->esc_like( '_site_transient_' ) . '%';

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				 WHERE option_name NOT LIKE %s
				   AND option_name NOT LIKE %s",
				$transientPattern,
				$siteTransientPattern
			)
		);
		$bar    = $this->progress( 'Options', $total );
		$lastId = 0;
		$out    = array();

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value, autoload
					 FROM {$wpdb->options}
					 WHERE option_id > %d
					   AND option_name NOT LIKE %s
					   AND option_name NOT LIKE %s
					 ORDER BY option_id ASC
					 LIMIT %d",
					$lastId,
					$transientPattern,
					$siteTransientPattern,
					$this->batchSize
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$bar->tick();
				$lastId = (int) $row['option_id'];
				$name   = (string) $row['option_name'];

				$rawValue = (string) $row['option_value'];
				$value    = $this->encoder->decodeStored( 'option', $name, $name, $rawValue );

				$out[ $name ] = array(
					'autoload' => (string) $row['autoload'],
					'value'    => $value,
				);
			}

			$this->interBatchSleep();
		}

		$bar->finish();

		if ( empty( $out ) ) {
			return;
		}

		$ok = $this->writer->write( 'option', 'options.json', $out );
		if ( ! $ok ) {
			$this->logger->skip( 'option', '*', 'JSON encode failed for options.json' );
			return;
		}
		$this->manifest->bumpCount( 'options', count( $out ) );
	}
}
