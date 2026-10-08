<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\MissedMetaKeys;

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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((metas.price >= 10 AND metas.price <= 20) OR (metas.color IN [\'red\', \'blue\'] AND metas.size = \'M\'))');
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

test('LIKE and REGEXP have no Meilisearch equivalent: the query runs on MySQL', function (string $compare) {
    $query = new MockWPQuery([
        'meta_query' => [['key' => 'title', 'value' => 'test', 'compare' => $compare]],
    ]);

    expect(fn () => (new MeiliQueryBuilder)->build($query))->toThrow(UnsupportedQuery::class, 'unsupported_compare:'.strtoupper($compare));
})->with(['LIKE', 'NOT LIKE', 'REGEXP', 'NOT REGEXP', 'RLIKE', 'like']);

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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((metas.event_date >= \'2023-01-01\' AND metas.event_date <= \'2023-12-31\'))');
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

function metaFilter(array $vars): string
{
    $filter = (new MeiliQueryBuilder)->build(new MockWPQuery($vars))['filter'];

    return substr($filter, strlen("post_type = 'post' AND post_status = 'publish' AND "));
}

test('a backslash cannot escape the closing quote', function () {
    expect(metaFilter(['meta_query' => [['key' => 'title', 'value' => "x\\' OR post_type = 'page"]]]))
        ->toBe("(metas.title = 'x\\\\\\' OR post_type = \\'page')");
});

test('operators and types are read in any case, as WordPress reads them', function () {
    expect(metaFilter(['meta_query' => [['key' => 'color', 'value' => ['red', 'blue'], 'compare' => 'not in']]]))
        ->toBe("((metas.color EXISTS AND metas.color NOT IN ['red', 'blue']))")
        ->and(metaFilter(['meta_query' => [['key' => 'rating', 'compare' => 'not exists']]]))
        ->toBe('(metas.rating NOT EXISTS)')
        ->and(metaFilter(['meta_query' => [['key' => 'price', 'value' => '10.5', 'compare' => '>', 'type' => 'numeric']]]))
        ->toBe('(metas.price > 10)');
});

test('an operator WordPress does not know is =', function () {
    expect(metaFilter(['meta_query' => [['key' => 'color', 'value' => 'red', 'compare' => '~~']]]))->toBe("(metas.color = 'red')");
});

test('a list of values is IN by default, a string list is split', function () {
    expect(metaFilter(['meta_query' => [['key' => 'color', 'value' => ['red', 'blue']]]]))->toBe("(metas.color IN ['red', 'blue'])")
        ->and(metaFilter(['meta_query' => [['key' => 'price', 'value' => '10, 20', 'compare' => 'BETWEEN', 'type' => 'NUMERIC']]]))
        ->toBe('((metas.price >= 10 AND metas.price <= 20))');
});

test('!= and NOT IN need the post to have the key, as in WordPress', function () {
    expect(metaFilter(['meta_query' => [['key' => 'color', 'value' => 'red', 'compare' => '!=']]]))
        ->toBe("((metas.color EXISTS AND metas.color != 'red'))");
});

test('a key without a value asks for the key to exist', function () {
    expect(metaFilter(['meta_key' => 'price']))->toBe('(metas.price EXISTS)')
        ->and(metaFilter(['meta_query' => [['key' => 'price', 'compare' => '>']]]))->toBe('(metas.price EXISTS)')
        ->and(metaFilter(['meta_query' => [['key' => 'price', 'value' => []]]]))->toBe('(metas.price EXISTS)');
});

test('meta_value_num is no filter, as in WordPress: only the key must exist', function () {
    expect(metaFilter(['meta_key' => 'price', 'meta_value_num' => 100, 'meta_compare' => '>']))->toBe('(metas.price EXISTS)');
});

test('EXISTS with a value is =', function () {
    expect(metaFilter(['meta_query' => [['key' => 'color', 'value' => 'red', 'compare' => 'EXISTS']]]))->toBe("(metas.color = 'red')");
});

test('booleans are compared as 1 and 0, as they are stored', function () {
    expect(metaFilter(['meta_query' => [['key' => 'rating', 'value' => true]]]))->toBe('(metas.rating = 1)')
        ->and(metaFilter(['meta_query' => [['key' => 'rating', 'value' => false, 'type' => 'NUMERIC']]]))->toBe('(metas.rating = 0)');
});

test('a numeric comparison with a value that is no number runs on MySQL', function () {
    expect(fn () => metaFilter(['meta_query' => [['key' => 'price', 'value' => '10 OR 1=1', 'compare' => '>', 'type' => 'NUMERIC']]]))
        ->toThrow(UnsupportedQuery::class, 'invalid_number');
});

test('a value that is neither a scalar nor a list runs on MySQL', function () {
    expect(fn () => metaFilter(['meta_query' => [['key' => 'price', 'value' => new \stdClass]]]))->toThrow(UnsupportedQuery::class);
});

test('BINARY, a list of keys, compare_key: the query runs on MySQL', function (array $clause, string $reason) {
    expect(fn () => metaFilter(['meta_query' => [$clause]]))->toThrow(UnsupportedQuery::class, $reason);
})->with([
    'BINARY' => [['key' => 'color', 'value' => 'Red', 'type' => 'BINARY'], 'unsupported_meta_type:BINARY'],
    'keys' => [['key' => ['color', 'size'], 'value' => 'red'], 'unsupported_meta_key'],
    'no key' => [['value' => 'red'], 'unsupported_meta_key'],
    'compare_key' => [['key' => 'col', 'compare_key' => 'LIKE'], 'unsupported_meta_compare_key'],
]);

test('a key that is not indexed sends the query to MySQL, and is remembered once the request ends', function () {
    MissedMetaKeys::reset();

    expect(fn () => metaFilter(['meta_query' => [['key' => 'not_indexed', 'value' => 1]]]))->toThrow(UnsupportedQuery::class, 'unindexed_meta:not_indexed')
        ->and(MissedMetaKeys::pending())->toBe(['not_indexed'])
        ->and(get_option('meiliscout/non_indexable_meta_keys', []))->toBe([]);

    MissedMetaKeys::flush();

    expect(get_option('meiliscout/non_indexable_meta_keys'))->toBe(['not_indexed']);
});

test('in an OR group, a clause that cannot be translated is not dropped', function () {
    expect(fn () => metaFilter(['meta_query' => ['relation' => 'OR', ['key' => 'color', 'value' => 'red'], ['key' => 'title', 'value' => 'x', 'compare' => 'LIKE']]]))
        ->toThrow(UnsupportedQuery::class);
});

test('an empty group gives no filter', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['meta_query' => ['relation' => 'OR', []]]))['filter'])
        ->toBe("post_type = 'post' AND post_status = 'publish'");
});
