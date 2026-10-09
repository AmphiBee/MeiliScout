<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Seo\SeoPolicy;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;

require_once __DIR__.'/cases.php';

// The cases resources/listings/test/codec.test.js runs too: both halves read and write the same paths

/**
 * The cases' definition with its facets in the path.
 */
function pathDefinition(): ListingDefinition
{
    casesDefinition();
    $cases = listingCases('path-cases.json');
    $args = listingCases('definition.json');

    foreach ($cases['paths'] as $key => $prefix) {
        $args['facets'][$key]['path'] = $prefix;
    }
    $args['seo'] = ['max_depth' => $cases['seo']['maxDepth'], 'min_results' => $cases['seo']['minResults']];
    $args['route'] = ['page' => 1];

    return ListingDefinition::fromArray('paths', $args);
}

/**
 * @param  array<string, mixed>  $state
 */
function caseState(array $state): ListingState
{
    return new ListingState($state['values'], $state['ranges'], $state['sort'], $state['page'], $state['search']);
}

function pathCases(string $set): array
{
    $cases = [];
    foreach (listingCases('path-cases.json')[$set] as $case) {
        $cases[$case['name']] = [$case];
    }

    return $cases;
}

test('a request reads as its state, written back in canonical form', function (array $case) {
    $definition = pathDefinition();
    $base = listingCases('path-cases.json')['base'];
    $state = UrlCodec::fromRequest($definition, $case['path'], $case['query'], $base);

    if ($case['state'] === null) {
        expect($state)->toBeNull()
            ->and(UrlCodec::canonicalUrl($definition, $case['path'], $case['query'], $base))->toBeNull();

        return;
    }

    expect(stateArray($state))->toEqual($case['state'])
        ->and(UrlCodec::url($definition, $state, $base))->toBe($case['url'])
        ->and(UrlCodec::canonicalUrl($definition, $case['path'], $case['query'], $base))->toBe($case['redirect']);

    // The canonical form is a fixed point
    $url = parse_url($case['url']);
    expect(UrlCodec::canonicalUrl($definition, $url['path'], $url['query'] ?? '', $base))->toBeNull();
})->with(pathCases('cases'));

test('a value is a link when it leads to a view that may be indexed', function (array $case) {
    $definition = pathDefinition();
    $target = SeoPolicy::linkTarget($definition, caseState($case['state']), $case['facet'], $case['value'], $case['count']);

    expect($target === null ? null : UrlCodec::url($definition, $target, listingCases('path-cases.json')['base']))->toBe($case['url']);
})->with(pathCases('links'));

test('only taxonomy lists go in the path, with prefixes that can be told apart, on a route', function (array $changes, string $error) {
    casesDefinition();
    $args = array_replace_recursive(listingCases('definition.json'), ['route' => ['page' => 1]], $changes);

    expect(fn () => ListingDefinition::fromArray('paths', $args))->toThrow(\Pollora\MeiliScout\Listings\Definition\InvalidListing::class, $error);
})->with([
    'a meta facet' => [['facets' => ['color' => ['path' => 'c']]], 'only a taxonomy list'],
    'a prefix with a slash' => [['facets' => ['category' => ['path' => 'a/b']]], 'lowercase letters'],
    'one prefix starts another' => [['facets' => ['category' => ['path' => 'type'], 'tag' => ['path' => 'type-de']]], 'cannot be told apart'],
]);

test('facets in the path need a route', function () {
    casesDefinition();
    $args = listingCases('definition.json');
    $args['facets']['category']['path'] = 'rubrique';

    expect(fn () => ListingDefinition::fromArray('paths', $args))->toThrow(\Pollora\MeiliScout\Listings\Definition\InvalidListing::class, 'need a route');
});

test('a view of the path is indexable with one value per facet, two facets at most, three results at least', function (array $values, int $total, ?string $reason) {
    expect(SeoPolicy::reason(pathDefinition(), new ListingState($values), $total))->toBe($reason);
})->with([
    'one facet' => [['category' => ['news']], 3, null],
    'two facets' => [['category' => ['news'], 'tag' => ['php']], 12, null],
    'two values of a facet' => [['category' => ['news', 'travel']], 12, 'values'],
    'too few results' => [['category' => ['news']], 2, 'few'],
    'no results' => [['category' => ['news']], 0, 'empty'],
    'a facet out of the path' => [['category' => ['news'], 'color' => ['red']], 12, 'filters'],
]);

test('the depth a listing indexes is its own', function () {
    casesDefinition();
    $args = listingCases('definition.json');
    $args['facets']['category']['path'] = 'rubrique';
    $args['facets']['tag']['path'] = 'sujet';
    $args['route'] = ['page' => 1];
    $args['seo'] = ['max_depth' => 1, 'min_results' => 1];
    $definition = ListingDefinition::fromArray('depth', $args);

    expect(SeoPolicy::reason($definition, new ListingState(['category' => ['news'], 'tag' => ['php']]), 12))->toBe('depth')
        ->and(SeoPolicy::reason($definition, new ListingState(['tag' => ['php']]), 1))->toBeNull();
});
