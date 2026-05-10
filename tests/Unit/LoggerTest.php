<?php

declare(strict_types=1);

use IdempotentExport\Logger;

it('is a no-op when constructed with no path (dry-run mode)', function (): void {
    $log = new Logger(null);
    $log->open();
    $log->skip('post', 1, 'reason');
    $log->warn('post', 2, 'note');
    $log->close();

    expect($log->skipCount())->toBe(1);
    expect($log->warnCount())->toBe(1);
    expect($log->skips())->toHaveCount(1);
});

it('writes one TSV-style line per event to errors.log', function (): void {
    $path = tmpdir() . '/errors.log';
    @mkdir(dirname($path), 0777, true);
    $log = new Logger($path);
    $log->open();
    $log->skip('post', 1, 'JSON encode failed');
    $log->warn('post', 2, 'key=x: object cast to array');
    $log->close();

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
    expect($lines)->toHaveCount(2);
    expect($lines[0])->toBe("skip\ttype=post\tid=1\tJSON encode failed");
    expect($lines[1])->toBe("warn\ttype=post\tid=2\tkey=x: object cast to array");
});

it('strips embedded newlines and tabs from log reasons to keep the line shape', function (): void {
    $path = tmpdir() . '/errors.log';
    @mkdir(dirname($path), 0777, true);
    $log = new Logger($path);
    $log->open();
    $log->warn('post', 9, "line1\nline2\tcol2");
    $log->close();

    $raw = (string) file_get_contents($path);
    expect(substr_count($raw, "\n"))->toBe(1);
    expect($raw)->toBe("warn\ttype=post\tid=9\tline1 line2 col2\n");
});

it('preserves insertion order in skips() for deterministic manifest layout', function (): void {
    $log = new Logger(null);
    $log->open();
    $log->skip('term', 5, 'reason a');
    $log->skip('post', 1, 'reason b');
    $log->skip('term', 2, 'reason c');

    $skips = $log->skips();
    expect(array_column($skips, 'id'))->toBe([5, 1, 2]);
    expect(array_column($skips, 'type'))->toBe(['term', 'post', 'term']);
});
