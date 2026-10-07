<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use function add_action;
use function get_option;
use function update_option;

/**
 * Counts the queries asking for Meilisearch that MySQL served instead, per hour.
 *
 * A query falls back when Meilisearch fails or cannot be reached, or when it
 * filters on a meta key that is not indexed. Counts are kept for a day, and
 * written once per request, at its end.
 */
final class SearchFallbacks
{
    public const ERROR = 'error';

    public const UNINDEXED_META = 'meta';

    private const OPTION = 'meiliscout/search_fallbacks';

    private const HOURS_KEPT = 24;

    /**
     * Fallbacks of this request, by reason.
     *
     * @var array<string, int>
     */
    private static array $pending = [];

    public static function record(string $reason): void
    {
        if (self::$pending === []) {
            add_action('shutdown', [self::class, 'flush']);
        }

        self::$pending[$reason] = (self::$pending[$reason] ?? 0) + 1;
    }

    /**
     * Adds this request's fallbacks to the hour's count. Hooked on shutdown.
     */
    public static function flush(): void
    {
        if (self::$pending === []) {
            return;
        }

        $hour = self::hour(time());
        $buckets = self::recentBuckets();

        foreach (self::$pending as $reason => $count) {
            $buckets[$hour][$reason] = ($buckets[$hour][$reason] ?? 0) + $count;
        }

        self::$pending = [];

        // Not autoloaded: only the admin reads it
        update_option(self::OPTION, $buckets, false);
    }

    /**
     * The fallbacks of the last 24 hours, by reason.
     *
     * @return array{error: int, meta: int, total: int}
     */
    public static function lastDay(): array
    {
        $totals = [self::ERROR => 0, self::UNINDEXED_META => 0];

        foreach (self::recentBuckets() as $counts) {
            foreach ($totals as $reason => $total) {
                $totals[$reason] = $total + (int) ($counts[$reason] ?? 0);
            }
        }

        return [...$totals, 'total' => array_sum($totals)];
    }

    /**
     * Forgets the fallbacks of this request not written yet. For tests.
     */
    public static function reset(): void
    {
        self::$pending = [];
    }

    /**
     * @return array<int, array<string, int>>
     */
    private static function recentBuckets(): array
    {
        $buckets = get_option(self::OPTION, []);

        if (! is_array($buckets)) {
            return [];
        }

        $oldest = self::hour(time()) - (self::HOURS_KEPT - 1) * 3600;

        return array_filter($buckets, static fn ($hour) => (int) $hour >= $oldest, ARRAY_FILTER_USE_KEY);
    }

    private static function hour(int $timestamp): int
    {
        return $timestamp - $timestamp % 3600;
    }
}
