<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Indexables;

use Pollora\MeiliScout\Config\SearchableAttributes;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;
use WP_Post;
use WP_Term;

use function apply_filters;
use function get_object_taxonomies;
use function get_permalink;
use function get_post_meta;
use function get_posts;
use function get_term;
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
     * @return string[]
     */
    public static function indexableStatuses(): array
    {
        return apply_filters('meiliscout/indexable_post_statuses', ['publish']);
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
        $postTypes = Settings::get('indexed_post_types', []);

        $this->metaKeys = $this->resolveMetaKeys($postTypes);

        $postsPerPage = Settings::get('indexing.posts_per_page', 200);

        // Track total items yielded for offset/limit support
        $totalYielded = 0;
        $maxItems = $limit ?? PHP_INT_MAX;

        foreach ($postTypes as $postType) {
            $page = 1;

            // Calculate starting page if offset is provided
            if ($offset !== null) {
                $page = (int) floor($offset / $postsPerPage) + 1;
            }

            do {
                $posts = get_posts([
                    'post_type' => $postType,
                    'posts_per_page' => $postsPerPage,
                    'paged' => $page,
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
                    $currentGlobalOffset = ($page - 1) * $postsPerPage + array_search($post, $posts, true);

                    if ($offset !== null && $currentGlobalOffset < $offset) {
                        continue;
                    }

                    if ($totalYielded >= $maxItems) {
                        return;
                    }

                    yield $post;
                    $totalYielded++;
                }

                $page++;

                if ($totalYielded >= $maxItems) {
                    return;
                }

                // Free the in-memory cache periodically (every 10 pages); the persistent cache is left alone
                if ($page % 10 === 0) {
                    if (function_exists('wp_cache_flush_runtime')) {
                        wp_cache_flush_runtime();
                    }
                    gc_collect_cycles();
                }

            } while (count($posts) === $postsPerPage);
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
                $terms[] = [
                    'term_id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'taxonomy' => $term->taxonomy,
                    'term_taxonomy_id' => (int) $term->term_taxonomy_id,
                    'parent' => (int) $term->parent,
                ];
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
        $meta = [];
        $cachedMeta = wp_cache_get($postId, 'post_meta');

        if ($cachedMeta === false) {
            // Fallback to regular queries
            foreach ($this->metaKeys as $key) {
                $value = get_post_meta($postId, $key, true);
                if ($value === '' || $value === null) {
                    continue;
                }
                // Skip INF, -INF, and NAN values as they cannot be JSON encoded
                $meta[$key] = (is_numeric($value) && is_finite((float) $value)) ? $value + 0 : $value;
            }
        } else {
            foreach ($this->metaKeys as $key) {
                if (! isset($cachedMeta[$key])) {
                    continue;
                }

                $value = maybe_unserialize($cachedMeta[$key][0] ?? '');
                if ($value === '' || $value === null) {
                    continue;
                }

                // Skip INF, -INF, and NAN values as they cannot be JSON encoded
                $meta[$key] = (is_numeric($value) && is_finite((float) $value)) ? $value + 0 : $value;
            }
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

        return apply_filters('meiliscout/post/document', $document, $item);
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
                $terms[] = [
                    'term_id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'taxonomy' => $term->taxonomy,
                    'term_taxonomy_id' => (int) $term->term_taxonomy_id,
                    'parent' => (int) $term->parent,
                ];
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

        $meta = [];
        foreach ($this->metaKeys as $key) {
            $value = get_post_meta($post->ID, $key, true);

            if ($value === '' || $value === null) {
                continue;
            }

            // Automatic casting of numeric values
            // Skip INF, -INF, and NAN values as they cannot be JSON encoded
            if (is_numeric($value) && is_finite((float) $value)) {
                $meta[$key] = $value + 0;
            } else {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }
}
