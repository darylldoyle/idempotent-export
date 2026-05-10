<?php

namespace IdempotentExport;

class Command {

	/**
	 * Export a WordPress site to a deterministic tree of JSON files.
	 *
	 * ## OPTIONS
	 *
	 * [<output-dir>]
	 * : Directory to write JSON into. Required, except on VIP where it defaults to
	 *   wp-content/uploads/private/idempotent-export-<timestamp>/.
	 *
	 * [--force]
	 * : Overwrite an existing non-empty output dir.
	 *
	 * [--dry-run]
	 * : Print counts and the first entity of each type, no writes. Always exits zero.
	 *
	 * [--post-type=<csv>]
	 * : Comma-separated list of post types to include. Default: all types discovered in the DB.
	 *
	 * [--since=<date>]
	 * : Only entities with *_date_gmt on or after this date (ISO 8601 or YYYY-MM-DD).
	 *
	 * [--until=<date>]
	 * : Only entities with *_date_gmt strictly before this date.
	 *
	 * [--include-revisions]
	 * : Include the 'revision' post type. Off by default.
	 *
	 * [--blog-id=<id>]
	 * : Required on multisite. The blog to export.
	 *
	 * [--batch-size=<n>]
	 * : Query batch size. Default 500.
	 *
	 * [--inter-batch-sleep-ms=<ms>]
	 * : Sleep between batched queries. Default 0. Recommend 50-100 on VIP.
	 *
	 * [--quiet]
	 * : Suppress progress bars.
	 *
	 * ## EXAMPLES
	 *
	 *     wp idempotent-export /tmp/snapshot
	 *     wp idempotent-export /tmp/snapshot --force --post-type=post,page
	 *     wp idempotent-export /tmp/snapshot --since=2024-01-01 --batch-size=1000
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments / flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		( new Run() )->execute( $args, $assoc_args );
	}
}
