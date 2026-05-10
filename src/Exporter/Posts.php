<?php

namespace IdempotentExport\Exporter;

class Posts extends AbstractExporter {

	public function run() {
		global $wpdb;

		$types = $wpdb->get_col( "SELECT DISTINCT post_type FROM {$wpdb->posts}" );
		$types = array_values( array_filter( (array) $types, array( $this->filters, 'postTypeAllowed' ) ) );
		if ( empty( $types ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders})",
				$types
			)
		);

		$bar    = $this->progress( 'Posts', $total );
		$lastId = 0;

		while ( true ) {
			$params = array_merge( $types, array( $lastId, $this->batchSize ) );
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->posts}
					 WHERE post_type IN ({$placeholders}) AND ID > %d
					 ORDER BY ID ASC LIMIT %d",
					$params
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			$batchIds = array();
			foreach ( $rows as $r ) {
				$batchIds[] = (int) $r['ID'];
			}

			$metaByPost     = $this->fetchMetaByPost( $batchIds );
			$termsByPost    = $this->fetchTermsByPost( $batchIds );
			$commentsByPost = $this->fetchCommentsByPost( $batchIds );

			foreach ( $rows as $row ) {
				$bar->tick();
				$postId = (int) $row['ID'];
				$lastId = $postId;

				if ( ! $this->filters->dateInRange( (string) $row['post_date_gmt'] ) ) {
					continue;
				}

				$row  = $this->unslashRow( $row );
				$data = $this->buildPostData( $row, $metaByPost, $termsByPost, $commentsByPost );

				list( $y, $m ) = $this->shardFromDate( $data['post_date_gmt'] );
				$path          = "posts/{$y}/{$m}/{$postId}.json";

				$ok = $this->writer->write( 'post', $path, $data );
				if ( ! $ok ) {
					$this->logger->skip( 'post', $postId, 'JSON encode failed' );
					continue;
				}
				$this->manifest->bumpCount( 'posts' );
			}

			$this->interBatchSleep();
		}

		$bar->finish();
	}

	/**
	 * @param int[] $postIds
	 * @return array<int, array<string, array<int, mixed>>>
	 */
	private function fetchMetaByPost( array $postIds ) {
		global $wpdb;
		if ( empty( $postIds ) ) {
			return array();
		}
		$inList = implode( ',', array_map( 'intval', $postIds ) );
		$rows   = $wpdb->get_results(
			"SELECT post_id, meta_id, meta_key, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE post_id IN ($inList)
			 ORDER BY post_id ASC, meta_key ASC, meta_id ASC",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$pid   = (int) $r['post_id'];
			$key   = (string) $r['meta_key'];
			$value = $this->encoder->decodeStored( 'post', $pid, $key, (string) $r['meta_value'] );
			$out[ $pid ][ $key ][] = $value;
		}
		return $out;
	}

	/**
	 * @param int[] $postIds
	 * @return array<int, array<string, int[]>>  post_id => taxonomy => [term_taxonomy_id, ...]
	 */
	private function fetchTermsByPost( array $postIds ) {
		global $wpdb;
		if ( empty( $postIds ) ) {
			return array();
		}
		$inList = implode( ',', array_map( 'intval', $postIds ) );
		$rows   = $wpdb->get_results(
			"SELECT tr.object_id, tt.term_taxonomy_id, tt.taxonomy
			 FROM {$wpdb->term_relationships} tr
			 JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tr.object_id IN ($inList) AND tt.taxonomy <> 'nav_menu'
			 ORDER BY tr.object_id ASC, tt.taxonomy ASC, tt.term_taxonomy_id ASC",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$pid                                   = (int) $r['object_id'];
			$tax                                   = (string) $r['taxonomy'];
			$out[ $pid ][ $tax ][]                 = (int) $r['term_taxonomy_id'];
		}
		return $out;
	}

	/**
	 * @param int[] $postIds
	 * @return array<int, int[]>
	 */
	private function fetchCommentsByPost( array $postIds ) {
		global $wpdb;
		if ( empty( $postIds ) ) {
			return array();
		}
		$inList = implode( ',', array_map( 'intval', $postIds ) );
		$rows   = $wpdb->get_results(
			"SELECT comment_post_ID, comment_ID
			 FROM {$wpdb->comments}
			 WHERE comment_post_ID IN ($inList)
			 ORDER BY comment_post_ID ASC, comment_ID ASC",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['comment_post_ID'] ][] = (int) $r['comment_ID'];
		}
		return $out;
	}

	/**
	 * @param array $row
	 * @param array $metaByPost
	 * @param array $termsByPost
	 * @param array $commentsByPost
	 * @return array
	 */
	private function buildPostData( array $row, array $metaByPost, array $termsByPost, array $commentsByPost ) {
		$postId = (int) $row['ID'];

		$data = array(
			'ID'                    => $postId,
			'comment_count'         => (int) $row['comment_count'],
			'comment_status'        => (string) $row['comment_status'],
			'comments'              => isset( $commentsByPost[ $postId ] ) ? $commentsByPost[ $postId ] : array(),
			'guid'                  => (string) $row['guid'],
			'menu_order'            => (int) $row['menu_order'],
			'meta'                  => isset( $metaByPost[ $postId ] ) ? $metaByPost[ $postId ] : new \stdClass(),
			'ping_status'           => (string) $row['ping_status'],
			'pinged'                => (string) $row['pinged'],
			'post_author'           => (int) $row['post_author'],
			'post_content'          => (string) $row['post_content'],
			'post_content_filtered' => (string) $row['post_content_filtered'],
			'post_date'             => (string) $row['post_date'],
			'post_date_gmt'         => (string) $row['post_date_gmt'],
			'post_excerpt'          => (string) $row['post_excerpt'],
			'post_mime_type'        => (string) $row['post_mime_type'],
			'post_modified'         => (string) $row['post_modified'],
			'post_modified_gmt'     => (string) $row['post_modified_gmt'],
			'post_name'             => (string) $row['post_name'],
			'post_parent'           => (int) $row['post_parent'],
			'post_password'         => (string) $row['post_password'],
			'post_status'           => (string) $row['post_status'],
			'post_title'            => (string) $row['post_title'],
			'post_type'             => (string) $row['post_type'],
			'terms'                 => isset( $termsByPost[ $postId ] ) ? $termsByPost[ $postId ] : new \stdClass(),
			'to_ping'               => (string) $row['to_ping'],
		);

		if ( 'attachment' === $row['post_type'] ) {
			// Capture verbatim resolved URL at export time.
			$url = wp_get_attachment_url( $postId );
			$data['attachment_url'] = false === $url ? '' : (string) $url;
		}

		// meta and terms must serialize as JSON objects even when empty. Convert empties
		// from arrays/objects to stdClass for clarity (json_encode renders {} for stdClass).
		if ( is_array( $data['meta'] ) && empty( $data['meta'] ) ) {
			$data['meta'] = new \stdClass();
		}
		if ( is_array( $data['terms'] ) && empty( $data['terms'] ) ) {
			$data['terms'] = new \stdClass();
		}

		return $data;
	}
}
