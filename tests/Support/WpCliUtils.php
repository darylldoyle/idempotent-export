<?php

declare(strict_types=1);

namespace WP_CLI\Utils;

if (!function_exists(__NAMESPACE__ . '\\make_progress_bar')) {
    function make_progress_bar(string $message, int $count, int $interval = 100): \WP_CLI\NoOp
    {
        return new \WP_CLI\NoOp();
    }
}
