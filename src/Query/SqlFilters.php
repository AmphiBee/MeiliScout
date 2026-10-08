<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use WeakMap;
use WP_Query;

use function apply_filters;

/**
 * Tells whether a plugin changed a query's SQL through WP_Query's filters, which Meilisearch never sees.
 *
 * posts_where, posts_join, posts_clauses... run before posts_pre_query,
 * unless suppress_filters is set. A multilingual plugin, a membership
 * plugin or a shop restricts the posts there: served from Meilisearch, the
 * query would return posts MySQL would not. So each of these filters is
 * watched, first and last, for every query: when what it returns differs
 * from what it was given, and a callback that is not declared harmless is
 * hooked on it, the query runs on MySQL (sql_filter:<hook>).
 *
 * A filter that changes nothing for a query (a plugin that only acts on
 * some queries) costs nothing. One whose change the site translates itself,
 * through meiliscout/search_params, is declared with meiliscout/ignored_sql_filters.
 */
final class SqlFilters
{
    /**
     * The filters of WP_Query::get_posts() that change its SQL, in the order they run.
     */
    public const HOOKS = [
        'posts_search', 'posts_search_orderby', 'posts_where', 'posts_join', 'posts_where_paged', 'posts_groupby',
        'posts_join_paged', 'posts_orderby', 'posts_distinct', 'post_limits', 'posts_fields', 'posts_clauses',
        'posts_where_request', 'posts_groupby_request', 'posts_join_request', 'posts_orderby_request',
        'posts_distinct_request', 'posts_fields_request', 'post_limits_request', 'posts_clauses_request', 'posts_request',
    ];

    /**
     * What each filter was given, by query.
     *
     * @var WeakMap<WP_Query, array<string, mixed>>|null
     */
    private static ?WeakMap $given = null;

    /**
     * The filters that changed something, by query.
     *
     * @var WeakMap<WP_Query, array<string, true>>|null
     */
    private static ?WeakMap $changed = null;

    /**
     * Watches the filters. Called once.
     */
    public static function watch(): void
    {
        self::$given = new WeakMap;
        self::$changed = new WeakMap;

        // A WP_Query object can run several queries
        add_action('pre_get_posts', [self::class, 'forget'], PHP_INT_MIN);

        foreach (self::HOOKS as $hook) {
            add_filter($hook, [self::class, 'given'], PHP_INT_MIN, 2);
            add_filter($hook, [self::class, 'returned'], PHP_INT_MAX, 2);
        }
    }

    /**
     * Forgets what the previous run of a query recorded. Hooked on pre_get_posts.
     */
    public static function forget(mixed $query): void
    {
        if ($query instanceof WP_Query && self::$given !== null && self::$changed !== null) {
            unset(self::$given[$query], self::$changed[$query]);
        }
    }

    /**
     * Records what a filter is given, before any other callback. Hooked first.
     */
    public static function given(mixed $value, mixed $query = null): mixed
    {
        if ($query instanceof WP_Query && self::$given !== null) {
            /** @var array<string, mixed> $given */
            $given = self::$given[$query] ?? [];
            $given[(string) current_filter()] = $value;
            self::$given[$query] = $given;
        }

        return $value;
    }

    /**
     * Compares what a filter returns with what it was given, after every other callback. Hooked last.
     */
    public static function returned(mixed $value, mixed $query = null): mixed
    {
        if (! $query instanceof WP_Query || self::$given === null || self::$changed === null) {
            return $value;
        }

        $hook = current_filter();
        $given = self::$given[$query] ?? [];

        if (array_key_exists($hook, $given) && $given[$hook] !== $value) {
            $changed = self::$changed[$query] ?? [];
            $changed[$hook] = true;
            self::$changed[$query] = $changed;
        }

        return $value;
    }

    /**
     * The first filter a plugin changed the query's SQL with, unless it is declared harmless; null when none.
     */
    public static function changedBy(WP_Query $query): ?string
    {
        if (self::$changed === null || ! isset(self::$changed[$query])) {
            return null;
        }

        $ignored = self::ignored();

        foreach (array_keys(self::$changed[$query]) as $hook) {
            if (! in_array($hook, $ignored, true) && self::unknownCallbacks($hook, $ignored) !== []) {
                return $hook;
            }
        }

        return null;
    }

    /**
     * The callbacks hooked on a filter, but the plugin's own and the ones declared harmless.
     *
     * @param  list<mixed>  $ignored
     * @return list<string>
     */
    private static function unknownCallbacks(string $hook, array $ignored): array
    {
        global $wp_filter;

        $unknown = [];

        foreach (isset($wp_filter[$hook]) ? $wp_filter[$hook]->callbacks : [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];

                if ($function === [self::class, 'given'] || $function === [self::class, 'returned']) {
                    continue;
                }

                $name = self::name($function);

                if (! in_array($name, $ignored, true) && ! in_array($function, $ignored, true)) {
                    $unknown[] = $name;
                }
            }
        }

        return $unknown;
    }

    /**
     * What meiliscout/ignored_sql_filters declares harmless: hooks, callback names, closures.
     *
     * @return list<mixed>
     */
    private static function ignored(): array
    {
        /**
         * Filters the SQL filters that do not stop Meilisearch from serving a query.
         *
         * Each entry is a hook ('posts_where': every callback on it), a
         * callback's name ('my_function', 'My_Class::method'), or the closure
         * itself. Declare a callback that changes nothing Meilisearch serves,
         * or whose change the site translates in meiliscout/search_params.
         *
         * @param  list<mixed>  $ignored  Default empty.
         */
        return apply_filters('meiliscout/ignored_sql_filters', []);
    }

    /**
     * A callback's name: 'function', 'Class::method', or 'Closure'.
     */
    public static function name(mixed $callback): string
    {
        return match (true) {
            is_string($callback) => $callback,
            $callback instanceof \Closure => 'Closure',
            is_array($callback) && isset($callback[0], $callback[1]) => (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]).'::'.(string) $callback[1],
            is_object($callback) => get_class($callback).'::__invoke',
            default => 'unknown',
        };
    }
}
