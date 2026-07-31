<?php

declare(strict_types=1);

use IdempotentExport\Encoder;
use IdempotentExport\Exporter\Users;
use IdempotentExport\Filters;
use IdempotentExport\Logger;
use IdempotentExport\Manifest;
use IdempotentExport\Tests\Support\FakeWpdb;
use IdempotentExport\Tests\Support\Fixtures;
use IdempotentExport\Writer;

function makeUsersExporter(): Users
{
    $logger = new Logger(null);
    $writer = new Writer(tmpdir(), false);
    @mkdir($writer->root(), 0777, true);
    return new Users(
        $writer,
        $logger,
        new Encoder($logger),
        Filters::fromCliArgs([]),
        new Manifest(),
        500,
        0,
        true
    );
}

it('writes one file per user under users/<id>.json', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertUser($wpdb, ['user_login' => 'alice']);
    Fixtures::insertUser($wpdb, ['user_login' => 'bob']);

    $e = makeUsersExporter();
    $e->run();

    expect(listTree(invade($e)->writer->root()))->toBe(['users/1.json', 'users/2.json']);
});

it('strips the password hash', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertUser($wpdb, ['user_login' => 'alice', 'user_pass' => 'should-not-leak']);

    $e = makeUsersExporter();
    $e->run();
    $raw  = (string) file_get_contents(invade($e)->writer->root() . '/users/1.json');
    $data = json_decode($raw, true);

    expect($raw)->not->toContain('should-not-leak');
    expect($data)->not->toHaveKey('user_pass');
});

it('strips session_tokens and _application_passwords from usermeta', function (): void {
    $wpdb = FakeWpdb::current();
    $id   = Fixtures::insertUser($wpdb);
    Fixtures::insertUserMeta($wpdb, $id, 'session_tokens', serialize(['hash' => 'secret']));
    Fixtures::insertUserMeta($wpdb, $id, '_application_passwords', serialize(['uuid' => 'abc']));
    Fixtures::insertUserMeta($wpdb, $id, 'nickname', 'alice');
    Fixtures::insertUserMeta($wpdb, $id, 'wp_capabilities', serialize(['administrator' => true]));

    $e = makeUsersExporter();
    $e->run();
    $raw  = (string) file_get_contents(invade($e)->writer->root() . "/users/{$id}.json");
    $data = json_decode($raw, true);

    expect($raw)->not->toContain('secret');
    expect($raw)->not->toContain('uuid');
    expect($data['meta'])->toHaveKey('nickname');
    expect($data['meta'])->toHaveKey('wp_capabilities');
    expect($data['meta'])->not->toHaveKey('session_tokens');
    expect($data['meta'])->not->toHaveKey('_application_passwords');
    expect($data['meta']['wp_capabilities'])->toBe([['administrator' => true]]);
});

it('preserves user_registered verbatim', function (): void {
    $wpdb = FakeWpdb::current();
    Fixtures::insertUser($wpdb, ['user_registered' => '2018-07-04 13:00:00']);

    $e = makeUsersExporter();
    $e->run();
    $data = readJson(invade($e)->writer->root() . '/users/1.json');

    expect($data['user_registered'])->toBe('2018-07-04 13:00:00');
});
