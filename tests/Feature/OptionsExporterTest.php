<?php

declare(strict_types=1);

use IdempotentExport\Encoder;
use IdempotentExport\Exporter\Options;
use IdempotentExport\Filters;
use IdempotentExport\Logger;
use IdempotentExport\Manifest;
use IdempotentExport\Tests\Support\FakeWpdb;
use IdempotentExport\Tests\Support\Fixtures;
use IdempotentExport\Writer;

function makeOptionsExporter(): Options
{
    $logger = new Logger(null);
    $writer = new Writer(tmpdir(), false);
    @mkdir($writer->root(), 0777, true);
    return new Options(
        $writer,
        $logger,
        new Encoder($logger),
        Filters::fromCliArgs([]),
        new Manifest(),
        500,
        0,
        true
    );
}

it('writes a single options.json keyed by option_name', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertOption($wpdb, 'siteurl', 'https://example.test');
    Fixtures::insertOption($wpdb, 'blogname', 'Example');

    $e = makeOptionsExporter();
    $e->run();

    expect(listTree(invade($e)->writer->root()))->toBe(['options.json']);
    $data = readJson(invade($e)->writer->root() . '/options.json');
    expect(array_keys($data))->toBe(['blogname', 'siteurl']);
    expect($data['siteurl']['value'])->toBe('https://example.test');
});

it('emits autoload alongside the value for each option', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertOption($wpdb, 'foo', 'bar', 'yes');
    Fixtures::insertOption($wpdb, 'baz', 'qux', 'no');

    $e = makeOptionsExporter();
    $e->run();
    $data = readJson(invade($e)->writer->root() . '/options.json');

    expect($data['foo'])->toBe(['autoload' => 'yes', 'value' => 'bar']);
    expect($data['baz'])->toBe(['autoload' => 'no', 'value' => 'qux']);
});

it('excludes regular and site transients', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertOption($wpdb, 'siteurl', 'https://example.test');
    Fixtures::insertOption($wpdb, '_transient_xyz', '1');
    Fixtures::insertOption($wpdb, '_transient_timeout_xyz', '99999');
    Fixtures::insertOption($wpdb, '_site_transient_abc', '1');

    $e = makeOptionsExporter();
    $e->run();
    $data = readJson(invade($e)->writer->root() . '/options.json');

    expect(array_keys($data))->toBe(['siteurl']);
});

it('unserialises PHP-serialised option values', function (): void {
    $wpdb    = FakeWpdb::current();
    $payload = ['template' => 'twentytwentyfour', 'stylesheet' => 'twentytwentyfour'];
    Fixtures::insertOption($wpdb, 'active_plugins', serialize(['akismet/akismet.php']));
    Fixtures::insertOption($wpdb, 'theme_mods', serialize($payload));

    $e = makeOptionsExporter();
    $e->run();
    $data = readJson(invade($e)->writer->root() . '/options.json');

    expect($data['active_plugins']['value'])->toBe(['akismet/akismet.php']);
    // Decoded back through Json::encode, so the assoc keys are alphabetised.
    expect($data['theme_mods']['value'])->toBe([
        'stylesheet' => 'twentytwentyfour',
        'template'   => 'twentytwentyfour',
    ]);
});

it('writes no file when there are no non-transient options', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertOption($wpdb, '_transient_only', '1');

    $e = makeOptionsExporter();
    $e->run();
    expect(listTree(invade($e)->writer->root()))->toBe([]);
});
