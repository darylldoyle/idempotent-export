<?php

declare(strict_types=1);

use IdempotentExport\Encoder;
use IdempotentExport\Exporter\Comments;
use IdempotentExport\Filters;
use IdempotentExport\Logger;
use IdempotentExport\Manifest;
use IdempotentExport\Tests\Support\FakeWpdb;
use IdempotentExport\Tests\Support\Fixtures;
use IdempotentExport\Writer;

function makeCommentsExporter(array $assoc = []): Comments
{
    $logger = new Logger(null);
    $writer = new Writer(tmpdir(), false);
    @mkdir($writer->root(), 0777, true);
    return new Comments(
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

it('shards comments by year/month from comment_date_gmt', function (): void {
    $wpdb = FakeWpdb::current();
    $postId = Fixtures::insertPost($wpdb);
    Fixtures::insertComment($wpdb, $postId, ['comment_date_gmt' => '2024-02-01 00:00:00']);
    Fixtures::insertComment($wpdb, $postId, ['comment_date_gmt' => '2024-09-15 12:00:00']);

    $e = makeCommentsExporter();
    $e->run();
    expect(listTree(invade($e)->writer->root()))->toBe([
        'comments/2024/02/1.json',
        'comments/2024/09/2.json',
    ]);
});

it('exports every status including spam and trash', function (): void {
    $wpdb = FakeWpdb::current();
    $postId = Fixtures::insertPost($wpdb);
    Fixtures::insertComment($wpdb, $postId, ['comment_approved' => '1']);
    Fixtures::insertComment($wpdb, $postId, ['comment_approved' => '0']);
    Fixtures::insertComment($wpdb, $postId, ['comment_approved' => 'spam']);
    Fixtures::insertComment($wpdb, $postId, ['comment_approved' => 'trash']);

    $e = makeCommentsExporter();
    $e->run();
    expect(listTree(invade($e)->writer->root()))->toHaveCount(4);

    $root = invade($e)->writer->root();
    $statuses = [];
    foreach (listTree($root) as $rel) {
        $statuses[] = readJson("$root/$rel")['comment_approved'];
    }
    expect($statuses)->toBe(['1', '0', 'spam', 'trash']);
});

it('respects --since on comment_date_gmt', function (): void {
    $wpdb = FakeWpdb::current();
    $postId = Fixtures::insertPost($wpdb);
    Fixtures::insertComment($wpdb, $postId, ['comment_date_gmt' => '2024-01-01 00:00:00']);
    Fixtures::insertComment($wpdb, $postId, ['comment_date_gmt' => '2024-06-01 00:00:00']);

    $e = makeCommentsExporter(['since' => '2024-05-01']);
    $e->run();
    $tree = listTree(invade($e)->writer->root());
    expect($tree)->toBe(['comments/2024/06/2.json']);
});

it('includes commentmeta', function (): void {
    $wpdb = FakeWpdb::current();
    $postId = Fixtures::insertPost($wpdb);
    $cid    = Fixtures::insertComment($wpdb, $postId);
    Fixtures::insertCommentMeta($wpdb, $cid, 'rating', '5');
    Fixtures::insertCommentMeta($wpdb, $cid, 'rating', '4');

    $e = makeCommentsExporter();
    $e->run();
    $data = readJson(invade($e)->writer->root() . '/comments/2024/04/1.json');
    expect($data['meta']['rating'])->toBe(['5', '4']);
});

it('coerces integer columns to ints in the JSON output', function (): void {
    $wpdb = FakeWpdb::current();
    $postId = Fixtures::insertPost($wpdb);
    Fixtures::insertComment($wpdb, $postId, [
        'comment_parent' => 42,
        'comment_karma'  => 7,
        'user_id'        => 11,
    ]);

    $e = makeCommentsExporter();
    $e->run();
    $raw = (string) file_get_contents(invade($e)->writer->root() . '/comments/2024/04/1.json');
    expect($raw)->toContain('"comment_parent": 42');
    expect($raw)->toContain('"comment_karma": 7');
    expect($raw)->toContain('"user_id": 11');
});
