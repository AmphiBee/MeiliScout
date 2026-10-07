<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Meilisearch\Endpoints\Indexes;

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
     * Pushes the settings, whatever was pushed before. Used by full indexations.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function push(Indexes $index, string $indexName, array $settings): void
    {
        $index->updateSettings($settings);
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
