<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Terms;

use Meilisearch\Client;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexSettings;
use stdClass;
use WP_Term;

/**
 * The result of a term query, in the shape get_terms() returns it.
 *
 * terms_pre_query skips everything WordPress does after its SQL, so it is
 * done here, the same way and with the same functions: the descendants of
 * child_of (_get_term_children), pad_counts (_pad_term_counts), the empty
 * terms of a hierarchical taxonomy, the page, then the shape fields asks
 * for. Array keys are WordPress' too, gaps included.
 *
 * When WordPress pages in SQL, Meilisearch gives the page. Otherwise every
 * matching term comes back as a light hit (ids, parent, counts), the work is
 * done on those, and only the terms returned are loaded.
 */
final class TermResults
{
    /**
     * Attributes of the light hits.
     */
    private const LIGHT = ['term_id', 'term_taxonomy_id', 'parent', 'count', 'tree_count', 'taxonomy', 'slug'];

    public function __construct(
        private readonly Client $client,
        private readonly string $index,
    ) {}

    /**
     * @return array<int|string, mixed>|string|null As get_terms() returns it: a count is a numeric string
     *
     * @throws UnsupportedQuery When there are more terms than can be put in order in PHP
     */
    public function get(TermQueryPlan $plan): array|string|null
    {
        $params = $plan->params();

        if ($plan->fields === 'count') {
            $total = $plan->objectTerms === null
                ? $this->search([...$params, 'hitsPerPage' => 0, 'page' => 1])->getTotalHits()
                : $this->objectTermRows($params, $plan->objectTerms);

            // SELECT COUNT(*) ... LIMIT offset, number: past the only row
            return $plan->limited && $plan->offset > 0 ? null : (string) $total;
        }

        if ($plan->pagedByMeilisearch()) {
            $hits = $this->search([...$params, 'offset' => $plan->offset, 'limit' => $plan->number, 'attributesToRetrieve' => self::LIGHT])->getHits();
            $terms = $this->load($this->stubs($hits), $plan);

            if ($plan->padCounts && $plan->fields === 'all') {
                $this->padCounts($terms, $plan);
            }

            return $this->format($terms, $plan->fields);
        }

        $terms = $this->load($this->inPhp($params, $plan), $plan);

        // WordPress caches padded terms as a list: keys are renumbered from the second call on, and with a persistent cache
        if ($plan->padCounts && $plan->fields === 'all') {
            $terms = array_values($terms);
        }

        return $this->format($terms, $plan->fields);
    }

    /**
     * The rows a count of terms by object_ids counts: one per post and term (SELECT DISTINCT COUNT(*)).
     *
     * @param  array<string, mixed>  $params
     * @param  array<int, list<int>>  $objectTerms
     */
    private function objectTermRows(array $params, array $objectTerms): int
    {
        $limit = IndexSettings::maxTotalHits();
        $hits = $this->search([...$params, 'offset' => 0, 'limit' => $limit, 'attributesToRetrieve' => ['term_id']])->getHits();

        if (count($hits) >= $limit) {
            throw new UnsupportedQuery('too_many_terms');
        }

        $matching = array_flip(array_map(static fn (array $hit) => (int) $hit['term_id'], $hits));
        $rows = 0;

        foreach ($objectTerms as $termIds) {
            $rows += count(array_filter($termIds, static fn (int $id) => isset($matching[$id])));
        }

        return $rows;
    }

