<?php

declare(strict_types=1);

namespace IdempotentExport\Tests\Support;

/**
 * Insert sample rows into a FakeWpdb. Each method is independent and returns
 * the IDs that were inserted, so tests can chain operations.
 */
class Fixtures
{
    public static function insertPost(FakeWpdb $wpdb, array $overrides = []): int
    {
        $row = array_merge([
            'post_author'           => 1,
            'post_date'             => '2024-03-15 10:30:00',
            'post_date_gmt'         => '2024-03-15 10:30:00',
            'post_content'          => '',
            'post_title'            => 'Untitled',
            'post_excerpt'          => '',
            'post_status'           => 'publish',
            'comment_status'        => 'open',
            'ping_status'           => 'open',
            'post_password'         => '',
            'post_name'             => 'untitled',
            'to_ping'               => '',
            'pinged'                => '',
            'post_modified'         => '2024-03-15 10:30:00',
            'post_modified_gmt'     => '2024-03-15 10:30:00',
            'post_content_filtered' => '',
            'post_parent'           => 0,
            'guid'                  => '',
            'menu_order'            => 0,
            'post_type'             => 'post',
            'post_mime_type'        => '',
            'comment_count'         => 0,
        ], $overrides);
        return self::insert($wpdb, 'wp_posts', $row);
    }

    public static function insertPostMeta(FakeWpdb $wpdb, int $postId, string $key, string $rawValue): int
    {
        return self::insert($wpdb, 'wp_postmeta', [
            'post_id'    => $postId,
            'meta_key'   => $key,
            'meta_value' => $rawValue,
        ]);
    }

    public static function insertTerm(FakeWpdb $wpdb, string $name, string $slug, string $taxonomy, array $overrides = []): array
    {
        $termId = self::insert($wpdb, 'wp_terms', array_merge([
            'name'       => $name,
            'slug'       => $slug,
            'term_group' => 0,
        ], $overrides['term'] ?? []));

        $ttId = self::insert($wpdb, 'wp_term_taxonomy', array_merge([
            'term_id'     => $termId,
            'taxonomy'    => $taxonomy,
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
        ], $overrides['tt'] ?? []));

        return ['term_id' => $termId, 'term_taxonomy_id' => $ttId];
    }

    public static function insertTermMeta(FakeWpdb $wpdb, int $termId, string $key, string $rawValue): int
    {
        return self::insert($wpdb, 'wp_termmeta', [
            'term_id'    => $termId,
            'meta_key'   => $key,
            'meta_value' => $rawValue,
        ]);
    }

    public static function assignTerm(FakeWpdb $wpdb, int $postId, int $termTaxonomyId): void
    {
        self::insert($wpdb, 'wp_term_relationships', [
            'object_id'        => $postId,
            'term_taxonomy_id' => $termTaxonomyId,
            'term_order'       => 0,
        ]);
    }

    public static function insertUser(FakeWpdb $wpdb, array $overrides = []): int
    {
        $row = array_merge([
            'user_login'          => 'user',
            'user_pass'           => '$P$Bxxxxxx',
            'user_nicename'       => 'user',
            'user_email'          => 'user@example.test',
            'user_url'            => '',
            'user_registered'     => '2024-01-01 00:00:00',
            'user_activation_key' => '',
            'user_status'         => 0,
            'display_name'        => 'User',
        ], $overrides);
        return self::insert($wpdb, 'wp_users', $row);
    }

    public static function insertUserMeta(FakeWpdb $wpdb, int $userId, string $key, string $rawValue): int
    {
        return self::insert($wpdb, 'wp_usermeta', [
            'user_id'    => $userId,
            'meta_key'   => $key,
            'meta_value' => $rawValue,
        ]);
    }

    public static function insertComment(FakeWpdb $wpdb, int $postId, array $overrides = []): int
    {
        $row = array_merge([
            'comment_post_ID'      => $postId,
            'comment_author'       => 'Anon',
            'comment_author_email' => 'anon@example.test',
            'comment_author_url'   => '',
            'comment_author_IP'    => '127.0.0.1',
            'comment_date'         => '2024-04-01 12:00:00',
            'comment_date_gmt'     => '2024-04-01 12:00:00',
            'comment_content'      => 'Hello.',
            'comment_karma'        => 0,
            'comment_approved'     => '1',
            'comment_agent'        => '',
            'comment_type'         => 'comment',
            'comment_parent'       => 0,
            'user_id'              => 0,
        ], $overrides);
        return self::insert($wpdb, 'wp_comments', $row);
    }

    public static function insertCommentMeta(FakeWpdb $wpdb, int $commentId, string $key, string $rawValue): int
    {
        return self::insert($wpdb, 'wp_commentmeta', [
            'comment_id' => $commentId,
            'meta_key'   => $key,
            'meta_value' => $rawValue,
        ]);
    }

    public static function insertOption(FakeWpdb $wpdb, string $name, string $rawValue, string $autoload = 'yes'): int
    {
        return self::insert($wpdb, 'wp_options', [
            'option_name'  => $name,
            'option_value' => $rawValue,
            'autoload'     => $autoload,
        ]);
    }

    private static function insert(FakeWpdb $wpdb, string $table, array $row): int
    {
        $cols     = array_keys($row);
        $place    = array_fill(0, count($cols), '?');
        $sql      = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', $place)
        );
        $stmt = $wpdb->pdo->prepare($sql);
        $i    = 1;
        foreach ($row as $value) {
            $stmt->bindValue($i++, $value);
        }
        $stmt->execute();
        return (int) $wpdb->pdo->lastInsertId();
    }
}
