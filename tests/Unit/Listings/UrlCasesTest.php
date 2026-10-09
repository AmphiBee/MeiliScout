<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Query\FacetPlan;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;

require_once __DIR__.'/cases.php';

// The cases resources/listings/test/codec.test.js runs too: both halves write the same URLs

/**
 * A state as the cases write it.
 *
 * @return array<string, mixed>
 */
function stateArray(ListingState $state): array
{
    return [
        'values' => $state->values,
        'ranges' => $state->ranges,
        'sort' => $state->sort,
        'page' => $state->page,
        'search' => $state->search,
    ];
}

/**
 * The cases this suite can run: the ones needing remove_accents() only with WordPress loaded.
 */
function urlCases(): array
{
    $cases = [];

    foreach (listingCases('url-cases.json')['cases'] as $case) {
        if (! empty($case['accents']) && ! function_exists('remove_accents')) {
            continue;
        }
        $cases[$case['name']] = [$case];
    }

    return $cases;
}

test('the client reads the codec from the template the server publishes', function () {
    $definition = casesDefinition();
    $template = listingCases('url-cases.json')['template'];

    $published = [
        'sortParam' => $definition->sortParam,
        'searchParam' => $definition->searchParam,
        'defaultSort' => $definition->defaultSort,
        'sorts' => FacetPlan::sorts($definition),
        'facets' => array_map(fn ($facet) => [
            'key' => $facet->key,
            'param' => $facet->param,
            'type' => $facet->type,
            'logic' => $facet->logic,
            'taxonomy' => $facet->isTaxonomy(),
            'decimals' => $facet->decimals,
        ], $definition->facets),
    ];

    expect($published)->toBe($template);
});

test('a query string reads as its state, written back in canonical form', function (array $case) {
    $definition = casesDefinition();
    $state = UrlCodec::fromQueryString($definition, $case['query'], $case['page'] ?? 1);
    $expected = $case['state'];

    expect(stateArray($state))->toEqual($expected)
        ->and(UrlCodec::queryString($definition, $state))->toBe($case['canonical'])
        ->and(UrlCodec::url($definition, $state, listingCases('url-cases.json')['base']))->toBe($case['url'])
        ->and(UrlCodec::canonicalQuery($definition, $case['query']))->toBe($case['redirect']);

    // The canonical form is a fixed point
    $again = UrlCodec::fromQueryString($definition, $case['canonical'], $case['page'] ?? 1);
    expect(stateArray($again))->toEqual($expected)
        ->and(UrlCodec::canonicalQuery($definition, $case['canonical']))->toBeNull();
})->with(urlCases());
