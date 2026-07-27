<?php

namespace IdempotentExport\Exporter;

class Users extends AbstractExporter {

	/**
	 * Meta keys that are stripped from export. Password hashes and session/app
	 * password material are operator-irrelevant on the destination.
	 *
	 * @var string[]
	 */
	private $strippedMeta = array(
		'session_tokens',
		'_application_passwords',
	);

	public function run() {
		global $wpdb;

		// On multisite, users are network-global but roles are per-blog. Export only
		// the members of the blog being exported, with their role for THIS blog
		// canonicalised to wp_capabilities (see canonicalizeRoleMeta).
		$multisite = is_multisite();
		$capKey    = $multisite ? $wpdb->get_blog_prefix() . 'capabilities' : '';

		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$bar    = $this->progress( 'Users', $total );
		$lastId = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
					$lastId,
					$this->batchSize
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			$userIds = array();
			foreach ( $rows as $r ) {
				$userIds[] = (int) $r['ID'];
			}
			$metaByUser = $this->fetchMetaByUser( $userIds );

			foreach ( $rows as $row ) {
				$bar->tick();
				$userId = (int) $row['ID'];
				$lastId = $userId;

				$meta = isset( $metaByUser[ $userId ] ) ? $metaByUser[ $userId ] : array();
				if ( $multisite && ! isset( $meta[ $capKey ] ) ) {
					// Not a member of the exported blog; they belong to another site's snapshot.
					continue;
				}
				$meta = $this->canonicalizeRoleMeta( $meta );

				$row = $this->unslashRow( $row );

				$data = array(
					'ID'                  => $userId,
					'display_name'        => (string) $row['display_name'],
					'meta'                => $meta ? $meta : new \stdClass(),
					'user_activation_key' => (string) $row['user_activation_key'],
					'user_email'          => (string) $row['user_email'],
					'user_login'          => (string) $row['user_login'],
					'user_nicename'       => (string) $row['user_nicename'],
					'user_registered'     => (string) $row['user_registered'],
					'user_status'         => (int) $row['user_status'],
					'user_url'            => (string) $row['user_url'],
				);

				$path = "users/{$userId}.json";

				$ok = $this->writer->write( 'user', $path, $data );
				if ( ! $ok ) {
					$this->logger->skip( 'user', $userId, 'JSON encode failed' );
					continue;
				}
				$this->manifest->bumpCount( 'users' );
			}

			$this->interBatchSleep();
		}

		$bar->finish();
	}

	/**
	 * On multisite, collapse the per-blog capability/level keys to canonical
	 * wp_capabilities / wp_user_level for the blog being exported, and drop any
	 * other blog's role keys (they belong to those sites' own snapshots). No-op on
	 * single-site, where the keys are already canonical.
	 *
	 * @param array $meta
	 * @return array
	 */
	private function canonicalizeRoleMeta( array $meta ) {
		global $wpdb;
		if ( ! is_multisite() ) {
			return $meta;
		}
		$capKey  = $wpdb->get_blog_prefix() . 'capabilities';
		$lvlKey  = $wpdb->get_blog_prefix() . 'user_level';
		$pattern = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+_)?(capabilities|user_level)$/';
		$out     = array();
		foreach ( $meta as $key => $values ) {
			if ( preg_match( $pattern, $key ) ) {
				if ( $key === $capKey ) {
					$out['wp_capabilities'] = $values;
				} elseif ( $key === $lvlKey ) {
					$out['wp_user_level'] = $values;
				}
				continue;
			}
			$out[ $key ] = $values;
		}
		return $out;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, array<string, array<int, mixed>>>
	 */
	private function fetchMetaByUser( array $userIds ) {
		global $wpdb;
		if ( empty( $userIds ) ) {
			return array();
		}
		$inList = implode( ',', array_map( 'intval', $userIds ) );
		$rows   = $wpdb->get_results(
			"SELECT user_id, umeta_id, meta_key, meta_value
			 FROM {$wpdb->usermeta}
			 WHERE user_id IN ($inList)
			 ORDER BY user_id ASC, meta_key ASC, umeta_id ASC",
			ARRAY_A
		);
		$out    = array();
		foreach ( (array) $rows as $r ) {
			$key = (string) $r['meta_key'];
			if ( in_array( $key, $this->strippedMeta, true ) ) {
				continue;
			}
			$uid                   = (int) $r['user_id'];
			$out[ $uid ][ $key ][] = $this->encoder->decodeStored( 'user', $uid, $key, (string) $r['meta_value'] );
		}
		return $out;
	}
}
