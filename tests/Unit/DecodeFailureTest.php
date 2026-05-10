<?php

declare(strict_types=1);

use IdempotentExport\DecodeFailure;

it('is a plain sentinel class with no shared state', function (): void {
    $a = new DecodeFailure();
    $b = new DecodeFailure();

    expect($a)->toBeInstanceOf(DecodeFailure::class);
    expect($b)->toBeInstanceOf(DecodeFailure::class);
    expect($a)->not->toBe($b);
});
