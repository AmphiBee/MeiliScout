<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Diagnostics;

use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
use Pollora\MeiliScout\Services\ContainsFilter;
use Pollora\MeiliScout\Services\SearchFallbacks;
use WP_Term;
use WP_Term_Query;

/**
 * Runs a WP_Term_Query (get_terms()) on MySQL then with use_meilisearch, and compares the two.
 *
 * MySQL is the oracle: a term query Meilisearch serves must return the same
 * thing, to the array keys and the type of each value. A query Meilisearch
 * does not serve must say why. The cases pick their terms in the site's data.
 *
 * Outcomes and modes are QueryParity's. Pseudo-arguments: `_user` runs the
 * query as that user, `_contains` turns Meilisearch's CONTAINS on for the
 * query (and back off), `_sql_filter` hooks a plugin's SQL filter
 * (['hook' => ..., 'change' => bool]).
 */
final class TermQueryParity
{
    /**
     * Fields of the first WP_Term compared between the two engines.
     */
    private const TERM_FIELDS = ['term_id', 'name', 'slug', 'term_group', 'term_taxonomy_id', 'taxonomy', 'description', 'parent', 'count', 'filter'];

    /**
     * Values of each engine's result given back, at most.
     */
    private const KEPT = 50;

    /**
     * The built-in cases, with the site's data filled in; a case whose data is missing has null args.
     *
     * @return list<array{label: string, args: array<string, mixed>|null, mode: string, keys: list<string>}>
     */
    public static function cases(): array
    {
        $d = self::siteData();
        $case = static fn (string $label, ?array $args, string $mode = QueryParity::MODE_ORDER, array $keys = []): array => ['label' => $label, 'args' => $args, 'mode' => $mode, 'keys' => $keys];
        $sorted = static fn (string $label, ?array $args, string ...$keys): array => $case($label, $args, QueryParity::MODE_SORTED, $keys);
        $fallback = static fn (string $label, ?array $args): array => $case($label, $args, QueryParity::MODE_FALLBACK);
        $when = static fn (bool $ok, array $args): ?array => $ok ? $args : null;

        $cat = ['taxonomy' => 'category'];
        $tag = ['taxonomy' => 'post_tag'];
        $branch = $d['branch'];
        $tags = $d['tags'];
        $cats = $d['categories'];
        $hasTags = count($tags) >= 6;
        $hasBranch = $branch !== null;

        return [
            // Taxonomies, defaults
            $case('category, defaults (hierarchical, hide_empty)', $cat),
            $case('category, hide_empty false', [...$cat, 'hide_empty' => false]),
            $case('category, hierarchical false', [...$cat, 'hierarchical' => false]),
            $case('post_tag, defaults', $tag),
            $case('post_tag, hide_empty false', [...$tag, 'hide_empty' => false]),
            $case('custom taxonomy', $when($d['custom'] !== null, ['taxonomy' => $d['custom'], 'hide_empty' => false])),
            $sorted('two taxonomies, by name', ['taxonomy' => ['category', 'post_tag'], 'hide_empty' => false], 'name'),
            $case('every taxonomy', ['hide_empty' => false, 'orderby' => 'term_id'], QueryParity::MODE_SET),
            $fallback('a taxonomy not indexed', ['taxonomy' => 'nav_menu', 'hide_empty' => false]),
            $case('get all', [...$cat, 'get' => 'all']),

            // Paging
            $case('number', [...$tag, 'number' => 5]),
            $case('number and offset', [...$tag, 'number' => 5, 'offset' => 5]),
            $case('offset past the end', [...$tag, 'number' => 5, 'offset' => 500]),
            $case('number, hierarchical (paged after the empty terms are dropped)', [...$cat, 'number' => 3, 'offset' => 2]),
            $case('number with parent (WordPress returns them all)', [...$cat, 'parent' => 0, 'hierarchical' => false, 'number' => 2]),

            // Which terms
            $case('include', $when($hasTags, [...$tag, 'include' => [$tags[3], $tags[0], $tags[5]]])),
            $case('include + orderby include', $when($hasTags, [...$tag, 'include' => [$tags[3], $tags[0], $tags[5]], 'orderby' => 'include'])),
            $case('include + orderby include DESC', $when($hasTags, [...$tag, 'include' => "{$tags[3]},{$tags[0]},{$tags[5]}", 'orderby' => 'include', 'order' => 'DESC'])),
            $case('exclude', $when($hasTags, [...$tag, 'exclude' => [$tags[0], $tags[1]]])),
            $case('exclude_tree', $when($hasBranch, [...$cat, 'hide_empty' => false, 'exclude_tree' => [$branch['top'] ?? 0]])),
            $case('include wins over exclude', $when($hasTags, [...$tag, 'include' => [$tags[0], $tags[1]], 'exclude' => [$tags[0]]])),
            $case('slug', $when($d['tag_slug'] !== null, [...$tag, 'slug' => $d['tag_slug']])),
            $case('slug list + orderby slug__in', $when(count($d['tag_slugs']) >= 3, [...$tag, 'slug' => $d['tag_slugs'], 'orderby' => 'slug__in'])),
            $case('name, other case and accents', $when($d['accented_name'] !== null, [...$cat, 'name' => mb_strtoupper((string) $d['accented_name'])])),
            $case('name list', $when(count($d['tag_names']) >= 2, [...$tag, 'name' => $d['tag_names']])),
            $case('term_taxonomy_id', $when(count($d['tt_ids']) >= 2, [...$tag, 'term_taxonomy_id' => $d['tt_ids']])),
            $case('parent 0', [...$cat, 'parent' => 0, 'hide_empty' => false]),
            $case('parent', $when($hasBranch, [...$cat, 'parent' => $branch['top'] ?? 0, 'hide_empty' => false])),
            $case('child_of', $when($hasBranch, [...$cat, 'child_of' => $branch['top'] ?? 0])),
            $case('child_of, hide_empty false', $when($hasBranch, [...$cat, 'child_of' => $branch['top'] ?? 0, 'hide_empty' => false])),
            $case('child_of, hierarchical false (intermediate empty terms dropped)', $when($hasBranch, [...$cat, 'child_of' => $branch['top'] ?? 0, 'hierarchical' => false])),
            $case('childless', [...$cat, 'childless' => true, 'hide_empty' => false]),
            $case('hide_empty keeps an empty term with posts below', $when($hasBranch, [...$cat, 'include' => [$branch['top'] ?? 0, $branch['middle'] ?? 0, $branch['leaf'] ?? 0, $d['empty_leaf']]])),
            $case('pad_counts', [...$cat, 'pad_counts' => true]),
            $case('pad_counts, top level only', [...$cat, 'pad_counts' => true, 'parent' => 0]),

            // Shapes
            $case('fields ids', [...$tag, 'fields' => 'ids']),
            $case('fields tt_ids', [...$tag, 'fields' => 'tt_ids']),
            $case('fields names', [...$tag, 'fields' => 'names', 'number' => 8]),
            $case('fields slugs', [...$cat, 'fields' => 'slugs']),
            $case('fields id=>name', [...$cat, 'fields' => 'id=>name', 'hide_empty' => false]),
            $case('fields id=>slug', [...$tag, 'fields' => 'id=>slug']),
            $case('fields id=>parent', [...$cat, 'fields' => 'id=>parent', 'hide_empty' => false]),
            $case('fields count', [...$tag, 'fields' => 'count']),
            $case('fields count, hide_empty false', [...$cat, 'fields' => 'count', 'hide_empty' => false]),
            $case('fields count with child_of (WordPress ignores it)', $when($hasBranch, [...$cat, 'fields' => 'count', 'child_of' => $branch['top'] ?? 0])),
            $case('fields count with offset (WordPress returns null)', [...$tag, 'fields' => 'count', 'number' => 5, 'offset' => 2]),
            $case('fields all_with_object_id without object_ids', [...$tag, 'fields' => 'all_with_object_id']),

            // Order
            $case('orderby name DESC', [...$tag, 'order' => 'DESC']),
            $case('order lowercase desc', [...$tag, 'order' => 'desc']),
            $case('order empty (DESC for WordPress)', [...$tag, 'order' => '']),
            $case('orderby slug', [...$tag, 'orderby' => 'slug']),
            $case('orderby term_id', [...$tag, 'orderby' => 'term_id']),
            $case('orderby id', [...$tag, 'orderby' => 'id', 'order' => 'DESC']),
            $sorted('orderby count', [...$tag, 'orderby' => 'count', 'order' => 'DESC'], 'count'),
            $sorted('orderby parent', [...$cat, 'orderby' => 'parent', 'hide_empty' => false], 'parent'),
            $sorted('orderby term_group', [...$tag, 'orderby' => 'term_group'], 'term_group'),
            $case('orderby term_taxonomy_id', [...$tag, 'orderby' => 'term_taxonomy_id']),
            $sorted('orderby description', [...$cat, 'orderby' => 'description', 'hide_empty' => false], 'description'),
            $case('orderby none', [...$tag, 'orderby' => 'none'], QueryParity::MODE_SET),
            $case('orderby term_order without object_ids (term_id)', [...$tag, 'orderby' => 'term_order']),
            $case('orderby unknown (name)', [...$tag, 'orderby' => 'whatever']),

            // Search
            $case('search', $when($d['search'] !== null, [...$tag, 'search' => $d['search'], 'hide_empty' => false]), QueryParity::MODE_SEARCH),
            $case('search, with CONTAINS', $when($d['search'] !== null, [...$tag, 'search' => $d['search'], 'hide_empty' => false, '_contains' => true])),
            $case('search on accents, with CONTAINS', [...$cat, 'search' => 'securite', 'hide_empty' => false, '_contains' => true]),
            $case('name__like, with CONTAINS', $when($d['search'] !== null, [...$tag, 'name__like' => $d['search'], 'fields' => 'names', 'hide_empty' => false, '_contains' => true])),
            $case('description__like, with CONTAINS', [...$cat, 'description__like' => 'rubrique', 'hide_empty' => false, '_contains' => true]),
            $fallback('name__like without CONTAINS', [...$tag, 'name__like' => 'press', 'hide_empty' => false]),

            // Metas
            $case('meta_query numeric', $when($d['metas'], [...$cat, 'hide_empty' => false, 'meta_query' => [['key' => 'position', 'value' => 5, 'compare' => '>', 'type' => 'NUMERIC']]])),
            $case('meta_key + meta_value', $when($d['metas'], [...$cat, 'hide_empty' => false, 'meta_key' => 'color', 'meta_value' => 'bleu'])),
            $case('meta_query NOT EXISTS', $when($d['metas'], [...$cat, 'hide_empty' => false, 'meta_query' => [['key' => 'color', 'compare' => 'NOT EXISTS']]])),
            $case('meta with several values =', $when($d['metas'], [...$cat, 'hide_empty' => false, 'meta_query' => [['key' => 'aliases', 'value' => 'alias-0']]])),
            $fallback('meta with several values !=', $when($d['metas'], [...$cat, 'hide_empty' => false, 'meta_query' => [['key' => 'aliases', 'value' => 'alias-0', 'compare' => '!=']]])),
            $sorted('orderby meta_value_num', $when($d['metas'], [...$cat, 'hide_empty' => false, 'meta_key' => 'position', 'orderby' => 'meta_value_num']), 'meta:position'),
            $sorted('orderby named meta clause', $when($d['metas'], [...$cat, 'hide_empty' => false, 'meta_query' => ['pos' => ['key' => 'position', 'type' => 'NUMERIC']], 'orderby' => 'pos', 'order' => 'DESC']), 'meta:position'),
            $fallback('meta key not indexed', [...$cat, 'hide_empty' => false, 'meta_query' => [['key' => 'not_indexed_'.md5('x'), 'compare' => 'EXISTS']]]),

            // Posts' terms
            $case('object_ids', $when($d['post'] > 0, [...$cat, 'object_ids' => [$d['post']]])),
            $sorted('object_ids, several posts and taxonomies', $when(count($d['posts']) >= 3, ['taxonomy' => ['category', 'post_tag'], 'object_ids' => $d['posts']]), 'name'),
            $case('object_ids, fields ids', $when(count($d['posts']) >= 3, [...$tag, 'object_ids' => $d['posts'], 'fields' => 'ids'])),
            $case('object_ids, fields count (one per post and term)', $when(count($d['posts']) >= 3, [...$tag, 'object_ids' => $d['posts'], 'fields' => 'count'])),
            $fallback('object_ids, a post of a type the taxonomy is not for', $when($d['custom'] !== null, ['taxonomy' => $d['custom'], 'object_ids' => [$d['post']]])),
            $fallback('object_ids, fields all_with_object_id', $when($d['post'] > 0, [...$cat, 'object_ids' => [$d['post']], 'fields' => 'all_with_object_id'])),
            $fallback('object_ids, orderby term_order', $when($d['post'] > 0, [...$cat, 'object_ids' => [$d['post']], 'orderby' => 'term_order'])),
            $fallback('object_ids, a draft', $when($d['draft'] > 0, [...$cat, 'object_ids' => [$d['draft']]])),
            $fallback('object_ids without a taxonomy', $when($d['post'] > 0, ['object_ids' => [$d['post']]])),

            // A plugin's SQL filters
            $fallback('terms_clauses of a plugin', [...$tag, '_sql_filter' => ['hook' => 'terms_clauses', 'change' => true]]),
            $case('terms_clauses of a plugin that changes nothing', [...$tag, '_sql_filter' => ['hook' => 'terms_clauses', 'change' => false]]),
            $fallback('list_terms_exclusions of a plugin', $when($hasTags, [...$tag, 'exclude' => [$tags[0]], '_sql_filter' => ['hook' => 'list_terms_exclusions', 'change' => true]])),
            $fallback('get_terms_orderby of a plugin', [...$tag, '_sql_filter' => ['hook' => 'get_terms_orderby', 'change' => true]]),
        ];
    }

