<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Meilisearch\Client;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Domain\Search\Enums\TaxonomyFields;
use Pollora\MeiliScout\Services\ClientFactory;
use WP_Query;

/**
 * WordPress query integration with MeiliSearch.
 * 
 * Intercepts WP_Query requests and redirects them to MeiliSearch when appropriate.
 */
class QueryIntegration
{
    /**
     * MeiliSearch client instance.
     *
     * @var Client|null
     */
    private ?Client $client;

    /**
     * MeiliSearch query builder.
     *
     * @var MeiliQueryBuilder
     */
    private MeiliQueryBuilder $builder;

    /**
     * Constructor.
     * 
     * Initializes the MeiliSearch client and sets up the WordPress filter hook.
     *
     * @param MeiliQueryBuilder $builder The query builder instance
     */
    public function __construct(MeiliQueryBuilder $builder)
    {
        $this->client = ClientFactory::getClient();

        if (! $this->client) {
            return;
        }

        $this->builder = $builder;
        add_filter('posts_pre_query', [$this, 'interceptQuery'], PHP_INT_MAX, 2);
    }

    /**
     * Intercepts WordPress queries and processes them with MeiliSearch.
     *
     * @param array|null $posts The posts array (usually null at this point)
     * @param WP_Query $query The WordPress query object
     * @return array|null The posts array or null to let WordPress handle the query
     */
    public function interceptQuery($posts, WP_Query $query): ?array
    {
        if (! isset($query->query_vars['use_meilisearch']) || ! $query->query_vars['use_meilisearch']) {
            return $posts;
        }

        $searchParams = $this->buildSearchParams($query);

        // If non-indexable meta keys are found, fall back to classic WP_Query mode
        if ($this->builder->hasNonIndexableMetaKeys()) {
            $query->query_vars['use_meilisearch'] = false;
            return $posts;
        }

        // Add parameters for facets
        $searchParams['facets'] = [
            'terms.term_id',
            'terms.term_taxonomy_id',
            'terms.taxonomy',
            'terms.name',
            'terms.slug',
        ];

        $results = $this->client->index('posts')->search('', $searchParams);

        $query->found_posts = $query->post_count = $results->getHitsCount();
        $query->max_num_pages = ceil($results->getHitsCount() / $results->getLimit());
        $query->posts = $this->convertToWpPosts($results->getHits());

        // Retrieve and format facet distribution
        $facetDistribution = $results->getFacetDistribution() ?? [];
        $formattedFacets = [];

        // Iterate through facets and reformat them to match expected structure
        foreach ($facetDistribution as $facetKey => $values) {
            // Extract taxonomy and field from key (e.g.: "taxonomies.category.term_id")
            $parts = explode('.', $facetKey);
            if (count($parts) === 3 && $parts[0] === 'taxonomies') {
                $taxonomy = $parts[1];
                $field = $parts[2];

                if (! isset($formattedFacets[$taxonomy])) {
                    $formattedFacets[$taxonomy] = [];
                }

                $formattedFacets[$taxonomy][$field] = $values;
            }
        }

        $query->facet_distribution = $formattedFacets;
        $query->facet_raw = $results->getRaw();

        return $query->posts;
    }

    /**
     * Builds search parameters from a WordPress query.
     *
     * @param WP_Query $query The WordPress query object
     * @return array The search parameters for MeiliSearch
     */
    public function buildSearchParams(WP_Query $query): array
    {
        return $this->builder->build(new WPQueryAdapter($query));
    }

    /**
     * Converts MeiliSearch hits to WordPress post objects.
     *
     * @param array $hits The search result hits from MeiliSearch
     * @return array Array of WP_Post objects
     */
    private function convertToWpPosts(array $hits): array
    {
        return array_map(function ($hit) {
            return new \WP_Post((object) $hit);
        }, $hits);
    }
}
