<?php

declare(strict_types=1);

use IdempotentExport\Output;
use IdempotentExport\Tests\Support\WpCliError;

it('creates and returns an absolute path for a fresh directory', function (): void {
    $path = tmpdir();
    $resolved = Output::resolve([$path], []);
    expect($resolved)->toBe($path);
    expect(is_dir($path))->toBeTrue();
});

it('errors out when the output dir is not empty and --force is missing', function (): void {
    $path = tmpdir();
    @mkdir($path, 0777, true);
    file_put_contents($path . '/stale.json', '{}');

    Output::resolve([$path], []);
})->throws(WpCliError::class, 'not empty');

it('overwrites a non-empty dir when --force is set', function (): void {
    $path = tmpdir();
    @mkdir($path, 0777, true);
    file_put_contents($path . '/stale.json', '{}');

    $resolved = Output::resolve([$path], ['force' => true]);
    expect($resolved)->toBe($path);
    // We don't physically delete contents - the importer is supposed to rewrite.
    expect(is_file($path . '/stale.json'))->toBeTrue();
});

it('errors out if the path exists and is not a directory', function (): void {
    $file = tmpdir();
    file_put_contents($file, 'x');

    Output::resolve([$file], []);
})->throws(WpCliError::class, 'not a directory');

it('requires a path on non-VIP environments', function (): void {
    Output::resolve([], []);
})->throws(WpCliError::class, 'output-dir is required');

it('resolves relative paths against the current working directory', function (): void {
    $tmpRoot = tmpdir();
    @mkdir($tmpRoot, 0777, true);
    $oldCwd = getcwd();
    chdir($tmpRoot);
    try {
        $resolved = Output::resolve(['snapshot'], []);
        expect($resolved)->toStartWith(rtrim($tmpRoot, '/\\'));
        expect($resolved)->toEndWith('snapshot');
    } finally {
        chdir($oldCwd);
    }
});
