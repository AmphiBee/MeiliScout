<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use function apply_filters;
use function function_exists;
use function get_taxonomies;
use function wp_cache_add_non_persistent_groups;
use function wp_cache_flush_runtime;

/**
 * Keeps an indexing run off the persistent object cache.
 *
 * Indexing reads posts, meta and terms in bulk. With a persistent object cache
 * (Redis), every primed post would be written back to the shared cache, which
 * evicts the entries the site actually serves from, and a large batch can
 * exceed Redis' maxmemory outright. During run(), the groups an indexing run
 * reads are made non-persistent: they live in PHP memory only for the run and
 * go back to normal afterwards.
 *
 * Options stay persistent on purpose: the async queue lives in an option, and
 * a write that skipped Redis would leave the web requests a stale copy.
 *
 * Isolation applies to the Redis Object Cache drop-in (it exposes
 * `ignored_groups`). With any other cache, run() simply calls the callback.
 */
final class ObjectCacheIsolation
{
    /**
     * Runs $callback with the indexing groups kept out of the persistent cache.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        global $wp_object_cache;

        if (! self::isSupported($wp_object_cache)) {
            return $callback();
        }

        $previousIgnored = $wp_object_cache->ignored_groups;
        $previousTypes = self::groupTypes($wp_object_cache);

        wp_cache_add_non_persistent_groups(self::groups());

        try {
            return $callback();
        } finally {
            // The drop-in can add non-persistent groups but not remove them:
            // restore its exact state, so later writes in this process (other
            // cron events) invalidate Redis again.
            $wp_object_cache->ignored_groups = $previousIgnored;
            self::setGroupTypes($wp_object_cache, $previousTypes);

            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
        }
    }

    /**
     * Groups an indexing run reads, made non-persistent for its duration.
     *
     * @return string[]
     */
    private static function groups(): array
    {
        $groups = [
            'posts',
            'post_meta',
            'post-queries',
            'terms',
            'term_meta',
            'term-queries',
            'users',
            'user_meta',
            'userlogins',
            'useremail',
            'userslugs',
            'user-queries',
        ];

        foreach (get_taxonomies() as $taxonomy) {
            $groups[] = "{$taxonomy}_relationships";
        }

        /**
         * Filters the object cache groups kept out of the persistent cache while indexing.
         *
         * Return an empty array to disable the isolation.
         *
         * @param string[] $groups
         */
        return (array) apply_filters('meiliscout/indexing_non_persistent_groups', $groups);
    }

    private static function isSupported(mixed $cache): bool
    {
        return is_object($cache)
            && property_exists($cache, 'ignored_groups')
            && is_array($cache->ignored_groups)
            && property_exists($cache, 'group_type')
            && function_exists('wp_cache_add_non_persistent_groups')
            && self::groups() !== [];
    }

    /**
     * @return array<string, string>
     */
    private static function groupTypes(object $cache): array
    {
        return (fn () => $this->group_type)->call($cache);
    }

    /**
     * @param array<string, string> $types
     */
    private static function setGroupTypes(object $cache, array $types): void
    {
        (function () use ($types): void {
            $this->group_type = $types;
        })->call($cache);
    }
}
