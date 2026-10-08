<?php

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
});

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

test('sentence searches the words as one phrase', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['s' => 'maison "de" thé', 'sentence' => true]))['q'])->toBe('"maison  de  thé"');
});

test('search_columns restricts the attributes searched, as WordPress reads it', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['s' => 'x', 'search_columns' => ['post_title', 'post_content']]))['attributesToSearchOn'])
        ->toBe(['post_title', 'content_text'])
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery(['s' => 'x', 'search_columns' => ['guid']]))['attributesToSearchOn'])
        ->toBe(['post_title', 'post_excerpt', 'content_text'])
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery(['s' => 'x'])))->not->toHaveKey('attributesToSearchOn');
});

test('a column the index does not search sends the query to MySQL', function () {
    update_option('meiliscout/searchable_attributes', ['post_title']);

    expect(fn () => (new MeiliQueryBuilder)->build(new MockWPQuery(['s' => 'x', 'search_columns' => ['post_excerpt']])))
        ->toThrow(\Pollora\MeiliScout\Query\UnsupportedQuery::class, 'unsupported_arg:search_columns');
});
