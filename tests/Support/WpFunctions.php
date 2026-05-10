<?php

declare(strict_types=1);

namespace IdempotentExport\Tests\Support {

    /**
     * Per-test environment state for the WP polyfills below.
     */
    class Env
    {
        public static array $tempPaths = [];

        public static string $siteUrl   = 'https://example.test';
        public static string $wpVersion = '6.9-test';
        public static bool $multisite   = false;
        /** @var int[] Stack of switched blog IDs. */
        public static array $blogStack = [];
        public static string $uploadBaseDir  = '';
        public static array $attachmentUrls = [];

        public static function reset(): void
        {
            self::$siteUrl        = 'https://example.test';
            self::$wpVersion      = '6.9-test';
            self::$multisite      = false;
            self::$blogStack      = [];
            self::$uploadBaseDir  = sys_get_temp_dir() . '/idem-export-uploads-' . bin2hex(random_bytes(4));
            @mkdir(self::$uploadBaseDir, 0777, true);
            self::$tempPaths[]    = self::$uploadBaseDir;
            self::$attachmentUrls = [];
        }

        public static function tmpdir(string $prefix): string
        {
            $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
            self::$tempPaths[] = $path;
            return $path;
        }

        public static function cleanup(): void
        {
            foreach (self::$tempPaths as $p) {
                self::rrmdir($p);
            }
            self::$tempPaths = [];
        }

        public static function rrmdir(string $path): void
        {
            if (!file_exists($path)) {
                return;
            }
            if (is_file($path) || is_link($path)) {
                @unlink($path);
                return;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                if ($f->isDir()) {
                    @rmdir($f->getPathname());
                } else {
                    @unlink($f->getPathname());
                }
            }
            @rmdir($path);
        }
    }
}

namespace {

    use IdempotentExport\Tests\Support\Env;

    // wpdb output mode constants used as the second argument to get_results().
    if (!defined('OBJECT')) {
        define('OBJECT', 'OBJECT');
    }
    if (!defined('OBJECT_K')) {
        define('OBJECT_K', 'OBJECT_K');
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('ARRAY_N')) {
        define('ARRAY_N', 'ARRAY_N');
    }

    if (!function_exists('wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            if (is_array($value)) {
                return array_map('wp_unslash', $value);
            }
            if (is_string($value)) {
                return stripslashes($value);
            }
            return $value;
        }
    }

    if (!function_exists('is_serialized')) {
        function is_serialized(mixed $data, bool $strict = true): bool
        {
            if (!is_string($data)) {
                return false;
            }
            $data = trim($data);
            if ('N;' === $data) {
                return true;
            }
            if (strlen($data) < 4) {
                return false;
            }
            if (':' !== $data[1]) {
                return false;
            }
            return (bool) preg_match('/^[adObis]:/', $data);
        }
    }

    if (!function_exists('wp_mkdir_p')) {
        function wp_mkdir_p(string $target): bool
        {
            return is_dir($target) || mkdir($target, 0777, true);
        }
    }

    if (!function_exists('wp_suspend_cache_addition')) {
        function wp_suspend_cache_addition(bool $suspend = true): bool
        {
            return $suspend;
        }
    }

    if (!function_exists('is_multisite')) {
        function is_multisite(): bool
        {
            return Env::$multisite;
        }
    }

    if (!function_exists('switch_to_blog')) {
        function switch_to_blog(int $blog_id): bool
        {
            Env::$blogStack[] = $blog_id;
            return true;
        }
    }

    if (!function_exists('restore_current_blog')) {
        function restore_current_blog(): bool
        {
            return null !== array_pop(Env::$blogStack);
        }
    }

    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir(?string $time = null, bool $create_dir = true, bool $refresh_cache = false): array
        {
            return [
                'basedir' => Env::$uploadBaseDir,
                'baseurl' => Env::$siteUrl . '/wp-content/uploads',
                'path'    => Env::$uploadBaseDir,
                'url'     => Env::$siteUrl . '/wp-content/uploads',
                'error'   => false,
            ];
        }
    }

    if (!function_exists('get_site_url')) {
        function get_site_url(): string
        {
            return Env::$siteUrl;
        }
    }

    if (!function_exists('get_bloginfo')) {
        function get_bloginfo(string $what = 'name'): string
        {
            if ('version' === $what) {
                return Env::$wpVersion;
            }
            return '';
        }
    }

    if (!function_exists('wp_get_attachment_url')) {
        function wp_get_attachment_url(int $post_id): string|false
        {
            return Env::$attachmentUrls[$post_id] ?? false;
        }
    }

    if (!function_exists('size_format')) {
        function size_format(int|float $bytes, int $decimals = 0): string
        {
            $units = ['B', 'kB', 'MB', 'GB', 'TB'];
            $b     = (float) $bytes;
            $i     = 0;
            while ($b >= 1024 && $i < count($units) - 1) {
                $b /= 1024;
                $i++;
            }
            return number_format($b, $decimals) . ' ' . $units[$i];
        }
    }

    if (!function_exists('sanitize_file_name')) {
        function sanitize_file_name(string $filename): string
        {
            $filename = str_replace(['/', '\\', "\0"], '_', $filename);
            $filename = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $filename) ?? '';
            $filename = trim($filename, '._-');
            return $filename === '' ? '' : $filename;
        }
    }
}
