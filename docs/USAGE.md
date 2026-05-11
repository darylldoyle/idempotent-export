# CLI usage

```
wp idempotent-export <output-dir> [flags]
```

`<output-dir>` is **required** on every environment except VIP. On VIP,
omitting it defaults to
`wp-content/uploads/private/idempotent-export-<UTC-timestamp>/`.

The command refuses to write into an existing non-empty directory unless
`--force` is set, so accidental overwrite of a previous export takes a
deliberate keystroke.

## Flags

| Flag                          | Default | Description |
|-------------------------------|---------|-------------|
| `--force`                     | off     | Overwrite a non-empty output dir. Existing files are not deleted up front — the run rewrites every file it owns and leaves anything else alone. |
| `--dry-run`                   | off     | Walk the source and print counts and the first entity of each type to stdout. No files written. Always exits zero. |
| `--post-type=<csv>`           | all     | Restrict to a comma-separated allowlist of post types. Discovery is from the database (`SELECT DISTINCT post_type`), so values can include types whose plugins are deactivated. |
| `--since=<date>`              | none    | Only entities whose `*_date_gmt` is on or after this date. ISO 8601 or `YYYY-MM-DD`. Applies to posts and comments. |
| `--until=<date>`              | none    | Only entities whose `*_date_gmt` is strictly before this date. ISO 8601 or `YYYY-MM-DD`. Applies to posts and comments. |
| `--include-revisions`         | off     | Include the `revision` post type. Off by default to keep exports manageable. |
| `--blog-id=<id>`              | none    | Required on multisite; ignored on single-site. The blog to export. |
| `--batch-size=<n>`            | 500     | Query batch size for paginated reads. Lower if individual entities carry huge meta values. |
| `--inter-batch-sleep-ms=<ms>` | 0       | Sleep between batches in milliseconds. Recommend 50–100 on VIP to avoid throttling. |
| `--quiet`                     | off     | Suppress progress bars. Final summary and errors still printed. |

## Exit codes

| Code | Meaning |
|------|---------|
| 0    | Clean run, or any `--dry-run`. |
| 1    | One or more entities were skipped (see `manifest.skipped` and `errors.log`). |
| ≥2   | Fatal preconditions failed: invalid args, unwritable target, multisite without `--blog-id`. WP-CLI raises these via `WP_CLI::error`. |

## Recipes

### A reproducible snapshot for a content audit

```bash
wp idempotent-export /tmp/snapshot --post-type=post,page --quiet
```

Two-space-indented JSON files with sorted keys → easy to diff against a
previous snapshot to see exactly what changed.

### A 2024-only export

```bash
wp idempotent-export /tmp/snapshot \
    --since=2024-01-01 --until=2025-01-01
```

Filters apply to `post_date_gmt` and `comment_date_gmt`. Terms, users
and options are exported in full regardless — they do not carry a
`_date_gmt` column.

### A VIP-friendly export with throttling

```bash
wp idempotent-export --inter-batch-sleep-ms=80 --batch-size=200
```

On VIP, the output dir is optional and defaults under
`wp-content/uploads/private/`. The run is also restricted to writable
VIP paths; supplying a path outside `wp-content/uploads/` errors out.

### A multisite per-blog export

```bash
for blog_id in $(wp site list --field=blog_id); do
    wp idempotent-export "/tmp/snapshot-${blog_id}" --blog-id="${blog_id}"
done
```

The exporter switches to the requested blog via `switch_to_blog()` and
restores the previous context on exit.

### Inspecting before committing

```bash
wp idempotent-export /tmp/snapshot --dry-run
```

Prints counts per entity type and the first entity of each type as
formatted JSON. Useful for verifying filters and sampling output before
running the full export.

## Behaviour notes

- **Live source content can change during the run.** A 2M-post export
  can take hours, and posts may be created, edited or deleted while the
  exporter walks the tables. The importer must tolerate dangling
  references (e.g. a comment whose `comment_post_ID` points at a post
  that was deleted mid-run). For strict consistency, put the source in
  maintenance mode for the duration of the run.
- **Custom DB collations are not preserved.** The importer recreates
  rows on whatever the destination's collation is.
- **`wp_get_attachment_url()` is captured verbatim** at export time.
  Sites mid-migration with broken URLs export those broken URLs.
- **The `cron` option is exported verbatim** and will resurrect every
  scheduled event on import. That's the importer's problem to handle,
  but worth knowing.
- **Per-entity failures skip the entity, log a warning, and the export
  continues.** A run with skips exits non-zero — the clean-zero run is
  the idempotency guarantee.
