<?php

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
    update_option('meiliscout/indexed_meta_keys', ['price']);
});

function sortFor(array $vars): ?array
{
    return (new MeiliQueryBuilder)->build(new MockWPQuery($vars))['sort'] ?? null;
}

test('posts are sorted by date, newest first, as WordPress does by default', function () {
    expect(sortFor([]))->toBe(['post_date:desc']);
});

test('a search keeps the relevance order', function () {
    expect(sortFor(['s' => 'meilisearch']))->toBeNull();
});

test('orderby and order are translated to sortable attributes', function () {
    expect(sortFor(['orderby' => 'title', 'order' => 'ASC']))->toBe(['post_title:asc'])
        ->and(sortFor(['orderby' => 'date title', 'order' => 'ASC']))->toBe(['post_date:asc', 'post_title:asc'])
        ->and(sortFor(['orderby' => ['title' => 'ASC', 'date' => 'DESC']]))->toBe(['post_title:asc', 'post_date:desc']);
});

test('a sort on an indexed meta key goes through metas', function () {
    expect(sortFor(['orderby' => 'meta_value_num', 'meta_key' => 'price', 'order' => 'ASC']))->toBe(['metas.price:asc'])
        ->and(sortFor([
            'orderby' => 'price_clause',
            'meta_query' => ['price_clause' => ['key' => 'price', 'compare' => 'EXISTS']],
        ]))->toBe(['metas.price:desc']);
});

test('fields that are not sortable are left out rather than rejected by Meilisearch', function () {
    expect(sortFor(['orderby' => 'rand']))->toBeNull()
        ->and(sortFor(['orderby' => 'meta_value', 'meta_key' => 'not_indexed']))->toBeNull()
        ->and(sortFor(['orderby' => 'menu_order title', 'order' => 'ASC']))->toBe(['post_title:asc']);
});

test('any post type or status leaves the filter open', function () {
    $filter = (new MeiliQueryBuilder)->build(new MockWPQuery(['post_type' => 'any', 'post_status' => 'any']))['filter'] ?? '';

    expect($filter)->toBe('');
});

test('an empty post type is posts, or every type for a search', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['post_type' => '']))['filter'])
        ->toBe("post_type = 'post' AND post_status = 'publish'")
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery(['post_type' => '', 's' => 'hello']))['filter'])
        ->toBe("post_status = 'publish'");
});

test('a query with a non-indexed meta key does not send the next ones to MySQL', function () {
    $builder = new MeiliQueryBuilder;

    $builder->build(new MockWPQuery(['meta_query' => [['key' => 'not_indexed', 'value' => 1]]]));
    expect($builder->hasNonIndexableMetaKeys())->toBeTrue();

    $builder->build(new MockWPQuery(['meta_query' => [['key' => 'price', 'value' => 1]]]));
    expect($builder->hasNonIndexableMetaKeys())->toBeFalse();
});

test('meta_key narrows the meta_query instead of replacing it', function () {
    update_option('meiliscout/indexed_meta_keys', ['price', 'color']);

    $filter = (new MeiliQueryBuilder)->build(new MockWPQuery([
        'meta_key' => 'price',
        'meta_value' => 10,
        'meta_type' => 'NUMERIC',
        'meta_query' => [['key' => 'color', 'value' => 'red']],
    ]))['filter'];

    expect($filter)->toContain("metas.color = 'red'")
        ->and($filter)->toContain('metas.price = 10');
});
