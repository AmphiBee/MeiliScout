<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Query;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;
use Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder\MockWPQuery;

// An indexable (a plugin's, through meiliscout/indexables) may push narrower settings than MeiliScout's own

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
});

function pushedToPosts(array $settings): void
{
    IndexSettings::remember(IndexNames::active('posts'), $settings);
}

test('the attributes pushed to an index are read back, every one when unknown', function () {
    expect(IndexSettings::displayed('example_test_posts'))->toBe(['*'])
        ->and(IndexSettings::searchable('example_test_posts'))->toBeNull();

    IndexSettings::remember('example_test_posts', ['displayedAttributes' => ['ID', 'card'], 'searchableAttributes' => ['post_title'], 'sortableAttributes' => ['post_date']]);

    expect(IndexSettings::displayed('example_test_posts'))->toBe(['ID', 'card'])
        ->and(IndexSettings::searchable('example_test_posts'))->toBe(['post_title']);
});

test('an attribute covers its fields, and * every attribute', function () {
    expect(IndexSettings::firstUncovered(['*'], ['ID', 'taxonomies']))->toBeNull()
        ->and(IndexSettings::firstUncovered(['ID', 'taxonomies'], ['ID', 'taxonomies.category']))->toBeNull()
        ->and(IndexSettings::firstUncovered(['ID', 'taxonomies_extra'], ['taxonomies']))->toBe('taxonomies')
        ->and(IndexSettings::firstUncovered(['ID', 'card'], ['ID', 'post_parent']))->toBe('post_parent');
});

test('ids are read when the index returns them', function () {
    pushedToPosts(['displayedAttributes' => ['ID', 'card']]);

    expect((new MeiliQueryBuilder)->build(new MockWPQuery([]))['attributesToRetrieve'])->toBe(['ID']);
});

test('a field the index does not return sends the query to MySQL', function () {
    pushedToPosts(['displayedAttributes' => ['ID', 'card']]);

    expect(fn () => (new MeiliQueryBuilder)->build(new MockWPQuery(['fields' => 'id=>parent'])))
        ->toThrow(UnsupportedQuery::class, 'undisplayed_attribute:post_parent');
});

test('search_columns is checked against what the index searches, not the admin setting', function () {
    pushedToPosts(['searchableAttributes' => ['post_title', 'card']]);

    expect(fn () => (new MeiliQueryBuilder)->build(new MockWPQuery(['s' => 'x', 'search_columns' => ['post_content']])))
        ->toThrow(UnsupportedQuery::class, 'unsupported_arg:search_columns')
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery(['s' => 'x', 'search_columns' => ['post_title']]))['attributesToSearchOn'])
        ->toBe(['post_title']);
});

test('a query without LIMIT stops at the lower of the two maxTotalHits', function () {
    pushedToPosts(['pagination' => ['maxTotalHits' => 1000]]);

    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['posts_per_page' => -1]))['hitsPerPage'])->toBe(1000);

    pushedToPosts(['pagination' => ['maxTotalHits' => 50000]]);

    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['posts_per_page' => -1]))['hitsPerPage'])->toBe(10000);
});
