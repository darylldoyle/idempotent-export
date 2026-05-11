# WP-to-WP Idempotent Export — PRD

The original product requirements document this implementation tracks.
Kept verbatim as the source of truth for design decisions; see
[`OUTPUT.md`](OUTPUT.md), [`USAGE.md`](USAGE.md) and
[`ARCHITECTURE.md`](ARCHITECTURE.md) for the implemented surface.

## Purpose

Produce a deterministic, complete snapshot of a single WordPress site as
a directory tree of JSON files, suitable for repeated import into
another WordPress site without DB resets between runs. Source WP IDs are
preserved as canonical identifiers. The importer is responsible for ID
reissuing and reference rewriting.

This PRD covers the export script only. Import is a separate project.

## Goals

- Idempotent. Re-running on an unchanged source produces byte-identical
  output, except for the manifest's `exported_at` field.
- Complete enough that a clean import faithfully reproduces source
  content, excluding binary media and authentication state.
- Operable on sites up to ~2M posts without falling over. Typical
  target is under 500k.
- WP-CLI native. Generic WordPress compatible, with VIP-aware behaviour
  where it matters.

## Non-goals

- Importing data.
- Migrating binary media (images, video, audio). URLs only.
- Network-wide multisite export. Single site at a time.
- Incremental re-export. Always full.
- Reference detection and emission. Owned by the importer.
- Secret stripping in options. Operator-trusted, verbatim export.

## Assumptions

These are baked into the design. Sites that violate them will export
incompletely without erroring.

- **Core schema only.** We export `wp_posts`, `wp_postmeta`, `wp_terms`,
  `wp_term_taxonomy`, `wp_term_relationships`, `wp_termmeta`,
  `wp_users`, `wp_usermeta`, `wp_comments`, `wp_commentmeta`,
  `wp_options`. Plugin custom tables are not exported. Notable
  casualties: WooCommerce HPOS orders (`wp_wc_orders` and friends),
  Gravity Forms entries, BuddyPress, bbPress, ActivityPub, WPML and
  Polylang translation linkage tables. WPML postmeta survives but the
  language relationships do not. Custom-table support is a follow-up
  project, ideally via a pluggable third-party-table exporter hook.
- **Database engine is MySQL or MariaDB.** Auto-increment retrieval is
  engine-specific.
- **Single language per site.** Multi-language sites work but their
  language relationships need a separate exporter.
- **Source schema is stable for the duration of the run.** No DDL
  during export.
- **Live source content can change during the run.** A 2M-post export
  can take hours. Posts may be created, edited, or deleted while we
  walk the tables. The importer must tolerate dangling references
  caused by mid-run mutations. Operators wanting strict consistency
  should put the source in maintenance mode for the run.
- **Custom DB collations are not preserved.** The importer recreates
  rows on whatever the destination's collation is. Usually a no-op,
  occasionally affects search ranking and case-insensitive comparisons.
- **`wp_get_attachment_url()` is captured verbatim.** Garbage in,
  garbage out. Sites mid-migration with broken URLs export those broken
  URLs.

## Entities exported

### Posts

All post types, registered or not. Source the type list from the
database:

```sql
SELECT DISTINCT post_type FROM {$wpdb->posts}
```

This catches orphaned content from deactivated plugins.

- All post statuses, including `private`, `pending`, `draft`, `trash`,
  `auto-draft`.
- Password-protected posts retain their `post_password` value.
- Revisions excluded by default. Opt in via `--include-revisions`.
- Per post: full row from `wp_posts`, all postmeta, taxonomy term
  assignments as term IDs, attached comment IDs.
- Block-theme navigation (`wp_navigation` post type) is captured here
  as a regular post. Classic menus (`nav_menu` taxonomy and
  `nav_menu_item` posts) are out of scope and excluded by post-type and
  taxonomy filter.

### Terms

All taxonomies, registered or not. Source the list from the database:

```sql
SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}
```

Full term row (from `wp_terms`), term_taxonomy row (from
`wp_term_taxonomy`), all termmeta. Hierarchy preserved via parent IDs
(which reference `term_id` per the source schema).

**`term_id` vs `term_taxonomy_id`.** WordPress has both. `term_id`
identifies the term itself. `term_taxonomy_id` identifies the term's
use within a specific taxonomy. A shared term across two taxonomies has
one `term_id` and two `term_taxonomy_id` rows. `wp_term_relationships`
joins posts to `term_taxonomy_id`.

The export uses `term_taxonomy_id` as the canonical key:

