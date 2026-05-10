<?php

declare(strict_types=1);

namespace IdempotentExport\Tests\Support;

/**
 * Thin shim for the bits of the global WP_CLI API the exporter touches.
 * Each test gets a fresh captured log; ::error and ::halt throw so tests
 * can assert on fatal-path behaviour.
 */
class WpCli
{
    public static array $log = [];
    public static array $success = [];
    public static array $warning = [];

    public static function install(): void
    {
        if (defined('WP_CLI')) {
            return;
        }
        define('WP_CLI', true);

        if (!class_exists('WP_CLI')) {
            class_alias(self::class . 'Facade', 'WP_CLI');
        }

        if (!class_exists('WP_CLI\\NoOp')) {
            require __DIR__ . '/WpCliNoOp.php';
        }
        if (!function_exists('WP_CLI\\Utils\\make_progress_bar')) {
            require __DIR__ . '/WpCliUtils.php';
        }
    }

    public static function reset(): void
    {
        self::$log     = [];
        self::$success = [];
        self::$warning = [];
    }
}

class WpCliFacade
{
    public static function error(string $message, int|bool $exit = true): void
    {
        throw new WpCliError($message);
    }

    public static function log(string $message): void
    {
        WpCli::$log[] = $message;
    }

    public static function success(string $message): void
    {
        WpCli::$success[] = $message;
    }

    public static function warning(string $message): void
    {
        WpCli::$warning[] = $message;
    }

    public static function halt(int $exit_code): void
    {
        throw new WpCliHalt($exit_code);
    }
}

class WpCliError extends \RuntimeException
{
}

class WpCliHalt extends \RuntimeException
{
    public function __construct(int $code)
    {
        parent::__construct("WP_CLI halt({$code})", $code);
    }

    public function exitCode(): int
    {
        return $this->getCode();
    }
}
