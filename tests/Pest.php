<?php

declare(strict_types=1);

use IdempotentExport\Tests\Support\Env;
use IdempotentExport\Tests\Support\FakeWpdb;
use IdempotentExport\Tests\Support\WpCli;

uses()
    ->beforeEach(function (): void {
        Env::reset();
        WpCli::reset();
        FakeWpdb::install();
    })
    ->afterEach(function (): void {
        Env::cleanup();
    })
    ->in('Unit', 'Feature');

/**
 * Build the absolute path of a temporary directory unique to the current test.
 * The directory is registered for cleanup at afterEach.
 */
function tmpdir(string $prefix = 'idem-export'): string
{
    return Env::tmpdir($prefix);
}

/**
 * Read a JSON file and decode it.
 */
function readJson(string $path): mixed
{
    expect(is_file($path))->toBeTrue("missing file: {$path}");
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Return an alphabetised list of file paths under $dir relative to $dir.
 */
function listTree(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    $files = [];
    foreach ($iter as $f) {
        if ($f->isFile()) {
            $files[] = ltrim(substr($f->getPathname(), strlen($dir)), '/\\');
        }
    }
    sort($files);
    return $files;
}
