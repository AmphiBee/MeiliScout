<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Meilisearch\Client;
use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\Builders\DateQueryBuilder;
use Pollora\MeiliScout\Query\Builders\MetaQueryBuilder;
use Pollora\MeiliScout\Query\Builders\PaginationBuilder;
use Pollora\MeiliScout\Query\Builders\SearchQueryBuilder;
use Pollora\MeiliScout\Query\Builders\TaxQueryBuilder;
use Pollora\MeiliScout\Query\Builders\TypeStatusBuilder;
use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Enums\MetaType;

class MeiliQueryBuilder
{
    private array $builders;
    private array $searchParams = [];
    private ?MetaQueryBuilder $metaQueryBuilder = null;

    public function __construct(?Client $client = null)
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
                ]
            ]);
        }

        foreach ($this->builders as $builder) {
            $builder->build($query, $this->searchParams);
        }

        // Joindre les filtres à la fin
        if (isset($this->searchParams['filter']) && is_array($this->searchParams['filter'])) {
            $this->searchParams['filter'] = implode(' AND ', $this->searchParams['filter']);
        }

        return $this->searchParams;
    }

    public function hasNonIndexableMetaKeys(): bool
    {
        return $this->metaQueryBuilder?->hasNonIndexableMetaKeys() ?? false;
    }
}
