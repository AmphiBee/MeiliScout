<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Query\Builders\Concerns\FormatsValues;
use Pollora\MeiliScout\Query\QuerySupport;
use Pollora\MeiliScout\Query\QueryVars;
use Pollora\MeiliScout\Query\UnsupportedQuery;

/**
 * Filters on the post types and statuses the query is about.
 *
 * The types are the ones WordPress works out (posts by default, the types of
 * a custom taxonomy on its archive, every searchable type for 'any'); the
 * statuses the ones it would include, among those the index holds. That a
 * status the index lacks has no post is checked by QuerySupport.
 */
class TypeStatusBuilder implements QueryBuilderInterface
{
    use FormatsValues;

    /**
     * @param  array<string, mixed>  $searchParams
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $searchParams['filter'] = $searchParams['filter'] ?? [];

        $types = QueryVars::postTypes($query);

        // 'any' without WordPress to list the types: the index only holds indexed ones
        if ($types !== 'any') {
            $searchParams['filter'][] = $this->filter('post_type', $types);
        }

        $requested = QuerySupport::requestedStatuses($query);
        $indexed = PostIndexable::indexableStatuses();

        if ($requested['explicit']) {
            foreach ($requested['statuses'] as $status) {
                if (! in_array($status, $indexed, true)) {
                    throw new UnsupportedQuery('unindexed_status:'.$status);
                }
            }
        }

        $searchParams['filter'][] = $this->filter('post_status', array_values(array_intersect($requested['statuses'], $indexed)));
    }

    /**
     * @param  list<string>  $values
     */
    private function filter(string $attribute, array $values): string
    {
        $values = array_values(array_unique($values));

        return count($values) === 1
            ? "{$attribute} = {$this->quote($values[0])}"
            : "{$attribute} IN [".implode(', ', array_map(fn (string $value) => $this->quote($value), $values)).']';
    }
}
