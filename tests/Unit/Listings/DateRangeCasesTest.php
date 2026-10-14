<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Query\FacetClauses;
use Pollora\MeiliScout\Listings\State\UrlCodec;

require_once __DIR__.'/cases.php';

// The cases resources/listings/test/date.test.js runs too: a range of days, in the URL and the filter

function dateRangeDefinition(): ListingDefinition
{
    $args = listingCases('definition.json');
    $args['facets'] = ['periode' => ['source' => 'date', 'type' => 'range']];
    casesDefinition();

    return ListingDefinition::fromArray('dates', $args);
}

test('a date range reads the posts\' date in timestamps', function () {
    $facet = dateRangeDefinition()->facet('periode');

    expect($facet->countField())->toBe('post_date_ts')
        ->and($facet->isDateRange())->toBeTrue()
        ->and($facet->label)->toBe('Date')
        ->and(FacetClauses::bounds($facet))->toBe(listingCases('date-cases.json')['facet']['bounds']);
});

test('a day range in the URL is the state and the filter the cases say', function (array $case) {
    $definition = dateRangeDefinition();
    $state = UrlCodec::fromQueryString($definition, $case['query']);

    expect($state->ranges)->toEqual($case['ranges'])
        ->and(FacetClauses::clause($definition->facet('periode'), $state))->toBe($case['clause'])
        ->and(UrlCodec::queryString($definition, $state))->toBe($case['canonical']);
})->with(function () {
    $cases = [];

    foreach (listingCases('date-cases.json')['cases'] as $case) {
        $cases[$case['name']] = [$case];
    }

    return $cases;
});

test('a date range is the date_query\'s, inclusive of the last day', function () {
    $definition = dateRangeDefinition();
    $state = UrlCodec::fromQueryString($definition, 'periode=2025-03-01..2025-06-30');

    expect(FacetClauses::args($definition->facet('periode'), $state))->toBe(['date_query' => [['after' => '2025-03-01', 'before' => '2025-06-30', 'inclusive' => true, 'column' => 'post_date']]]);
});
