<?php

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;


test('basic query should include default parameters', function () {
    $query = new MockWPQuery();
    $builder = new MeiliQueryBuilder();

    $params = $builder->build($query);

    expect($params)->toMatchArray([
        'limit' => 10,
        'filter' => 'post_type = \'post\' AND post_status = \'publish\''
    ]);
});

test('pagination parameters are correctly calculated', function () {
    $query = new MockWPQuery([
        'posts_per_page' => 20,
        'paged' => 3
    ]);

    $builder = new MeiliQueryBuilder();
    $params = $builder->build($query);

    expect($params)->toMatchArray([
        'limit' => 20,
        'offset' => 40,
        'filter' => 'post_type = \'post\' AND post_status = \'publish\''
    ]);
});

test('post type filter is correctly formatted', function () {
    $query = new MockWPQuery([
        'post_type' => ['post', 'page']
    ]);

    $builder = new MeiliQueryBuilder();
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type IN [\'post\', \'page\'] AND post_status = \'publish\'');
});

test('post status filter is correctly formatted', function () {
    $query = new MockWPQuery([
        'post_status' => ['publish', 'draft']
    ]);

    $builder = new MeiliQueryBuilder();
    $params = $builder->build($query);

    expect($params['filter'])->toContain('post_type = \'post\' AND post_status IN [\'publish\', \'draft\']');
});
