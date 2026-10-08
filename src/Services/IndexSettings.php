<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Meilisearch\Endpoints\Indexes;

use function apply_filters;
use function get_option;
use function update_option;

/**
 * Pushes an index's settings to Meilisearch, remembering what was last pushed.
 *
 * Every settings update is a task Meilisearch queues and, when a filterable or
 * sortable attribute changes, a re-index of the whole index. Real-time indexing
 * used to send them on every save: it now sends them only when they changed.
 */
final class IndexSettings
{
    /**
     * Option prefix under which the fingerprint of the last pushed settings is kept.
     */
    private const OPTION_PREFIX = 'meiliscout/index_settings_hash/';

    /**
     * Results a search can reach, by default: Meilisearch's own default is 1000.
     */
    public const DEFAULT_MAX_TOTAL_HITS = 10000;

    /**
     * The most results a search can reach: posts_per_page -1 stops there, and so does paging.
     *
     * Set in the admin (Settings, Advanced); the meiliscout/max_total_hits filter has the last word.
     */
    public static function maxTotalHits(): int
    {
        $value = (int) \Pollora\MeiliScout\Config\Settings::get('max_total_hits', self::DEFAULT_MAX_TOTAL_HITS);

        return max(1, (int) apply_filters('meiliscout/max_total_hits', $value > 0 ? $value : self::DEFAULT_MAX_TOTAL_HITS));
    }

    /**
     * Pushes the settings, whatever was pushed before. Used by full indexations.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function push(Indexes $index, string $indexName, array $settings): void
    {
        $index->updateSettings($settings);
        self::remember($indexName, $settings);
    }

    /**
     * Records settings an index already has, such as the ones a rebuilt index was swapped in with.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function remember(string $indexName, array $settings): void
    {
        update_option(self::OPTION_PREFIX.$indexName, self::fingerprint($settings), false);
    }

    /**
     * Pushes the settings only when they differ from the last ones pushed.
     *
     * @param  array<string, mixed>  $settings
     * @return bool Whether the settings were pushed
     */
    public static function pushIfChanged(Indexes $index, string $indexName, array $settings): bool
    {
        if (get_option(self::OPTION_PREFIX.$indexName) === self::fingerprint($settings)) {
            return false;
        }

        self::push($index, $indexName, $settings);

        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function fingerprint(array $settings): string
    {
        return md5((string) json_encode($settings));
    }
}
