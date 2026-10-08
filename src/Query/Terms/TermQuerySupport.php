<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Terms;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Services\IndexNames;
use WP_Term_Query;

/**
 * Tells whether Meilisearch can answer a term query as MySQL would, before it is translated.
 *
 * The index must be in schema 4 and hold every term of the queried
 * taxonomies: they must be indexed, and have been by the last full
 * indexation. A plugin that changed the query's SQL sends it to MySQL.
 *
 * Unlike WP_Query's, the arguments a term query is given beyond
 * WP_Term_Query's are no reason to fall back: wp_dropdown_categories() and
 * others pass their own, and a plugin can only act on them through the SQL
 * filters, which are watched (TermSqlFilters). WP_Term_Query's own
 * arguments are each translated, or fall back in the builder.
 */
final class TermQuerySupport
{
    /**
     * Why Meilisearch cannot answer the query as MySQL would, or null when it can.
     *
     * @param  list<string>  $changedSqlFilters  The SQL filters a plugin changed the query with
     */
    public static function check(WP_Term_Query $query, array $changedSqlFilters): ?string
    {
        if (IndexNames::activeSchema('taxonomies') < 4) {
            return 'schema_too_old';
        }

        $hook = TermSqlFilters::changedBy($changedSqlFilters);

        if ($hook !== null) {
            return 'sql_filter:'.$hook;
        }

        return self::unindexedTaxonomy($query);
    }

    /**
     * The taxonomies whose every term the index holds: indexed, and by the last full indexation.
     *
     * @return list<string>
     */
    public static function queryableTaxonomies(): array
    {
        $structure = get_option('meiliscout/last_indexing_structure', []);
        $indexed = is_array($structure) && isset($structure['taxonomies']) ? (array) $structure['taxonomies'] : [];

        return array_values(array_intersect((array) Settings::get('indexed_taxonomies', []), $indexed));
    }

    /**
     * A queried taxonomy the index lacks; without a taxonomy, any taxonomy the site has terms in.
     */
    private static function unindexedTaxonomy(WP_Term_Query $query): ?string
    {
        $queryable = self::queryableTaxonomies();
        $taxonomies = array_values(array_map('strval', (array) ($query->query_vars['taxonomy'] ?? [])));

        if ($taxonomies === []) {
            global $wpdb;

            $taxonomies = array_map('strval', (array) $wpdb->get_col("SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}"));
        }

        foreach ($taxonomies as $taxonomy) {
            if (! in_array($taxonomy, $queryable, true)) {
                return 'unindexed_taxonomy:'.$taxonomy;
            }
        }

        return null;
    }
}
