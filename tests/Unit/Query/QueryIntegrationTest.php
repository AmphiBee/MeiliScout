<?php

declare(strict_types=1);

namespace {
    if (! class_exists('WP_Query', false)) {
        // WordPress allows dynamic properties on WP_Query too
        #[\AllowDynamicProperties]
        class WP_Query
        {
            public array $posts = [];
            public int $post_count = 0;
            public int $found_posts = 0;
            public int $max_num_pages = 0;

            public function __construct(public array $query_vars = []) {}

            public function get($key, $default = '') { return $this->query_vars[$key] ?? $default; }

            public function set($key, $value) { $this->query_vars[$key] = $value; }
        }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Query {

    use Meilisearch\Client;
    use Meilisearch\Endpoints\Indexes;
    use Meilisearch\Search\SearchResult;
    use Pollora\MeiliScout\Query\MeiliQueryBuilder;
    use Pollora\MeiliScout\Query\QueryIntegration;

    function integrationWith(Client $client): QueryIntegration
    {
        $reflection = new \ReflectionClass(QueryIntegration::class);
        $integration = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('client')->setValue($integration, $client);
        $reflection->getProperty('builder')->setValue($integration, new MeiliQueryBuilder);

        return $integration;
    }

    function clientReturning(SearchResult|\Throwable $result, $test): Client
    {
        $mock = fn (string $class) => (fn () => $this->createMock($class))->call($test);

        $index = $mock(Indexes::class);
        $result instanceof \Throwable
            ? $index->method('search')->willThrowException($result)
            : $index->method('search')->willReturn($result);

        $client = $mock(Client::class);
        $client->method('index')->willReturn($index);

        return $client;
    }

    function searchResult(array $ids, int $total, int $limit = 2): SearchResult
    {
        return new SearchResult([
            'hits' => array_map(fn (int $id) => ['ID' => $id, 'post_title' => "Post {$id}"], $ids),
            'offset' => 0,
            'limit' => $limit,
            'estimatedTotalHits' => $total,
            'processingTimeMs' => 1,
            'query' => '',
            'facetDistribution' => ['terms.slug' => ['news' => 3]],
        ]);
    }

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $this->errorLog = ini_set('error_log', '/dev/null');
    });

    afterEach(function () {
        ini_set('error_log', (string) $this->errorLog);
    });

    test('queries that do not ask for Meilisearch are left to WordPress', function () {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('index');

        expect(integrationWith($client)->interceptQuery(null, new \WP_Query(['post_type' => 'post'])))->toBeNull();
    });

    test('a failing search falls back to MySQL instead of breaking the page', function () {
        $integration = integrationWith(clientReturning(new \RuntimeException('Meilisearch is down'), $this));

        expect($integration->interceptQuery(null, new \WP_Query(['use_meilisearch' => true])))->toBeNull();
    });

    test('post_count is the number of posts returned, found_posts the total', function () {
        $integration = integrationWith(clientReturning(searchResult([1, 2], 5), $this));
        $query = new \WP_Query(['use_meilisearch' => true, 'posts_per_page' => 2, 'fields' => 'ids']);

        $posts = $integration->interceptQuery(null, $query);

        expect($posts)->toHaveCount(2)
            ->and($query->post_count)->toBe(2)
            ->and($query->found_posts)->toBe(5)
            ->and($query->max_num_pages)->toBe(3)
            ->and($query->facet_distribution)->toBe(['terms.slug' => ['news' => 3]]);
    });

    test('a query for ids gets ids back', function () {
        $integration = integrationWith(clientReturning(searchResult([7, 9], 2), $this));

        expect($integration->interceptQuery(null, new \WP_Query(['use_meilisearch' => true, 'fields' => 'ids'])))->toBe([7, 9]);
    });
}
