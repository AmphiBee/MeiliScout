<?php

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Query\UnsupportedQuery;

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
});

test('basic query should include default parameters', function () {
    $query = new MockWPQuery;
    $builder = new MeiliQueryBuilder;

    $params = $builder->build($query);

    expect($params)->toMatchArray([
        'hitsPerPage' => 10,
        'page' => 1,
        'filter' => 'post_type = \'post\' AND post_status = \'publish\'',
        'attributesToRetrieve' => ['ID'],
    ]);
});

test('pagination parameters are correctly calculated', function () {
    $query = new MockWPQuery([
        'posts_per_page' => 20,
        'paged' => 3,
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    // Paging by page number: Meilisearch then counts the results exactly
    expect($params)->toMatchArray([
        'hitsPerPage' => 20,
        'page' => 3,
        'filter' => 'post_type = \'post\' AND post_status = \'publish\'',
    ])->not->toHaveKey('offset');
});

test('an offset replaces the page, as in WordPress', function () {
    $params = (new MeiliQueryBuilder)->build(new MockWPQuery(['posts_per_page' => 5, 'paged' => 4, 'offset' => 2]));

    expect($params)->toMatchArray(['limit' => 5, 'offset' => 2])->not->toHaveKey('page');
});

test('a query that wants no total pages by limit and offset', function () {
    $params = (new MeiliQueryBuilder)->build(new MockWPQuery(['posts_per_page' => 5, 'paged' => 3, 'no_found_rows' => true]));

    expect($params)->toMatchArray(['limit' => 5, 'offset' => 10]);
});

test('all posts, or no paging, go up to the maximum number of results', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['posts_per_page' => -1])))->toMatchArray(['hitsPerPage' => 10000, 'page' => 1])
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery(['nopaging' => true])))->toMatchArray(['hitsPerPage' => 10000, 'page' => 1]);

    update_option('meiliscout/max_total_hits', 2500);

    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['posts_per_page' => -1])))->toMatchArray(['hitsPerPage' => 2500]);
});

test('posts_per_page is read as WordPress reads it', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['posts_per_page' => '']))['hitsPerPage'])->toBe(10)
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery(['posts_per_page' => -5]))['hitsPerPage'])->toBe(5)
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery(['posts_per_page' => '3']))['hitsPerPage'])->toBe(3);
});

test('post type filter is correctly formatted', function () {
    $query = new MockWPQuery([
        'post_type' => ['post', 'page'],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type IN [\'post\', \'page\'] AND post_status = \'publish\'');
});

test('a status the index does not hold sends the query to MySQL', function () {
    expect(fn () => (new MeiliQueryBuilder)->build(new MockWPQuery(['post_status' => ['publish', 'draft']])))
        ->toThrow(UnsupportedQuery::class, 'unindexed_status:draft')
        ->and(fn () => (new MeiliQueryBuilder)->build(new MockWPQuery(['post_status' => 'publish,private'])))
        ->toThrow(UnsupportedQuery::class, 'unindexed_status:private');
});

test('indexed statuses are filtered on', function () {
    $GLOBALS['filters']['meiliscout/indexable_post_statuses'] = ['publish', 'private'];

    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['post_status' => 'publish,private']))['filter'])
        ->toBe("post_type = 'post' AND post_status IN ['publish', 'private']");
});

test('building a query does not change it', function () {
    update_option('meiliscout/indexed_meta_keys', ['price']);
    $query = new MockWPQuery(['meta_key' => 'price', 'meta_value' => 10]);
    $vars = $query->query_vars;
    $builder = new MeiliQueryBuilder;

    $first = $builder->build($query);

    expect($query->query_vars)->toBe($vars)
        ->and($builder->build($query))->toBe($first);
});
