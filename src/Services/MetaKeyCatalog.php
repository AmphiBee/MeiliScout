<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Providers\SingleIndexingServiceProvider;

use function get_transient;
use function set_transient;

/**
 * The meta keys of the posts that can be indexed, for the admin to pick from.
 *
 * Counting them reads the whole postmeta table: the list is cached for an hour.
 */
final class MetaKeyCatalog
{
    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_LIST = 'list';

    public const TYPE_TEXT = 'text';

    public const TYPE_EMPTY = 'empty';

    private const CACHE_KEY = 'meiliscout_meta_keys_';

    private const CACHE_TTL = 3600;

    /**
     * Values read to guess a key's type.
     */
    private const SAMPLE_SIZE = 50;

    /**
     * The meta keys of the published posts of these types, most used first.
     *
     * @param  list<string>  $postTypes
     * @return list<array{key: string, posts: int, post_types: array<string, int>}>
     */
    public function keys(array $postTypes): array
    {
        if ($postTypes === []) {
            return [];
        }

        sort($postTypes);
        $cacheKey = self::CACHE_KEY.md5(implode(',', $postTypes));
        $cached = get_transient($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $statuses = PostIndexable::indexableStatuses();
        $rows = $wpdb->get_results($wpdb->prepare(
            sprintf(
                "SELECT pm.meta_key, p.post_type, COUNT(DISTINCT p.ID) AS posts
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type IN (%s) AND p.post_status IN (%s)
                 GROUP BY pm.meta_key, p.post_type",
                implode(',', array_fill(0, count($postTypes), '%s')),
                implode(',', array_fill(0, count($statuses), '%s'))
            ),
            ...$postTypes,
            ...$statuses
        ), ARRAY_A);

        $keys = [];

        foreach ((array) $rows as $row) {
            $key = (string) $row['meta_key'];

            if (in_array($key, SingleIndexingServiceProvider::INTERNAL_META_KEYS, true)) {
                continue;
            }

            $keys[$key] ??= ['key' => $key, 'posts' => 0, 'post_types' => []];
            $keys[$key]['posts'] += (int) $row['posts'];
            $keys[$key]['post_types'][(string) $row['post_type']] = (int) $row['posts'];
        }

        usort($keys, static fn (array $a, array $b) => [$b['posts'], $a['key']] <=> [$a['posts'], $b['key']]);

        set_transient($cacheKey, $keys, self::CACHE_TTL);

        return $keys;
    }

    /**
     * The type of each key, guessed from some of its values.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public function types(array $keys): array
    {
        global $wpdb;

        $types = [];

        foreach ($keys as $key) {
            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT %d",
                $key,
                self::SAMPLE_SIZE
            ));

            $types[$key] = self::detect(array_map('strval', (array) $values));
        }

        return $types;
    }

    /**
     * The type every value fits, from the narrowest.
     *
     * @param  list<string>  $values
     */
    public static function detect(array $values): string
    {
        if ($values === []) {
            return self::TYPE_EMPTY;
        }

        $all = static fn (callable $test) => array_reduce($values, static fn (bool $carry, string $value) => $carry && $test($value), true);

        return match (true) {
            // A serialized array, read without building any object
            $all(static fn (string $value) => (bool) preg_match('/^a:\d+:\{.*\}$/s', $value)
                && is_array(@unserialize($value, ['allowed_classes' => false]))) => self::TYPE_LIST,
            $all(static fn (string $value) => in_array(strtolower($value), ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true))
                && ! $all(static fn (string $value) => in_array($value, ['0', '1'], true)) => self::TYPE_BOOLEAN,
            $all(self::isDate(...)) => self::TYPE_DATE,
            $all(static fn (string $value) => is_numeric($value)) => self::TYPE_NUMBER,
            default => self::TYPE_TEXT,
        };
    }

    /**
     * Y-m-d, with an optional time, or Ymd as ACF stores its dates.
     */
    private static function isDate(string $value): bool
    {
        if (! preg_match('/^(\d{4})-?(\d{2})-?(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value, $parts)) {
            return false;
        }

        // Ymd without dashes only when it can be a date at all: 12345678 is a number
        $hasDashes = str_contains($value, '-');
        [, $year, $month, $day] = array_map('intval', $parts);

        return checkdate($month, $day, $year) && ($hasDashes || ($year >= 1900 && $year <= 2100));
    }
}
