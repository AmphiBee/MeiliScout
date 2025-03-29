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
     * @param QueryInterface $query The WordPress query
     * @param array    $searchParams The Meilisearch search parameters
     * @return void
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        // Si posts_per_page est -1, on ne met pas de limite
        $postsPerPage = $query->get('posts_per_page', get_option('posts_per_page'));
        if ($postsPerPage !== -1) {
            $searchParams['limit'] = (int) $postsPerPage;

            // Calcul de l'offset en s'assurant qu'il soit positif
            $paged = max(1, $query->get('paged', 1));
            $offset = ($paged - 1) * $postsPerPage;

            if ($offset > 0) {
                $searchParams['offset'] = $offset;
            }
        } else {
            // Si posts_per_page est -1, on met une limite très élevée pour récupérer tous les résultats
            $searchParams['limit'] = 1000; // ou une autre valeur maximale appropriée
        }
    }
}
