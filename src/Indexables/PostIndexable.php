<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Indexables;

use Pollora\MeiliScout\Config\SearchableAttributes;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;
use Pollora\MeiliScout\Services\MetaValueFlags;
use WP_Post;
use WP_Term;

use function apply_filters;
use function get_ancestors;
use function get_object_taxonomies;
use function get_option;
use function get_permalink;
use function get_post_meta;
use function get_post_status;
use function get_posts;
use function get_term;
use function is_taxonomy_hierarchical;
use function is_wp_error;
use function maybe_unserialize;
use function strip_shortcodes;
use function update_meta_cache;
use function update_object_term_cache;
use function wp_cache_get;
use function wp_get_post_terms;
use function wp_strip_all_tags;

class PostIndexable implements Indexable
{
    private array $metaKeys = [];

    /**
     * Setting that adds private posts to the index (Content screen).
     */
    public const INDEX_PRIVATE = 'index_private';

    /**
     * The statuses of the posts sent to the index: published ones, and private ones when the admin chose to.
     *
     * @return string[]
     */
    public static function indexableStatuses(): array
    {
        $statuses = Settings::get(self::INDEX_PRIVATE, false) ? ['publish', 'private'] : ['publish'];

        // Attachments, once media are indexed: their status follows their parent's
        if (in_array('attachment', (array) Settings::get('indexed_post_types', []), true)) {
            $statuses[] = 'inherit';
        }

        return apply_filters('meiliscout/indexable_post_statuses', $statuses);
    }

    /**
     * The statuses the index holds every post of: the indexable ones the last full indexation sent.
     *
     * A status made indexable since only has the posts saved since: queries
     * that include it run on MySQL until a full indexation sends them all.
     *
     * @return list<string>
     */
    public static function queryableStatuses(): array
    {
        $structure = get_option('meiliscout/last_indexing_structure', []);
        $indexed = is_array($structure) && isset($structure['statuses']) ? (array) $structure['statuses'] : ['publish'];

        return array_values(array_intersect(self::indexableStatuses(), $indexed));
    }

    /**
     * Preloaded terms cache indexed by post ID.
     * @var array<int, array<array<string, mixed>>>
     */
    private array $preloadedTerms = [];

    /**
     * Preloaded meta cache indexed by post ID.
     * @var array<int, array<string, mixed>>
     */
    private array $preloadedMeta = [];

    /**
     * Whether batch data has been preloaded.
     */
    private bool $batchPreloaded = false;

    public function getIndexName(): string
    {
        return IndexNames::target('posts');
    }

    public function getPrimaryKey(): string
    {
        return 'ID';
    }

    public function getIndexSettings(): array
    {
        $postTypes = Settings::get('indexed_post_types', []);
        $filterableMetaKeys = Settings::get('indexed_meta_keys', []);

        $filterableAttributes = [
            'post_type',
            'post_status',
            // WP_Query arguments, from schema 3
            'ID',
            'post_name',
            'post_author',
            'post_parent',
            'menu_order',
            'comment_count',
            'has_password',
            ...array_map(fn (string $column) => "{$column}_ts", PostDates::COLUMNS),
            'date_parts',
            // From schema 4
            'post_title_sort',
            'comment_status',
            'ping_status',
            'post_mime_type',
            'mime_group',
            'mime_subgroup',
            'parent_status',
        ];

        foreach ($filterableMetaKeys as $metaKey) {
            $filterableAttributes[] = "metas.{$metaKey}";
        }

        // Terms, one field per taxonomy: taxonomies.category.slug, taxonomies.post_tag.term_id...
        $filterableAttributes[] = 'taxonomies';

        return [
            'searchableAttributes' => SearchableAttributes::forIndex(),
            'filterableAttributes' => array_values(array_unique($filterableAttributes)),
            'sortableAttributes' => array_values(array_unique([
                'post_title',
                'post_date',
                'ID',
                'post_name',
                'post_author',
                'post_parent',
                'post_modified',
                'post_type',
                'menu_order',
                'comment_count',
                'post_title_sort',
                ...array_map(fn($key) => "metas.{$key}", $filterableMetaKeys),
            ])),
            'displayedAttributes' => apply_filters(
                'meiliscout/post/displayed_attributes',
                ['*'],
                $filterableMetaKeys
            ),
            'rankingRules' => self::rankingRules(),
            'pagination' => ['maxTotalHits' => IndexSettings::maxTotalHits()],
        ];
    }

