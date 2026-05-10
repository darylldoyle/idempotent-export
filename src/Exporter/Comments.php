<?php

namespace IdempotentExport\Exporter;

class Comments extends AbstractExporter {

	public function run() {
		global $wpdb;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
		$bar    = $this->progress( 'Comments', $total );
		$lastId = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->comments}
					 WHERE comment_ID > %d
					 ORDER BY comment_ID ASC LIMIT %d",
					$lastId,
					$this->batchSize
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			$commentIds = array();
			foreach ( $rows as $r ) {
				$commentIds[] = (int) $r['comment_ID'];
			}
			$metaByComment = $this->fetchMetaByComment( $commentIds );

			foreach ( $rows as $row ) {
				$bar->tick();
				$commentId = (int) $row['comment_ID'];
				$lastId    = $commentId;

				if ( ! $this->filters->dateInRange( (string) $row['comment_date_gmt'] ) ) {
					continue;
				}

				$row = $this->unslashRow( $row );

				$data = array(
					'comment_ID'           => $commentId,
					'comment_agent'        => (string) $row['comment_agent'],
					'comment_approved'     => (string) $row['comment_approved'],
					'comment_author'       => (string) $row['comment_author'],
					'comment_author_IP'    => (string) $row['comment_author_IP'],
					'comment_author_email' => (string) $row['comment_author_email'],
					'comment_author_url'   => (string) $row['comment_author_url'],
					'comment_content'      => (string) $row['comment_content'],
					'comment_date'         => (string) $row['comment_date'],
					'comment_date_gmt'     => (string) $row['comment_date_gmt'],
					'comment_karma'        => (int) $row['comment_karma'],
					'comment_parent'       => (int) $row['comment_parent'],
					'comment_post_ID'      => (int) $row['comment_post_ID'],
					'comment_type'         => (string) $row['comment_type'],
					'meta'                 => isset( $metaByComment[ $commentId ] ) ? $metaByComment[ $commentId ] : new \stdClass(),
					'user_id'              => (int) $row['user_id'],
				);

				if ( is_array( $data['meta'] ) && empty( $data['meta'] ) ) {
					$data['meta'] = new \stdClass();
				}

				list( $y, $m ) = $this->shardFromDate( $data['comment_date_gmt'] );
				$path          = "comments/{$y}/{$m}/{$commentId}.json";

				$ok = $this->writer->write( 'comment', $path, $data );
				if ( ! $ok ) {
					$this->logger->skip( 'comment', $commentId, 'JSON encode failed' );
					continue;
				}
				$this->manifest->bumpCount( 'comments' );
			}

			$this->interBatchSleep();
		}

		$bar->finish();
	}

	/**
	 * @param int[] $commentIds
	 * @return array<int, array<string, array<int, mixed>>>
	 */
	private function fetchMetaByComment( array $commentIds ) {
		global $wpdb;
		if ( empty( $commentIds ) ) {
			return array();
		}
		$inList = implode( ',', array_map( 'intval', $commentIds ) );
		$rows   = $wpdb->get_results(
			"SELECT comment_id, meta_id, meta_key, meta_value
			 FROM {$wpdb->commentmeta}
			 WHERE comment_id IN ($inList)
			 ORDER BY comment_id ASC, meta_key ASC, meta_id ASC",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$cid = (int) $r['comment_id'];
			$key = (string) $r['meta_key'];
			$out[ $cid ][ $key ][] = $this->encoder->decodeStored( 'comment', $cid, $key, (string) $r['meta_value'] );
		}
		return $out;
	}
}
