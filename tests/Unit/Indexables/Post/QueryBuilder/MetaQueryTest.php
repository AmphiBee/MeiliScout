<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;

beforeEach(function () {
    // Reset options before each test
    $GLOBALS['wp_options'] = [];
    
    // Configure indexed meta keys for tests
    update_option('meiliscout/indexed_meta_keys', [
        'price', 'description', 'event_date', 'email', 'rating', 'category_id',
        'color', 'size', 'title'
    ]);
});

test('single meta query is correctly formatted', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            [
                'key' => 'price',
                'value' => '10',
                'compare' => '=',
                'type' => 'NUMERIC',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.price = 10)');
});

test('meta query with shorthand parameters is correctly formatted', function () {
    $query = new MockWPQuery([
        'meta_key' => 'price',
        'meta_value' => '10',
        'meta_compare' => '>',
        'meta_type' => 'NUMERIC',
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.price > 10)');
});

test('multiple meta queries are combined with AND by default', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            [
                'key' => 'price',
                'value' => '10',
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
            [
                'key' => 'color',
                'value' => 'red',
                'compare' => '=',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.price > 10 AND metas.color = \'red\')');
});

test('meta queries respect the relation parameter', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            'relation' => 'OR',
            [
                'key' => 'price',
                'value' => '10',
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
            [
                'key' => 'color',
                'value' => 'red',
                'compare' => '=',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.price > 10 OR metas.color = \'red\')');
});

test('nested meta queries are correctly formatted', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            'relation' => 'OR',
            [
                'key' => 'price',
                'value' => ['10', '20'],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            ],
            [
                'relation' => 'AND',
                [
                    'key' => 'color',
                    'value' => ['red', 'blue'],
                    'compare' => 'IN',
                ],
                [
                    'key' => 'size',
                    'value' => 'M',
                    'compare' => '=',
                ],
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe(
        'post_type = \'post\' AND post_status = \'publish\' AND '.
        '(metas.price BETWEEN [10, 20] OR '.
        '(metas.color IN [\'red\', \'blue\'] AND metas.size = \'M\'))'
    );
});

test('meta query with EXISTS operator is correctly formatted', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            [
                'key' => 'rating',
                'compare' => 'EXISTS',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.rating EXISTS)');
});

test('meta query with NOT EXISTS operator is correctly formatted', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            [
                'key' => 'rating',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.rating NOT EXISTS)');
});

test('meta query with LIKE operator is correctly formatted', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            [
                'key' => 'title',
                'value' => 'test',
                'compare' => 'LIKE',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.title LIKE \'test\')');
});

test('meta query with date type is correctly formatted', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            [
                'key' => 'event_date',
                'value' => ['2023-01-01', '2023-12-31'],
                'compare' => 'BETWEEN',
                'type' => 'DATE',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.event_date BETWEEN [\'2023-01-01\', \'2023-12-31\'])');
});

test('meta query with REGEXP operator is correctly formatted', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            [
                'key' => 'email',
                'value' => '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$',
                'compare' => 'REGEXP',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.email REGEXP \'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$\')');
});

test('meta query with special characters is correctly escaped', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            [
                'key' => 'description',
                'value' => "O'Reilly's Book",
                'compare' => '=',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.description = \'O\\\'Reilly\\\'s Book\')');
});

test('meta query with numeric array values is correctly formatted', function () {
    $query = new MockWPQuery([
        'meta_query' => [
            [
                'key' => 'price',
                'value' => [10, 20, 30],
                'compare' => 'IN',
                'type' => 'NUMERIC',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (metas.price IN [10, 20, 30])');
});
