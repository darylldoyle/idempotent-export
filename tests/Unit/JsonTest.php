<?php

declare(strict_types=1);

use IdempotentExport\Json;

it('returns scalars unchanged', function (): void {
    expect(Json::sortKeys(1))->toBe(1);
    expect(Json::sortKeys('x'))->toBe('x');
    expect(Json::sortKeys(null))->toBeNull();
    expect(Json::sortKeys(true))->toBeTrue();
});

it('alphabetises associative array keys with ASCII (case-sensitive) order', function (): void {
    $sorted = Json::sortKeys(['banana' => 1, 'Apple' => 2, 'cherry' => 3]);
    expect(array_keys($sorted))->toBe(['Apple', 'banana', 'cherry']);
});

it('preserves the order of sequential lists', function (): void {
    $list = ['c', 'a', 'b'];
    expect(Json::sortKeys($list))->toBe($list);
});

it('sorts nested associative arrays but not nested lists', function (): void {
    $in = [
        'meta' => [
            'z' => [3, 1, 2],
            'a' => [10, 20],
        ],
        'list' => [
            ['z' => 1, 'a' => 2],
            ['k' => 1],
        ],
    ];
    $out = Json::sortKeys($in);
    expect(array_keys($out))->toBe(['list', 'meta']);
    expect(array_keys($out['meta']))->toBe(['a', 'z']);
    expect($out['meta']['z'])->toBe([3, 1, 2]);
    expect($out['list'][0])->toBe(['a' => 2, 'z' => 1]);
});

it('encodes with two-space indent and a trailing newline', function (): void {
    $json = Json::encode(['b' => 1, 'a' => 2]);
    expect($json)->toBe("{\n  \"a\": 2,\n  \"b\": 1\n}\n");
});

it('renders empty stdClass as a JSON object {} and empty array as []', function (): void {
    $json = Json::encode(['m' => new stdClass(), 'l' => []]);
    expect($json)->toContain('"l": []');
    expect($json)->toContain('"m": {}');
});

it('keeps unicode unescaped and preserves slashes verbatim', function (): void {
    $json = Json::encode(['url' => 'https://example.com/path', 'jp' => 'こんにちは']);
    expect($json)->toContain('https://example.com/path');
    expect($json)->toContain('こんにちは');
});

it('substitutes invalid UTF-8 bytes rather than failing', function (): void {
    $bad  = "valid \xC3\x28 bytes"; // \xC3\x28 is invalid UTF-8
    $json = Json::encode(['x' => $bad]);
    expect($json)->toContain('"x":');
    expect($json)->not->toContain("\xC3\x28");
});

it('throws JsonException on a non-substitutable failure', function (): void {
    // A resource cannot be JSON-encoded under any flag.
    $resource = fopen('php://memory', 'rb');
    Json::encode(['r' => $resource]);
})->throws(JsonException::class);

it('mirrors the PRD post example shape exactly', function (): void {
    $post = [
        'ID'                    => 12345,
        'comment_count'         => 3,
        'comment_status'        => 'open',
        'comments'              => [991, 992, 1003],
        'guid'                  => 'https://example.com/?p=12345',
        'menu_order'            => 0,
        'meta'                  => [
            '_edit_last'             => ['42'],
            '_thumbnail_id'          => ['8821'],
            '_yoast_wpseo_focuskw'   => ['idempotent migration'],
            'custom_related_posts'   => [
                ['items' => [201, 305, 419], 'layout' => 'grid'],
            ],
            'fueled_review_status'   => ['approved', 'second_pass'],
        ],
        'ping_status'           => 'open',
        'pinged'                => '',
        'post_author'           => 42,
        'post_content'          => "<!-- wp:paragraph -->\n<p>Hello world.</p>\n<!-- /wp:paragraph -->",
        'post_content_filtered' => '',
        'post_date'             => '2024-03-15 10:30:00',
        'post_date_gmt'         => '2024-03-15 10:30:00',
        'post_excerpt'          => '',
        'post_mime_type'        => '',
        'post_modified'         => '2024-03-16 09:12:00',
        'post_modified_gmt'     => '2024-03-16 09:12:00',
        'post_name'             => 'example-post',
        'post_parent'           => 0,
        'post_password'         => '',
        'post_status'           => 'publish',
        'post_title'            => 'Example post',
        'post_type'             => 'post',
        'terms'                 => [
            'category' => [4, 17],
            'post_tag' => [231, 458],
        ],
        'to_ping'               => '',
    ];

    $json = Json::encode($post);
    $lines = explode("\n", $json);

    expect($lines[0])->toBe('{');
    expect($lines[1])->toBe('  "ID": 12345,');
    expect($lines[2])->toBe('  "comment_count": 3,');
    expect($lines[3])->toBe('  "comment_status": "open",');
    // _thumbnail_id ordering inside meta block
    expect($json)->toContain("\"_edit_last\": [\n      \"42\"\n    ]");
    // Final trailing newline.
    expect(substr($json, -2))->toBe("}\n");
});

it('is byte-stable across repeated encodes', function (): void {
    $payload = ['z' => 1, 'a' => ['c' => 3, 'b' => 2], 'm' => new stdClass()];
    $a = Json::encode($payload);
    $b = Json::encode($payload);
    expect($a)->toBe($b);
});
