# Testing

The suite is written with [Pest](https://pestphp.com/) on top of PHPUnit
10. 99 tests, ~260 assertions, sub-second end-to-end.

## Running the suite

```bash
composer install
composer test                # everything
composer test:unit           # tests/Unit only
composer test:feature        # tests/Feature only
```

Or directly:

```bash
./vendor/bin/pest --no-coverage
./vendor/bin/pest --filter Json
./vendor/bin/pest tests/Feature/RunTest.php
```

Requirements: PHP 8.1+, `pdo_sqlite` extension.

## Suite layout

```
tests/
├── bootstrap.php         ── loads autoloader, registers shims, installs WP_CLI facade
├── Pest.php              ── beforeEach/afterEach + helper functions
├── Unit/                 ── pure-PHP tests, no DB
│   ├── JsonTest.php
│   ├── EncoderTest.php
│   ├── FiltersTest.php
│   ├── LoggerTest.php
│   ├── ManifestTest.php
│   ├── WriterTest.php
│   ├── OutputTest.php
│   ├── DecodeFailureTest.php
│   └── AbstractExporterTest.php
├── Feature/              ── exporter integration tests against FakeWpdb
│   ├── PostsExporterTest.php
│   ├── TermsExporterTest.php
│   ├── UsersExporterTest.php
│   ├── CommentsExporterTest.php
│   ├── OptionsExporterTest.php
│   └── RunTest.php       ── end-to-end (clean run, idempotence, dry-run, multisite)
└── Support/
    ├── WpFunctions.php   ── polyfills: wp_unslash, is_serialized, wp_mkdir_p, ...
    ├── WpCli.php         ── facade + WpCliError / WpCliHalt exceptions
    ├── WpCliNoOp.php     ── stub for WP_CLI\NoOp
    ├── WpCliUtils.php    ── stub for WP_CLI\Utils\make_progress_bar
    ├── FakeWpdb.php      ── SQLite-backed wpdb stand-in
    └── Fixtures.php      ── typed seed helpers
```

## Unit vs Feature

The split is by what the test needs:

- **Unit** tests stand up a single class with minimal surrounding
  state. They run against in-memory data — no SQLite, no filesystem
  beyond per-test temp dirs. Fast and tightly scoped.
- **Feature** tests run a real exporter end-to-end. They install a
  fresh `FakeWpdb`, seed it through `Fixtures`, run the exporter, and
  inspect the written tree.

## How tests get a database

`FakeWpdb` is a stand-in for the global `$wpdb`. It uses an in-memory
SQLite database with the standard WordPress table schemas
(`wp_posts`, `wp_postmeta`, etc.), and implements just the wpdb surface
the exporter uses:

```php
public string $posts, $postmeta, $terms, ... ;
public function esc_like(string $text): string;
public function prepare(string $query, mixed ...$args): string;
public function get_var(string $sql): null|string;
public function get_col(string $sql): array;
public function get_results(string $sql, string $output = 'ARRAY_A'): array;
```

Two MySQL-isms are explicitly emulated:

1. **`LIKE` with backslash as default escape.** SQLite's `LIKE` has no
   default escape character; MySQL's is `\`. The exporter relies on the
   MySQL behaviour (via `$wpdb->esc_like('_transient_') . '%'`), so
   `FakeWpdb` registers a custom `like()` SQL function via
   `PDO::sqliteCreateFunction` that uses `\` as the implicit escape.
2. **`information_schema.tables` AUTO_INCREMENT lookup.** SQLite has
   no `information_schema`. `FakeWpdb::get_results` intercepts queries
   that mention it and returns canned data from `$autoIncrement`, which
   tests can override.

A `Fixtures` helper seeds rows with typed defaults so tests can write
`Fixtures::insertPost($wpdb, ['post_title' => 'Hello'])` without
restating every column.

## Per-test isolation

Pest's `beforeEach` resets all global state:

```php
uses()->beforeEach(function (): void {
    Env::reset();           // fresh per-test WP function state
    WpCli::reset();         // empties captured WP_CLI logs
    FakeWpdb::install();    // brand-new in-memory SQLite, fresh $wpdb
})->afterEach(function (): void {
    Env::cleanup();         // removes any temp dirs created by tmpdir()
})->in('Unit', 'Feature');
```

Tests can call `tmpdir()` to get a unique path under `sys_get_temp_dir`;
the path is auto-registered for `afterEach` cleanup.

## The idempotence test

`tests/Feature/RunTest.php` includes a test that exercises the central
PRD guarantee. It runs a full export, snapshots the output bytes into
memory, re-installs a fresh `FakeWpdb`, re-seeds identical data, runs
again, then asserts:

- The two output trees have the same set of files.
- Every file's bytes match — except `manifest.json`, which is compared
  with `exported_at` removed.

If you change anything about JSON encoding, key ordering, sharding or
filename construction, this is the test that will catch you.

## Adding a new test

```php
<?php

declare(strict_types=1);

use IdempotentExport\Tests\Support\FakeWpdb;
use IdempotentExport\Tests\Support\Fixtures;

it('does the new thing', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertPost($wpdb, ['post_title' => 'X']);

    // exercise the exporter, assert against listTree() / readJson()
    expect(true)->toBeTrue();
});
```

For new exporters, follow the pattern in `tests/Feature/PostsExporterTest.php`:
build the exporter with a fresh `Writer`/`Logger`/`Encoder`/`Filters`/`Manifest`,
run it, then inspect `listTree($writer->root())` and `readJson(path)`.

## Test conventions

- Each `Feature/*Test.php` file owns a small `make<X>Exporter()` helper
  for wiring up the dependencies. Don't reach across files for these —
  copy them; the duplication is cheaper than coupling tests through a
  shared factory.
- Use `invade($obj)` (defined in `PostsExporterTest`) to read the
  protected `writer` property when you need the output root inside a
  test. Don't add public getters to production code purely for tests.
- When an assertion compares JSON-encoded structure, remember that
  `Json::encode` alphabetises object keys — write the expectation in
  sorted order.

## CI

The suite is fast enough (<1s) to run on every push without thought.
Recommended GitHub Actions config:

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.2'
    extensions: pdo_sqlite, sqlite3
- run: composer install --no-progress --prefer-dist
- run: composer test
```

No external services, no MySQL, no WordPress install required — the
shim layer covers everything.