- Term filenames are `terms/{taxonomy}/{term_taxonomy_id}.json`.
- A post's `terms` map references entries by `term_taxonomy_id`.
- The term file itself includes both `term_id` and `term_taxonomy_id`
  so the importer can reconstruct shared terms across taxonomies if
  needed.

### Attachments

Treated as posts with `post_type = 'attachment'`, plus extra fields:

- `guid`
- `_wp_attached_file` meta
- Resolved `wp_get_attachment_url()` value at export time
- `_wp_attachment_metadata` (sizes, dimensions, MIME)

No binary transfer.

### Users

All users.

- Password hashes stripped. Importer forces resets.
- `session_tokens` user meta stripped.
- Application passwords stripped.
- All other user meta preserved verbatim.

### Comments

All statuses: approved, pending, spam, trash. Full row plus all comment
meta.

### Options

All `wp_options` rows except transients (`option_name` matching
`_transient_%` or `_site_transient_%`).

Each row exports the full triple: `option_name`, `option_value`,
`autoload`. The autoload flag is part of the export, so importers can
preserve it.

No other stripping. API keys, OAuth tokens, SMTP credentials, license
keys, encryption salts all export verbatim. Operator's responsibility.

The `cron` option exports verbatim and will resurrect every scheduled
event on import. Importer's problem to solve, but worth knowing.

On multisite, sitemeta for the chosen blog is included. Network-level
options are excluded.

## Output

### Directory layout

```
{output-dir}/
  manifest.json
  errors.log
  posts/{YYYY}/{MM}/{ID}.json
  terms/{taxonomy}/{ID}.json
  users/{ID}.json
  comments/{YYYY}/{MM}/{ID}.json
  options.json
```

Posts and comments shard by year and month from their `*_date_gmt`
columns. Keeps directory sizes manageable at 2M scale while keeping one
file per entity, which preserves the git-diff workflow.

### File format

- One JSON file per entity. UTF-8.
- Sorted keys. Two-space indent.
- Source WP IDs are canonical. Filenames match the ID.

### Meta shape

A single `meta_key` can have multiple rows in `wp_postmeta`,
`wp_usermeta`, and `wp_commentmeta`. The export always represents meta
as `{ "key": [value, value, ...] }`, even when only one value exists.
Single-valued and multi-valued cases are represented identically. The
importer never has to guess.

### Slashing

`$wpdb` returns content as stored, which on WordPress means slashed.
`get_post()` and friends unslash on read. We standardise on raw `$wpdb`
reads then `wp_unslash()` once before JSON-encoding. This guarantees
content does not gain or lose backslashes across re-export cycles.

### Encoding

`json_encode` is called with `JSON_UNESCAPED_UNICODE |
JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE |
JSON_THROW_ON_ERROR`.

- Substitution handles legacy pre-utf8mb4 sites that carry invalid byte
  sequences in `post_content`. The substituted character is logged as a
  warning per affected entity. Lossy but recoverable.
- Throwing on error means a serialisation failure for any other reason
  routes through the standard skip handler instead of silently
  producing `false` and a corrupt file.

### manifest.json

```json
{
  "schema_version": "1.0.0",
  "exported_at": "2026-05-10T12:00:00Z",
  "source": {
    "site_url": "https://example.com",
    "wp_version": "6.9",
    "is_multisite": true,
    "blog_id": 5,
    "auto_increment": {
      "posts": 4823901,
      "terms": 12453,
      "users": 8821,
      "comments": 192341
    }
  },
  "counts": {
    "posts": 184221,
    "terms": 9412,
    "users": 4821,
    "comments": 88421,
    "options": 412
  },
  "filters_applied": {
    "post_types": null,
    "since": null,
    "until": null,
    "include_revisions": false
  },
  "skipped": []
}
```

`auto_increment` lets the importer detect ID-collision risk across
multiple export runs against the same source over time.

### Serialized values

PHP-serialized values in postmeta, usermeta, commentmeta, and options
are unserialized and re-encoded as JSON.

- Plain arrays round-trip cleanly.
- Objects are cast to associative arrays. Each cast logs a warning with
  entity type, entity ID, and meta key.
- Values that fail to unserialize are kept as the raw string and warned.

### Example: a post file

`posts/2024/03/12345.json`:

