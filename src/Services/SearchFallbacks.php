<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use function add_action;
use function get_option;
use function update_option;

/**
 * Counts the queries asking for Meilisearch that MySQL served instead, per hour and per reason.
 *
 * A reason is what QueryIntegration records: engine_error, unreachable,
 * unsupported_arg:author, unindexed_meta:price, unindexed_status:draft...
 * Counts are kept for a day, and written once per request, at its end.
 */
final class SearchFallbacks
{
    /**
     * Meilisearch answered with an error.
     */
    public const ERROR = 'engine_error';

    /**
     * Meilisearch could not be reached.
     */
    public const UNREACHABLE = 'unreachable';

    /**
     * Prefix of the reasons for a meta key that is not indexed.
     */
    public const UNINDEXED_META = 'unindexed_meta';

    /**
     * Reasons kept per hour: a site with many distinct ones keeps the most frequent.
     */
    private const REASONS_KEPT = 50;

    private const OPTION = 'meiliscout/search_fallbacks';

    private const HOURS_KEPT = 24;

    /**
     * Fallbacks of this request, by reason.
     *
     * @var array<string, int>
     */
    private static array $pending = [];

    /**
     * Whether fallbacks are not counted right now.
     */
    private static bool $paused = false;

    public static function record(string $reason): void
    {
        if (self::$paused) {
            return;
        }

        if (self::$pending === []) {
            add_action('shutdown', [self::class, 'flush']);
        }

        self::$pending[$reason] = (self::$pending[$reason] ?? 0) + 1;
    }

    /**
     * Runs a callback without counting the fallbacks of its queries: diagnostics are no traffic.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRecording(callable $callback): mixed
    {
        $paused = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $paused;
        }
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

        arsort($buckets[$hour]);
        $buckets[$hour] = array_slice($buckets[$hour], 0, self::REASONS_KEPT, true);

        self::$pending = [];

        // Not autoloaded: only the admin reads it
        update_option(self::OPTION, $buckets, false);
    }

    /**
     * The fallbacks of the last 24 hours: in all, by reason (most frequent first),
     * those due to Meilisearch failing, and those due to meta keys not indexed.
     *
     * @return array{total: int, error: int, meta: int, reasons: array<string, int>}
     */
    public static function lastDay(): array
    {
        $reasons = [];

        foreach (self::recentBuckets() as $counts) {
            foreach ((array) $counts as $reason => $count) {
                $reason = self::LEGACY_REASONS[$reason] ?? (string) $reason;
                $reasons[$reason] = ($reasons[$reason] ?? 0) + (int) $count;
            }
        }

        arsort($reasons);

        $sum = static fn (callable $matches) => array_sum(array_filter($reasons, $matches, ARRAY_FILTER_USE_KEY));

        return [
            'total' => array_sum($reasons),
            'error' => $sum(static fn (string $reason) => in_array($reason, [self::ERROR, self::UNREACHABLE, 'build_error'], true)),
            'meta' => $sum(static fn (string $reason) => str_starts_with($reason, self::UNINDEXED_META)),
            'reasons' => $reasons,
        ];
    }

    /**
     * Reasons recorded before they were detailed.
     */
    private const LEGACY_REASONS = ['error' => self::ERROR, 'meta' => self::UNINDEXED_META];

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
