<?php

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder\MockWPQuery;

test('search parameter is correctly set', function () {
    $query = new MockWPQuery([
        's' => 'test search'
    ]);

    $builder = new MeiliQueryBuilder();
    $params = $builder->build($query);

    expect($params)->toMatchArray([
        'q' => 'test search',
        'limit' => 10,
        'filter' => 'post_type = \'post\' AND post_status = \'publish\''
    ]);
});

test('empty search parameter is handled correctly', function () {
    $query = new MockWPQuery([
        's' => ''
    ]);

    $builder = new MeiliQueryBuilder();
    $params = $builder->build($query);

    expect($params)->not->toHaveKey('q')
        ->and($params)->toMatchArray([
            'limit' => 10,
            'filter' => 'post_type = \'post\' AND post_status = \'publish\''
        ]);
});
