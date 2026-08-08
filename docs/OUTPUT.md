# Output format

## Directory layout

```
{output-dir}/
├── manifest.json
├── errors.log
├── options.json
├── posts/
│   └── {YYYY}/{MM}/{ID}.json
├── terms/
│   └── {taxonomy}/{term_taxonomy_id}.json
├── users/
│   └── {ID}.json
└── comments/
    └── {YYYY}/{MM}/{ID}.json
```

- Posts and comments shard by year and month from their `*_date_gmt`
  columns. Keeps directory sizes manageable at 2M-post scale while
  preserving one-file-per-entity, which preserves the git-diff workflow.
- Source WordPress IDs are used as filenames.
- Terms use `term_taxonomy_id` (not `term_id`) as the canonical key,
  because a single `term_id` can appear in multiple taxonomies. See
  [the terms section](#terms) below.

## File format

- UTF-8 JSON.
- Sorted object keys (case-sensitive ASCII order). List ordering is
  preserved.
- Two-space indent.
- One trailing newline.
- Encoded with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
  JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR`.

### Slashing

Strings are written exactly as `$wpdb` returns them.

Slashing is WordPress's convention for data on its way *into*
`wp_insert_post()` and friends, which unslash it before storing — so what
comes back out of the database is already unslashed. Unslashing it again
on the way to the snapshot would strip real backslashes out of regexes,
Windows paths and escaped JSON. The importer re-slashes on the way in,
because that is what the write APIs expect.

### Meta shape

A single `meta_key` can appear in multiple rows. Meta is always emitted
as `{ key: [value, value, ...] }`, even when only one value exists. The
importer never has to guess whether a meta field is single- or
multi-valued.

```json
{
  "meta": {
    "_thumbnail_id": ["8821"],
    "fueled_review_status": ["approved", "second_pass"]
  }
}
```

Multi-value ordering is by `meta_id`, which preserves WordPress's
insertion order.

### Serialised values

PHP-serialised **containers** stored in `*_meta` or `wp_options` are
unserialised and re-encoded as JSON:

- Plain arrays round-trip cleanly.
- Objects are cast to associative arrays. Each cast logs a warning with
  entity type, entity ID and meta key.
- `unserialize()` is called with `allowed_classes => false`, so unknown
  classes never get instantiated. The class name survives in the
  `__PHP_Incomplete_Class_Name` key after the cast.
- Values that fail to unserialize are kept as the raw string, with a
  warning.
- So are values JSON cannot represent — nesting past 500 levels, or a
  non-finite float. Encoding one would fail and take its whole entity out
  of the export; degrading the single value keeps the entity.

A serialised **scalar** (`b:0;`, `i:0;`, `d:1.5;`) is left as its stored
string. WordPress re-serialises arrays and objects on write but not
scalars, so unwrapping one here would change what the destination stores:
`b:0;` would arrive as `""` and `d:1.5;` as `"1.5"`, losing the type.

### Empty objects vs empty arrays

A post with no meta emits `"meta": {}`, not `"meta": []`. The empty
object marks the field's *shape* — a key/value map that happens to be
empty — so the importer doesn't have to special-case empty inputs.

## manifest.json

```json
{
  "counts": {
    "comments": 88421,
    "options":  412,
    "posts":    184221,
    "terms":    9412,
    "users":    4821
  },
  "exported_at": "2026-05-10T12:00:00Z",
  "filters_applied": {
    "include_revisions": false,
    "post_types":        null,
    "since":             null,
    "until":             null
  },
  "schema_version": "1.0.0",
  "skipped": [],
  "source": {
    "auto_increment": {
      "comments":      192341,
      "posts":         4823901,
      "term_taxonomy": 12461,
      "terms":         12453,
      "users":         8821
    },
    "blog_id":      5,
    "is_multisite": true,
    "site_url":     "https://example.com",
    "wp_version":   "6.9"
  }
}
```

- `exported_at` is the **only** non-deterministic field. Re-runs over
  unchanged data produce byte-identical output everywhere else.
- `source.auto_increment` snapshots the source's per-table
  `AUTO_INCREMENT` values from `information_schema.tables`. Importers
  can detect ID-collision risk across multiple export runs against the
  same source over time, and an importer preserving source IDs raises the
  destination's counters to these values so new content cannot reuse a
  migrated ID. `terms` and `term_taxonomy` are separate sequences and both
  are recorded.
- `skipped` is a sorted (by type, then id) list of `{type, id, reason}`
  entries for any entity that couldn't be written.

## errors.log

One line per warning or skip, written in insertion order. Pipe / tab
separated for grep-and-tail friendliness during long runs.

```
warn	type=post	id=12345	invalid utf8 in post_content
warn	type=post	id=12345	key=settings: object cast to array
skip	type=user	id=42	JSON encode failed
```

A clean run writes an empty `errors.log`. The file is always created.

## Per-entity JSON

### Posts

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

- Every `wp_posts` column is present, plus the derived `meta`, `terms`
  and `comments` fields.
- `terms` keys are taxonomy slugs. Values are arrays of
  `term_taxonomy_id` sorted ascending — *not* `term_id`, because the
  importer needs to know which (taxonomy, term) pair the post is in.
- `comments` is a sorted list of every comment ID attached to the post,
  across every status (approved, pending, spam, trash). Comment bodies
  live in their own files.
- Integer columns (`ID`, `post_author`, `post_parent`, `menu_order`,
  `comment_count`) are emitted as JSON numbers. Other columns stay as
  strings — so `_thumbnail_id` is `"8821"`, not `8821`.

Attachments (`post_type=attachment`) carry one additional top-level key:

```json
{
  "attachment_url": "https://cdn.example.com/path/to/file.jpg"
}
```

This is `wp_get_attachment_url($id)` resolved at export time, captured
verbatim. No binary is copied.

### Terms

```json
{
  "count": 17,
  "description": "",
  "meta": {
    "order": ["3"]
  },
  "name": "Engineering",
  "parent": 4,
  "slug": "engineering",
  "taxonomy": "category",
  "term_group": 0,
  "term_id": 8,
  "term_taxonomy_id": 17
}
```

- The filename is the `term_taxonomy_id`.
- Both `term_id` and `term_taxonomy_id` are emitted, so the importer
  can reconstruct shared terms across taxonomies if needed.
- `parent` is a `term_id`, per the source schema.

The `nav_menu` taxonomy is **always excluded** — classic menus are out
of scope. Block-theme navigation (`wp_navigation` post type) is exported
as a regular post.

### Users

```json
{
  "ID": 42,
  "display_name": "Alice Admin",
  "meta": {
    "nickname": ["alice"],
    "wp_capabilities": [{"administrator": true}]
  },
  "user_activation_key": "",
  "user_email": "alice@example.com",
  "user_login": "alice",
  "user_nicename": "alice",
  "user_registered": "2018-07-04 13:00:00",
  "user_status": 0,
  "user_url": ""
}
```

- `user_pass` is **stripped** — the importer is expected to force
  resets.
- `session_tokens` and `_application_passwords` user meta are
  **stripped**.
- Everything else is verbatim.

### Comments

```json
{
  "comment_ID": 991,
  "comment_agent": "Mozilla/5.0 ...",
  "comment_approved": "1",
  "comment_author": "Bob",
  "comment_author_IP": "127.0.0.1",
  "comment_author_email": "bob@example.com",
  "comment_author_url": "",
  "comment_content": "Nice post.",
  "comment_date": "2024-04-01 12:00:00",
  "comment_date_gmt": "2024-04-01 12:00:00",
  "comment_karma": 0,
  "comment_parent": 0,
  "comment_post_ID": 12345,
  "comment_type": "comment",
  "meta": {
    "rating": ["5"]
  },
  "user_id": 0
}
```

Every status is exported: approved, pending, spam, trash. Filter via
`--since` / `--until` if you only need a slice.

### Options

A single `options.json`, keyed by `option_name`:

```json
{
  "active_plugins": {
    "autoload": "yes",
    "value": ["akismet/akismet.php", "jetpack/jetpack.php"]
  },
  "blogname": {
    "autoload": "yes",
    "value": "Example"
  },
  "theme_mods": {
    "autoload": "yes",
    "value": {
      "stylesheet": "twentytwentyfour",
      "template": "twentytwentyfour"
    }
  }
}
```

- Transient rows (`option_name LIKE '_transient_%'` or `_site_transient_%`)
  are excluded.
- `autoload` is preserved, so the importer can recreate the flag
  exactly.
- API keys, OAuth tokens, SMTP credentials, license keys and encryption
  salts are exported **verbatim**. The exporter is operator-trusted; if
  you need redaction, do it before importing.

## Determinism rules

The exporter guarantees byte-identical output for unchanged source data,
modulo `manifest.exported_at`. The rules:

- Object keys are alphabetically sorted (case-sensitive ASCII).
- Options are sorted by `option_name`.
- Postmeta / usermeta / commentmeta are sorted by `meta_key`, then by
  `meta_id` within a key.
- Term assignments on a post are sorted by `term_taxonomy_id`.
- Per-post comment ID lists are sorted ascending.
- Timestamps are UTC, sourced from `*_date_gmt` columns.
- The skipped list in `manifest.json` is sorted by type then id.
- The order of lines in `errors.log` follows the exporter's stable
  processing order (paginated by ID), so the same source produces the
  same log.
