<?php

declare(strict_types=1);

namespace IdempotentExport\Tests\Support;

use PDO;

/**
 * In-memory SQLite-backed stand-in for the global $wpdb. Implements just
 * enough of the wpdb surface to satisfy the exporter: table-name properties,
 * prepare(), get_var/col/results(), esc_like().
 *
 * MySQL-specific bits the exporter relies on are emulated:
 * - LIKE recognises '\' as the default escape character (matches MySQL).
 * - information_schema.tables AUTO_INCREMENT lookups are intercepted and
 *   mapped to per-table next-id values tracked by the fake.
 *
 * The class is installed as the global $wpdb at the start of each test.
 */
class FakeWpdb
{
    public string $posts             = 'wp_posts';
    public string $postmeta          = 'wp_postmeta';
    public string $terms             = 'wp_terms';
    public string $term_taxonomy     = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $termmeta          = 'wp_termmeta';
    public string $users             = 'wp_users';
    public string $usermeta          = 'wp_usermeta';
    public string $comments          = 'wp_comments';
    public string $commentmeta       = 'wp_commentmeta';
    public string $options           = 'wp_options';

    public PDO $pdo;

    /** @var array<string,int> Mock AUTO_INCREMENT values for information_schema queries. */
    public array $autoIncrement = [
        'wp_posts'    => 1,
        'wp_terms'    => 1,
        'wp_users'    => 1,
        'wp_comments' => 1,
    ];

