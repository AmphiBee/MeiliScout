<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\Builders\Concerns\FormatsValues;

abstract class AbstractFilterBuilder implements QueryBuilderInterface
{
    use FormatsValues;

    abstract protected function getQueryKey(): string;

    abstract protected function buildSingleFilter(array $query): string;

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
