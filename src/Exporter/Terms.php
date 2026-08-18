<?php

namespace IdempotentExport\Exporter;

class Terms extends AbstractExporter {

	public function run() {
		global $wpdb;

		$taxonomies = $wpdb->get_col( "SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}" );
		$taxonomies = array_values(
			array_filter(
				(array) $taxonomies,
				array( $this->filters, 'taxonomyAllowed' )
			)
		);
		if ( empty( $taxonomies ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		$total        = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$placeholders})",
				$taxonomies
			)
		);

		$bar    = $this->progress( 'Terms', $total );
		$lastTt = 0;

		while ( true ) {
			$params = array_merge( $taxonomies, array( $lastTt, $this->batchSize ) );
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, tt.parent, tt.count,
					        t.name, t.slug, t.term_group
					 FROM {$wpdb->term_taxonomy} tt
					 JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
					 WHERE tt.taxonomy IN ({$placeholders}) AND tt.term_taxonomy_id > %d
					 ORDER BY tt.term_taxonomy_id ASC
					 LIMIT %d",
					$params
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			$termIds = array();
			foreach ( $rows as $r ) {
				$termIds[] = (int) $r['term_id'];
			}
			$metaByTerm = $this->fetchMetaByTerm( $termIds );

			foreach ( $rows as $row ) {
				$bar->tick();
				$ttId   = (int) $row['term_taxonomy_id'];
				$lastTt = $ttId;

				$termId = (int) $row['term_id'];

				$data = array(
					'count'            => (int) $row['count'],
					'description'      => (string) $row['description'],
					'meta'             => isset( $metaByTerm[ $termId ] ) ? $metaByTerm[ $termId ] : new \stdClass(),
					'name'             => (string) $row['name'],
					'parent'           => (int) $row['parent'],
					'slug'             => (string) $row['slug'],
					'taxonomy'         => (string) $row['taxonomy'],
					'term_group'       => (int) $row['term_group'],
					'term_id'          => $termId,
					'term_taxonomy_id' => $ttId,
				);

				if ( is_array( $data['meta'] ) && empty( $data['meta'] ) ) {
					$data['meta'] = new \stdClass();
				}

				$slug = sanitize_file_name( $row['taxonomy'] );
				if ( '' === $slug ) {
					$slug = 'unknown';
				}
				$path = "terms/{$slug}/{$ttId}.json";

				$ok = $this->writer->write( 'term', $path, $data );
				if ( ! $ok ) {
					$this->logger->skip( 'term', $ttId, 'JSON encode failed' );
					continue;
				}
				$this->manifest->bumpCount( 'terms' );
			}

			$this->interBatchSleep();
		}

		$bar->finish();
	}

	/**
	 * @param int[] $termIds
	 * @return array<int, array<string, array<int, mixed>>>
	 */
	private function fetchMetaByTerm( array $termIds ) {
		global $wpdb;
		if ( empty( $termIds ) ) {
			return array();
		}
		$inList = implode( ',', array_map( 'intval', $termIds ) );
		$rows   = $wpdb->get_results(
			"SELECT term_id, meta_id, meta_key, meta_value
			 FROM {$wpdb->termmeta}
			 WHERE term_id IN ($inList)
			 ORDER BY term_id ASC, meta_key ASC, meta_id ASC",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$tid = (int) $r['term_id'];
			$key = (string) $r['meta_key'];
			$out[ $tid ][ $key ][] = $this->encoder->decodeStored( 'term', $tid, $key, (string) $r['meta_value'] );
		}
		return $out;
	}
}
