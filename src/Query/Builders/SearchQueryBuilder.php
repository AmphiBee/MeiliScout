<?php

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;

class SearchQueryBuilder implements QueryBuilderInterface
{
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $searchTerm = $query->get('s');
        
        if (!empty($searchTerm)) {
            $searchParams['q'] = $searchTerm;
        }
    }
} 