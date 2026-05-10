<?php

declare(strict_types=1);

use IdempotentExport\Filters;
use IdempotentExport\Tests\Support\WpCliError;

it('defaults to allowing all types except classic-menu items and revisions', function (): void {
    $f = Filters::fromCliArgs([]);
    expect($f->postTypeAllowed('post'))->toBeTrue();
    expect($f->postTypeAllowed('page'))->toBeTrue();
    expect($f->postTypeAllowed('wp_navigation'))->toBeTrue();
    expect($f->postTypeAllowed('revision'))->toBeFalse();
    expect($f->postTypeAllowed('nav_menu_item'))->toBeFalse();
});

it('restricts to --post-type=<csv>', function (): void {
    $f = Filters::fromCliArgs(['post-type' => 'post, page ,custom']);
    expect($f->postTypeAllowed('post'))->toBeTrue();
    expect($f->postTypeAllowed('page'))->toBeTrue();
    expect($f->postTypeAllowed('custom'))->toBeTrue();
    expect($f->postTypeAllowed('attachment'))->toBeFalse();
});

it('opts revisions in via --include-revisions but still excludes nav_menu_item', function (): void {
    $f = Filters::fromCliArgs(['include-revisions' => true]);
    expect($f->postTypeAllowed('revision'))->toBeTrue();
    expect($f->postTypeAllowed('nav_menu_item'))->toBeFalse();
});

it('always excludes the nav_menu taxonomy', function (): void {
    $f = Filters::fromCliArgs([]);
    expect($f->taxonomyAllowed('nav_menu'))->toBeFalse();
    expect($f->taxonomyAllowed('category'))->toBeTrue();
    expect($f->taxonomyAllowed('post_tag'))->toBeTrue();
});

it('treats since/until as a half-open GMT window', function (): void {
    $f = Filters::fromCliArgs(['since' => '2024-01-01', 'until' => '2024-12-31T00:00:00Z']);
    expect($f->dateInRange('2024-06-01 00:00:00'))->toBeTrue();
    expect($f->dateInRange('2023-12-31 23:59:59'))->toBeFalse();
    expect($f->dateInRange('2024-12-31 00:00:00'))->toBeFalse(); // until is exclusive
});

it('accepts ISO 8601 since/until values', function (): void {
    $f = Filters::fromCliArgs(['since' => '2024-03-15T10:30:00Z']);
    expect($f->dateInRange('2024-03-15 10:30:00'))->toBeTrue();
    expect($f->dateInRange('2024-03-15 10:29:59'))->toBeFalse();
});

it('errors out on an unparseable date', function (): void {
    Filters::fromCliArgs(['since' => 'not-a-date']);
})->throws(WpCliError::class);

it('snapshots the filter state for the manifest', function (): void {
    $f = Filters::fromCliArgs([
        'post-type'         => 'post,page',
        'since'             => '2024-01-01',
        'until'             => '2024-12-31',
        'include-revisions' => true,
    ]);
    $payload = $f->toManifest();
    expect($payload)->toHaveKeys(['include_revisions', 'post_types', 'since', 'until']);
    expect($payload['post_types'])->toBe(['post', 'page']);
    expect($payload['include_revisions'])->toBeTrue();
    expect($payload['since'])->toBe('2024-01-01 00:00:00');
    expect($payload['until'])->toBe('2024-12-31 00:00:00');
});

it('represents an unset filter as null in the manifest', function (): void {
    $payload = Filters::fromCliArgs([])->toManifest();
    expect($payload['post_types'])->toBeNull();
    expect($payload['since'])->toBeNull();
    expect($payload['until'])->toBeNull();
    expect($payload['include_revisions'])->toBeFalse();
});
