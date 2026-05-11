# Architecture

A map of how the exporter is put together, why it's shaped this way, and
where to plug in.

## Module layout

```
src/
├── Command.php              ── WP-CLI entry point (thin)
├── Run.php                  ── orchestrator
├── Output.php               ── output-dir resolution & VIP validation
├── Filters.php              ── parses --post-type / --since / --until / --include-revisions
├── Manifest.php             ── builds manifest.json
├── Writer.php               ── writes JSON files (no-op in dry-run)
├── Json.php                 ── deterministic JSON encoding
├── Encoder.php              ── unslash + safe unserialize + object cast
├── Logger.php               ── skip/warn events for errors.log & manifest.skipped
├── DecodeFailure.php        ── sentinel returned by Encoder
├── autoload.php             ── PSR-4-style autoloader
└── Exporter/
    ├── AbstractExporter.php ── shared base (batching helpers, progress bar)
    ├── Posts.php
    ├── Terms.php
    ├── Users.php
    ├── Comments.php
    └── Options.php
```

Roughly: `Command` → `Run` → each `Exporter` → `Writer` + `Logger`,
with `Json` / `Encoder` / `Filters` / `Manifest` used cross-cuttingly.

## The flow of one run

```
WP-CLI:  wp idempotent-export /tmp/snapshot --since=2024-01-01
   │
Command::__invoke
   │
Run::execute
   ├── parse flags             (Filters::fromCliArgs)
   ├── resolve output dir      (Output::resolve, VIP-aware)
   ├── multisite: switch_to_blog
   ├── wp_suspend_cache_addition(true)
   ├── construct shared services (Logger, Writer, Encoder, Manifest)
   ├── PostsExporter->run()
   ├── TermsExporter->run()
   ├── UsersExporter->run()
   ├── CommentsExporter->run()
   ├── OptionsExporter->run()
   ├── write manifest.json     (last, so counts include every exporter)
   ├── restore_current_blog
   └── exit 0  (or WP_CLI::halt(1) if Logger::skipCount() > 0)
```

Each exporter is independent. The order is fixed (posts first, options
last) for predictable progress output and consistent failure
attribution, not because of cross-dependencies.

## How exporters read the database

All exporters use direct `$wpdb` reads — never `WP_Query` or `get_posts`
— because:

1. `WP_Query` fires user-land filters (`pre_get_posts`,
   `the_posts`, …) that could mutate or hide rows.
2. `WP_Query` would also load the post object cache, which can fill RAM
   to gigabytes on a 2M-post site.

The pagination pattern is **ID-keyset**, not offset-based:

```sql
SELECT * FROM wp_posts
WHERE post_type IN (...) AND ID > {$last_id}
ORDER BY ID ASC LIMIT {$batch_size}
```

`LIMIT/OFFSET` would be O(offset) per page on large tables. The keyset
pattern is O(batch_size) regardless of where in the table we are.

Per-batch lookups (postmeta, term assignments, comment IDs) are
**bulk** rather than per-row:

```sql
SELECT post_id, meta_key, meta_value
FROM wp_postmeta
WHERE post_id IN (..batch..)
ORDER BY post_id, meta_key, meta_id
```

This collapses what would be N+1 queries into one per batch.

`wp_suspend_cache_addition(true)` is set for the run, so even
incidental cache writes the exporter triggers (e.g. via
`wp_get_attachment_url`) don't grow memory.

## Idempotence model

The exporter's central promise: **re-running over an unchanged source
produces a byte-identical output tree**, except for `manifest.exported_at`.

It's enforced at four layers:

1. **Stable read order.** Keyset pagination plus per-batch SQL
   `ORDER BY` clauses on every join → the exporter visits rows in a
   deterministic sequence.

2. **Stable in-memory shape.** Multi-value meta is built as a sorted
   `{key: [values]}` map. Empty containers are forced to the right JSON
   type (`{}` vs `[]`) so JSON encoding doesn't choose differently
   between runs.

3. **Stable encoding.** `Json::encode` recursively `ksort`s object keys
   (case-sensitive ASCII), preserves list ordering, and emits a fixed
   two-space indent — independent of PHP's `JSON_PRETTY_PRINT` 4-space
   default.

