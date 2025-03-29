<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;

class TypeStatusBuilder implements QueryBuilderInterface
{
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $searchParams['filter'] = $searchParams['filter'] ?? [];

        $this->addFilter($searchParams['filter'], 'post_type', $query->get('post_type') ?? 'post');
        $this->addFilter($searchParams['filter'], 'post_status', $query->get('post_status') ?? 'publish');
    }

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
