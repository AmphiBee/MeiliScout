<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;

/**
 * Builder for post type and status filters.
 * 
 * Handles the conversion of WordPress post_type and post_status parameters to MeiliSearch filter syntax.
 */
class TypeStatusBuilder implements QueryBuilderInterface
{
    /**
     * Builds the post type and status filters for MeiliSearch.
     * 
     * @param QueryInterface $query The WordPress query
     * @param array $searchParams The MeiliSearch search parameters to modify
     * @return void
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $searchParams['filter'] = $searchParams['filter'] ?? [];

        $postType = $query->get('post_type');
        if ($postType === null || $postType === '' || $postType === []) {
            // As in WordPress: posts, unless a search or a taxonomy query widens it to every type
            $postType = (! empty($query->get('s')) || ! empty($query->get('tax_query'))) ? 'any' : 'post';
        }

        // 'any' leaves the type open: only indexed post types are in the index
        if (! $this->isAny($postType)) {
            $this->addFilter($searchParams['filter'], 'post_type', $postType);
        }

        $postStatus = $query->get('post_status');
        if ($postStatus === null || $postStatus === '' || $postStatus === []) {
            $postStatus = 'publish';
        }

        if (! $this->isAny($postStatus)) {
            $this->addFilter($searchParams['filter'], 'post_status', $postStatus);
        }
    }

    /**
     * Whether a post_type or post_status query var asks for any value.
     *
     * @param  array|string  $value  The query var
     */
    private function isAny(array|string $value): bool
    {
        return $value === 'any' || $value === ['any'];
    }

    /**
     * Adds a filter to the filter array.
     * 
     * @param array $filters The filter array to modify
     * @param string $key The filter key
     * @param array|string $value The filter value(s)
     * @return void
     */
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
