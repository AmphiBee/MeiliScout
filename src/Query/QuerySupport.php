<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Query\Builders\DateQueryBuilder;
use Pollora\MeiliScout\Query\Builders\PostFieldsBuilder;

use function apply_filters;

/**
 * Tells whether Meilisearch can answer a query as MySQL would, before it is translated.
 *
 * A query var is either translated faithfully by the builders, or known not
 * to change which posts come back; any other one that is set sends the query
 * to MySQL, with the reason. An unknown query var is no exception: it may be
 * a plugin's, read in a posts_where filter Meilisearch never sees.
 *
 * A plugin that changed the query's SQL through a filter (posts_where...)
 * sends it to MySQL too: see SqlFilters.
 *
 * Statuses and post types are checked against what the index holds: a query
 * for drafts, or one that includes private posts for a logged-in user, runs
 * on MySQL when the site has such posts.
 */
final class QuerySupport
{
    /**
     * Query vars translated by the builders, or that do not change which posts come back.
     */
    private const HANDLED = [
        // MeiliScout's own
        'use_meilisearch', 'meilisearch_facets',
        // Type and status: checked below
        'post_type', 'post_status',
        // Search, and what WP_Query::parse_search() stores
        's', 'sentence', 'search_columns', 'search_terms', 'search_terms_count', 'search_orderby_title',
        // Taxonomies and metas
        'tax_query', 'meta_query', 'meta_key', 'meta_value', 'meta_value_num', 'meta_compare', 'meta_type',
        // Order and paging
        'orderby', 'order', 'posts_per_page', 'posts_per_archive_page', 'showposts', 'posts_per_rss', 'nopaging', 'paged', 'offset', 'no_found_rows', 'fields',
        // Caches, filters, presentation
        'suppress_filters', 'cache_results', 'update_post_term_cache', 'update_post_meta_cache', 'lazy_load_term_meta',
        'update_menu_item_cache', 'ignore_sticky_posts', 'caller_get_posts', 'comments_per_page', 'page', 'cpage',
        'error', 'preview', 'feed', 'tb', 'embed', 'comments_popup',
        // Public query vars WP_Query never reads; withoutcomments only matters for a single post's comment feed
        'posts', 'search', 'calendar', 'more', 'pb', 'withoutcomments',
    ];

    /**
     * The WP_Query arguments nothing translates, which send a query to MySQL, with an example.
     *
     * Each is a case of the parity harness, which checks that it falls back;
     * the integration suite checks that every argument WordPress knows is
     * either translated or listed here.
     *
     * @var array<string, array<string, mixed>>
     */
    public const UNTRANSLATED = [
        // Permissions and passwords: not in the documents
        'perm' => ['perm' => 'readable'],
        'post_password' => ['post_password' => 'secret'],
        // A search on the whole title or content, LIKE without %
        'exact' => ['s' => 'word', 'exact' => true],
        // Operators on meta keys
        'meta_compare_key' => ['meta_key' => 'key', 'meta_compare_key' => 'LIKE'],
        'meta_type_key' => ['meta_key' => 'key', 'meta_type_key' => 'BINARY'],
        // A comment feed of several posts: the posts commented last
        'withcomments' => ['feed' => 'rss2', 'withcomments' => 1],
        // Requests for something other than posts
        'robots' => ['robots' => 1],
        'favicon' => ['favicon' => 1],
        'sitemap' => ['sitemap' => 'posts'],
        'sitemap-subtype' => ['sitemap-subtype' => 'post'],
        'sitemap-stylesheet' => ['sitemap-stylesheet' => 'sitemap'],
        'rest_route' => ['rest_route' => '/wp/v2/posts'],
    ];

    /**
     * Shortcuts WP_Query turns into tax_query clauses.
     */
    private const TAXONOMY_SHORTCUTS = [
        'cat', 'category_name', 'category__in', 'category__not_in', 'category__and',
        'tag', 'tag_id', 'tag__in', 'tag__not_in', 'tag__and', 'tag_slug__in', 'tag_slug__and',
        'taxonomy', 'term',
    ];

    /**
     * Query vars for which 0 (or false) is a value, not the absence of one.
     */
    private const ZERO_IS_A_VALUE = ['post_parent', 'menu_order', 'hour', 'minute', 'second', 'comment_count', 'has_password'];

