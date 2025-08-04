<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;

/**
 * Builder for handling WP_Query pagination parameters in Meilisearch.
 */
class PaginationBuilder implements QueryBuilderInterface
{
    /**
     * Builds the pagination parameters for Meilisearch.
     *
     * @param  QueryInterface  $query  The WordPress query
     * @param  array  $searchParams  The Meilisearch search parameters
     * @return void
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        // If posts_per_page is -1, don't set a limit
        $postsPerPage = $query->get('posts_per_page', get_option('posts_per_page'));
        if ($postsPerPage !== -1) {
            $searchParams['limit'] = (int) $postsPerPage;

            // Calculate offset ensuring it's positive
            $paged = max(1, $query->get('paged', 1));
            $offset = ($paged - 1) * $postsPerPage;

            if ($offset > 0) {
                $searchParams['offset'] = $offset;
            }
        } else {
            // If posts_per_page is -1, set a very high limit to retrieve all results
            $searchParams['limit'] = 1000; // or another appropriate maximum value
        }
    }
}