    /** @var string[] Every SQL statement executed since install(). */
    public array $statements = [];

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->registerLike();
        $this->createSchema();
    }

    /**
     * Install a fresh FakeWpdb as the global $wpdb.
     */
    public static function install(): self
    {
        $fake          = new self();
        $GLOBALS['wpdb'] = $fake;
        return $fake;
    }

    public static function current(): self
    {
        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof self) {
            throw new \RuntimeException('FakeWpdb is not installed.');
        }
        return $GLOBALS['wpdb'];
    }

    /**
     * Mimic wpdb::esc_like. Escapes underscore, percent and backslash.
     */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * Inline placeholder substitution roughly matching wpdb::prepare.
     * Supported placeholders: %s, %d, %f, %%.
     *
     * @param mixed ...$args
     */
    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i      = 0;
        $values = array_values($args);
        return preg_replace_callback(
            '/%(%|s|d|f)/',
            function ($m) use (&$i, $values) {
                if ($m[1] === '%') {
                    return '%';
                }
                $value = $values[$i] ?? null;
                $i++;
                if ($m[1] === 'd') {
                    return (string) (int) $value;
                }
                if ($m[1] === 'f') {
                    return rtrim(rtrim(sprintf('%F', (float) $value), '0'), '.');
                }
                return "'" . str_replace("'", "''", (string) $value) . "'";
            },
            $query
        );
    }

    public function get_var(string $sql): null|string
    {
        $this->statements[] = $sql;
        $stmt = $this->pdo->query($sql);
        $row  = $stmt->fetch(PDO::FETCH_NUM);
        return false === $row ? null : (string) $row[0];
    }

    /**
     * @return string[]
     */
    public function get_col(string $sql): array
    {
        $this->statements[] = $sql;
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
        return array_map(static fn ($r) => (string) $r[0], $rows);
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function get_results(string $sql, string $output = 'ARRAY_A'): array
    {
        $this->statements[] = $sql;
        if (str_contains($sql, 'information_schema.tables')) {
            return $this->mockAutoIncrement();
        }
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array{TABLE_NAME:string, AUTO_INCREMENT:int}>
     */
    private function mockAutoIncrement(): array
    {
        $out = [];
        foreach ($this->autoIncrement as $table => $next) {
            $out[] = ['TABLE_NAME' => $table, 'AUTO_INCREMENT' => (string) $next];
        }
        return $out;
    }

    /**
     * Register a LIKE override that treats '\' as the default escape character,
     * matching MySQL's default and the way wpdb's esc_like() produces patterns.
     */
    private function registerLike(): void
    {
        $callable = static function (string $pattern, ?string $expr, string $escape = '\\'): bool {
            if (null === $expr) {
                return false;
            }
            $regex = '';
            $len   = strlen($pattern);
            for ($i = 0; $i < $len; $i++) {
                $c = $pattern[$i];
                if ($c === $escape && $i + 1 < $len) {
                    $regex .= preg_quote($pattern[$i + 1], '/');
                    $i++;
                    continue;
                }
                if ($c === '%') {
                    $regex .= '.*';
                } elseif ($c === '_') {
                    $regex .= '.';
                } else {
                    $regex .= preg_quote($c, '/');
                }
            }
            return (bool) preg_match('/^' . $regex . '$/iu', $expr);
        };
        $this->pdo->sqliteCreateFunction('like', $callable, 2);
        $this->pdo->sqliteCreateFunction('like', $callable, 3);
    }

    private function createSchema(): void
    {
        $statements = [
            "CREATE TABLE wp_posts (
                ID INTEGER PRIMARY KEY,
                post_author INTEGER DEFAULT 0,
                post_date TEXT DEFAULT '',
                post_date_gmt TEXT DEFAULT '',
                post_content TEXT DEFAULT '',
                post_title TEXT DEFAULT '',
                post_excerpt TEXT DEFAULT '',
                post_status TEXT DEFAULT '',
                comment_status TEXT DEFAULT '',
                ping_status TEXT DEFAULT '',
                post_password TEXT DEFAULT '',
                post_name TEXT DEFAULT '',
                to_ping TEXT DEFAULT '',
                pinged TEXT DEFAULT '',
                post_modified TEXT DEFAULT '',
                post_modified_gmt TEXT DEFAULT '',
                post_content_filtered TEXT DEFAULT '',
                post_parent INTEGER DEFAULT 0,
                guid TEXT DEFAULT '',
                menu_order INTEGER DEFAULT 0,
                post_type TEXT DEFAULT '',
                post_mime_type TEXT DEFAULT '',
                comment_count INTEGER DEFAULT 0
            )",
            "CREATE TABLE wp_postmeta (
                meta_id INTEGER PRIMARY KEY,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )",
            "CREATE TABLE wp_terms (
                term_id INTEGER PRIMARY KEY,
                name TEXT DEFAULT '',
                slug TEXT DEFAULT '',
                term_group INTEGER DEFAULT 0
            )",
            "CREATE TABLE wp_term_taxonomy (
                term_taxonomy_id INTEGER PRIMARY KEY,
                term_id INTEGER NOT NULL,
                taxonomy TEXT DEFAULT '',
                description TEXT DEFAULT '',
                parent INTEGER DEFAULT 0,
                count INTEGER DEFAULT 0
            )",
            "CREATE TABLE wp_term_relationships (
                object_id INTEGER NOT NULL,
                term_taxonomy_id INTEGER NOT NULL,
                term_order INTEGER DEFAULT 0,
                PRIMARY KEY (object_id, term_taxonomy_id)
            )",
            "CREATE TABLE wp_termmeta (
                meta_id INTEGER PRIMARY KEY,
                term_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )",
            "CREATE TABLE wp_users (
                ID INTEGER PRIMARY KEY,
                user_login TEXT DEFAULT '',
                user_pass TEXT DEFAULT '',
                user_nicename TEXT DEFAULT '',
                user_email TEXT DEFAULT '',
                user_url TEXT DEFAULT '',
                user_registered TEXT DEFAULT '',
                user_activation_key TEXT DEFAULT '',
                user_status INTEGER DEFAULT 0,
                display_name TEXT DEFAULT ''
            )",
            "CREATE TABLE wp_usermeta (
                umeta_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )",
            "CREATE TABLE wp_comments (
                comment_ID INTEGER PRIMARY KEY,
                comment_post_ID INTEGER NOT NULL DEFAULT 0,
                comment_author TEXT DEFAULT '',
                comment_author_email TEXT DEFAULT '',
                comment_author_url TEXT DEFAULT '',
                comment_author_IP TEXT DEFAULT '',
                comment_date TEXT DEFAULT '',
                comment_date_gmt TEXT DEFAULT '',
                comment_content TEXT DEFAULT '',
                comment_karma INTEGER DEFAULT 0,
                comment_approved TEXT DEFAULT '1',
                comment_agent TEXT DEFAULT '',
                comment_type TEXT DEFAULT '',
                comment_parent INTEGER DEFAULT 0,
                user_id INTEGER DEFAULT 0
            )",
            "CREATE TABLE wp_commentmeta (
                meta_id INTEGER PRIMARY KEY,
                comment_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )",
            "CREATE TABLE wp_options (
                option_id INTEGER PRIMARY KEY,
                option_name TEXT NOT NULL,
                option_value TEXT,
                autoload TEXT DEFAULT 'yes'
            )",
        ];
        foreach ($statements as $sql) {
            $this->pdo->exec($sql);
        }
    }
}