    /**
     * Why Meilisearch cannot answer the query as MySQL would, or null when it can.
     */
    public static function check(QueryInterface $query): ?string
    {
        return self::unsupportedVar($query) ?? self::integration($query) ?? self::sqlFilter($query) ?? self::unindexedStatus($query) ?? self::unindexedType($query);
    }

    /**
     * Why an integration sends the query to MySQL (WPML: wpml_display_as_translated).
     */
    private static function integration(QueryInterface $query): ?string
    {
        /**
         * Filters the reason a query runs on MySQL rather than Meilisearch, for
         * an integration that knows Meilisearch would answer it wrongly.
         *
         * @param  string|null  $reason  Null: no reason.
         * @param  QueryInterface  $query
         */
        $reason = apply_filters('meiliscout/fallback_reason', null, $query);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * A filter a plugin changed the query's SQL with: Meilisearch would not apply the change.
     */
    private static function sqlFilter(QueryInterface $query): ?string
    {
        $wp = QueryVars::wp($query);
        $hook = $wp !== null ? SqlFilters::changedBy($wp) : null;

        return $hook !== null ? 'sql_filter:'.$hook : null;
    }

    /**
     * The first query var that is set and that nothing translates.
     */
    private static function unsupportedVar(QueryInterface $query): ?string
    {
        $vars = match (true) {
            $query instanceof WPQueryAdapter => $query->wpQuery()->query_vars,
            property_exists($query, 'query_vars') => (array) $query->query_vars,
            default => [],
        };
        $handled = self::handledVars($query);

        foreach ($vars as $name => $value) {
            if (isset($handled[$name]) || self::isEmpty((string) $name, $value)) {
                continue;
            }

            return 'unsupported_arg:'.$name;
        }

        return null;
    }

    /**
     * The query vars translated by the builders, or that do not change which posts come back.
     *
     * A post type's or a taxonomy's own query var is translated too, once WordPress parsed it.
     *
     * @return list<string>
     */
    public static function translatedVars(): array
    {
        return [...self::HANDLED, ...PostFieldsBuilder::VARS, ...DateQueryBuilder::VARS, ...self::TAXONOMY_SHORTCUTS];
    }

    /**
     * @return array<string, true>
     */
    private static function handledVars(QueryInterface $query): array
    {
        $handled = [...self::HANDLED, ...PostFieldsBuilder::VARS, ...DateQueryBuilder::VARS];

        // WordPress parsed these into tax_query clauses, which are translated
        if ($query instanceof WPQueryAdapter) {
            $handled = [...$handled, ...self::TAXONOMY_SHORTCUTS];

            // A post type's query var: WordPress turned it into name or pagename
            foreach (get_post_types([], 'objects') as $type) {
                $queryVar = is_object($type) ? ($type->query_var ?: null) : null; // @phpstan-ignore function.alreadyNarrowedType

                if (is_string($queryVar)) {
                    $handled[] = $queryVar;
                }
            }

            foreach (get_taxonomies([], 'objects') as $taxonomy) {
                // Taxonomy objects, though a stand-in for WordPress may give names
                $queryVar = is_object($taxonomy) ? ($taxonomy->query_var ?: null) : null; // @phpstan-ignore function.alreadyNarrowedType

                if (is_string($queryVar)) {
                    $handled[] = $queryVar;
                }
            }
        }

        /**
         * Filters the query vars Meilisearch can serve a query with.
         *
         * Add a var a plugin reads only to change something Meilisearch
         * handles too, e.g. a var turned into a tax_query on parse_query.
         *
         * @param  list<string>  $handled
         */
        $handled = apply_filters('meiliscout/supported_query_vars', $handled);

        return array_fill_keys((array) $handled, true);
    }

    private static function isEmpty(string $name, mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (in_array($name, self::ZERO_IS_A_VALUE, true)) {
            return false;
        }

        return $value === false || $value === 0 || $value === '0';
    }

    /**
     * A status the query includes, that is not indexed, and that the queried types have posts in.
     */
    private static function unindexedStatus(QueryInterface $query): ?string
    {
        $indexed = PostIndexable::queryableStatuses();
        $requested = self::requestedStatuses($query);

        if (self::singularUnindexed($query, $indexed)) {
            return 'unindexed_status:singular';
        }

        if ($requested['explicit']) {
            // Asked for by name: no index holds drafts, pending or scheduled posts
            foreach ($requested['statuses'] as $status) {
                if (! in_array($status, $indexed, true)) {
                    return 'unindexed_status:'.$status;
                }
            }

            return null;
        }

        $types = QueryVars::postTypes($query);

        foreach ($requested['statuses'] as $status) {
            if (! in_array($status, $indexed, true) && self::hasPosts($types, $status)) {
                return 'unindexed_status:'.$status;
            }
        }

        return null;
    }

    /**
     * A single post asked for, that exists with a status the index does not hold.
     *
     * WordPress does not filter a single post on its status in SQL: it shows a
     * draft or a private post to whoever may read it, after the query, and
     * counts it in found_posts even for those who may not.
     *
     * @param  list<string>  $indexed  The statuses the index holds every post of
     */
    private static function singularUnindexed(QueryInterface $query, array $indexed): bool
    {
        $wp = QueryVars::wp($query);

        if ($wp === null || ! $wp->is_singular || ! empty($query->get('post_status'))) {
            return false;
        }

        global $wpdb;

        $statuses = implode(', ', array_map(static fn (string $status) => $wpdb->prepare('%s', $status), $indexed ?: ['publish']));
        $id = abs((int) $query->get('p'));
        $name = (string) $query->get('name');

        $where = match (true) {
            $id > 0 => $wpdb->prepare('ID = %d', $id),
            $name !== '' => $wpdb->prepare('post_name = %s', $name),
            default => null,
        };

        return $where !== null
            && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where} AND post_status NOT IN ({$statuses})") > 0;
    }

