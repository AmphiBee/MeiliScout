<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\Builders\Concerns\FormatsValues;

/**
 * Abstract base class for filter builders.
 * 
 * Provides common functionality for building MeiliSearch filter expressions
 * from WordPress query parameters.
 */
abstract class AbstractFilterBuilder implements QueryBuilderInterface
{
    use FormatsValues;

    /**
     * Gets the query key this builder is responsible for.
     * 
     * @return string The query key
     */
    abstract protected function getQueryKey(): string;

    /**
     * Builds a single filter expression from a query array.
     * 
     * @param array $query The query array
     * @return string The filter expression
     */
    abstract protected function buildSingleFilter(array $query): string;

    /**
     * Builds filter parameters from a query.
     * 
     * @param QueryInterface $query The query to build from
     * @param array $searchParams The search parameters to modify
     * @return void
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $queryData = $query->get($this->getQueryKey());

        if (empty($queryData) || ! is_array($queryData)) {
            return;
        }

        $relation = strtoupper($queryData['relation'] ?? 'AND');
        unset($queryData['relation']);

        if ($filters = $this->buildFilters($queryData, $relation)) {
            $searchParams['filter'] = $searchParams['filter'] ?? [];
            $searchParams['filter'][] = "($filters)";
        }
    }

    /**
     * Recursively builds filter expressions from an array of queries.
     * 
     * @param array $queries The queries to build filters from
     * @param string $relation The relation between filters ('AND' or 'OR')
     * @return string The combined filter expression
     */
    private function buildFilters(array $queries, string $relation = 'AND'): string
    {
        $filters = array_map(function ($query) {
            if (! is_array($query)) {
                return '';
            }

            if (isset($query['relation'])) {
                return '('.$this->buildFilters($query, strtoupper($query['relation'])).')';
            }

            return $this->buildSingleFilter($query);
        }, $queries);

        return implode(" $relation ", array_filter($filters));
    }
}
