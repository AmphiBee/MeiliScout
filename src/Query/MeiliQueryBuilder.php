<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Meilisearch\Client;
use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Enums\MetaType;
use Pollora\MeiliScout\Query\Builders\DateQueryBuilder;
use Pollora\MeiliScout\Query\Builders\MetaQueryBuilder;
use Pollora\MeiliScout\Query\Builders\OrderBuilder;
use Pollora\MeiliScout\Query\Builders\PaginationBuilder;
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
     * @var array
     */
    private array $builders;

    /**
     * Meta query builder instance.
     *
     * @var MetaQueryBuilder|null
     */
    private ?MetaQueryBuilder $metaQueryBuilder = null;

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
            new TaxQueryBuilder,
            $this->metaQueryBuilder = new MetaQueryBuilder,
            new DateQueryBuilder,
            new OrderBuilder,
        ];
    }

    /**
     * Builds search parameters from a query.
     *
     * @param QueryInterface $query The query to build parameters from
     * @return array The constructed search parameters for MeiliSearch
     */
    public function build(QueryInterface $query): array
    {
        if ($query->get('meta_key') !== null && $query->get('meta_key') !== '') {
            $clause = [
                'key' => $query->get('meta_key'),
                'value' => $query->get('meta_value') ?? $query->get('meta_value_num') ?? null,
                'compare' => $query->get('meta_compare') ?? ComparisonOperator::getDefault()->value,
                'type' => $query->get('meta_type') ?? MetaType::getDefault()->value,
            ];
            $metaQuery = $query->get('meta_query');

            // meta_key narrows the meta_query, as in WordPress: it must not replace it
            $query->set('meta_query', empty($metaQuery) || ! is_array($metaQuery)
                ? [$clause]
                : ['relation' => 'AND', $metaQuery, $clause]);
        }

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

        return $params;
    }

    /**
     * Checks if the query contains meta keys that are not indexed.
     *
     * @return bool True if there are non-indexable meta keys, false otherwise
     */
    public function hasNonIndexableMetaKeys(): bool
    {
        return $this->metaQueryBuilder?->hasNonIndexableMetaKeys() ?? false;
    }
}