```json
{
  "ID": 12345,
  "comment_count": 3,
  "comment_status": "open",
  "comments": [991, 992, 1003],
  "guid": "https://example.com/?p=12345",
  "menu_order": 0,
  "meta": {
    "_edit_last": ["42"],
    "_thumbnail_id": ["8821"],
    "_yoast_wpseo_focuskw": ["idempotent migration"],
    "custom_related_posts": [
      {
        "items": [201, 305, 419],
        "layout": "grid"
      }
    ],
    "fueled_review_status": ["approved", "second_pass"]
  },
  "ping_status": "open",
  "pinged": "",
  "post_author": 42,
  "post_content": "<!-- wp:paragraph -->\n<p>Hello world.</p>\n<!-- /wp:paragraph -->",
  "post_content_filtered": "",
  "post_date": "2024-03-15 10:30:00",
  "post_date_gmt": "2024-03-15 10:30:00",
  "post_excerpt": "",
  "post_mime_type": "",
  "post_modified": "2024-03-16 09:12:00",
  "post_modified_gmt": "2024-03-16 09:12:00",
  "post_name": "example-post",
  "post_parent": 0,
  "post_password": "",
  "post_status": "publish",
  "post_title": "Example post",
  "post_type": "post",
  "terms": {
    "category": [4, 17],
    "post_tag": [231, 458]
  },
  "to_ping": ""
}
```

Things worth noting in this example:

- Top-level keys are alphabetically sorted, including `ID` (uppercase,
  as it appears in `wp_posts`).
- Every `meta` value is an array. `_thumbnail_id` is single-valued;
  `fueled_review_status` is multi-valued. Same shape.
- `custom_related_posts` was a PHP-serialized array on the source. It's
  been unserialized and re-encoded as JSON. Wrapped in a single-element
  array because it's a single meta row.
- `terms` keys are taxonomy slugs. Values are sorted arrays of
  `term_taxonomy_id` (not `term_id`).
- `comments` is a sorted array of comment IDs attached to this post.
  The actual comment bodies live in their own files under `comments/`.
- `_thumbnail_id` is `"8821"` as a string, not `8821` as an integer.
  Postmeta values are always strings on the source. We preserve that.
  The importer can coerce per known-numeric meta keys if it wants.

## CLI

```
wp idempotent-export <output-dir> [flags]
```

Flags:

- `--force`: overwrite an existing non-empty output dir.
- `--dry-run`: print counts and the first entity of each type, no writes.
- `--post-type=<csv>`: restrict to specified post types.
- `--since=<date>`: only entities with `*_date_gmt` on or after this
  date. ISO 8601 or `YYYY-MM-DD`.
- `--until=<date>`: only entities with `*_date_gmt` before this date.
- `--include-revisions`: include the `revision` post type.
- `--blog-id=<id>`: required on multisite. Errors out if missing.
- `--batch-size=<n>`: query batch size. Default 500.
- `--quiet`: suppress progress bars.

Default behaviour refuses to write into a non-empty output dir.
`--force` is required to override.

## Operational behaviour

### Failure handling

Per-entity failures (corrupt meta, encoding issues, unreadable rows)
skip the entity, log a warning, and the export continues. Each skip
adds an entry to `manifest.skipped` with entity type, ID, and reason.
The same entries are written to `errors.log` next to `manifest.json` in
the output dir, one per line, for easier grep and tail during long runs.

A clean run exits zero. Any skips produce a non-zero exit. The full
clean run is the idempotency guarantee.

`--dry-run` always exits zero, even if it would have produced skips on
a real run. Dry-run is not an export.

### Progress

WP-CLI progress bars per entity type. Final summary lists counts
written, counts skipped, total duration, total output size.

### Determinism

- Sorted JSON keys throughout.
- All timestamps emitted as UTC, sourced from `*_date_gmt` columns
  where available.
- Repeat runs produce byte-identical files except `manifest.exported_at`.

Specific ordering rules:

- Options sorted by `option_name`.
- Postmeta, usermeta, commentmeta sorted by `meta_key`, then by
  `meta_id` within a key (preserves insertion order for multi-value
  meta).
- Term assignments on a post sorted by `term_taxonomy_id`. Plugins
  relying on `wp_term_relationships` insertion order will lose that
  order. No known core or major-plugin reliance on it.
- Per-post comment ID lists sorted ascending.

### Performance and safety

- Streamed paginated queries. `WP_Query` with `no_found_rows => true`,
  `update_post_meta_cache => false`, `update_post_term_cache => false`.
  Meta and term assignments fetched in separate batched queries.
- `wp_suspend_cache_addition(true)` during the run.
- Target memory ceiling: under 512MB resident regardless of site size.

VIP specifics:

- Output dir is validated against VIP-writable paths. Defaults route
  into `wp-content/uploads/private/` if no path is given on a VIP
  environment.
- Configurable inter-batch sleep. Default 0ms, recommended 50-100ms on
  VIP.
- Honours `VIP_GO_APP_ENVIRONMENT` for environment-aware logging only.
  No behavioural gating.

## Open questions

None outstanding.
