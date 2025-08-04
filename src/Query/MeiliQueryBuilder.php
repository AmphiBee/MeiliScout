<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Meilisearch\Client;
use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Enums\MetaType;
use Pollora\MeiliScout\Query\Builders\DateQueryBuilder;
use Pollora\MeiliScout\Query\Builders\MetaQueryBuilder;
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
     * Search parameters for MeiliSearch.
     *
     * @var array
     */
    private array $searchParams = [];

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
        $this->searchParams = [];

        if ($query->get('meta_key') !== null && $query->get('meta_key') !== '') {
            $query->set('meta_query', [
                [
                    'key' => $query->get('meta_key'),
                    'value' => $query->get('meta_value') ?? $query->get('meta_value_num') ?? null,
                    'compare' => $query->get('meta_compare') ?? ComparisonOperator::getDefault()->value,
                    'type' => $query->get('meta_type') ?? MetaType::getDefault()->value,
                ],
            ]);
        }

        foreach ($this->builders as $builder) {
            $builder->build($query, $this->searchParams);
        }

        // Combine filters at the end
        if (isset($this->searchParams['filter']) && is_array($this->searchParams['filter'])) {
            $this->searchParams['filter'] = implode(' AND ', $this->searchParams['filter']);
        }

        return $this->searchParams;
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