    /**
     * Runs the built-in cases, or the ones whose label contains $filter.
     *
     * @return list<array<string, mixed>>
     */
    public static function runCases(?string $filter = null): array
    {
        $results = [];

        foreach (self::cases() as $case) {
            if ($filter !== null && $filter !== '' && stripos($case['label'], $filter) === false) {
                continue;
            }

            $results[] = $case['args'] === null
                ? ['case' => $case['label'], 'outcome' => QueryParity::SKIP, 'notes' => ['the site lacks the data for this case']]
                : ['case' => $case['label'], ...self::compare($case['args'], $case['mode'], $case['keys'])];
        }

        return $results;
    }

    /**
     * Runs one term query on both engines and compares them.
     *
     * @param  array<string, mixed>  $args
     * @param  list<string>  $keys  For MODE_SORTED: the fields sorted on (name, count, meta:position...)
     * @return array<string, mixed>
     */
    public static function compare(array $args, string $mode = QueryParity::MODE_ORDER, array $keys = []): array
    {
        $previousUser = get_current_user_id();
        $contains = ! empty($args['_contains']) && ! ContainsFilter::enabled();

        try {
            if ($contains) {
                ContainsFilter::set(true);
            }

            $mysql = self::run($args, false);
            $meili = SearchFallbacks::withoutRecording(static fn () => self::run($args, true));
        } catch (\Throwable $e) {
            return ['outcome' => QueryParity::ERROR, 'notes' => [get_class($e).': '.$e->getMessage()]];
        } finally {
            wp_set_current_user($previousUser);

            if ($contains) {
                ContainsFilter::set(false);
            }
        }

        $base = [
            'mysql_found' => $mysql['found'],
            'meili_found' => $meili['found'],
            'reason' => $meili['reason'],
            'params' => $meili['params'],
            'mysql_ids' => array_slice($mysql['values'], 0, self::KEPT, true),
            'meili_ids' => array_slice($meili['values'], 0, self::KEPT, true),
        ];

        if (! $meili['served']) {
            return ['outcome' => QueryParity::FALLBACK, ...$base, 'notes' => [$meili['reason'] ?? 'not intercepted']];
        }

        if ($mode === QueryParity::MODE_FALLBACK) {
            return ['outcome' => QueryParity::DIFF, ...$base, 'notes' => ['served by Meilisearch, where it cannot answer as MySQL']];
        }

        if ($mode === QueryParity::MODE_SEARCH) {
            $overlap = count(array_intersect($mysql['values'], $meili['values']));

            return ['outcome' => QueryParity::INFO, ...$base, 'notes' => [sprintf('MySQL %d / Meilisearch %d terms; overlap %d', $mysql['found'], $meili['found'], $overlap)]];
        }

        $notes = [];

        if ($mysql['type'] !== $meili['type']) {
            $notes[] = "a {$mysql['type']} on MySQL, a {$meili['type']} on Meilisearch";
        } elseif ($mode === QueryParity::MODE_SET || $mode === QueryParity::MODE_SORTED) {
            $a = $mysql['values'];
            $b = $meili['values'];
            sort($a);
            sort($b);

            if ($a !== $b) {
                $notes[] = sprintf('missing %s / extra %s', json_encode(array_slice(array_values(array_diff($mysql['values'], $meili['values'])), 0, 6)), json_encode(array_slice(array_values(array_diff($meili['values'], $mysql['values'])), 0, 6)));
            } elseif ($mode === QueryParity::MODE_SORTED) {
                $sortKeys = static fn (array $values): array => array_map(static fn ($id): string => self::sortKey((int) $id, $keys), array_values($values));

                if ($sortKeys($mysql['values']) !== $sortKeys($meili['values'])) {
                    $notes[] = 'different order: '.json_encode(array_slice($sortKeys($mysql['values']), 0, 4), JSON_UNESCAPED_UNICODE).' vs '.json_encode(array_slice($sortKeys($meili['values']), 0, 4), JSON_UNESCAPED_UNICODE);
                }
            }
        } elseif ($mysql['values'] !== $meili['values']) {
            $notes[] = sprintf('MySQL %s vs Meilisearch %s', self::excerpt($mysql['values']), self::excerpt($meili['values']));
        }

        foreach (self::fieldDifferences($mysql['first'], $meili['first']) as $difference) {
            $notes[] = $difference;
        }

        return ['outcome' => $notes === [] ? QueryParity::OK : QueryParity::DIFF, ...$base, 'notes' => $notes];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{values: array<int|string, mixed>, found: int, type: string, served: bool, reason: string|null, params: array<string, mixed>|null, first: mixed}
     */
    private static function run(array $args, bool $meilisearch): array
    {
        wp_set_current_user((int) ($args['_user'] ?? 0));
        $sqlFilter = $args['_sql_filter'] ?? null;
        unset($args['_user'], $args['_contains'], $args['_sql_filter']);

        $callback = null;

        if (is_array($sqlFilter)) {
            $change = ! empty($sqlFilter['change']);
            $callback = static fn ($value) => match (true) {
                ! $change => $value,
                is_array($value) && isset($value['where']) => ['where' => $value['where'].' AND 1 = 1'] + $value,
                is_string($value) => $value === '' ? 't.term_id NOT IN (0)' : $value.' ',
                default => $value,
            };
            add_filter((string) $sqlFilter['hook'], $callback);
        }

        try {
            $query = new WP_Term_Query;
            $result = $query->query(['use_meilisearch' => $meilisearch] + $args);
        } finally {
            if ($callback !== null) {
                remove_filter((string) $sqlFilter['hook'], $callback);
            }
        }

        $info = $query->meiliscout ?? null;
        $values = is_array($result)
            ? array_map(static fn ($value) => $value instanceof WP_Term ? $value->term_id : $value, $result)
            : [];

        return [
            'values' => $values,
            'found' => is_array($result) ? count($result) : (int) $result,
            // A count is a numeric string, an int (no child), or null (an offset)
            'type' => is_array($result) ? 'array' : get_debug_type($result).' '.var_export($result, true),
            'served' => is_array($info) && ! empty($info['served']),
            'reason' => is_array($info) ? ($info['reason'] ?? null) : null,
            'params' => is_array($info) ? ($info['params'] ?? null) : null,
            'first' => is_array($result) ? (reset($result) ?: null) : null,
        ];
    }

    /**
     * The values a term is sorted on, as one string: terms that tie have the same.
     *
     * @param  list<string>  $keys
     */
    private static function sortKey(int $id, array $keys): string
    {
        $term = get_term($id);
        $values = [];

        foreach ($keys as $key) {
            $values[] = match (true) {
                ! $term instanceof WP_Term => '',
                str_starts_with($key, 'meta:') => (string) get_term_meta($id, substr($key, 5), true),
                // As MySQL's collation compares them
                $key === 'name', $key === 'description' => PostIndexable::titleSortKey((string) $term->$key),
                default => (string) ($term->$key ?? ''),
            };
        }

        return implode(' | ', $values);
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private static function excerpt(array $values): string
    {
        return (string) json_encode(array_slice($values, 0, 8, true), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    private static function fieldDifferences(mixed $mysql, mixed $meili): array
    {
        if (! $mysql instanceof WP_Term || ! $meili instanceof WP_Term || $mysql->term_id !== $meili->term_id) {
            return [];
        }

        $differences = [];

        foreach (self::TERM_FIELDS as $field) {
            if (($mysql->$field ?? null) !== ($meili->$field ?? null)) {
                $differences[] = sprintf('WP_Term->%s %s vs %s', $field, var_export($mysql->$field ?? null, true), var_export($meili->$field ?? null, true));
            }
        }

        return $differences;
    }

    /**
     * The terms, posts and metas of the site the cases point at.
     *
     * @return array<string, mixed>
     */
    private static function siteData(): array
    {
        global $wpdb;

        $tags = array_map('intval', $wpdb->get_col("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'post_tag' AND count > 0 ORDER BY term_id"));
        $tagRows = $wpdb->get_results("SELECT t.slug, t.name, tt.term_taxonomy_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt USING (term_id) WHERE tt.taxonomy = 'post_tag' AND tt.count > 0 ORDER BY t.term_id LIMIT 4");

        // A category with grandchildren, empty itself
        $branch = $wpdb->get_row("SELECT top.term_id AS top, middle.term_id AS middle, leaf.term_id AS leaf
            FROM {$wpdb->term_taxonomy} leaf
            JOIN {$wpdb->term_taxonomy} middle ON middle.term_id = leaf.parent AND middle.taxonomy = 'category'
            JOIN {$wpdb->term_taxonomy} top ON top.term_id = middle.parent AND top.taxonomy = 'category'
            WHERE leaf.taxonomy = 'category' AND leaf.count > 0 AND top.count = 0 LIMIT 1", ARRAY_A);

        $accented = $wpdb->get_var("SELECT t.name FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt USING (term_id) WHERE tt.taxonomy = 'category' AND tt.count > 0 AND t.name REGEXP '[éèàçô]' LIMIT 1");
        $custom = $wpdb->get_var("SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE taxonomy NOT IN ('category', 'post_tag', 'nav_menu', 'post_format', 'wp_theme', 'wp_template_part_area') GROUP BY taxonomy ORDER BY COUNT(*) DESC LIMIT 1");
        $search = isset($tagRows[0]) ? mb_substr((string) $tagRows[0]->name, 1, 4) : null;

        return [
            'tags' => $tags,
            'categories' => array_map('intval', $wpdb->get_col("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'category' ORDER BY term_id")),
            'tag_slug' => isset($tagRows[0]) ? (string) $tagRows[0]->slug : null,
            'tag_slugs' => array_map(static fn ($row) => (string) $row->slug, array_reverse($tagRows)),
            'tag_names' => array_map(static fn ($row) => (string) $row->name, array_slice($tagRows, 0, 2)),
            'tt_ids' => array_map(static fn ($row) => (int) $row->term_taxonomy_id, array_slice($tagRows, 1, 2)),
            'branch' => is_array($branch) ? array_map('intval', $branch) : null,
            'empty_leaf' => (int) $wpdb->get_var("SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = 'category' AND tt.count = 0 AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} c WHERE c.parent = tt.term_id) LIMIT 1"),
            'accented_name' => is_string($accented) ? $accented : null,
            'custom' => is_string($custom) ? $custom : null,
            'search' => $search !== null && mb_strlen($search) >= 3 ? $search : null,
            // The demo's term metas, when they are indexed
            'metas' => array_diff(['position', 'color', 'aliases'], TaxonomyIndexable::selectedMetaKeys()) === []
                && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key IN ('position', 'color', 'aliases')") > 0,
            'post' => (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID LIMIT 1"),
            'posts' => array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID LIMIT 2, 4")),
            'draft' => (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'draft' ORDER BY ID LIMIT 1"),
        ];
    }
}
