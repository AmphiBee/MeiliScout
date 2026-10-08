<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Indexables;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;
use Pollora\MeiliScout\Services\MetaValueFlags;
use WP_Term;

use function apply_filters;
use function get_term;
use function get_term_children;
use function get_term_link;
use function get_term_meta;
use function get_terms;
use function is_taxonomy_hierarchical;
use function is_wp_error;
use function maybe_unserialize;
use function update_termmeta_cache;
use function wp_cache_get;

/**
 * The terms of the indexed taxonomies, for get_terms() (WP_Term_Query) and term searches.
 *
 * Schema 4 documents carry what term queries are translated against: ids
 * and counts as numbers, the name and the description folded the way
 * MySQL's collation compares them (name_sort, description_fold, description_sort), and
 * tree_count, the posts of the term and of its descendants, for hide_empty
 * on a hierarchical taxonomy.
 */
class TaxonomyIndexable implements Indexable
{
    /**
     * Setting holding the term meta keys to index, apart from the posts' (Content screen).
     */
    public const META_KEYS_SETTING = 'indexed_term_meta_keys';

    private array $metaKeys = [];

    /**
     * Preloaded meta cache indexed by term ID.
     * @var array<int, array<string, mixed>>
     */
    private array $preloadedMeta = [];

    /**
     * Whether batch data has been preloaded.
     */
    private bool $batchPreloaded = false;

    /**
     * Each term's count, and its children, by taxonomy: tree counts of a batch are worked out from them.
     *
     * @var array<string, array{counts: array<int, int>, children: array<int, list<int>>}>
     */
    private array $hierarchies = [];

    public function getIndexName(): string
    {
        return IndexNames::target('taxonomies');
    }

    public function getPrimaryKey(): string
    {
        return 'term_id';
    }

    public function getIndexSettings(): array
    {
        $metaAttributes = array_map(static fn (string $key) => "metas.{$key}", self::selectedMetaKeys());

        return [
            'filterableAttributes' => [
                'taxonomy', 'term_id', 'term_taxonomy_id', 'name_sort', 'slug', 'description_fold',
                'parent', 'count', 'tree_count', 'term_group', ...$metaAttributes,
            ],
            'sortableAttributes' => [
                'name', 'name_sort', 'slug', 'description_sort', 'term_group', 'term_id', 'term_taxonomy_id',
                'count', 'parent', 'taxonomy', ...$metaAttributes,
            ],
            // A term query's search looks into the name and the slug, as WordPress does
            'searchableAttributes' => ['name', 'slug', 'description'],
            'rankingRules' => self::rankingRules(),
            'pagination' => ['maxTotalHits' => IndexSettings::maxTotalHits()],
        ];
    }

    /**
     * The ranking rules of the taxonomies index: `sort` first, so that an order is followed strictly.
     *
     * @return list<string>
     */
    public static function rankingRules(): array
    {
        return array_values((array) apply_filters(
            'meiliscout/term/ranking_rules',
            ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness']
        ));
    }

    public function getItems(?int $offset = null, ?int $limit = null): iterable
    {
        $taxonomies = array_values((array) Settings::get('indexed_taxonomies', []));
        $this->metaKeys = self::resolveMetaKeys($taxonomies);

        if ($taxonomies === []) {
            return;
        }

        // One list across the taxonomies: an offset is a position in the whole run, not in each taxonomy
        $terms = get_terms([
            'taxonomy' => $taxonomies,
            'hide_empty' => false,
            'orderby' => 'term_id',
            'order' => 'ASC',
            'offset' => $offset ?? 0,
            'number' => $limit ?? 0, // 0 means no limit in get_terms
            'update_term_meta_cache' => false,
            'use_meilisearch' => false,
        ]);

        if (is_wp_error($terms)) {
            return;
        }

        yield from $terms;
    }

    /**
     * The term meta keys selected in the Content screen.
     *
     * @return list<string>
     */
    public static function selectedMetaKeys(): array
    {
        return array_values(array_filter((array) Settings::get(self::META_KEYS_SETTING, []), 'is_string'));
    }

