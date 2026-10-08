<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Meilisearch\Client;
use Meilisearch\Search\SearchResult;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Query\Builders\FieldsBuilder;
use Pollora\MeiliScout\Query\Builders\PaginationBuilder;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\SearchFallbacks;
use WP_Post;
use WP_Query;

/**
 * Serves the WP_Query that ask for it (use_meilisearch) from Meilisearch.
 *
 * Translate or fall back: a query is answered by Meilisearch only when every
 * argument it has is translated faithfully. Otherwise MySQL runs it, and the
 * reason is recorded, rather than Meilisearch returning other posts than
 * MySQL would. Meilisearch returns ids; the posts are loaded from the
 * database, so that they are the very objects MySQL would have given.
 *
 * What happened is on the query, in $query->meiliscout: served or not, why
 * not, the search parameters, the index and the time taken.
 */
class QueryIntegration
{
    /**
     * MeiliSearch client instance.
     */
    private ?Client $client;

    /**
     * MeiliSearch query builder.
     */
    private MeiliQueryBuilder $builder;

    /**
     * Initializes the MeiliSearch client and sets up the WordPress filter hooks.
     *
     * @param  MeiliQueryBuilder  $builder  The query builder instance
     */
    public function __construct(MeiliQueryBuilder $builder)
    {
        $this->client = ClientFactory::getReadClient();
        $this->builder = $builder;

        if (! $this->client) {
            // Meilisearch is down: the queries asking for it run on MySQL, and are counted
            add_filter('posts_pre_query', [$this, 'countUnservedQuery'], PHP_INT_MAX, 2);

            return;
        }

        add_filter('posts_pre_query', [$this, 'interceptQuery'], PHP_INT_MAX, 2);

        // WP_Query counts the posts of a query for ids itself, after posts_pre_query
        add_filter('found_posts_query', [$this, 'skipFoundRowsQuery'], PHP_INT_MAX, 2);
        add_filter('found_posts', [$this, 'foundPosts'], PHP_INT_MIN, 2);
    }

    /**
     * Answers a query from Meilisearch, or leaves it to MySQL.
     *
     * @param  array<int, mixed>|null  $posts  The posts array (null unless another plugin answered)
     * @param  WP_Query  $query  The WordPress query object
     * @return array<int, mixed>|null The posts, or null to let WordPress run the query
     */
    public function interceptQuery($posts, WP_Query $query): ?array
    {
        // A WP_Query object can run several queries
        unset($query->meiliscout);

        if ($posts !== null || empty($query->query_vars['use_meilisearch'])) {
            return $posts;
        }

        $started = microtime(true);
        $adapter = new WPQueryAdapter($query);
        $index = IndexNames::active('posts');
        $query->meiliscout = ['served' => false, 'reason' => null, 'params' => null, 'index' => $index, 'time' => null];

        try {
            $reason = QuerySupport::check($adapter);

            if ($reason !== null) {
                throw new UnsupportedQuery($reason);
            }

            $params = $this->builder->build($adapter);
        } catch (UnsupportedQuery $e) {
            return $this->fallBack($query, $e->reason);
        } catch (\Throwable $e) {
            error_log('MeiliScout: could not translate the query, falling back to MySQL: '.$e->getMessage());

            return $this->fallBack($query, 'build_error');
        }

        // Facets cost on every search: computed only for the queries that ask for them,
        // e.g. 'meilisearch_facets' => ['taxonomies.category.slug']
        $facets = $query->get('meilisearch_facets');
        if (is_array($facets) && $facets !== []) {
            $params['facets'] = array_values($facets);
        }

        $query->meiliscout['params'] = $params;

        try {
            $results = $this->search($index, $params);
            $total = $this->total($results, $index, $params, $query);
        } catch (\Throwable $e) {
            // Let WordPress run the query on MySQL rather than break the page
            error_log('MeiliScout: search failed, falling back to MySQL: '.$e->getMessage());

            return $this->fallBack($query, 'engine_error');
        }

        $hits = $results->getHits();
        $order = PhpOrder::of($adapter);

        if ($order !== null) {
            // Every result is needed to put them in order
            if ($total > count($hits)) {
                return $this->fallBack($query, 'unsupported_orderby:'.$order->kind);
            }

            $hits = $this->page($order->sort($hits), $adapter);
        }

        $posts = $this->posts($hits, $query, FieldsBuilder::hydrateFromDocuments($adapter));
        $this->setFoundPosts($query, $adapter, $posts, $total);

        $query->facet_distribution = $results->getFacetDistribution();
        $query->facet_raw = $results->getRaw();
        $query->meiliscout['served'] = true;
        $query->meiliscout['time'] = round((microtime(true) - $started) * 1000, 2);

        return $posts;
    }

    /**
     * Counts a query asking for Meilisearch while it cannot be reached; WordPress runs it.
     *
     * @param  array<int, mixed>|null  $posts
     * @return array<int, mixed>|null
     */
    public function countUnservedQuery($posts, WP_Query $query): ?array
    {
        unset($query->meiliscout);

        if ($posts === null && ! empty($query->query_vars['use_meilisearch'])) {
            $query->meiliscout = ['served' => false, 'reason' => SearchFallbacks::UNREACHABLE, 'params' => null, 'index' => null, 'time' => null];
            SearchFallbacks::record(SearchFallbacks::UNREACHABLE);
        }

        return $posts;
    }

