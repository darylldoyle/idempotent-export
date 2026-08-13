<?php

declare(strict_types=1);

use IdempotentExport\Encoder;
use IdempotentExport\Logger;

beforeEach(function (): void {
    $this->logger  = new Logger(null);
    $this->encoder = new Encoder($this->logger);
});

it('returns plain scalar strings unchanged', function (): void {
    $value = $this->encoder->decodeStored('post', 1, '_thumbnail_id', '8821');
    expect($value)->toBe('8821');
});

it('keeps backslashes in stored values verbatim so JSON escapes survive', function (): void {
    $raw   = '[{"content":"<a href=\\"https:\\/\\/x.test\\">A<\\/a>"}]';
    $value = $this->encoder->decodeStored('post', 1, 'footnotes', $raw);
    expect($value)->toBe($raw);
    expect(json_decode($value, true))->toBeArray();
});

it('round-trips a serialised array as a native array', function (): void {
    $raw    = serialize(['items' => [1, 2, 3], 'layout' => 'grid']);
    $value  = $this->encoder->decodeStored('post', 7, 'custom', $raw);
    expect($value)->toBe(['items' => [1, 2, 3], 'layout' => 'grid']);
});

it('preserves serialized scalar false (b:0;) without flagging decode failure', function (): void {
    $value = $this->encoder->decodeStored('opt', 0, 'flag', serialize(false));
    expect($value)->toBe('b:0;');
    expect($this->logger->warnCount())->toBe(0);
});

it('casts serialised objects to associative arrays and warns', function (): void {
    $obj      = new stdClass();
    $obj->foo = 'bar';

    $value = $this->encoder->decodeStored('post', 9, 'objmeta', serialize($obj));

    expect($value)->toBeArray();
    expect($value['foo'])->toBe('bar');
    // allowed_classes=false records the original class name in the mangled key.
    expect($value['__PHP_Incomplete_Class_Name'])->toBe('stdClass');
    expect($this->logger->warnCount())->toBe(1);
    expect($this->logger->skipCount())->toBe(0);
});

it('recursively casts nested objects', function (): void {
    $inner        = new stdClass();
    $inner->name  = 'inner';
    $outer        = new stdClass();
    $outer->inner = $inner;

    $value = $this->encoder->decodeStored('post', 10, 'nested', serialize($outer));

    expect($value)->toBeArray();
    expect($value['inner'])->toBeArray();
    expect($value['inner']['name'])->toBe('inner');
    expect($this->logger->warnCount())->toBeGreaterThanOrEqual(1);
});

it('keeps the raw string and warns when unserialize fails', function (): void {
    $raw   = 'a:5:{not valid';
    $value = $this->encoder->decodeStored('post', 11, 'broken', $raw);
    expect($value)->toBe('a:5:{not valid');
    expect($this->logger->warnCount())->toBe(1);
});

it('does not run unserialize on plain text that happens to start with a letter', function (): void {
    $value = $this->encoder->decodeStored('post', 12, 'note', 'hello world');
    expect($value)->toBe('hello world');
    expect($this->logger->warnCount())->toBe(0);
});

it('does not execute __wakeup on unknown classes (security regression check)', function (): void {
    // Hand-craft a serialised "RemoteGadget" object. allowed_classes=false must
    // refuse to instantiate the class even if it happens to be defined in the
    // running process.
    eval(<<<'PHP'
namespace { class RemoteGadget { public string $payload = ""; public function __wakeup() { throw new \RuntimeException("instantiated"); } } }
PHP);

    $payload = 'O:12:"RemoteGadget":1:{s:7:"payload";s:3:"pwn";}';
    $value   = $this->encoder->decodeStored('post', 13, 'gadget', $payload);

    expect($value)->toBeArray();
    expect($value['payload'])->toBe('pwn');
});
