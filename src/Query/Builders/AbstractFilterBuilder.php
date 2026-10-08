<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\Builders\Concerns\FormatsValues;

/**
 * Translates a nested query (tax_query, meta_query) into a Meilisearch filter.
 *
 * As in WordPress, a clause that restricts nothing (NOT IN no term) is left
 * out of its group, and a group left empty is left out of its parent. A
 * clause that cannot be translated is no such clause: it throws
 * UnsupportedQuery, and the query runs on MySQL. Dropping it would widen an
 * AND group and narrow an OR one.
 */
abstract class AbstractFilterBuilder implements QueryBuilderInterface
{
    use FormatsValues;

    /**
     * A filter no document matches, for a clause WordPress answers with no post.
     */
    protected const MATCH_NOTHING = 'post_type IN []';

    /**
     * The clauses to translate.
     *
     * @return array<int|string, mixed>
     */
    abstract protected function queries(QueryInterface $query): array;

    /**
     * Whether an entry of the query is a clause, rather than a nested group.
     *
     * @param  array<int|string, mixed>  $entry
     */
    abstract protected function isClause(array $entry): bool;

    /**
     * The filter of one clause, or '' when it restricts nothing.
     *
     * @param  array<string, mixed>  $clause
     *
     * @throws \Pollora\MeiliScout\Query\UnsupportedQuery
     */
    abstract protected function buildSingleFilter(array $clause): string;

    /**
     * Builds filter parameters from a query.
     *
     * @param  QueryInterface  $query  The query to build from
     * @param  array<string, mixed>  $searchParams  The search parameters to modify
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $filter = $this->buildGroup($this->queries($query));

        if ($filter !== '') {
            $searchParams['filter'] = $searchParams['filter'] ?? [];
            $searchParams['filter'][] = "($filter)";
        }
    }

    /**
     * The filter of a group of clauses, or '' when none of them restricts anything.
     *
     * @param  array<int|string, mixed>  $group
     */
    private function buildGroup(array $group): string
    {
        $relation = strtoupper((string) ($group['relation'] ?? 'AND')) === 'OR' ? 'OR' : 'AND';
        unset($group['relation']);

        $filters = [];

        foreach ($group as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if ($this->isClause($entry)) {
                $filter = $this->buildSingleFilter($entry);
            } else {
                $filter = $this->buildGroup($entry);
                $filter = $filter !== '' ? "({$filter})" : '';
            }

            if ($filter !== '') {
                $filters[] = $filter;
            }
        }

        return implode(" {$relation} ", $filters);
    }
}
