<?php

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;

test('search parameter is correctly set', function () {
    $query = new MockWPQuery([
        's' => 'test search',
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params)->toMatchArray([
        'q' => 'test search',
        'hitsPerPage' => 10,
        'filter' => 'post_type = \'post\' AND post_status = \'publish\'',
    ]);
});

test('empty search parameter is handled correctly', function () {
    $query = new MockWPQuery([
        's' => '',
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params)->not->toHaveKey('q')
        ->and($params)->toMatchArray([
            'hitsPerPage' => 10,
            'filter' => 'post_type = \'post\' AND post_status = \'publish\'',
        ]);
});

test('0 is a search too', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['s' => '0'])))->toMatchArray(['q' => '0']);
});

test('without a post type, a search is on every type', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['s' => 'hello', 'post_type' => '']))['filter'])
        ->toBe(anyTypeFilter()."post_status = 'publish'");
});
