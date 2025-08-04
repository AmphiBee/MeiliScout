<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;

/**
 * Builder for post type and status filters.
 * 
 * Handles the conversion of WordPress post_type and post_status parameters to MeiliSearch filter syntax.
 */
class TypeStatusBuilder implements QueryBuilderInterface
{
    /**
     * Builds the post type and status filters for MeiliSearch.
     * 
     * @param QueryInterface $query The WordPress query
     * @param array $searchParams The MeiliSearch search parameters to modify
     * @return void
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $searchParams['filter'] = $searchParams['filter'] ?? [];

        $this->addFilter($searchParams['filter'], 'post_type', $query->get('post_type') ?? 'post');
        $this->addFilter($searchParams['filter'], 'post_status', $query->get('post_status') ?? 'publish');
    }

    /**
     * Adds a filter to the filter array.
     * 
     * @param array $filters The filter array to modify
     * @param string $key The filter key
     * @param array|string $value The filter value(s)
     * @return void
     */
    private function addFilter(array &$filters, string $key, array|string $value): void
    {
        if (is_array($value)) {
            $escapedValues = array_map(fn ($val) => sprintf("'%s'", addslashes($val)), $value);
            $filters[] = sprintf('%s IN [%s]', $key, implode(', ', $escapedValues));
        } else {
            $filters[] = sprintf("%s = '%s'", $key, addslashes($value));
        }
    }
}
