<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;

/**
 * Builder for handling WP_Query ordering parameters in Meilisearch.
 */
class OrderBuilder implements QueryBuilderInterface
{
    /**
     * Builds the sort parameters for Meilisearch based on WP_Query orderby and order parameters.
     *
     * @param  QueryInterface  $query  The WordPress query
     * @param  array  $searchParams  The Meilisearch search parameters
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $orderby = $query->get('orderby', 'post_date');
        $order = strtolower($query->get('order', 'desc'));

        // Gestion des cas spéciaux
        if ($orderby === 'date') {
            $orderby = 'post_date';
        } elseif ($orderby === 'ID') {
            $orderby = 'ID';
        }

        // Handle meta_value and meta_value_num ordering
        if (in_array($orderby, ['meta_value', 'meta_value_num'])) {
            $metaKey = $query->get('meta_key');
            if ($metaKey) {
                $searchParams['sort'] = ["metas.$metaKey:$order"];
            }

            return;
        }

        // Handle multiple orderby parameters
        if (is_array($orderby)) {
            $sorts = [];
            foreach ($orderby as $field => $direction) {
                if ($field === 'date') {
                    $field = 'post_date';
                }
                $direction = strtolower($direction);
                $sorts[] = "$field:$direction";
            }
            if (! empty($sorts)) {
                $searchParams['sort'] = $sorts;
            }

            return;
        }

        // Single field ordering
        $searchParams['sort'] = ["$orderby:$order"];
    }
}