    /**
     * The ranking rules of the posts index: `sort` first.
     *
     * A search without a sort is ranked by relevance as before. With one (an
     * explicit orderby), the order asked for is followed strictly, as on
     * MySQL, rather than only breaking ties between equally relevant posts.
     *
     * @return list<string>
     */
    public static function rankingRules(): array
    {
        return array_values((array) apply_filters(
            'meiliscout/post/ranking_rules',
            ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness']
        ));
    }

    public function getItems(?int $offset = null, ?int $limit = null): iterable
    {
        $postTypes = array_values((array) Settings::get('indexed_post_types', []));

        $this->metaKeys = $this->resolveMetaKeys($postTypes);

        if ($postTypes === []) {
            return;
        }

        $postsPerPage = max(1, (int) Settings::get('indexing.posts_per_page', 200));

        // One list across the post types, by id: an offset is a position in the
        // whole run, not in each type. A chunked run used to apply each chunk's
        // offset to every type, and never sent the types after the first.
        $position = max(0, (int) ($offset ?? 0));
        $remaining = $limit ?? PHP_INT_MAX;
        $pages = 0;

        while ($remaining > 0) {
            $size = (int) min($postsPerPage, $remaining);

            $posts = get_posts([
                'post_type' => $postTypes,
                'posts_per_page' => $size,
                'offset' => $position,
                'post_status' => self::indexableStatuses(),
                'orderby' => 'ID',
                'order' => 'ASC',
                'suppress_filters' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'no_found_rows' => true,
                'cache_results' => false,
            ]);

            foreach ($posts as $post) {
                yield $post;
            }

            $position += count($posts);
            $remaining -= count($posts);

            if (count($posts) < $size) {
                return;
            }

            // Free the in-memory cache periodically (every 10 pages); the persistent cache is left alone
            if (++$pages % 10 === 0) {
                if (function_exists('wp_cache_flush_runtime')) {
                    wp_cache_flush_runtime();
                }
                gc_collect_cycles();
            }
        }
    }

    private function gatherMetaKeys(array $postTypes): array
    {
        global $wpdb;

        if (empty($postTypes)) {
            return [];
        }

        $escapedTypes = array_map(fn($type) => esc_sql((string) $type), $postTypes);
        $postTypesStr = "'" . implode("','", $escapedTypes) . "'";

        $query = "
            SELECT DISTINCT meta_key
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type IN ({$postTypesStr})
        ";

        return $wpdb->get_col($query);
    }

    /**
     * Preloads batch data for multiple posts to avoid N+1 queries.
     *
     * This method should be called before formatting a batch of posts.
     * It preloads all meta and terms in bulk queries.
     *
     * @param WP_Post[] $posts Array of posts to preload data for
     */
    public function preloadBatchData(array $posts): void
    {
        if (empty($posts)) {
            return;
        }

        $postIds = array_map(fn($post) => $post->ID, $posts);
        $postTypes = array_unique(array_map(fn($post) => $post->post_type, $posts));

        // Ensure meta keys are set from settings or gathered from DB
        if (empty($this->metaKeys)) {
            $this->metaKeys = $this->resolveMetaKeys($postTypes);
        }

        // Preload meta cache using WordPress core function
        update_meta_cache('post', $postIds);

        // Preload terms cache using WordPress core function
        update_object_term_cache($postIds, $postTypes);

        // Build preloaded terms array from cache
        $this->preloadedTerms = [];
        foreach ($posts as $post) {
            $this->preloadedTerms[$post->ID] = $this->buildTermsFromCache($post);
        }

        // Build preloaded meta array from cache
        $this->preloadedMeta = [];
        foreach ($posts as $post) {
            $this->preloadedMeta[$post->ID] = $this->buildMetaFromCache($post->ID);
        }

        $this->batchPreloaded = true;
    }

    /**
     * Builds terms array from WordPress cache.
     *
     * @param WP_Post $post The post
     * @return array<array<string, mixed>> Flattened terms array
     */
    private function buildTermsFromCache(WP_Post $post): array
    {
        $terms = [];
        $taxonomies = get_object_taxonomies($post->post_type);

        foreach ($taxonomies as $taxonomy) {
            // wp_get_object_terms uses cache when available
            $cachedTerms = wp_cache_get($post->ID, "{$taxonomy}_relationships");

            if ($cachedTerms === false) {
                // Fallback to regular query if not in cache
                $rawTerms = wp_get_post_terms($post->ID, $taxonomy);
            } else {
                // Get term objects from cached term IDs
                $rawTerms = [];
                foreach ((array) $cachedTerms as $termId) {
                    $term = get_term($termId, $taxonomy);
                    if ($term instanceof WP_Term) {
                        $rawTerms[] = $term;
                    }
                }
            }

            // An unknown taxonomy gives a WP_Error
            if (is_wp_error($rawTerms)) {
                continue;
            }

            foreach ($rawTerms as $term) {
                $terms[] = $this->termEntry($term);
            }
        }

        return $terms;
    }

