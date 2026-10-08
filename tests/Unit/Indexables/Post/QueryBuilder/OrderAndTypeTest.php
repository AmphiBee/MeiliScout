<?php

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Query\UnsupportedQuery;

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
    update_option('meiliscout/indexed_meta_keys', ['price', 'color']);
});

function sortFor(array $vars): ?array
{
    return (new MeiliQueryBuilder)->build(new MockWPQuery($vars))['sort'] ?? null;
}

test('posts are sorted by date, newest first, as WordPress does by default', function () {
    expect(sortFor([]))->toBe(['post_date:desc']);
});

test('a search keeps the relevance order', function () {
    expect(sortFor(['s' => 'meilisearch']))->toBeNull()
        ->and(sortFor(['s' => 'meilisearch', 'orderby' => 'relevance']))->toBeNull();
});

test('a search with an explicit order follows it strictly, every word matching', function () {
    $params = (new MeiliQueryBuilder)->build(new MockWPQuery(['s' => 'meilisearch wordpress', 'orderby' => 'date']));

    expect($params['sort'])->toBe(['post_date:desc'])
        ->and($params['matchingStrategy'])->toBe('all');
});

test('orderby and order are translated to sortable attributes', function () {
    expect(sortFor(['orderby' => 'title', 'order' => 'ASC']))->toBe(['post_title_sort:asc'])
        ->and(sortFor(['orderby' => 'date title', 'order' => 'asc']))->toBe(['post_date:asc', 'post_title_sort:asc'])
        ->and(sortFor(['orderby' => ['title' => 'ASC', 'date' => 'DESC']]))->toBe(['post_title_sort:asc', 'post_date:desc'])
        ->and(sortFor(['orderby' => ['title' => 'nonsense']]))->toBe(['post_title_sort:desc']);
});

test('the fields of the post sort on their attributes', function () {
    expect(sortFor(['orderby' => 'name author modified parent type ID menu_order comment_count', 'order' => 'ASC']))
        ->toBe(['post_name:asc', 'post_author:asc', 'post_modified:asc', 'post_parent:asc', 'post_type:asc', 'ID:asc', 'menu_order:asc', 'comment_count:asc']);
});

test('indexes built before schema 3 sort on the date and the raw title only', function () {
    update_option('meiliscout/schema_version', 2);

    expect(sortFor(['orderby' => 'title date']))->toBe(['post_title:desc', 'post_date:desc'])
        ->and(fn () => sortFor(['orderby' => 'author']))->toThrow(UnsupportedQuery::class, 'schema_too_old');
});

test('random and list orders are put in order in PHP: no sort is sent', function () {
    expect(sortFor(['orderby' => 'rand']))->toBeNull()
        ->and(sortFor(['orderby' => 'RAND(5)']))->toBeNull()
        ->and(sortFor(['orderby' => 'post__in', 'post__in' => [3, 1]]))->toBeNull()
        ->and(sortFor(['orderby' => ['post_name__in' => 'ASC'], 'post_name__in' => ['a']]))->toBeNull();
});

test('an order on an empty list is ignored, as in WordPress', function () {
    expect(sortFor(['orderby' => 'post__in', 'order' => 'ASC']))->toBe(['post_date:asc']);
});

test('values WordPress ignores are ignored; none left is the date', function () {
    expect(sortFor(['orderby' => 'nonsense title', 'order' => 'ASC']))->toBe(['post_title_sort:asc'])
        ->and(sortFor(['orderby' => 'nonsense', 'order' => 'ASC']))->toBe(['post_date:asc'])
        ->and(sortFor(['orderby' => 'relevance']))->toBe(['post_date:desc'])
        ->and(sortFor(['orderby' => 'none']))->toBeNull()
        ->and(sortFor(['orderby' => []]))->toBeNull();
});

test('a sort on an indexed meta key goes through metas', function () {
    expect(sortFor(['orderby' => 'meta_value_num', 'meta_key' => 'price', 'order' => 'ASC']))->toBe(['metas.price:asc'])
        ->and(sortFor(['orderby' => 'price', 'meta_key' => 'price', 'order' => 'ASC']))->toBe(['metas.price:asc'])
        ->and(sortFor([
            'orderby' => 'price_clause',
            'meta_query' => ['price_clause' => ['key' => 'price', 'compare' => 'EXISTS']],
        ]))->toBe(['metas.price:desc'])
        ->and(sortFor([
            'orderby' => 'meta_value',
            'meta_query' => ['relation' => 'AND', ['key' => 'color', 'value' => 'red'], ['key' => 'price', 'compare' => 'EXISTS']],
        ]))->toBe(['metas.color:desc']);
});

test('an order WordPress gives and the index cannot sends the query to MySQL', function (array $vars, string $reason) {
    expect(fn () => sortFor($vars))->toThrow(UnsupportedQuery::class, $reason);
})->with([
    'rand with another order' => [['orderby' => 'rand title'], 'unsupported_orderby:rand'],
    'a list order with another' => [['orderby' => ['post__in' => 'ASC', 'title' => 'ASC'], 'post__in' => [1]], 'unsupported_orderby:post__in'],
    'a meta key not indexed' => [['orderby' => 'meta_value', 'meta_key' => 'not_indexed'], 'unindexed_meta:not_indexed'],
]);

// 'any' is every type get_post_types() lists, or left open when there is no WordPress to ask
function anyTypeFilter(): string
{
    return function_exists('get_post_types')
        ? "post_type IN ['".implode("', '", get_post_types(['exclude_from_search' => false]))."'] AND "
        : '';
}

test('any post type is every searchable type; any status is the indexed ones', function () {
    $filter = (new MeiliQueryBuilder)->build(new MockWPQuery(['post_type' => 'any', 'post_status' => 'any']))['filter'] ?? '';

    expect($filter)->toBe(anyTypeFilter()."post_status = 'publish'");
});

test('an empty post type is posts, or every type for a search', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['post_type' => '']))['filter'])
        ->toBe("post_type = 'post' AND post_status = 'publish'")
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery(['post_type' => '', 's' => 'hello']))['filter'])
        ->toBe(anyTypeFilter()."post_status = 'publish'");
});

test('a query with a non-indexed meta key does not send the next ones to MySQL', function () {
    $builder = new MeiliQueryBuilder;

    expect(fn () => $builder->build(new MockWPQuery(['meta_query' => [['key' => 'not_indexed', 'value' => 1]]])))
        ->toThrow(UnsupportedQuery::class);

    expect($builder->build(new MockWPQuery(['meta_query' => [['key' => 'price', 'value' => 1]]]))['filter'])
        ->toContain('metas.price = 1');
});

test('meta_key narrows the meta_query instead of replacing it', function () {
    $filter = (new MeiliQueryBuilder)->build(new MockWPQuery([
        'meta_key' => 'price',
        'meta_value' => 10,
        'meta_type' => 'NUMERIC',
        'meta_query' => [['key' => 'color', 'value' => 'red']],
    ]))['filter'];

    expect($filter)->toContain("metas.color = 'red'")
        ->and($filter)->toContain('metas.price = 10');
});
