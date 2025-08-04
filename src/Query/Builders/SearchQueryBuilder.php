<?php

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;

/**
 * Builder for search query parameters.
 * 
 * Handles the conversion of WordPress 's' (search) parameter to MeiliSearch query format.
 */
class SearchQueryBuilder implements QueryBuilderInterface
{
    /**
     * Builds search query parameters from a WordPress query.
     * 
     * @param QueryInterface $query The WordPress query
     * @param array $searchParams The MeiliSearch search parameters to modify
     * @return void
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $searchTerm = $query->get('s');

        if (! empty($searchTerm)) {
            $searchParams['q'] = $searchTerm;
        }
    }
}
