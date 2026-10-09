<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Meilisearch\Search\SearchResult;

/**
 * Meilisearch's answers, kept for the rest of the request.
 *
 * Several WP_Query of a page ask for the same search: a Query Loop runs its
 * query again in each pagination block to know the number of pages. The
 * answer is the same, as nothing is written to the index in between that the
 * search could see (Meilisearch applies writes as tasks, after the request).
 *
 * Keyed by index and parameters; forgotten when a post or its terms change,
 * which a later query of the same request may depend on.
 */
final class SearchMemo
{
    /**
     * How many answers are kept: a page asks for a handful.
     */
    private const CAPACITY = 50;

    /** @var array<string, SearchResult> */
    private static array $answers = [];

    private static bool $hooked = false;

    /**
     * The memoized answer to this search, or the one $search gives, kept.
     *
     * @param  array<string, mixed>  $params
     * @param  callable(): SearchResult  $search
     * @param  bool  $memoized  Set to whether the answer came from the memo
     */
    public static function remember(string $index, array $params, callable $search, bool &$memoized = false): SearchResult
    {
        $memoized = false;

        /**
         * Filters whether Meilisearch's answers are kept for the rest of the request.
         *
         * @param  bool  $enabled  Default true.
         */
        if (! apply_filters('meiliscout/search_memo', true)) {
            return $search();
        }

        self::hook();

        $key = $index."\0".serialize($params);

        if (isset(self::$answers[$key])) {
            $memoized = true;

            return self::$answers[$key];
        }

        $answer = $search();

        if (count(self::$answers) >= self::CAPACITY) {
            array_shift(self::$answers);
        }

        return self::$answers[$key] = $answer;
    }

    public static function flush(): void
    {
        self::$answers = [];
    }

    /**
     * Back to the start of a request: no answer, no hook (tests).
     */
    public static function reset(): void
    {
        self::$answers = [];
        self::$hooked = false;
    }

    /**
     * A post saved, deleted or given other terms during the request: the
     * queries after it ask Meilisearch again.
     */
    private static function hook(): void
    {
        if (self::$hooked) {
            return;
        }

        self::$hooked = true;

        add_action('clean_post_cache', [self::class, 'flush']);
        add_action('clean_object_term_cache', [self::class, 'flush']);
    }
}