    /**
     * The statuses a query includes, as WP_Query::get_posts() works them out.
     *
     * @return array{statuses: list<string>, explicit: bool}
     */
    public static function requestedStatuses(QueryInterface $query): array
    {
        $status = $query->get('post_status');
        $wp = QueryVars::wp($query);

        if ($status !== null && $status !== '' && $status !== []) {
            $statuses = array_values(array_filter(array_map('trim', is_array($status) ? array_map('strval', $status) : explode(',', (string) $status))));

            if (! in_array('any', $statuses, true)) {
                return ['statuses' => $statuses, 'explicit' => true];
            }

            if ($wp === null) {
                return ['statuses' => ['publish'], 'explicit' => false];
            }

            // Every status but the ones excluded from search (trash, auto-draft), and those named too
            $excluded = array_diff(get_post_stati(['exclude_from_search' => true]), $statuses);

            return ['statuses' => array_values(array_diff(get_post_stati(), $excluded, ['any'])), 'explicit' => false];
        }

        if ($wp === null) {
            return ['statuses' => ['publish'], 'explicit' => false];
        }

        // The public statuses; in the admin, the ones listed under "All"; private ones for a logged-in user
        $statuses = array_values(get_post_stati(['public' => true]));

        if ($wp->is_admin) {
            $statuses = [...$statuses, ...array_values(get_post_stati(['protected' => true, 'show_in_admin_all_list' => true]))];
        }

        if (is_user_logged_in()) {
            $statuses = [...$statuses, ...array_values(get_post_stati(['private' => true]))];
        }

        return ['statuses' => array_values(array_unique($statuses)), 'explicit' => false];
    }

    /**
     * A queried post type that is not indexed and has posts in the queried statuses.
     */
    private static function unindexedType(QueryInterface $query): ?string
    {
        $types = QueryVars::postTypes($query);

        if ($types === 'any') {
            return null;
        }

        $indexed = (array) Settings::get('indexed_post_types', []);
        $statuses = self::requestedStatuses($query)['statuses'];

        foreach ($types as $type) {
            if (in_array($type, $indexed, true)) {
                continue;
            }

            foreach ($statuses as $status) {
                if (self::hasPosts([$type], $status)) {
                    return 'unindexed_type:'.$type;
                }
            }
        }

        return null;
    }

    /**
     * Whether posts of these types have this status; counts are cached by WordPress.
     *
     * @param  list<string>|'any'  $types
     */
    private static function hasPosts(array|string $types, string $status): bool
    {
        if ($types === 'any' || ! function_exists('wp_count_posts')) {
            return true;
        }

        foreach ($types as $type) {
            if ((int) (wp_count_posts($type)->$status ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