    /**
     * No SELECT FOUND_ROWS() after a query for ids Meilisearch answered: it gave the total.
     */
    public function skipFoundRowsQuery(string $sql, WP_Query $query): string
    {
        return isset($query->meiliscout['found_posts']) ? '' : $sql;
    }

    /**
     * The total of a query for ids Meilisearch answered, for the other found_posts filters to start from.
     */
    public function foundPosts(int|string $found, WP_Query $query): int|string
    {
        return $query->meiliscout['found_posts'] ?? $found;
    }

    /**
     * Builds search parameters from a WordPress query.
     *
     * @param  WP_Query  $query  The WordPress query object
     * @return array<string, mixed> The search parameters for MeiliSearch
     *
     * @throws UnsupportedQuery
     */
    public function buildSearchParams(WP_Query $query): array
    {
        return $this->builder->build(new WPQueryAdapter($query));
    }

    private function fallBack(WP_Query $query, string $reason): null
    {
        $query->meiliscout['reason'] = $reason;
        SearchFallbacks::record($reason);

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function search(string $index, array $params): SearchResult
    {
        $q = $params['q'] ?? '';
        unset($params['q']);

        return $this->client->index($index)->search($q, $params);
    }

    /**
     * The exact number of results; counted apart when paging by offset gave an estimate.
     *
     * @param  array<string, mixed>  $params
     */
    private function total(SearchResult $results, string $index, array $params, WP_Query $query): int
    {
        if (isset($params['hitsPerPage'])) {
            return (int) $results->getTotalHits();
        }


        if (! empty($query->get('no_found_rows'))) {
            return 0;
        }

        $count = array_diff_key($params, array_flip(['limit', 'offset', 'sort', 'attributesToRetrieve', 'facets']));

        return (int) $this->search($index, [...$count, 'hitsPerPage' => 0, 'page' => 1])->getTotalHits();
    }

    /**
     * The page asked for, out of every result put in order.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    private function page(array $hits, WPQueryAdapter $query): array
    {
        if (QueryVars::isUnpaged($query)) {
            return $hits;
        }

        $postsPerPage = PaginationBuilder::postsPerPage($query);
        $offset = $query->get('offset');
        $start = is_numeric($offset) ? abs((int) $offset) : (max(1, abs((int) $query->get('paged', 1))) - 1) * $postsPerPage;

        return array_slice($hits, $start, $postsPerPage);
    }

    /**
     * The posts, in the shape the query asked for.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return array<int, mixed>
     */
    private function posts(array $hits, WP_Query $query, bool $fromDocuments): array
    {
        $fields = $query->get('fields');

        if ($fields === 'ids') {
            return array_map(static fn (array $hit) => (int) $hit['ID'], $hits);
        }

        if ($fields === 'id=>parent') {
            return array_map(static fn (array $hit) => (object) ['ID' => (int) $hit['ID'], 'post_parent' => (int) ($hit['post_parent'] ?? 0)], $hits);
        }

        if ($fromDocuments) {
            return array_map(static fn (array $hit) => new WP_Post((object) $hit), $hits);
        }

        $ids = array_map(static fn (array $hit) => (int) $hit['ID'], $hits);

        if ($ids === []) {
            return [];
        }

        _prime_post_caches($ids, (bool) $query->get('update_post_term_cache', true), (bool) $query->get('update_post_meta_cache', true));

        $statuses = PostIndexable::queryableStatuses();
        $posts = [];

        foreach ($ids as $id) {
            $post = get_post($id);

            // The index may lag behind: a post deleted or unpublished since is not returned
            if ($post instanceof WP_Post && in_array($post->post_status, $statuses, true)) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    /**
     * found_posts and max_num_pages, as WP_Query::set_found_posts() sets them.
     *
     * For a query for ids, WordPress calls set_found_posts() itself after
     * posts_pre_query: the total is handed to it through the found_posts filter.
     *
     * @param  array<int, mixed>  $posts
     */
    private function setFoundPosts(WP_Query $query, WPQueryAdapter $adapter, array $posts, int $total): void
    {
        $query->found_posts = 0;
        $query->max_num_pages = 0;

        if (! empty($query->get('no_found_rows')) || $posts === []) {
            return;
        }

        $hasLimits = ! QueryVars::isUnpaged($adapter);

        if (! $hasLimits && $total > count($posts)) {
            error_log(sprintf('MeiliScout: a query for all posts stopped at %d of %d results: raise the maximum number of results per query.', count($posts), $total));
        }

        $found = $hasLimits ? $total : count($posts);

        if (in_array($query->get('fields'), ['ids', 'id=>parent'], true)) {
            $query->meiliscout['found_posts'] = $found;

            return;
        }

        $query->found_posts = (int) apply_filters_ref_array('found_posts', [$found, &$query]);

        if ($hasLimits) {
            $query->max_num_pages = (int) ceil($query->found_posts / PaginationBuilder::postsPerPage($adapter));
        }
    }
}