    /**
     * The term meta keys documents carry: the ones selected for terms, else every key of these taxonomies' terms.
     *
     * @param  list<string>  $taxonomies
     * @return list<string>
     */
    public static function resolveMetaKeys(array $taxonomies): array
    {
        $configured = self::selectedMetaKeys();

        return $configured !== [] ? $configured : self::gatherMetaKeysFromTaxonomies($taxonomies);
    }

    /**
     * Every meta key of the terms of these taxonomies.
     *
     * @param  list<string>  $taxonomies
     * @return list<string>
     */
    private static function gatherMetaKeysFromTaxonomies(array $taxonomies): array
    {
        global $wpdb;

        if ($taxonomies === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($taxonomies), '%s'));

        return array_values(array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tm.meta_key FROM {$wpdb->termmeta} tm
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
             WHERE tt.taxonomy IN ({$placeholders})",
            ...$taxonomies
        ))));
    }

    public function formatForIndexing(mixed $item): array
    {
        if (! $item instanceof WP_Term) {
            throw new \InvalidArgumentException('Item must be instance of WP_Term');
        }

        $document = get_object_vars($item);
        $link = get_term_link($item);

        $document = [
            ...$document,
            // Numbers as numbers, for sorts and comparisons to be numeric
            'term_id' => (int) $item->term_id,
            'term_taxonomy_id' => (int) $item->term_taxonomy_id,
            'parent' => (int) $item->parent,
            'count' => (int) $item->count,
            'term_group' => (int) $item->term_group,
            // Folded as MySQL's collation compares them
            'name_sort' => PostIndexable::titleSortKey((string) $item->name),
            'description_fold' => PostIndexable::titleSortKey((string) $item->description),
            // Meilisearch sorts '' last, MySQL first: ' ' sorts first
            'description_sort' => PostIndexable::titleSortKey((string) $item->description) ?: ' ',
            'tree_count' => $this->treeCount($item),
            'url' => is_string($link) ? $link : '',
            'metas' => $this->getMetaData($item),
        ];

        $filtered = apply_filters('meiliscout/term/document', $document, $item);
        MetaValueFlags::noteAltered($document['metas'], is_array($filtered) ? (array) ($filtered['metas'] ?? []) : [], 'term');

        return $filtered;
    }

    public function formatForSearch(array $hit): mixed
    {
        return new WP_Term((object) $hit);
    }

    /**
     * The posts of a term and of its descendants: hide_empty keeps an empty term with a descendant that has posts.
     */
    public function treeCount(WP_Term $term): int
    {
        $count = (int) $term->count;

        if (! is_taxonomy_hierarchical($term->taxonomy)) {
            return $count;
        }

        if (isset($this->hierarchies[$term->taxonomy])) {
            return $this->treeCountFrom($this->hierarchies[$term->taxonomy], (int) $term->term_id);
        }

        $children = get_term_children((int) $term->term_id, $term->taxonomy);

        if (! is_array($children) || $children === []) {
            return $count;
        }

        global $wpdb;

        $ids = implode(',', array_map('intval', $children));

        return $count + (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(count) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id IN ({$ids})",
            $term->taxonomy
        ));
    }

    /**
     * @param  array{counts: array<int, int>, children: array<int, list<int>>}  $hierarchy
     * @param  array<int, true>  $seen  Guards against a loop in the hierarchy
     */
    private function treeCountFrom(array $hierarchy, int $termId, array $seen = []): int
    {
        $total = $hierarchy['counts'][$termId] ?? 0;
        $seen[$termId] = true;

        foreach ($hierarchy['children'][$termId] ?? [] as $child) {
            if (! isset($seen[$child])) {
                $total += $this->treeCountFrom($hierarchy, $child, $seen);
            }
        }

        return $total;
    }

    /**
     * Each term's count and children in a hierarchical taxonomy, read once for a batch.
     *
     * @return array{counts: array<int, int>, children: array<int, list<int>>}
     */
    private function loadHierarchy(string $taxonomy): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, parent, count FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
            $taxonomy
        ));
        $hierarchy = ['counts' => [], 'children' => []];

        foreach ($rows as $row) {
            $hierarchy['counts'][(int) $row->term_id] = (int) $row->count;

            if ((int) $row->parent > 0) {
                $hierarchy['children'][(int) $row->parent][] = (int) $row->term_id;
            }
        }

        return $hierarchy;
    }

    /**
     * @return array<string, mixed>
     */
    private function getMetaData(WP_Term $term): array
    {
        // Use preloaded data if available (batch mode)
        if ($this->batchPreloaded && isset($this->preloadedMeta[$term->term_id])) {
            return $this->preloadedMeta[$term->term_id];
        }

        // Fallback to individual queries (single item mode), which no getItems() precedes
        if ($this->metaKeys === []) {
            $this->metaKeys = self::resolveMetaKeys([$term->taxonomy]);
        }

        $meta = [];

        foreach ($this->metaKeys as $key) {
            $values = get_term_meta($term->term_id, $key, false);

            if (is_array($values) && $values !== []) {
                $meta[$key] = PostIndexable::documentValue($key, array_values($values), 'term');
            }
        }

        return $meta;
    }

    /**
     * Preloads batch data for multiple terms to avoid N+1 queries.
     *
     * This method should be called before formatting a batch of terms.
     * It preloads all meta in bulk queries, and the hierarchy tree counts are worked out from.
     *
     * @param WP_Term[] $terms Array of terms to preload data for
     */
    public function preloadBatchData(array $terms): void
    {
        if (empty($terms)) {
            return;
        }

        $termIds = array_map(fn ($term) => $term->term_id, $terms);
        $taxonomies = array_values(array_unique(array_map(fn ($term) => $term->taxonomy, $terms)));

        // Ensure meta keys are set from settings or gathered from DB
        if (empty($this->metaKeys)) {
            $this->metaKeys = self::resolveMetaKeys($taxonomies);
        }

        foreach ($taxonomies as $taxonomy) {
            if (! isset($this->hierarchies[$taxonomy]) && is_taxonomy_hierarchical($taxonomy)) {
                $this->hierarchies[$taxonomy] = $this->loadHierarchy($taxonomy);
            }
        }

        // Preload meta cache using WordPress core function
        update_termmeta_cache($termIds);

        $this->preloadedMeta = [];

        foreach ($terms as $term) {
            $this->preloadedMeta[$term->term_id] = $this->buildMetaFromCache($term->term_id);
        }

        $this->batchPreloaded = true;
    }

    /**
     * Builds meta array from WordPress cache.
     *
     * @param int $termId The term ID
     * @return array<string, mixed> Meta data array
     */
    private function buildMetaFromCache(int $termId): array
    {
        $cachedMeta = wp_cache_get($termId, 'term_meta');

        if ($cachedMeta === false) {
            $term = get_term($termId);

            return $term instanceof WP_Term ? $this->getMetaData($term) : [];
        }

        $meta = [];

        foreach ($this->metaKeys as $key) {
            if (! isset($cachedMeta[$key]) || ! is_array($cachedMeta[$key]) || $cachedMeta[$key] === []) {
                continue;
            }

            $meta[$key] = PostIndexable::documentValue($key, array_map('maybe_unserialize', array_values($cachedMeta[$key])), 'term');
        }

        return $meta;
    }

    /**
     * Clears the preloaded batch data.
     *
     * Call this after processing a batch to free memory.
     */
    public function clearBatchData(): void
    {
        $this->preloadedMeta = [];
        $this->hierarchies = [];
        $this->batchPreloaded = false;
    }

    /**
     * Sets the meta keys to use for indexing.
     *
     * @param array $metaKeys Array of meta key names
     */
    public function setMetaKeys(array $metaKeys): void
    {
        $this->metaKeys = $metaKeys;
    }

    /**
     * Gets the current meta keys.
     *
     * @return array Array of meta key names
     */
    public function getMetaKeys(): array
    {
        return $this->metaKeys;
    }
}
