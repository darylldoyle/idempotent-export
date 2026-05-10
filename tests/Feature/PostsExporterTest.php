<?php

declare(strict_types=1);

use IdempotentExport\Encoder;
use IdempotentExport\Exporter\Posts;
use IdempotentExport\Filters;
use IdempotentExport\Logger;
use IdempotentExport\Manifest;
use IdempotentExport\Tests\Support\Env;
use IdempotentExport\Tests\Support\FakeWpdb;
use IdempotentExport\Tests\Support\Fixtures;
use IdempotentExport\Writer;

function makePostsExporter(array $assoc = [], int $batch = 500): Posts
{
    $logger = new Logger(null);
    $writer = new Writer(tmpdir(), false);
    @mkdir($writer->root(), 0777, true);
    return new Posts(
        $writer,
        $logger,
        new Encoder($logger),
        Filters::fromCliArgs($assoc),
        new Manifest(),
        $batch,
        0,
        true
    );
}

it('writes one JSON file per post sharded by year/month from post_date_gmt', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertPost($wpdb, ['post_title' => 'A', 'post_date_gmt' => '2024-03-15 10:00:00']);
    Fixtures::insertPost($wpdb, ['post_title' => 'B', 'post_date_gmt' => '2024-07-01 09:00:00']);

    $e = makePostsExporter();
    $e->run();

    $root  = invade($e)->writer->root();
    $files = listTree($root);
    expect($files)->toBe(['posts/2024/03/1.json', 'posts/2024/07/2.json']);

    $a = readJson("$root/posts/2024/03/1.json");
    expect($a['ID'])->toBe(1);
    expect($a['post_title'])->toBe('A');
    expect($a['post_type'])->toBe('post');
});

it('emits meta values as arrays keyed by meta_key, preserving multi-value order', function (): void {
    $wpdb = FakeWpdb::current();
    $id   = Fixtures::insertPost($wpdb);
    Fixtures::insertPostMeta($wpdb, $id, 'fueled_review_status', 'second_pass');
    Fixtures::insertPostMeta($wpdb, $id, 'fueled_review_status', 'approved');
    Fixtures::insertPostMeta($wpdb, $id, '_thumbnail_id', '8821');

    $e = makePostsExporter();
    $e->run();

    $root = invade($e)->writer->root();
    $data = readJson("$root/posts/2024/03/{$id}.json");

    expect($data['meta']['_thumbnail_id'])->toBe(['8821']);
    expect($data['meta']['fueled_review_status'])->toBe(['second_pass', 'approved']);
});

it('represents zero meta and zero terms as JSON objects, not arrays', function (): void {
    $wpdb = FakeWpdb::current();
    $id   = Fixtures::insertPost($wpdb);

    $e = makePostsExporter();
    $e->run();
    $root = invade($e)->writer->root();
    $raw  = (string) file_get_contents("$root/posts/2024/03/{$id}.json");

    expect($raw)->toContain('"meta": {}');
    expect($raw)->toContain('"terms": {}');
});

it('keys terms by taxonomy slug with sorted term_taxonomy_ids and excludes nav_menu', function (): void {
    $wpdb = FakeWpdb::current();
    $postId = Fixtures::insertPost($wpdb);

    $cat1 = Fixtures::insertTerm($wpdb, 'Cat A', 'cat-a', 'category');
    $cat2 = Fixtures::insertTerm($wpdb, 'Cat B', 'cat-b', 'category');
    $tag  = Fixtures::insertTerm($wpdb, 'Tag', 'tag', 'post_tag');
    $menu = Fixtures::insertTerm($wpdb, 'Menu', 'menu', 'nav_menu');

    // Assign deliberately in non-sorted order.
    Fixtures::assignTerm($wpdb, $postId, $cat2['term_taxonomy_id']);
    Fixtures::assignTerm($wpdb, $postId, $cat1['term_taxonomy_id']);
    Fixtures::assignTerm($wpdb, $postId, $tag['term_taxonomy_id']);
    Fixtures::assignTerm($wpdb, $postId, $menu['term_taxonomy_id']);

    $e = makePostsExporter();
    $e->run();
    $root = invade($e)->writer->root();
    $data = readJson("$root/posts/2024/03/{$postId}.json");

    expect($data['terms'])->toHaveKeys(['category', 'post_tag']);
    expect($data['terms'])->not->toHaveKey('nav_menu');
    expect($data['terms']['category'])->toBe([$cat1['term_taxonomy_id'], $cat2['term_taxonomy_id']]);
    expect($data['terms']['post_tag'])->toBe([$tag['term_taxonomy_id']]);
});

it('includes a sorted list of attached comment IDs (every status)', function (): void {
    $wpdb = FakeWpdb::current();
    $postId = Fixtures::insertPost($wpdb);

    $c1 = Fixtures::insertComment($wpdb, $postId, ['comment_approved' => '1']);
    $c2 = Fixtures::insertComment($wpdb, $postId, ['comment_approved' => 'spam']);
    $c3 = Fixtures::insertComment($wpdb, $postId, ['comment_approved' => 'trash']);
    $c4 = Fixtures::insertComment($wpdb, $postId, ['comment_approved' => '0']);

    $e = makePostsExporter();
    $e->run();
    $root = invade($e)->writer->root();
    $data = readJson("$root/posts/2024/03/{$postId}.json");

    expect($data['comments'])->toBe([$c1, $c2, $c3, $c4]);
});

