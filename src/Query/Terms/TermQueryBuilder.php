<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Terms;

use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Query\Builders\Concerns\FormatsValues;
use Pollora\MeiliScout\Query\Builders\MetaQueryBuilder;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\ContainsFilter;
use Pollora\MeiliScout\Services\MetaValueFlags;
use WP_Term_Query;

/**
 * Translates a WP_Term_Query into a Meilisearch search: what its SQL does, and what WordPress does after it.
 *
 * The query is read at terms_pre_query, as WP_Term_Query::get_terms() left
 * it: its vars parsed, its exclusions worked out (exclude, exclude_tree,
 * childless). The SQL's WHERE becomes the filter, its ORDER BY the sort,
 * and its LIMIT the page, when WordPress sets one. When it does not
 * (hierarchical queries, child_of, parent), WordPress filters and pages the
 * terms in PHP: the plan says so, for TermResults to do the same.
 *
 * Pure: the query is never modified. What cannot be translated throws UnsupportedQuery.
 */
final class TermQueryBuilder
{
    use FormatsValues;

    /**
     * orderby values and the attributes they sort on (WP_Term_Query::parse_orderby()).
     */
    private const ORDERS = [
        'term_id' => 'term_id', 'name' => 'name_sort', 'slug' => 'slug', 'term_group' => 'term_group',
        'count' => 'count', 'parent' => 'parent', 'taxonomy' => 'taxonomy',
        'term_taxonomy_id' => 'term_taxonomy_id', 'description' => 'description_sort',
    ];

    public function build(WP_Term_Query $query): TermQueryPlan
    {
        $args = $query->query_vars;
        $plan = new TermQueryPlan;
        $plan->taxonomies = array_values(array_map('strval', (array) ($args['taxonomy'] ?? [])));
        $plan->fields = is_string($args['fields'] ?? null) ? $args['fields'] : 'all';
        $plan->childOf = (int) ($args['child_of'] ?? 0);
        $plan->hierarchical = ! empty($args['hierarchical']) && $plan->fields !== 'count';
        $plan->hideEmpty = ! empty($args['hide_empty']);
        $plan->padCounts = ! empty($args['pad_counts']);
        $plan->number = (int) ($args['number'] ?? 0);
        $plan->offset = (int) ($args['offset'] ?? 0);
        $plan->updateMetaCache = ! empty($args['update_term_meta_cache']);
        $parent = $args['parent'] ?? '';

        // Don't limit the query results when we have to descend the family tree: as WordPress
        $plan->limited = $plan->number > 0 && ! $plan->hierarchical && ! $plan->childOf && $parent === '';

        if ($plan->taxonomies !== []) {
            $plan->filters[] = 'taxonomy IN ['.$this->formatArrayValues($plan->taxonomies).']';
        }

        $include = $args['include'] ?? [];
        if (! empty($include)) {
            $plan->filters[] = 'term_id IN ['.implode(', ', wp_parse_id_list($include)).']';
        }

        $exclusions = $this->exclusions($query);
        if ($exclusions !== []) {
            $plan->filters[] = 'term_id NOT IN ['.implode(', ', $exclusions).']';
        }

        foreach ([$this->names($args, $plan->taxonomies), $this->slugs($args), $this->termTaxonomyIds($args)] as $filter) {
            if ($filter !== null) {
                $plan->filters[] = $filter;
            }
        }

        foreach (['name__like' => 'name_sort', 'description__like' => 'description_fold'] as $var => $attribute) {
            if (! empty($args[$var])) {
                $plan->filters[] = $this->contains($attribute, $args[$var], $var);
            }
        }

        if (! empty($args['object_ids'])) {
            throw new UnsupportedQuery('unsupported_arg:object_ids');
        }

        if ($parent !== '') {
            $plan->filters[] = 'parent = '.(int) $parent;
        }

        if ($plan->hideEmpty && ! $plan->hierarchical) {
            $plan->filters[] = 'count > 0';
        }

        if (! empty($args['search'])) {
            $this->search($plan, (string) $args['search']);
        }

        $this->metaQuery($query, $plan);
        $this->order($query, $plan);

        return $plan;
    }

