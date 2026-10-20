<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Search;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Search\SearchWeights;
use Pollora\MeiliScout\Search\WeightedSearch;

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
    Settings::save('indexed_post_types', ['post', 'page', 'product']);
});

afterEach(fn () => $GLOBALS['wp_options'] = []);

test('weights are clamped to 0-10, rounded to halves, and only numbers count', function () {
    expect(SearchWeights::normalize(['a' => 12, 'b' => -3, 'c' => '2.3', 'd' => 'high', 'e' => 1.26, 7 => 3]))
        ->toBe(['a' => 10.0, 'b' => 0.0, 'c' => 2.5, 'e' => 1.5]);
});

test('a type is searched with the defaults until weights are set', function () {
    expect(SearchWeights::isActive())->toBeFalse()
        ->and(SearchWeights::profile('post'))->toBe(SearchWeights::DEFAULT_PROFILE);

    Settings::save(SearchWeights::SETTING, ['post' => ['post_title' => 6, 'content_text' => 0]]);

    expect(SearchWeights::isActive())->toBeTrue()
        ->and(SearchWeights::profile('post'))->toBe(['post_title' => 6.0, 'content_text' => 0.0])
        ->and(SearchWeights::enabled('post'))->toBe(['post_title' => 6.0])
        // A type with nothing set keeps the defaults
        ->and(SearchWeights::profile('page'))->toBe(SearchWeights::DEFAULT_PROFILE);
});

test('the fields searched are the ones with a weight, heaviest first', function () {
    Settings::save(SearchWeights::SETTING, [
        'post' => ['post_title' => 2, 'content_text' => 5, 'post_excerpt' => 0],
        'page' => ['post_title' => 3, 'terms.name' => 1],
        'product' => ['metas._sku' => 9],
    ]);

    expect(SearchWeights::enabledFields())->toBe(['metas._sku', 'content_text', 'post_title', 'terms.name'])
        ->and(SearchWeights::enabledFields(['post']))->toBe(['content_text', 'post_title']);
});

test('fields of the same weight are one query, and identical tiers are shared by types', function () {
    Settings::save(SearchWeights::SETTING, [
        'post' => ['post_title' => 4, 'terms.name' => 4, 'content_text' => 1],
        'page' => ['post_title' => 4, 'terms.name' => 4, 'content_text' => 2],
    ]);

    expect(SearchWeights::tiers(['post', 'page']))->toBe([
        ['fields' => ['post_title', 'terms.name'], 'weight' => 4.0, 'types' => ['post', 'page']],
        ['fields' => ['content_text'], 'weight' => 2.0, 'types' => ['page']],
        ['fields' => ['content_text'], 'weight' => 1.0, 'types' => ['post']],
    ]);
});

test('a search is weighted only by relevance, with several weights', function () {
    $q = ['q' => 'shoes', 'index' => ''];

    expect(WeightedSearch::applies($q, ['post']))->toBeFalse(); // not configured: every field weighs the same way as before

    Settings::save(SearchWeights::SETTING, ['post' => ['post_title' => 4, 'content_text' => 1]]);

    expect(WeightedSearch::applies($q, ['post']))->toBeTrue()
        ->and(WeightedSearch::applies([...$q, 'q' => ''], ['post']))->toBeFalse()
        ->and(WeightedSearch::applies([...$q, 'sort' => ['post_date:desc']], ['post']))->toBeFalse()
        ->and(WeightedSearch::applies([...$q, 'facets' => ['post_type']], ['post']))->toBeFalse()
        ->and(WeightedSearch::applies([...$q, 'hitsPerPage' => 0], ['post']))->toBeFalse();

    // One weight only: nothing to merge
    Settings::save(SearchWeights::SETTING, ['post' => ['post_title' => 4, 'content_text' => 4]]);

    expect(WeightedSearch::applies($q, ['post']))->toBeFalse();
});

test('each tier is a query on its fields, scaled by its weight, on its types', function () {
    Settings::save(SearchWeights::SETTING, ['post' => ['post_title' => 4, 'content_text' => 1]]);

    $queries = WeightedSearch::queries('posts', ['q' => 'shoes', 'filter' => 'post_status = "publish"', 'limit' => 10, 'offset' => 20, 'attributesToRetrieve' => ['ID']], ['post']);

    expect($queries)->toHaveCount(2)
        ->and($queries[0]['attributesToSearchOn'])->toBe(['post_title'])
        ->and($queries[0]['federationOptions'])->toBe(['weight' => 4.0])
        ->and($queries[0]['filter'])->toBe(['post_status = "publish"', 'post_type IN ["post"]'])
        ->and($queries[1]['federationOptions'])->toBe(['weight' => 1.0])
        // Paging belongs to the federation, not to its queries
        ->and($queries[0])->not->toHaveKeys(['limit', 'offset'])
        ->and($queries[0]['attributesToRetrieve'])->toBe(['ID']);
});

test('a search limited to some columns keeps only the tiers that cover them', function () {
    Settings::save(SearchWeights::SETTING, ['post' => ['post_title' => 4, 'content_text' => 1]]);

    $queries = WeightedSearch::queries('posts', ['q' => 'x', 'attributesToSearchOn' => ['post_title']], ['post']);

    expect($queries)->toHaveCount(1)->and($queries[0]['attributesToSearchOn'])->toBe(['post_title']);
});

test('an ordinary search is limited to the fields the searched types weigh', function () {
    Settings::save(SearchWeights::SETTING, ['post' => ['post_title' => 4, 'content_text' => 0]]);

    expect(WeightedSearch::restrict(['q' => 'x'], '', ['post'])['attributesToSearchOn'])->toBe(['post_title'])
        ->and(WeightedSearch::restrict(['q' => ''], '', ['post']))->not->toHaveKey('attributesToSearchOn');
});

test('weights are saved for indexed types and known fields only', function () {
    Settings::save('indexed_meta_keys', ['budget']);

    SearchWeights::save([
        'post' => ['post_title' => 20, 'metas.budget' => 2, 'metas.unknown' => 3, 'nonsense' => 1],
        'ghost' => ['post_title' => 1],
    ]);

    expect(SearchWeights::configured())->toBe(['post' => ['post_title' => 10.0, 'metas.budget' => 2.0]]);
});
