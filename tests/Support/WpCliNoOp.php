<?php

declare(strict_types=1);

namespace WP_CLI;

if (!class_exists(NoOp::class, false)) {
    class NoOp
    {
        public function __call(string $name, array $args): mixed
        {
            return null;
        }
    }
}
