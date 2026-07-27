# WP Idempotent Export

[![Tests](https://github.com/darylldoyle/idempotent-export/actions/workflows/tests.yml/badge.svg)](https://github.com/darylldoyle/idempotent-export/actions/workflows/tests.yml)

A WP-CLI command that exports a single WordPress site as a deterministic
directory tree of JSON files, designed to be re-imported into another
WordPress site **without** wiping the destination between runs.

Re-running the command against an unchanged source produces a
byte-identical output tree (modulo a single timestamp in the manifest), so
the export can be checked into version control and diff-reviewed.

```
wp idempotent-export /path/to/snapshot
```

## What you get

```
snapshot/
├── manifest.json
├── errors.log
├── options.json
├── posts/2024/03/12345.json
├── terms/category/4.json
├── users/42.json
└── comments/2024/04/991.json
```

- One JSON file per entity. UTF-8, sorted keys, two-space indent.
- Source WordPress IDs are preserved as canonical identifiers — the importer
  is responsible for ID reissuing and reference rewriting.
- The manifest records the source URL, WP version, table AUTO_INCREMENT
  values, counts, applied filters and any skipped entities.

## What's exported

| Entity     | Source tables                                                  | File path                                 |
|------------|----------------------------------------------------------------|-------------------------------------------|
| Posts      | `wp_posts`, `wp_postmeta`, `wp_term_relationships`             | `posts/{YYYY}/{MM}/{ID}.json`             |
| Terms      | `wp_terms`, `wp_term_taxonomy`, `wp_termmeta`                  | `terms/{taxonomy}/{term_taxonomy_id}.json` |
| Users      | `wp_users`, `wp_usermeta`                                      | `users/{ID}.json`                         |
| Comments   | `wp_comments`, `wp_commentmeta`                                | `comments/{YYYY}/{MM}/{ID}.json`          |
| Options    | `wp_options` (transients excluded)                             | `options.json`                            |
| Attachments| `wp_posts` (`post_type=attachment`) plus resolved URL          | `posts/{YYYY}/{MM}/{ID}.json`             |

Binary media is **not** copied — only the URLs. Password hashes,
session tokens and application passwords are stripped from user exports.

For the rationale behind each design decision, see the
[PRD](docs/PRD.md) — this README is a summary, the PRD is the source of
truth.

## Install

The exporter is distributed as a WP-CLI package / WordPress plugin.

```
git clone https://github.com/darylldoyle/idempotent-export.git \
    wp-content/plugins/idempotent-export
wp plugin activate idempotent-export
```

Or include it as a Composer dependency in a larger plugin/mu-plugin
bundle. The plugin only registers itself when `WP_CLI` is loaded, so it
has no front-end cost.

PHP 7.4+ for the runtime. PHP 8.2+ for the test suite.

## Quickstart

```bash
# Full export of the current site.
wp idempotent-export /tmp/snapshot

# Restrict to a subset of post types.
wp idempotent-export /tmp/snapshot --post-type=post,page

# Export only content modified in 2024.
wp idempotent-export /tmp/snapshot \
    --since=2024-01-01 --until=2025-01-01

# Dry run: print counts and a sample of each entity type.
wp idempotent-export /tmp/snapshot --dry-run

# Multisite: blog-id is required.
wp idempotent-export /tmp/snapshot --blog-id=5
```

A clean run exits zero. Any per-entity skips produce a non-zero exit,
and the affected entities are listed both in `manifest.skipped` and in
the `errors.log` next to it.

## Documentation

- [`docs/USAGE.md`](docs/USAGE.md) — every flag, exit codes, recipes.
- [`docs/OUTPUT.md`](docs/OUTPUT.md) — output format, per-entity JSON
  shapes, manifest schema, examples.
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — how the exporter is
  put together internally, the idempotence model, where to extend.
- [`docs/TESTING.md`](docs/TESTING.md) — running the Pest test suite,
  layout, the FakeWpdb test double.
- [`docs/PRD.md`](docs/PRD.md) — the original product requirements
  document this implementation tracks.

## Non-goals

- Importing data. A separate project owns that.
- Migrating binary media. URLs only.
- Network-wide multisite export. One site at a time.
- Incremental re-export. Always full.
- Exporting plugin custom tables (HPOS orders, Gravity Forms entries,
  BuddyPress, etc.). A pluggable hook for third-party table exporters
  is on the roadmap.

## License

MIT.
