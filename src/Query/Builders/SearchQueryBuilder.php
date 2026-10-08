<?php

declare(strict_types=1);

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
     * @param  QueryInterface  $query  The WordPress query
     * @param  array<string, mixed>  $searchParams  The MeiliSearch search parameters to modify
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $searchTerm = $query->get('s');

        // '0' is a search too
        if (is_scalar($searchTerm) && (string) $searchTerm !== '') {
            $searchParams['q'] = (string) $searchTerm;
        }
    }
}
