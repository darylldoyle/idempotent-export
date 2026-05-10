<?php

declare(strict_types=1);

use IdempotentExport\Writer;

it('writes a JSON file to the requested relative path', function (): void {
    $root = tmpdir();
    @mkdir($root, 0777, true);
    $w = new Writer($root, false);

    expect($w->write('post', 'posts/2024/03/12.json', ['ID' => 12]))->toBeTrue();

    $abs = $root . '/posts/2024/03/12.json';
    expect(is_file($abs))->toBeTrue();
    expect(file_get_contents($abs))->toBe("{\n  \"ID\": 12\n}\n");
});

it('creates nested directories on demand', function (): void {
    $root = tmpdir();
    @mkdir($root, 0777, true);
    $w = new Writer($root, false);
    $w->write('term', 'terms/category/1.json', ['x' => 1]);

    expect(is_dir($root . '/terms/category'))->toBeTrue();
});

it('produces no files in dry-run but captures the first entity per type as a sample', function (): void {
    $root = tmpdir();
    @mkdir($root, 0777, true);
    $w = new Writer($root, true);

    $w->write('post', 'posts/2024/03/1.json', ['ID' => 1]);
    $w->write('post', 'posts/2024/03/2.json', ['ID' => 2]);
    $w->write('term', 'terms/category/1.json', ['ID' => 99]);

    expect(is_dir($root . '/posts'))->toBeFalse();
    expect($w->samples())->toBe([
        'post' => ['ID' => 1],
        'term' => ['ID' => 99],
    ]);
});

it('returns false when the payload cannot be JSON-encoded', function (): void {
    $root = tmpdir();
    @mkdir($root, 0777, true);
    $w        = new Writer($root, false);
    $resource = fopen('php://memory', 'rb');

    expect($w->write('opt', 'options.json', ['x' => $resource]))->toBeFalse();
    expect(is_file($root . '/options.json'))->toBeFalse();
});

it('writeRoot drops the file at the root path and creates parent dirs', function (): void {
    $root = tmpdir();
    @mkdir($root, 0777, true);
    $w = new Writer($root, false);
    $w->writeRoot('manifest.json', ['k' => 1]);

    expect(file_get_contents($root . '/manifest.json'))->toBe("{\n  \"k\": 1\n}\n");
});

it('writeRoot is a no-op in dry-run', function (): void {
    $root = tmpdir();
    @mkdir($root, 0777, true);
    $w = new Writer($root, true);
    $w->writeRoot('manifest.json', ['k' => 1]);

    expect(is_file($root . '/manifest.json'))->toBeFalse();
});
