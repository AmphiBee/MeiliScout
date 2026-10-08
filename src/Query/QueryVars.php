<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Pollora\MeiliScout\Contracts\QueryInterface;
use WP_Meta_Query;
use WP_Query;
use WP_Tax_Query;

/**
 * The query as WordPress understood it, for the builders to translate.
 *
 * By the time posts_pre_query runs, WP_Query has turned its shortcuts into
 * parsed state: cat, tag, category_name and taxonomy query vars are clauses
 * of $query->tax_query, meta_key and meta_value one of $query->meta_query,
 * and the post type a search or a custom taxonomy archive implies is known.
 * Reading that state, rather than the raw query vars, is what makes the
 * translation match MySQL.
 *
 * A query that is no WP_Query (the unit tests' stand-in) only has its raw
 * vars: they are read the way WordPress would parse them, as far as it can
 * be done without WordPress.
 */
final class QueryVars
{
    /**
     * The WP_Query behind a query, when there is one.
     */
    public static function wp(QueryInterface $query): ?WP_Query
    {
        return $query instanceof WPQueryAdapter ? $query->wpQuery() : null;
    }

    /**
     * The tax_query clauses, shortcuts included.
     *
     * @return array<int|string, mixed>
     */
    public static function taxQuery(QueryInterface $query): array
    {
        $wp = self::wp($query);

        if ($wp !== null && $wp->tax_query instanceof WP_Tax_Query) {
            return $wp->tax_query->queries;
        }

        $taxQuery = $query->get('tax_query');

        return is_array($taxQuery) ? $taxQuery : [];
    }

    /**
     * The meta_query clauses, meta_key and meta_value included, as WP_Meta_Query::parse_query_vars() builds them.
     *
     * @return array<int|string, mixed>
     */
    public static function metaQuery(QueryInterface $query): array
    {
        $wp = self::wp($query);

        // Set by WP_Query::get_posts(), before posts_pre_query
        // @phpstan-ignore instanceof.alwaysTrue
        if ($wp !== null && $wp->meta_query instanceof WP_Meta_Query) {
            return $wp->meta_query->queries;
        }

        $primary = [];

        foreach (['key', 'compare', 'type', 'compare_key', 'type_key'] as $field) {
            if (! empty($query->get("meta_{$field}"))) {
                $primary[$field] = $query->get("meta_{$field}");
            }
        }

        // WP_Query sets meta_value to '' by default: no value
        $value = $query->get('meta_value');
        if ($value !== null && $value !== '' && $value !== []) {
            $primary['value'] = $value;
        }

        $existing = $query->get('meta_query');
        $existing = is_array($existing) ? $existing : [];

        return match (true) {
            $primary !== [] && $existing !== [] => ['relation' => 'AND', $primary, $existing],
            $primary !== [] => [$primary],
            default => $existing,
        };
    }

    /**
     * The post types the query is about: a list, or 'any' when WordPress is not there to tell.
     *
     * As in WP_Query::get_posts(): an empty post_type is 'post', the types of
     * the queried custom taxonomies on a taxonomy archive, 'page' for a page;
     * 'any' is every type not excluded from search.
     *
     * @return list<string>|'any'
     */
    public static function postTypes(QueryInterface $query): array|string
    {
        $postType = $query->get('post_type');
        $wp = self::wp($query);

        if ($postType === null || $postType === '' || $postType === []) {
            $postType = $wp !== null ? self::impliedPostType($wp) : self::impliedPostTypeWithoutWordPress($query);
        }

        if ($postType === 'any' || $postType === ['any']) {
            if (! function_exists('get_post_types')) {
                return 'any';
            }

            return array_values(get_post_types(['exclude_from_search' => false]));
        }

        return array_values(array_map('strval', (array) $postType));
    }

    /**
     * The value of `order`, as WordPress reads it: ASC or DESC (the default).
     */
    public static function order(mixed $order): string
    {
        return is_string($order) && strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    }

    /**
     * Whether WordPress queries without a LIMIT: nopaging, -1 posts per page, a single post or page.
     */
    public static function isUnpaged(QueryInterface $query): bool
    {
        $wp = self::wp($query);

        return ! empty($query->get('nopaging'))
            || (int) $query->get('posts_per_page') === -1
            || ($wp !== null && $wp->is_singular);
    }

    /**
     * The post type WP_Query::get_posts() derives when none is given.
     *
     * @return string|list<string>
     */
    private static function impliedPostType(WP_Query $wp): string|array
    {
        if ($wp->is_tax && $wp->tax_query instanceof WP_Tax_Query) {
            $taxonomies = array_keys($wp->tax_query->queried_terms);
            $types = [];

            foreach (get_post_types(['exclude_from_search' => false]) as $type) {
                $objectTaxonomies = $type === 'attachment' ? get_taxonomies_for_attachments() : get_object_taxonomies($type);

                if (array_intersect($taxonomies, $objectTaxonomies)) {
                    $types[] = $type;
                }
            }

            return $types === [] ? 'any' : $types;
        }

        return match (true) {
            $wp->is_attachment => 'attachment',
            $wp->is_page => 'page',
            default => 'post',
        };
    }

    /**
     * Without WordPress: posts, or any type for a search (WP_Query sets post_type to 'any' then).
     */
    private static function impliedPostTypeWithoutWordPress(QueryInterface $query): string
    {
        $search = $query->get('s');

        return $search !== null && $search !== '' ? 'any' : 'post';
    }
}
