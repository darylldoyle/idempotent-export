<?php

declare(strict_types=1);

use IdempotentExport\Filters;
use IdempotentExport\Manifest;
use IdempotentExport\Tests\Support\FakeWpdb;

it('exposes the schema version constant', function (): void {
    expect(Manifest::SCHEMA_VERSION)->toBe('1.0.0');
});

it('captures source info including AUTO_INCREMENT from the wpdb wrapper', function (): void {
    $wpdb = FakeWpdb::current();
    $wpdb->autoIncrement = [
        'wp_posts'         => 100,
        'wp_terms'         => 50,
        'wp_term_taxonomy' => 58,
        'wp_users'         => 7,
        'wp_comments'      => 30,
    ];

    $m = new Manifest();
    $m->captureSource(blogId: null);
    $m->captureFilters(Filters::fromCliArgs([]));

    $out = $m->build([]);

    expect($out['schema_version'])->toBe('1.0.0');
    expect($out['source']['auto_increment'])->toBe([
        'posts'         => 100,
        'terms'         => 50,
        'term_taxonomy' => 58,
        'users'         => 7,
        'comments'      => 30,
    ]);
    expect($out['source']['blog_id'])->toBeNull();
    expect($out['source']['is_multisite'])->toBeFalse();
    expect($out['source']['site_url'])->toBe('https://example.test');
    expect($out['source']['wp_version'])->toBe('6.9-test');
});

it('counts entities via bumpCount', function (): void {
    $m = new Manifest();
    $m->bumpCount('posts', 3);
    $m->bumpCount('posts', 2);
    $m->bumpCount('terms', 1);

    expect($m->counts()['posts'])->toBe(5);
    expect($m->counts()['terms'])->toBe(1);
    expect($m->counts()['users'])->toBe(0);
});

it('sorts the skipped list by type then id', function (): void {
    $m = new Manifest();
    $m->captureSource(null);
    $m->captureFilters(Filters::fromCliArgs([]));

    $out = $m->build([
        ['type' => 'post',    'id' => 2,  'reason' => 'b'],
        ['type' => 'comment', 'id' => 5,  'reason' => 'a'],
        ['type' => 'post',    'id' => 10, 'reason' => 'c'],
        ['type' => 'post',    'id' => 1,  'reason' => 'd'],
    ]);

    expect(array_column($out['skipped'], 'type'))->toBe(['comment', 'post', 'post', 'post']);
    expect(array_column($out['skipped'], 'id'))->toBe([5, 1, 2, 10]);
});

it('always emits exported_at as an ISO 8601 UTC string with Z suffix', function (): void {
    $m = new Manifest();
    $m->captureSource(null);
    $m->captureFilters(Filters::fromCliArgs([]));

    $out = $m->build([]);
    expect($out['exported_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('round-trips filters into filters_applied', function (): void {
    $m = new Manifest();
    $m->captureSource(null);
    $m->captureFilters(Filters::fromCliArgs([
        'post-type'         => 'post,page',
        'include-revisions' => true,
        'since'             => '2024-01-01',
    ]));

    $out = $m->build([]);
    expect($out['filters_applied']['post_types'])->toBe(['post', 'page']);
    expect($out['filters_applied']['include_revisions'])->toBeTrue();
    expect($out['filters_applied']['since'])->toBe('2024-01-01 00:00:00');
});
