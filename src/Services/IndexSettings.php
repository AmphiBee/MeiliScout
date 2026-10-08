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
     * Option prefix under which the attributes an index returns and searches, and its pagination, are kept as last pushed.
     */
    private const ATTRIBUTES_OPTION_PREFIX = 'meiliscout/index_attributes/';

    /**
     * The settings queries depend on: an indexable (a plugin's, through meiliscout/indexables) may narrow them.
     */
    private const RECORDED = ['displayedAttributes', 'searchableAttributes', 'pagination'];

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
     * The most results a search of this index reaches: an indexable may have pushed a lower maxTotalHits.
     */
    public static function reachable(string $indexName): int
    {
        $attributes = get_option(self::ATTRIBUTES_OPTION_PREFIX.$indexName, []);
        $pushed = is_array($attributes) ? ($attributes['pagination']['maxTotalHits'] ?? null) : null;

        return is_numeric($pushed) && (int) $pushed > 0 ? min(self::maxTotalHits(), (int) $pushed) : self::maxTotalHits();
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
        // Read by queries, hence autoloaded
        update_option(self::ATTRIBUTES_OPTION_PREFIX.$indexName, array_intersect_key($settings, array_flip(self::RECORDED)), true);
    }

    /**
     * The attributes the index returns, as last pushed: every one (`*`) when unknown.
     *
     * @return list<string>
     */
    public static function displayed(string $indexName): array
    {
        return self::recorded($indexName, 'displayedAttributes') ?? ['*'];
    }

    /**
     * The attributes the index searches, as last pushed, or null when unknown.
     *
     * @return list<string>|null
     */
    public static function searchable(string $indexName): ?array
    {
        return self::recorded($indexName, 'searchableAttributes');
    }

    /**
     * The first of the attributes a list of settings leaves out, or null when it covers them all.
     *
     * `*` covers every attribute, and an attribute its fields: `taxonomies` covers `taxonomies.category`.
     *
     * @param  list<string>  $list
     * @param  list<string>  $attributes
     */
    public static function firstUncovered(array $list, array $attributes): ?string
    {
        if (in_array('*', $list, true)) {
            return null;
        }

        foreach ($attributes as $attribute) {
            $covered = false;

            foreach ($list as $setting) {
                if ($attribute === $setting || str_starts_with($attribute, $setting.'.')) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function recorded(string $indexName, string $setting): ?array
    {
        $attributes = get_option(self::ATTRIBUTES_OPTION_PREFIX.$indexName, []);
        $list = is_array($attributes) ? ($attributes[$setting] ?? null) : null;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : null;
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