    /**
     * Builds meta array from WordPress cache.
     *
     * @param int $postId The post ID
     * @return array<string, mixed> Meta data array
     */
    private function buildMetaFromCache(int $postId): array
    {
        $cachedMeta = wp_cache_get($postId, 'post_meta');

        if ($cachedMeta === false) {
            return $this->metaFromDatabase($postId);
        }

        $meta = [];

        foreach ($this->metaKeys as $key) {
            if (! isset($cachedMeta[$key]) || ! is_array($cachedMeta[$key]) || $cachedMeta[$key] === []) {
                continue;
            }

            $meta[$key] = self::documentValue($key, array_map('maybe_unserialize', array_values($cachedMeta[$key])));
        }

        return $meta;
    }

    /**
     * The metas of a post, read one key at a time.
     *
     * @return array<string, mixed>
     */
    private function metaFromDatabase(int $postId): array
    {
        $meta = [];

        foreach ($this->metaKeys as $key) {
            $values = get_post_meta($postId, $key, false);

            if (is_array($values) && $values !== []) {
                $meta[$key] = self::documentValue($key, array_values($values));
            }
        }

        return $meta;
    }

    /**
     * What a document holds for a meta key, from every value the post has (schema 4).
     *
     * One value as it is, several as a list, as MySQL has one row per value;
     * numbers as numbers; an empty value too, since MySQL has its row. What
     * the values are like is noted for queries (MetaValueFlags).
     *
     * @param  list<mixed>  $values  Unserialized
     * @param  'post'|'term'  $objectType  Whose meta
     */
    public static function documentValue(string $key, array $values, string $objectType = 'post'): mixed
    {
        MetaValueFlags::note($key, $values, $objectType);

        // INF, -INF and NAN cannot be JSON encoded: kept as text
        $values = array_map(static fn (mixed $value) => is_numeric($value) && is_finite((float) $value) ? $value + 0 : $value, $values);

        return count($values) === 1 ? $values[0] : $values;
    }

