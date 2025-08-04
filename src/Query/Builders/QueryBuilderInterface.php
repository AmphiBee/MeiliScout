<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;

/**
 * Interface for query builder components.
 * 
 * Defines the contract for classes that build parts of a MeiliSearch query
 * from WordPress query parameters.
 */
interface QueryBuilderInterface
{
    /**
     * Builds search parameters from a query.
     * 
     * @param QueryInterface $query The query to build parameters from
     * @param array $searchParams The search parameters to modify
     * @return void
     */
    public function build(QueryInterface $query, array &$searchParams): void;
}
