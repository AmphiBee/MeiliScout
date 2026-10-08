<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\Builders\DateQueryBuilder;
use Pollora\MeiliScout\Query\Builders\FieldsBuilder;
use Pollora\MeiliScout\Query\Builders\MetaQueryBuilder;
use Pollora\MeiliScout\Query\Builders\OrderBuilder;
use Pollora\MeiliScout\Query\Builders\PaginationBuilder;
use Pollora\MeiliScout\Query\Builders\PostFieldsBuilder;
use Pollora\MeiliScout\Query\Builders\SearchQueryBuilder;
use Pollora\MeiliScout\Query\Builders\TaxQueryBuilder;
use Pollora\MeiliScout\Query\Builders\TypeStatusBuilder;

/**
 * MeiliSearch query builder.
 * 
 * Builds search parameters for MeiliSearch from a WordPress query.
 */
class MeiliQueryBuilder
{
    /**
     * Collection of query builders.
     *
     * @var list<Builders\QueryBuilderInterface>
     */
    private array $builders;

    /**
     * Constructor.
     * 
     * Initializes all the query builders needed to construct a MeiliSearch query.
     */
    public function __construct()
    {
        $this->builders = [
            new PaginationBuilder,
            new SearchQueryBuilder,
            new TypeStatusBuilder,
            new PostFieldsBuilder,
            new TaxQueryBuilder,
            new MetaQueryBuilder,
            new DateQueryBuilder,
            new OrderBuilder,
            new FieldsBuilder,
        ];
    }

    /**
     * Builds search parameters from a query.
     *
     * The query is only read: building it twice gives the same parameters.
     *
     * @param QueryInterface $query The query to build parameters from
     * @return array<string, mixed> The constructed search parameters for MeiliSearch
     *
     * @throws UnsupportedQuery When Meilisearch cannot answer it as MySQL would
     */
    public function build(QueryInterface $query): array
    {
        /** @var array<string, mixed> $params */
        $params = [];

        foreach ($this->builders as $builder) {
            $builder->build($query, $params);
        }

        // Combine filters at the end; send none rather than an empty one
        $filters = $params['filter'] ?? [];
        unset($params['filter']);

        if (! empty($filters)) {
            $params['filter'] = implode(' AND ', $filters);
        }

        /**
         * Filters the parameters of the Meilisearch search a WP_Query becomes.
         *
         * @param  array<string, mixed>  $params
         * @param  QueryInterface  $query
         */
        return apply_filters('meiliscout/search_params', $params, $query);
    }
}
