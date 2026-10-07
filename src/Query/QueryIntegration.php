<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Meilisearch\Client;
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
        $this->client = ClientFactory::getReadClient();

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

        try {
            $results = $this->client->index('posts')->search('', $searchParams);
        } catch (\Throwable $e) {
            // Let WordPress run the query on MySQL rather than break the page
            error_log('MeiliScout: search failed, falling back to MySQL: '.$e->getMessage());

            return $posts;
        }

        $hits = $results->getHits();
        $limit = $results->getLimit();

        // getHitsCount() is the size of this page: the total is (estimated)TotalHits
        $query->found_posts = $results->getTotalHits() ?? $results->getEstimatedTotalHits() ?? $results->getHitsCount();
        $query->max_num_pages = $limit > 0 ? (int) ceil($query->found_posts / $limit) : 1;
        $query->facet_distribution = $results->getFacetDistribution();
        $query->facet_raw = $results->getRaw();

        if ($query->get('fields') === 'ids') {
            $query->posts = array_map(fn (array $hit) => (int) $hit['ID'], $hits);
        } else {
            $query->posts = $this->convertToWpPosts($hits);
        }

        $query->post_count = count($query->posts);

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
