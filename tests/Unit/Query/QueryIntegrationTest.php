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
            public $tax_query = null;
            public $meta_query = null;
            public bool $is_singular = false;
            public bool $is_tax = false;
            public bool $is_attachment = false;
            public bool $is_page = false;
            public bool $is_admin = false;

            public function __construct(public array $query_vars = []) {}

            public function get($key, $default = '') { return $this->query_vars[$key] ?? $default; }

            public function set($key, $value) { $this->query_vars[$key] = $value; }

            public bool $main = false;
            public bool $is_search = false;
            public bool $is_category = false;
            public bool $is_tag = false;
            public bool $is_post_type_archive = false;

            public function is_main_query() { return $this->main; }
            public function is_search() { return $this->is_search; }
            public function is_category() { return $this->is_category; }
            public function is_tag() { return $this->is_tag; }
            public function is_tax() { return $this->is_tax; }
            public function is_post_type_archive() { return $this->is_post_type_archive; }
        }
    }

    if (! function_exists('wp_doing_ajax')) {
        function wp_doing_ajax() { return ! empty($GLOBALS['doing_ajax']); }
    }
    if (! function_exists('is_admin')) {
        function is_admin() { return ! empty($GLOBALS['is_admin']); }
    }

    // What WP_Query::get_posts() would know about the site
    if (! function_exists('get_post_types')) {
        function get_post_types($args = [], $output = 'names') { return ['post' => 'post', 'page' => 'page']; }
    }
    if (! function_exists('get_post_stati')) {
        function get_post_stati($args = [], $output = 'names')
        {
            return match (true) {
                ! empty($args['public']) => ['publish' => 'publish'],
                ! empty($args['private']) => ['private' => 'private'],
                ! empty($args['protected']) => ['draft' => 'draft', 'pending' => 'pending', 'future' => 'future'],
                ! empty($args['exclude_from_search']) => ['trash' => 'trash', 'auto-draft' => 'auto-draft'],
                default => ['publish' => 'publish', 'private' => 'private', 'draft' => 'draft', 'trash' => 'trash', 'auto-draft' => 'auto-draft'],
            };
        }
    }
    if (! function_exists('get_taxonomies')) {
        function get_taxonomies($args = [], $output = 'names') { return ['category' => 'category']; }
    }
    if (! function_exists('is_user_logged_in')) {
        function is_user_logged_in() { return ! empty($GLOBALS['logged_in']); }
    }
    if (! function_exists('wp_count_posts')) {
        function wp_count_posts($type = 'post', $perm = '') { return (object) ($GLOBALS['post_counts'][$type] ?? ['publish' => 5]); }
    }
    if (! function_exists('get_post')) {
        function get_post($post) { return $GLOBALS['posts'][$post] ?? null; }
    }
    if (! function_exists('_prime_post_caches')) {
        function _prime_post_caches($ids, $terms = true, $meta = true) { $GLOBALS['primed'] = $ids; }
    }
    if (! function_exists('current_user_can')) {
        function current_user_can($capability, ...$args) { return in_array($capability, $GLOBALS['capabilities'] ?? [], true); }
    }
    if (! function_exists('get_post_type_object')) {
        function get_post_type_object($type) { return (object) ['cap' => (object) ['read_private_posts' => "read_private_{$type}s"], 'hierarchical' => $type === 'page']; }
    }
    if (! function_exists('get_current_user_id')) {
        function get_current_user_id() { return $GLOBALS['current_user_id'] ?? 0; }
    }
    if (! function_exists('apply_filters_ref_array')) {
        function apply_filters_ref_array($hook, $args) { return $GLOBALS['filters'][$hook] ?? $args[0]; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Query {

    use Meilisearch\Client;
    use Meilisearch\Endpoints\Indexes;
    use Meilisearch\Search\SearchResult;
    use Pollora\MeiliScout\Query\MeiliQueryBuilder;
    use Pollora\MeiliScout\Query\QueryIntegration;
    use Pollora\MeiliScout\Services\SearchFallbacks;

    function integrationWith(Client $client): QueryIntegration
    {
        $reflection = new \ReflectionClass(QueryIntegration::class);
        $integration = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('client')->setValue($integration, $client);
        $reflection->getProperty('builder')->setValue($integration, new MeiliQueryBuilder);

        return $integration;
    }

    /**
     * @param  list<SearchResult|\Throwable>|SearchResult|\Throwable  $results  One per search, in order
     */
    function clientReturning(array|SearchResult|\Throwable $results, $test, ?array &$searches = null): Client
    {
        $mock = fn (string $class) => (fn () => $this->createMock($class))->call($test);
        $results = is_array($results) ? $results : [$results];
        $searches = [];

        $index = $mock(Indexes::class);
        $index->method('search')->willReturnCallback(function ($q, $params) use (&$results, &$searches) {
            $searches[] = ['q' => $q, ...$params];
            $result = count($results) > 1 ? array_shift($results) : $results[0];

            if ($result instanceof \Throwable) {
                throw $result;
            }

            return $result;
        });

        $client = $mock(Client::class);
        $client->method('index')->willReturn($index);

        return $client;
    }

    function searchResult(array $ids, int $total, array $extra = []): SearchResult
    {
        return new SearchResult([
            'hits' => array_map(fn (int $id) => ['ID' => $id, 'post_parent' => $id * 10, 'post_title' => "Post {$id}"], $ids),
            'hitsPerPage' => 2,
            'page' => 1,
            'totalHits' => $total,
            'totalPages' => (int) ceil($total / 2),
            'processingTimeMs' => 1,
            'query' => '',
            'facetDistribution' => ['taxonomies.category.slug' => ['news' => 3]],
            ...$extra,
        ]);
    }

    function publishedPosts(int ...$ids): void
    {
        foreach ($ids as $id) {
            $GLOBALS['posts'][$id] = new \WP_Post($id);
        }
    }

    beforeEach(function () {
        $GLOBALS['wp_options'] = ['meiliscout/indexed_post_types' => ['post', 'page']];
        $GLOBALS['posts'] = [];
        $GLOBALS['logged_in'] = false;
        $GLOBALS['post_counts'] = [];
        $GLOBALS['capabilities'] = [];
        $GLOBALS['doing_ajax'] = false;
        $GLOBALS['is_admin'] = false;
        SearchFallbacks::reset();
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
        $query = new \WP_Query(['use_meilisearch' => true]);

        expect($integration->interceptQuery(null, $query))->toBeNull()
            ->and($query->meiliscout)->toMatchArray(['served' => false, 'reason' => 'engine_error']);
    });

    test('a query MySQL served instead is counted by reason, once the request ends', function () {
        $integration = integrationWith(clientReturning(new \RuntimeException('Meilisearch is down'), $this));

        $integration->interceptQuery(null, new \WP_Query(['use_meilisearch' => true]));
        $integration->interceptQuery(null, new \WP_Query(['use_meilisearch' => true, 'post_mime_type' => 'image/png']));
        $integration->interceptQuery(null, new \WP_Query(['use_meilisearch' => true, 'meta_query' => [['key' => 'nope', 'value' => 1]]]));

        expect(SearchFallbacks::lastDay()['total'])->toBe(0);

        SearchFallbacks::flush();

        expect(SearchFallbacks::lastDay())->toBe([
            'total' => 3,
            'error' => 1,
            'meta' => 1,
            'reasons' => ['engine_error' => 1, 'unsupported_arg:post_mime_type' => 1, 'unindexed_meta:nope' => 1],
        ]);
    });

    test('an argument nothing translates sends the query to MySQL, without asking Meilisearch', function (array $vars, string $reason) {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('index');

        if (isset($vars['_schema'])) {
            update_option('meiliscout/schema_version', $vars['_schema']);
            unset($vars['_schema']);
        }

        $query = new \WP_Query(['use_meilisearch' => true, ...$vars]);

        expect(integrationWith($client)->interceptQuery(null, $query))->toBeNull()
            ->and($query->meiliscout['reason'])->toBe($reason);
    })->with([
        'title before schema 4' => [['title' => 'Hello', '_schema' => 3], 'schema_too_old'],
        'post_password' => [['post_password' => 'secret'], 'unsupported_arg:post_password'],
        'post_mime_type' => [['post_mime_type' => 'image/png'], 'unsupported_arg:post_mime_type'],
        'exact' => [['s' => 'x', 'exact' => true], 'unsupported_arg:exact'],
        'a plugin\'s var' => [['lang' => 'fr'], 'unsupported_arg:lang'],
        'a date before schema 3' => [['year' => 2024, '_schema' => 2], 'schema_too_old'],
        'drafts' => [['post_status' => 'draft'], 'unindexed_status:draft'],
        'a type not indexed' => [['post_type' => 'product'], 'unindexed_type:product'],
        'a type that cannot be translated' => [['meta_query' => [['key' => 'k', 'value' => true, 'compare' => 'REGEXP']]], 'unindexed_meta:k'],
    ]);

    test('empty or default arguments do not send the query to MySQL', function () {
        publishedPosts(1, 2);
        $integration = integrationWith(clientReturning(searchResult([1, 2], 2), $this));
        $query = new \WP_Query(['use_meilisearch' => true, 'p' => 0, 'post__in' => [], 'author' => '', 'year' => '', 'has_password' => null, 'posts_per_page' => 2]);

        expect($integration->interceptQuery(null, $query))->toHaveCount(2)
            ->and($query->meiliscout['served'])->toBeTrue();
    });

    test('a logged-in user sees private posts: the query runs on MySQL when there are some', function () {
        $GLOBALS['logged_in'] = true;
        $client = clientReturning(searchResult([1], 1), $this);
        publishedPosts(1);

        $GLOBALS['post_counts'] = ['post' => ['publish' => 4, 'private' => 0]];
        expect(integrationWith($client)->interceptQuery(null, new \WP_Query(['use_meilisearch' => true])))->not->toBeNull();

        $GLOBALS['post_counts'] = ['post' => ['publish' => 4, 'private' => 1]];
        $query = new \WP_Query(['use_meilisearch' => true]);
        expect(integrationWith($client)->interceptQuery(null, $query))->toBeNull()
            ->and($query->meiliscout['reason'])->toBe('unindexed_status:private');
    });

    test('a query that cannot be built falls back instead of breaking the page', function () {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('index');
        update_option('meiliscout/indexed_meta_keys', ['price']);
        $query = new \WP_Query(['use_meilisearch' => true, 'meta_query' => [['key' => 'price', 'value' => new \stdClass]]]);

        expect(integrationWith($client)->interceptQuery(null, $query))->toBeNull()
            ->and($query->meiliscout['reason'])->toStartWith('unsupported_value');
    });

    test('posts are loaded from the database, in the order Meilisearch gave', function () {
        publishedPosts(7, 9);
        $integration = integrationWith(clientReturning(searchResult([9, 7], 2), $this, $searches));

        $posts = $integration->interceptQuery(null, new \WP_Query(['use_meilisearch' => true, 'posts_per_page' => 2]));

        expect(array_map(fn ($post) => $post->ID, $posts))->toBe([9, 7])
            ->and($GLOBALS['primed'])->toBe([9, 7])
            ->and($searches[0]['attributesToRetrieve'])->toBe(['ID']);
    });

    test('a post deleted or unpublished since it was indexed is not returned', function () {
        publishedPosts(1);
        $GLOBALS['posts'][3] = new \WP_Post(3, 'post', '', 'draft');
        $integration = integrationWith(clientReturning(searchResult([1, 2, 3], 3), $this));

        $posts = $integration->interceptQuery(null, new \WP_Query(['use_meilisearch' => true]));

        expect(array_map(fn ($post) => $post->ID, $posts))->toBe([1]);
    });

    test('posts can be built from the documents instead: the whole documents are asked for', function () {
        $GLOBALS['filters']['meiliscout/hydrate_from_documents'] = true;

        expect((new MeiliQueryBuilder)->build(new \Pollora\MeiliScout\Query\WPQueryAdapter(new \WP_Query(['use_meilisearch' => true]))))
            ->not->toHaveKey('attributesToRetrieve')
            ->and((new MeiliQueryBuilder)->build(new \Pollora\MeiliScout\Query\WPQueryAdapter(new \WP_Query(['fields' => 'ids']))))
            ->toHaveKey('attributesToRetrieve', ['ID']);
    });

    test('post_count is the number of posts returned, found_posts the exact total', function () {
        publishedPosts(1, 2);
        $integration = integrationWith(clientReturning(searchResult([1, 2], 5), $this));
        $query = new \WP_Query(['use_meilisearch' => true, 'posts_per_page' => 2]);

        $posts = $integration->interceptQuery(null, $query);

        expect($posts)->toHaveCount(2)
            ->and($query->found_posts)->toBe(5)
            ->and($query->max_num_pages)->toBe(3)
            ->and($query->facet_distribution)->toBe(['taxonomies.category.slug' => ['news' => 3]]);
    });

    test('found_posts goes through the found_posts filter, as on MySQL', function () {
        publishedPosts(1, 2);
        $GLOBALS['filters']['found_posts'] = 3;
        $query = new \WP_Query(['use_meilisearch' => true, 'posts_per_page' => 2]);

        integrationWith(clientReturning(searchResult([1, 2], 5), $this))->interceptQuery(null, $query);

        expect($query->found_posts)->toBe(3)
            ->and($query->max_num_pages)->toBe(2);
    });

    test('no total for no_found_rows, nor for a page past the results, as on MySQL', function () {
        publishedPosts(1);
        $query = new \WP_Query(['use_meilisearch' => true, 'no_found_rows' => true]);
        integrationWith(clientReturning(searchResult([1], 5), $this))->interceptQuery(null, $query);

        $past = new \WP_Query(['use_meilisearch' => true, 'paged' => 9]);
        integrationWith(clientReturning(searchResult([], 5), $this))->interceptQuery(null, $past);

        expect([$query->found_posts, $query->max_num_pages, $past->found_posts, $past->max_num_pages])->toBe([0, 0, 0, 0]);
    });

    test('all posts: the total is the number returned, and there are no pages, as on MySQL', function () {
        publishedPosts(1, 2, 3);
        $query = new \WP_Query(['use_meilisearch' => true, 'posts_per_page' => -1]);

        integrationWith(clientReturning(searchResult([1, 2, 3], 3), $this))->interceptQuery(null, $query);

        expect([$query->found_posts, $query->max_num_pages])->toBe([3, 0]);
    });

    test('with an offset, the total is counted apart', function () {
        publishedPosts(1, 2);
        $integration = integrationWith(clientReturning([
            new SearchResult(['hits' => [['ID' => 1], ['ID' => 2]], 'offset' => 7, 'limit' => 2, 'estimatedTotalHits' => 1000, 'processingTimeMs' => 1, 'query' => '']),
            searchResult([], 12),
        ], $this, $searches));
        $query = new \WP_Query(['use_meilisearch' => true, 'posts_per_page' => 2, 'offset' => 7]);

        $integration->interceptQuery(null, $query);

        expect($query->found_posts)->toBe(12)
            ->and($searches[1])->toMatchArray(['hitsPerPage' => 0, 'page' => 1])
            ->and($searches[1])->not->toHaveKey('sort');
    });

    test('a query for ids gets ids back, and its total through found_posts', function () {
        $integration = integrationWith(clientReturning(searchResult([7, 9], 4), $this));
        $query = new \WP_Query(['use_meilisearch' => true, 'fields' => 'ids', 'posts_per_page' => 2]);

        expect($integration->interceptQuery(null, $query))->toBe([7, 9])
            ->and($integration->skipFoundRowsQuery('SELECT FOUND_ROWS()', $query))->toBe('')
            ->and($integration->foundPosts(1, $query))->toBe(4);
    });

    test('a query for parents gets ids and parents back', function () {
        $integration = integrationWith(clientReturning(searchResult([7], 1), $this, $searches));
        $posts = $integration->interceptQuery(null, new \WP_Query(['use_meilisearch' => true, 'fields' => 'id=>parent']));

        expect($posts)->toEqual([(object) ['ID' => 7, 'post_parent' => 70]])
            ->and($searches[0]['attributesToRetrieve'])->toBe(['ID', 'post_parent']);
    });

    test('a query object run again forgets what Meilisearch did the first time', function () {
        $integration = integrationWith(clientReturning(searchResult([7, 9], 4), $this));
        $query = new \WP_Query(['use_meilisearch' => true, 'fields' => 'ids']);
        $integration->interceptQuery(null, $query);

        $query->query_vars['use_meilisearch'] = false;
        $integration->interceptQuery(null, $query);

        expect($integration->skipFoundRowsQuery('SELECT FOUND_ROWS()', $query))->toBe('SELECT FOUND_ROWS()')
            ->and($integration->foundPosts(11, $query))->toBe(11);
    });

    test('a list order fetches every result, puts them in order and cuts out the page', function () {
        publishedPosts(1, 2, 3, 4, 5);
        $integration = integrationWith(clientReturning(searchResult([1, 2, 3, 4, 5], 5), $this, $searches));
        $query = new \WP_Query(['use_meilisearch' => true, 'post__in' => [5, 4, 3, 2, 1], 'orderby' => 'post__in', 'posts_per_page' => 2, 'paged' => 2]);

        $posts = $integration->interceptQuery(null, $query);

        expect(array_map(fn ($post) => $post->ID, $posts))->toBe([3, 2])
            ->and($searches[0])->toMatchArray(['hitsPerPage' => 1000, 'page' => 1])
            ->and($searches[0])->not->toHaveKey('sort')
            ->and([$query->found_posts, $query->max_num_pages])->toBe([5, 3]);
    });

    test('a random order on more results than can be put in order runs on MySQL', function () {
        $integration = integrationWith(clientReturning(searchResult([1, 2], 5000), $this));
        $query = new \WP_Query(['use_meilisearch' => true, 'orderby' => 'rand']);

        expect($integration->interceptQuery(null, $query))->toBeNull()
            ->and($query->meiliscout['reason'])->toBe('unsupported_orderby:rand');
    });

    test('indexed private posts: a logged-in user gets those they may read, as WordPress gives them', function () {
        $GLOBALS['logged_in'] = true;
        $GLOBALS['current_user_id'] = 4;
        $GLOBALS['capabilities'] = ['read_private_pages'];
        update_option('meiliscout/index_private', true);
        update_option('meiliscout/last_indexing_structure', ['statuses' => ['publish', 'private']]);
        update_option('meiliscout/schema_version', 3);
        publishedPosts(1);
        $integration = integrationWith(clientReturning(searchResult([1], 1), $this, $searches));

        $integration->interceptQuery(null, new \WP_Query(['use_meilisearch' => true, 'post_type' => ['post', 'page']]));

        expect($searches[0]['filter'])->toStartWith(
            "((post_type = 'page' AND post_status IN ['publish', 'private']) OR (post_type = 'post' AND (post_status = 'publish' OR (post_status = 'private' AND post_author = 4))))"
        );
    });

    test('private posts made indexable run on MySQL until a full indexation sends them', function () {
        $GLOBALS['logged_in'] = true;
        $GLOBALS['post_counts'] = ['post' => ['publish' => 4, 'private' => 2]];
        update_option('meiliscout/index_private', true);
        $query = new \WP_Query(['use_meilisearch' => true]);

        expect(integrationWith(clientReturning(searchResult([1], 1), $this))->interceptQuery(null, $query))->toBeNull()
            ->and($query->meiliscout['reason'])->toBe('unindexed_status:private');
    });

    test('another plugin\'s answer is left alone', function () {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('index');

        expect(integrationWith($client)->interceptQuery([1], new \WP_Query(['use_meilisearch' => true])))->toBe([1]);
    });
}
