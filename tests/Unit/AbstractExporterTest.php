<?php

declare(strict_types=1);

use IdempotentExport\Encoder;
use IdempotentExport\Exporter\AbstractExporter;
use IdempotentExport\Filters;
use IdempotentExport\Logger;
use IdempotentExport\Manifest;
use IdempotentExport\Writer;

/**
 * Test double exposing AbstractExporter's protected helpers.
 */
class _AbstractProbe extends AbstractExporter
{
    public function run() {}

    public function probeShardFromDate(string $d): array
    {
        return $this->shardFromDate($d);
    }

    public function probeUnslashRow(array $row): array
    {
        return $this->unslashRow($row);
    }
}

beforeEach(function (): void {
    $this->probe = new _AbstractProbe(
        new Writer(tmpdir(), true),
        new Logger(null),
        new Encoder(new Logger(null)),
        Filters::fromCliArgs([]),
        new Manifest(),
        500,
        0,
        true
    );
});

it('extracts year and month from a Y-m-d H:i:s GMT timestamp', function (): void {
    expect($this->probe->probeShardFromDate('2024-03-15 10:30:00'))->toBe(['2024', '03']);
});

it('falls back to 0000/00 for missing or invalid GMT dates', function (): void {
    expect($this->probe->probeShardFromDate(''))->toBe(['0000', '00']);
    expect($this->probe->probeShardFromDate('not-a-date'))->toBe(['0000', '00']);
    expect($this->probe->probeShardFromDate('0000-00-00 00:00:00'))->toBe(['0000', '00']);
});

it('unslashes every string field in a row but leaves non-strings alone', function (): void {
    $row = [
        'ID'           => 7,
        'post_title'   => "It\\'s fine",
        'menu_order'   => 0,
        'post_content' => 'Already \\\\ escaped',
    ];
    $out = $this->probe->probeUnslashRow($row);

    expect($out['ID'])->toBe(7);
    expect($out['menu_order'])->toBe(0);
    expect($out['post_title'])->toBe("It's fine");
    expect($out['post_content'])->toBe('Already \\ escaped');
});