4. **Stable file paths.** Filenames use source IDs. Shards use
   `*_date_gmt` columns, which don't drift with the server's local
   timezone.

The `Logger` does not sort events — it preserves insertion order. Since
exporters emit in a stable order, `errors.log` is deterministic too.

## Failure isolation

The exporter treats per-entity failures (corrupt meta, encoding issues,
unreadable rows) as **skip**s, not fatals: the entity is omitted from
output, a record lands in `manifest.skipped` and `errors.log`, and the
run continues.

The other category — **warning** — is for lossy-but-recoverable
transforms (object → array casts, invalid UTF-8 substitution, raw
strings kept when `unserialize` fails). The entity is still exported.

Skip counts drive the process exit code; warn counts do not. A clean
zero-skip run is the idempotency guarantee.

Fatal errors (unwritable output dir, missing `--blog-id` on multisite)
go through `WP_CLI::error` and abort the process immediately.

## Security posture

- **No arbitrary class instantiation during unserialize.**
  `Encoder::tryUnserialize` calls `unserialize($v, ['allowed_classes' =>
  false])`. Unknown classes become `__PHP_Incomplete_Class` objects,
  which the recursive cast flattens to an associative array. No
  `__wakeup` / `__destruct` gadgets run.
- **Password hashes are never written.** `Users` exporter doesn't
  include `user_pass` in the row.
- **Session and application-password material is dropped.**
  `Users` exporter strips `session_tokens` and `_application_passwords`
  user meta.
- **Other secrets are exported verbatim.** API keys, license keys,
  encryption salts, SMTP credentials. The exporter is operator-trusted;
  if you need redaction, do it before importing.
- **No `__wakeup` on serialised objects.** See above.

## Extension points

The clean places to extend, in rough order of "I want to" → "I should
fork":

### Add a new entity type

Drop a new class under `src/Exporter/`, extend `AbstractExporter`,
implement `run()`. The required services (Writer, Logger, Encoder,
Filters, Manifest, batch size, sleep, quiet flag) are wired in via the
parent constructor. Register it in `Run::execute` between the existing
exporters.

`Manifest::bumpCount($type)` accepts any string key — new entity types
appear in `counts` automatically.

### Add a new flag

Document it in `Command`'s docblock (WP-CLI parses that), pass it
through `Run::execute`. If it influences entity inclusion, fold it into
`Filters` rather than threading it through every exporter.

### Custom plugin tables (HPOS, Gravity Forms, …)

Not currently supported. The right shape for this is a public hook —
something like `apply_filters('idempotent_export_third_party_tables',
[])` — that lets each plugin return an array of
`{name, columns, file_path_template, meta_table}` descriptors. Build it
as a generic table exporter that takes a descriptor and walks the table
with the same keyset pattern. Open work, not yet started.

### Override file paths or naming

`Writer::write` takes a relative path. Each exporter computes its own
path. If you need a different layout, subclass the relevant exporter
and override its path construction. Don't try to centralise this in
`Writer` — the path is part of the entity's *identity*, and entity
identity belongs to the exporter.

### Custom encoding rules

If a specific plugin's serialised data needs custom unpacking,
intercept it in your own exporter after fetching meta and before
handing the row to `Writer`. Don't modify `Encoder` — it intentionally
has one behaviour and applies it uniformly.

## What this exporter does *not* do

Worth being explicit, because the absences are deliberate:

- **No reference detection.** The exporter never inspects post content
  or meta values looking for IDs to remap. The importer owns ID
  reissuing and reference rewriting.
- **No secret stripping.** Beyond the user-credential surface above,
  every option and meta value goes out as-is.
- **No schema validation.** If `wp_postmeta` rows reference posts that
  no longer exist, they're skipped on read (their post is gone) but
  not flagged separately. Same for orphaned `wp_term_relationships`
  rows — they're silently filtered by the JOIN.
- **No collation preservation.** The importer recreates rows on
  whatever the destination's collation is.
- **No mid-run consistency.** Live source content can mutate while the
  exporter walks the tables. Use maintenance mode if you need a
  point-in-time snapshot.