    /**
     * Every matching term, then what WordPress does after its SQL.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, stdClass>
     */
    private function inPhp(array $params, TermQueryPlan $plan): array
    {
        $limit = IndexSettings::maxTotalHits();
        $hits = $this->search([...$params, 'offset' => 0, 'limit' => $limit, 'attributesToRetrieve' => self::LIGHT])->getHits();

        if (count($hits) >= $limit) {
            throw new UnsupportedQuery('too_many_terms');
        }

        $terms = $this->stubs($hits);

        if ($plan->listOrder !== null) {
            $terms = $this->inListOrder($terms, $plan->listOrder);

            // Paged in SQL, on the order of the list
            if ($plan->limited) {
                return array_slice($terms, $plan->offset, $plan->number);
            }
        }

        if ($plan->childOf) {
            foreach ($plan->taxonomies as $taxonomy) {
                if (_get_term_hierarchy($taxonomy) !== []) {
                    $children = _get_term_children($plan->childOf, $terms, $taxonomy);
                    $terms = is_array($children) ? $children : [];
                }
            }
        }

        if ($plan->padCounts && $plan->fields === 'all') {
            $this->padCounts($terms, $plan);
        }

        // An empty term stays when one of its descendants has posts
        if ($plan->hierarchical && $plan->hideEmpty) {
            foreach ($terms as $key => $term) {
                if (! $term->count && $term->tree_count <= 0) {
                    unset($terms[$key]);
                }
            }
        }

        if ($plan->hierarchical && $plan->number) {
            $terms = $plan->offset >= count($terms) ? [] : array_slice($terms, $plan->offset, $plan->number, true);
        }

        return $terms;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function search(array $params): \Meilisearch\Search\SearchResult
    {
        $q = (string) ($params['q'] ?? '');
        unset($params['q']);

        return $this->client->index($this->index)->search($q, $params);
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return list<stdClass>
     */
    private function stubs(array $hits): array
    {
        return array_map(static fn (array $hit): stdClass => (object) [
            'term_id' => (int) ($hit['term_id'] ?? 0),
            'term_taxonomy_id' => (int) ($hit['term_taxonomy_id'] ?? 0),
            'parent' => (int) ($hit['parent'] ?? 0),
            'count' => (int) ($hit['count'] ?? 0),
            'tree_count' => (int) ($hit['tree_count'] ?? 0),
            'taxonomy' => (string) ($hit['taxonomy'] ?? ''),
            'slug' => (string) ($hit['slug'] ?? ''),
        ], $hits);
    }

    /**
     * The terms in the order of a list, as MySQL's FIELD(): those not in it first, then by position.
     *
     * @param  list<stdClass>  $terms
     * @param  array{field: string, values: list<int|string>, desc: bool}  $order
     * @return list<stdClass>
     */
    private function inListOrder(array $terms, array $order): array
    {
        $positions = [];

        foreach ($order['values'] as $position => $value) {
            $positions[(string) $value] ??= $position + 1;
        }

        $field = $order['field'];
        usort($terms, static function (stdClass $a, stdClass $b) use ($positions, $field, $order): int {
            $comparison = ($positions[(string) $a->$field] ?? 0) <=> ($positions[(string) $b->$field] ?? 0);

            return $order['desc'] ? -$comparison : $comparison;
        });

        return $terms;
    }

    /**
     * Adds the posts of their descendants among the terms to their counts, as WordPress does.
     *
     * @param  array<object>  $terms
     */
    private function padCounts(array &$terms, TermQueryPlan $plan): void
    {
        foreach ($plan->taxonomies as $taxonomy) {
            _pad_term_counts($terms, $taxonomy);
        }
    }

    /**
     * The terms themselves, from the database as WordPress loads them, keys kept.
     *
     * @param  array<int, stdClass|WP_Term>  $stubs
     * @return array<int, WP_Term>
     */
    private function load(array $stubs, TermQueryPlan $plan): array
    {
        $ids = array_map(static fn (object $stub) => (int) $stub->term_id, $stubs);

        if ($ids === []) {
            return [];
        }

        _prime_term_caches(array_values($ids), false);

        $terms = [];

        foreach ($stubs as $key => $stub) {
            $term = get_term((int) $stub->term_id);

            // The index may lag behind: a term deleted since is not returned
            if (! $term instanceof WP_Term) {
                continue;
            }

            // A count WordPress padded
            if ($plan->padCounts && $plan->fields === 'all') {
                $term->count = (int) $stub->count;
            }

            $terms[$key] = $term;
        }

        if ($plan->updateMetaCache) {
            wp_lazyload_term_meta(array_values(array_map(static fn (WP_Term $term) => $term->term_id, $terms)));
        }

        return $terms;
    }

    /**
     * The shape fields asks for, as WP_Term_Query::format_terms() gives it.
     *
     * @param  array<int, WP_Term>  $terms
     * @return array<int|string, mixed>
     */
    private function format(array $terms, string $fields): array
    {
        $formatted = [];

        foreach ($terms as $term) {
            match ($fields) {
                'id=>parent' => $formatted[$term->term_id] = $term->parent,
                'ids' => $formatted[] = (int) $term->term_id,
                'tt_ids' => $formatted[] = (int) $term->term_taxonomy_id,
                'names' => $formatted[] = $term->name,
                'slugs' => $formatted[] = $term->slug,
                'id=>name' => $formatted[$term->term_id] = $term->name,
                'id=>slug' => $formatted[$term->term_id] = $term->slug,
                default => null,
            };
        }

        return in_array($fields, ['all', 'all_with_object_id'], true) ? $terms : $formatted;
    }
}
