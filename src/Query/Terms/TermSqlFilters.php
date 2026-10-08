<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Terms;

use Pollora\MeiliScout\Query\SqlFilters;
use WP_Term_Query;

use function apply_filters;

/**
 * Tells whether a plugin changed a term query's SQL through WP_Term_Query's filters, which Meilisearch never sees.
 *
 * list_terms_exclusions, get_terms_orderby, get_terms_fields and
 * terms_clauses run before terms_pre_query. A multilingual plugin restricts
 * the terms there. As for posts (see SqlFilters), each filter is watched,
 * first and last: a change made by a callback that is not declared harmless
 * sends the query to MySQL (sql_filter:<hook>).
 *
 * These filters are not given the query. Each query opens a frame on
 * pre_get_terms, the changes go to the frame on top, and terms_pre_query
 * closes it. A query run inside another one (exclude_tree runs one) opens
 * its own frame above; one that returns before terms_pre_query leaves its
 * frame, whose changes then count for the query below.
 */
final class TermSqlFilters
{
    /**
     * The filters of WP_Term_Query::get_terms() that change its SQL.
     */
    public const HOOKS = ['get_terms_orderby', 'list_terms_exclusions', 'get_terms_fields', 'terms_clauses'];

    /**
     * Open frames, from the oldest: the query, what each filter was given, and the filters that changed something.
     *
     * @var list<array{query: WP_Term_Query, given: array<string, mixed>, changed: array<string, true>}>
     */
    private static array $frames = [];

    private static bool $watching = false;

    /**
     * Watches the filters. Called once.
     */
    public static function watch(): void
    {
        if (self::$watching) {
            return;
        }

        self::$watching = true;
        add_action('pre_get_terms', [self::class, 'open'], PHP_INT_MIN);

        foreach (self::HOOKS as $hook) {
            add_filter($hook, [self::class, 'given'], PHP_INT_MIN);
            add_filter($hook, [self::class, 'returned'], PHP_INT_MAX);
        }
    }

    /**
     * Opens the frame of a query. Hooked on pre_get_terms.
     */
    public static function open(mixed $query): void
    {
        if ($query instanceof WP_Term_Query) {
            self::$frames[] = ['query' => $query, 'given' => [], 'changed' => []];
        }
    }

    /**
     * Records what a filter is given, before any other callback.
     */
    public static function given(mixed $value): mixed
    {
        $top = array_key_last(self::$frames);

        if ($top !== null) {
            self::$frames[$top]['given'][(string) current_filter()] = $value;
        }

        return $value;
    }

    /**
     * Compares what a filter returns with what it was given, after every other callback.
     */
    public static function returned(mixed $value): mixed
    {
        $top = array_key_last(self::$frames);
        $hook = (string) current_filter();

        if ($top !== null && array_key_exists($hook, self::$frames[$top]['given']) && self::$frames[$top]['given'][$hook] !== $value) {
            self::$frames[$top]['changed'][$hook] = true;
        }

        return $value;
    }

    /**
     * Closes a query's frame, and those left open above it: the filters a plugin changed its SQL with.
     *
     * @return list<string>
     */
    public static function close(WP_Term_Query $query): array
    {
        $changed = [];

        for ($index = count(self::$frames) - 1; $index >= 0; $index--) {
            $frame = array_pop(self::$frames);
            $changed = [...$changed, ...array_keys($frame['changed'])];

            if ($frame['query'] === $query) {
                return array_values(array_unique($changed));
            }
        }

        // No frame: the query did not fire pre_get_terms
        return [];
    }

    /**
     * The first filter a plugin changed the query's SQL with, unless it is declared harmless; null when none.
     *
     * @param  list<string>  $changed  The filters that changed something
     */
    public static function changedBy(array $changed): ?string
    {
        /**
         * Filters the SQL filters that do not stop Meilisearch from serving a term query.
         *
         * As meiliscout/ignored_sql_filters: hooks, callback names, closures.
         *
         * @param  list<mixed>  $ignored  Default empty.
         */
        $ignored = apply_filters('meiliscout/ignored_term_sql_filters', []);

        foreach ($changed as $hook) {
            if (! in_array($hook, $ignored, true) && self::unknownCallbacks($hook, $ignored)) {
                return $hook;
            }
        }

        return null;
    }

    /**
     * Whether a callback hooked on the filter is neither the plugin's own nor declared harmless.
     *
     * @param  list<mixed>  $ignored
     */
    private static function unknownCallbacks(string $hook, array $ignored): bool
    {
        global $wp_filter;

        foreach (isset($wp_filter[$hook]) ? $wp_filter[$hook]->callbacks : [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];

                if ($function === [self::class, 'given'] || $function === [self::class, 'returned']) {
                    continue;
                }

                if (! in_array(SqlFilters::name($function), $ignored, true) && ! in_array($function, $ignored, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Forgets the open frames. For tests.
     */
    public static function reset(): void
    {
        self::$frames = [];
    }
}
