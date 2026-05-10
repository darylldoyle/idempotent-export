<?php

declare(strict_types=1);

use IdempotentExport\Encoder;
use IdempotentExport\Exporter\Terms;
use IdempotentExport\Filters;
use IdempotentExport\Logger;
use IdempotentExport\Manifest;
use IdempotentExport\Tests\Support\FakeWpdb;
use IdempotentExport\Tests\Support\Fixtures;
use IdempotentExport\Writer;

function makeTermsExporter(array $assoc = []): Terms
{
    $logger = new Logger(null);
    $writer = new Writer(tmpdir(), false);
    @mkdir($writer->root(), 0777, true);
    return new Terms(
        $writer,
        $logger,
        new Encoder($logger),
        Filters::fromCliArgs($assoc),
        new Manifest(),
        500,
        0,
        true
    );
}

it('writes one file per term_taxonomy_id under terms/{taxonomy}/', function (): void {
    $wpdb = FakeWpdb::current();
    $a = Fixtures::insertTerm($wpdb, 'Cat', 'cat', 'category');
    $b = Fixtures::insertTerm($wpdb, 'Tag', 'tag', 'post_tag');

    $e = makeTermsExporter();
    $e->run();

    $root = invade($e)->writer->root();
    expect(listTree($root))->toBe([
        "terms/category/{$a['term_taxonomy_id']}.json",
        "terms/post_tag/{$b['term_taxonomy_id']}.json",
    ]);
});

it('exposes both term_id and term_taxonomy_id', function (): void {
    $wpdb = FakeWpdb::current();
    $t = Fixtures::insertTerm($wpdb, 'X', 'x', 'category');

    $e = makeTermsExporter();
    $e->run();
    $data = readJson(invade($e)->writer->root() . "/terms/category/{$t['term_taxonomy_id']}.json");

    expect($data['term_id'])->toBe($t['term_id']);
    expect($data['term_taxonomy_id'])->toBe($t['term_taxonomy_id']);
    expect($data['taxonomy'])->toBe('category');
});

it('always excludes the nav_menu taxonomy', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertTerm($wpdb, 'Main', 'main', 'nav_menu');
    Fixtures::insertTerm($wpdb, 'Cat', 'cat', 'category');

    $e = makeTermsExporter();
    $e->run();
    $tree = listTree(invade($e)->writer->root());
    expect(count($tree))->toBe(1);
    expect($tree[0])->toStartWith('terms/category/');
});

it('preserves parent IDs so hierarchy can be reconstructed', function (): void {
    $wpdb = FakeWpdb::current();
    $parent = Fixtures::insertTerm($wpdb, 'Parent', 'parent', 'category');
    $child  = Fixtures::insertTerm($wpdb, 'Child', 'child', 'category', [
        'tt' => ['parent' => $parent['term_id']],
    ]);

    $e = makeTermsExporter();
    $e->run();

    $childData = readJson(invade($e)->writer->root() . "/terms/category/{$child['term_taxonomy_id']}.json");
    expect($childData['parent'])->toBe($parent['term_id']);
});

it('includes termmeta as a key-to-array map', function (): void {
    $wpdb = FakeWpdb::current();
    $t = Fixtures::insertTerm($wpdb, 'Cat', 'cat', 'category');
    Fixtures::insertTermMeta($wpdb, $t['term_id'], 'order', '7');
    Fixtures::insertTermMeta($wpdb, $t['term_id'], 'tag', 'red');
    Fixtures::insertTermMeta($wpdb, $t['term_id'], 'tag', 'blue');

    $e = makeTermsExporter();
    $e->run();
    $data = readJson(invade($e)->writer->root() . "/terms/category/{$t['term_taxonomy_id']}.json");

    expect($data['meta']['order'])->toBe(['7']);
    expect($data['meta']['tag'])->toBe(['red', 'blue']);
});

it('writes shared terms across taxonomies under both taxonomy slugs', function (): void {
    $wpdb = FakeWpdb::current();

    // One wp_terms row, two wp_term_taxonomy rows referring to it.
    $stmt = $wpdb->pdo->prepare('INSERT INTO wp_terms (name, slug) VALUES (?, ?)');
    $stmt->execute(['Shared', 'shared']);
    $termId = (int) $wpdb->pdo->lastInsertId();

    $stmt = $wpdb->pdo->prepare('INSERT INTO wp_term_taxonomy (term_id, taxonomy) VALUES (?, ?)');
    $stmt->execute([$termId, 'category']);
    $tt1 = (int) $wpdb->pdo->lastInsertId();
    $stmt->execute([$termId, 'post_tag']);
    $tt2 = (int) $wpdb->pdo->lastInsertId();

    $e = makeTermsExporter();
    $e->run();

    $root = invade($e)->writer->root();
    $a    = readJson("$root/terms/category/{$tt1}.json");
    $b    = readJson("$root/terms/post_tag/{$tt2}.json");

    expect($a['term_id'])->toBe($termId);
    expect($b['term_id'])->toBe($termId);
    expect($a['term_taxonomy_id'])->toBe($tt1);
    expect($b['term_taxonomy_id'])->toBe($tt2);
});

it('renders an empty meta map as {} not []', function (): void {
    $wpdb = FakeWpdb::current();
    $t = Fixtures::insertTerm($wpdb, 'X', 'x', 'category');

    $e = makeTermsExporter();
    $e->run();
    $raw = (string) file_get_contents(invade($e)->writer->root() . "/terms/category/{$t['term_taxonomy_id']}.json");
    expect($raw)->toContain('"meta": {}');
});