    /**
     * Clears the preloaded batch data.
     *
     * Call this after processing a batch to free memory.
     */
    public function clearBatchData(): void
    {
        $this->preloadedTerms = [];
        $this->preloadedMeta = [];
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

    public function formatForIndexing(mixed $item): array
    {
        if (! $item instanceof WP_Post) {
            throw new \InvalidArgumentException('Item must be instance of WP_Post');
        }

        $document = $this->withoutProtectedText(get_object_vars($item));
        $document = [...$document, ...$this->queryFields($item)];

        $document['url'] = get_permalink($item);
        $document['content_text'] = $this->plainText((string) $document['post_content']);
        $document['terms'] = $this->getFlattenedTerms($item);
        $document['taxonomies'] = $this->groupedByTaxonomy($document['terms']);
        $document['metas'] = $this->getMetaData($item);

        $filtered = apply_filters('meiliscout/post/document', $document, $item);
        MetaValueFlags::noteAltered($document['metas'], is_array($filtered) ? (array) ($filtered['metas'] ?? []) : []);

        return $filtered;
    }

    /**
     * Fields WP_Query arguments are translated against (schema 3).
     *
     * Numbers as numbers, for sorts and comparisons to be numeric; whether the
     * post has a password, without the password; the dates as timestamps and
     * parts; and the title folded the way MySQL's collation compares it.
     *
     * @return array<string, mixed>
     */
    private function queryFields(WP_Post $post): array
    {
        $fields = [];

        foreach (['ID', 'post_author', 'post_parent', 'menu_order', 'comment_count'] as $field) {
            if (isset($post->$field)) {
                $fields[$field] = (int) $post->$field;
            }
        }

        $fields['has_password'] = (string) $post->post_password !== '';

        foreach (PostDates::COLUMNS as $column) {
            $timestamp = PostDates::timestamp((string) ($post->$column ?? ''));

            if ($timestamp !== null) {
                $fields["{$column}_ts"] = $timestamp;
            }
        }

        foreach (PostDates::PART_COLUMNS as $column) {
            $parts = PostDates::parts((string) ($post->$column ?? ''));

            if ($parts !== null) {
                $fields['date_parts'][$column] = $parts;
            }
        }

        $fields['post_title_sort'] = self::titleSortKey((string) $post->post_title);

        // An attachment's type, by group (post_mime_type LIKE 'image/%'), and its parent's status, which WordPress reads for 'inherit'
        $mimeType = (string) $post->post_mime_type;

        if ($mimeType !== '') {
            [$group, $subgroup] = array_pad(explode('/', strtolower($mimeType), 2), 2, '');
            $fields['mime_group'] = $group;
            $fields['mime_subgroup'] = $subgroup;
        }

        if ((int) $post->post_parent > 0) {
            $parentStatus = get_post_status((int) $post->post_parent);

            if (is_string($parentStatus)) {
                $fields['parent_status'] = $parentStatus;
            }
        }

        return $fields;
    }

    /**
     * A title folded for sorting: lowercase and without accents, close to how MySQL's collations order titles.
     */
    public static function titleSortKey(string $title): string
    {
        $title = function_exists('remove_accents') ? remove_accents($title) : $title;

        return mb_strtolower(trim($title));
    }

    /**
     * The terms, one list per taxonomy.
     *
     * Meilisearch flattens a list of objects into one list per field: filtering
     * the flat `terms` on taxonomy and slug matched them independently, so a tag
     * named like a category satisfied a category filter. Grouped by taxonomy,
     * `taxonomies.category.slug` only holds category slugs.
     *
     * @param  array<array<string, mixed>>  $terms
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupedByTaxonomy(array $terms): array
    {
        $grouped = [];

        foreach ($terms as $term) {
            $taxonomy = (string) $term['taxonomy'];
            unset($term['taxonomy']);
            $grouped[$taxonomy][] = $term;
        }

        return $grouped;
    }

    /**
     * The text of a post's content, without markup, block comments or shortcodes: what a search should match.
     */
    private function plainText(string $content): string
    {
        $text = wp_strip_all_tags(strip_shortcodes($content));

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function withoutProtectedText(array $document): array
    {
        $isProtected = ($document['post_password'] ?? '') !== '';
        unset($document['post_password']);

        if (! $isProtected) {
            return $document;
        }

        return [...$document, 'post_content' => '', 'post_content_filtered' => '', 'post_excerpt' => ''];
    }

    public function formatForSearch(array $hit): mixed
    {
        return new WP_Post((object) $hit);
    }

    /**
     * A term as the documents carry it. From posts schema 5, a term of a
     * hierarchical taxonomy also carries the ids of its ancestors (tree, the
     * term first): a filter on taxonomies.{taxonomy}.tree takes a term with its
     * descendants, and a facet on it counts a parent with its children.
     *
     * @return array<string, mixed>
     */
    private function termEntry(WP_Term $term): array
    {
        $entry = [
            'term_id' => (int) $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'taxonomy' => $term->taxonomy,
            'term_taxonomy_id' => (int) $term->term_taxonomy_id,
            'parent' => (int) $term->parent,
        ];

        if (is_taxonomy_hierarchical($term->taxonomy)) {
            $entry['tree'] = [
                (int) $term->term_id,
                ...array_map('intval', get_ancestors((int) $term->term_id, $term->taxonomy, 'taxonomy')),
            ];
        }

        return $entry;
    }

    private function getFlattenedTerms(WP_Post $post): array
    {
        // Use preloaded data if available (batch mode)
        if ($this->batchPreloaded && isset($this->preloadedTerms[$post->ID])) {
            return $this->preloadedTerms[$post->ID];
        }

        // Fallback to individual queries (single item mode)
        $terms = [];
        $taxonomies = get_object_taxonomies($post->post_type);

        foreach ($taxonomies as $taxonomy) {
            $rawTerms = wp_get_post_terms($post->ID, $taxonomy);
            // An unknown taxonomy gives a WP_Error
            if (is_wp_error($rawTerms)) {
                continue;
            }

            foreach ($rawTerms as $term) {
                $terms[] = $this->termEntry($term);
            }
        }

        return $terms;
    }

    /**
     * Configured keys win; without them the database is asked. Every indexing path
     * needs this, including the single-document one, which no preloading precedes.
     *
     * @param  string[]  $postTypes
     * @return string[]
     */
    private function resolveMetaKeys(array $postTypes): array
    {
        $configured = Settings::get('indexed_meta_keys', []);

        return ! empty($configured) ? $configured : $this->gatherMetaKeys($postTypes);
    }

    private function getMetaData(WP_Post $post): array
    {
        // Use preloaded data if available (batch mode)
        if ($this->batchPreloaded && isset($this->preloadedMeta[$post->ID])) {
            return $this->preloadedMeta[$post->ID];
        }

        // Fallback to individual queries (single item mode)
        if ($this->metaKeys === []) {
            $this->metaKeys = $this->resolveMetaKeys([$post->post_type]);
        }

        return $this->metaFromDatabase($post->ID);
    }
}