it('excludes revisions by default and includes them with --include-revisions', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertPost($wpdb, ['post_type' => 'post', 'post_date_gmt' => '2024-03-15 10:00:00']);
    Fixtures::insertPost($wpdb, ['post_type' => 'revision', 'post_date_gmt' => '2024-03-15 10:00:00']);

    $e = makePostsExporter();
    $e->run();
    $rootA = invade($e)->writer->root();
    expect(listTree($rootA))->toBe(['posts/2024/03/1.json']);

    $e2 = makePostsExporter(['include-revisions' => true]);
    $e2->run();
    $rootB = invade($e2)->writer->root();
    expect(listTree($rootB))->toBe(['posts/2024/03/1.json', 'posts/2024/03/2.json']);
});

it('never exports nav_menu_item even if revisions are opted in', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertPost($wpdb, ['post_type' => 'nav_menu_item']);

    $e = makePostsExporter(['include-revisions' => true]);
    $e->run();
    expect(listTree(invade($e)->writer->root()))->toBe([]);
});

it('restricts output to the --post-type allowlist', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertPost($wpdb, ['post_type' => 'post']);
    Fixtures::insertPost($wpdb, ['post_type' => 'page']);
    Fixtures::insertPost($wpdb, ['post_type' => 'custom']);

    $e = makePostsExporter(['post-type' => 'post,custom']);
    $e->run();
    $tree = listTree(invade($e)->writer->root());
    expect($tree)->toBe(['posts/2024/03/1.json', 'posts/2024/03/3.json']);
});

it('applies the --since / --until window on post_date_gmt', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertPost($wpdb, ['post_date_gmt' => '2023-12-31 23:59:59']);
    Fixtures::insertPost($wpdb, ['post_date_gmt' => '2024-01-01 00:00:00']);
    Fixtures::insertPost($wpdb, ['post_date_gmt' => '2024-12-31 00:00:00']);

    $e = makePostsExporter(['since' => '2024-01-01', 'until' => '2024-12-31']);
    $e->run();
    $tree = listTree(invade($e)->writer->root());
    expect($tree)->toBe(['posts/2024/01/2.json']);
});

it('captures a resolved attachment_url for attachment-typed posts', function (): void {
    $wpdb = FakeWpdb::current();
    $id = Fixtures::insertPost($wpdb, ['post_type' => 'attachment']);
    Env::$attachmentUrls[$id] = 'https://cdn.example.com/path/to/file.jpg';

    $e = makePostsExporter();
    $e->run();
    $data = readJson(invade($e)->writer->root() . "/posts/2024/03/{$id}.json");

    expect($data['post_type'])->toBe('attachment');
    expect($data['attachment_url'])->toBe('https://cdn.example.com/path/to/file.jpg');
});

it('unserialises PHP-serialised meta into nested JSON structures', function (): void {
    $wpdb = FakeWpdb::current();
    $id   = Fixtures::insertPost($wpdb);
    $payload = ['items' => [201, 305, 419], 'layout' => 'grid'];
    Fixtures::insertPostMeta($wpdb, $id, 'custom_related_posts', addslashes(serialize($payload)));

    $e = makePostsExporter();
    $e->run();
    $data = readJson(invade($e)->writer->root() . "/posts/2024/03/{$id}.json");

    expect($data['meta']['custom_related_posts'])->toBe([$payload]);
});

it('paginates correctly past batch boundaries', function (): void {
    $wpdb = FakeWpdb::current();
    for ($i = 0; $i < 7; $i++) {
        Fixtures::insertPost($wpdb, ['post_title' => "Post $i"]);
    }
    $e = makePostsExporter(batch: 2);
    $e->run();

    expect(listTree(invade($e)->writer->root()))->toHaveCount(7);
});

it('coerces post columns to the expected types', function (): void {
    $wpdb = FakeWpdb::current();
    $id   = Fixtures::insertPost($wpdb, [
        'post_author'   => 42,
        'menu_order'    => 5,
        'post_parent'   => 99,
        'comment_count' => 3,
    ]);

    $e = makePostsExporter();
    $e->run();
    $raw  = (string) file_get_contents(invade($e)->writer->root() . "/posts/2024/03/{$id}.json");
    $data = json_decode($raw, true);

    // Integer fields render without quotes.
    expect($raw)->toContain('"post_author": 42');
    expect($raw)->toContain('"menu_order": 5');
    expect($raw)->toContain('"post_parent": 99');
    expect($raw)->toContain('"comment_count": 3');
    expect($data['post_author'])->toBe(42);
});

/**
 * Tiny invade() helper: returns an anonymous proxy that reads protected/private
 * properties of $obj. Saves boilerplate Reflection.
 */
function invade(object $obj): object
{
    return new class($obj) {
        public function __construct(private object $target) {}
        public function __get(string $name): mixed
        {
            $r = new ReflectionObject($this->target);
            $p = $r->getProperty($name);
            return $p->getValue($this->target);
        }
    };
}