    /**
     * The ids WordPress excludes: exclude, exclude_tree and childless, as it worked them out.
     *
     * @return list<int>
     */
    private function exclusions(WP_Term_Query $query): array
    {
        // A protected property, filled before terms_pre_query
        $where = (fn () => $this->sql_clauses['where'] ?? null)->call($query);

        if (! is_array($where)) {
            throw new UnsupportedQuery('unsupported_exclusions');
        }

        $sql = $where['exclusions'] ?? '';

        if ($sql === '') {
            return [];
        }

        if (! is_string($sql) || ! preg_match('/^t\.term_id NOT IN \(([\d,\s-]*)\)$/', trim($sql), $matches)) {
            // Written by a list_terms_exclusions filter
            throw new UnsupportedQuery('unsupported_exclusions');
        }

        return array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', $matches[1])), static fn (string $id) => $id !== ''))));
    }

    /**
     * name: the names sanitized as WordPress sanitizes them, compared as MySQL's collation compares them.
     *
     * @param  array<string, mixed>  $args
     * @param  list<string>  $taxonomies
     */
    private function names(array $args, array $taxonomies): ?string
    {
        $names = ($args['name'] ?? '') === '' ? [] : (array) $args['name'];

        if ($names === []) {
            return null;
        }

        $taxonomy = $taxonomies[0] ?? false;
        $folded = array_map(
            static fn ($name) => PostIndexable::titleSortKey(stripslashes((string) sanitize_term_field('name', $name, 0, $taxonomy, 'db'))),
            $names
        );

        return 'name_sort IN ['.implode(', ', array_map(fn (string $name) => $this->quote($name), $folded)).']';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function slugs(array $args): ?string
    {
        $slugs = ($args['slug'] ?? '') === '' ? [] : array_map('sanitize_title', (array) $args['slug']);

        return $slugs === [] ? null : 'slug IN ['.implode(', ', array_map(fn ($slug) => $this->quote((string) $slug), $slugs)).']';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function termTaxonomyIds(array $args): ?string
    {
        $ids = ($args['term_taxonomy_id'] ?? '') === '' ? [] : array_map('intval', (array) $args['term_taxonomy_id']);

        return $ids === [] ? null : 'term_taxonomy_id IN ['.implode(', ', $ids).']';
    }

    /**
     * LIKE '%value%' on a folded field, through CONTAINS; MySQL without it.
     */
    private function contains(string $attribute, mixed $value, string $var): string
    {
        if (! ContainsFilter::enabled()) {
            throw new UnsupportedQuery('unsupported_compare:LIKE');
        }

        if (! is_scalar($value)) {
            throw new UnsupportedQuery('unsupported_arg:'.$var);
        }

        return "{$attribute} CONTAINS ".$this->quote(self::fold((string) $value));
    }

    /**
     * search: WordPress' (name LIKE %s% OR slug LIKE %s%) through CONTAINS, else Meilisearch's relevance.
     */
    private function search(TermQueryPlan $plan, string $search): void
    {
        if (ContainsFilter::enabled()) {
            $needle = $this->quote(self::fold($search));
            $plan->filters[] = "(name_sort CONTAINS {$needle} OR slug CONTAINS {$needle})";

            return;
        }

        $plan->q = $search;
        $plan->relevance = true;
    }

    private function metaQuery(WP_Term_Query $query, TermQueryPlan $plan): void
    {
        $params = [];
        (new MetaQueryBuilder('term'))->build(new TermQueryAdapter($query), $params);

        foreach ($params['filter'] ?? [] as $filter) {
            $plan->filters[] = $filter;
        }
    }

    /**
     * ORDER BY, as WP_Term_Query::parse_orderby() and parse_order() build it.
     */
    private function order(WP_Term_Query $query, TermQueryPlan $plan): void
    {
        $args = $query->query_vars;

        // A count is not ordered; a search without CONTAINS is ranked by relevance
        if ($plan->fields === 'count' || $plan->relevance) {
            return;
        }

        $order = $args['order'] ?? 'ASC';
        $direction = is_string($order) && $order !== '' && strtoupper($order) === 'ASC' ? 'asc' : 'desc';
        $orderby = is_scalar($args['orderby'] ?? '') ? (string) ($args['orderby'] ?? '') : '';

        // 'term_order' is a legal sort order only when joining the relationship table
        if ($orderby === 'term_order' && empty($args['object_ids'])) {
            $orderby = 'term_id';
        }

        $orderby = strtolower($orderby);

        if (isset(self::ORDERS[$orderby])) {
            $plan->sort = [self::ORDERS[$orderby].':'.$direction];

            return;
        }

        if ($orderby === 'include' && ! empty($args['include'])) {
            $plan->listOrder = ['field' => 'term_id', 'values' => wp_parse_id_list($args['include']), 'desc' => $direction === 'desc'];

            return;
        }

        if ($orderby === 'slug__in' && ! empty($args['slug']) && is_array($args['slug'])) {
            $plan->listOrder = ['field' => 'slug', 'values' => array_map('sanitize_title_for_query', $args['slug']), 'desc' => $direction === 'desc'];

            return;
        }

        if ($orderby === 'none') {
            return;
        }

        if ($orderby === '' || $orderby === 'id') {
            $plan->sort = ['term_id:'.$direction];

            return;
        }

        if ($orderby === 'term_order') {
            throw new UnsupportedQuery('unsupported_orderby:term_order');
        }

        // The name, unless it is an order on a meta clause
        $plan->sort = [($this->metaOrder($query, $orderby) ?? 'name_sort').':'.$direction];
    }

    /**
     * The attribute of an order on a meta clause (WP_Term_Query::parse_orderby_meta()), or null.
     */
    private function metaOrder(WP_Term_Query $query, string $orderby): ?string
    {
        $clauses = self::metaClauses((new TermQueryAdapter($query))->get('meta_query', []));

        if ($clauses === []) {
            return null;
        }

        $primary = reset($clauses);
        $primaryKey = ! empty($primary['key']) && is_string($primary['key']) ? $primary['key'] : null;
        $allowed = [...($primaryKey !== null ? [$primaryKey] : []), 'meta_value', 'meta_value_num', ...array_map('strval', array_keys($clauses))];

        if (! in_array($orderby, $allowed, true)) {
            return null;
        }

        $key = match (true) {
            $orderby === $primaryKey, $orderby === 'meta_value', $orderby === 'meta_value_num' => $primaryKey,
            default => is_string($clauses[$orderby]['key'] ?? null) ? $clauses[$orderby]['key'] : null,
        };

        if ($key === null || ! in_array($key, MetaQueryBuilder::indexedKeys('term'), true)) {
            throw new UnsupportedQuery('unsupported_orderby:'.$orderby);
        }

        $flags = MetaValueFlags::of($key, 'term');

        if (! MetaValueFlags::sortable($key, 'term')) {
            throw new UnsupportedQuery('unsupported_orderby:'.$orderby);
        }

        return "metas.{$key}";
    }

    /**
     * The first-order clauses of a meta query, by name: WP_Meta_Query::get_clauses().
     *
     * @param  mixed  $metaQuery
     * @return array<int|string, array<string, mixed>>
     */
    private static function metaClauses(mixed $metaQuery): array
    {
        $clauses = [];

        foreach (is_array($metaQuery) ? $metaQuery : [] as $name => $entry) {
            if ($name === 'relation' || ! is_array($entry)) {
                continue;
            }

            if (isset($entry['key']) || isset($entry['value'])) {
                $clauses[$name] = $entry;
            } else {
                $clauses += self::metaClauses($entry);
            }
        }

        return $clauses;
    }

    /**
     * A text folded as MySQL's collation compares it in a LIKE: lowercase, without accents, spaces kept.
     */
    public static function fold(string $text): string
    {
        return mb_strtolower(function_exists('remove_accents') ? remove_accents($text) : $text);
    }
}
