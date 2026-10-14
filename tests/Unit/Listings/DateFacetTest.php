<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Query\FacetClauses;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;

require_once __DIR__.'/cases.php';

function dateDefinition(array $facets): ListingDefinition
{
    $args = listingCases('definition.json');
    $args['facets'] = $facets;
    casesDefinition();

    return ListingDefinition::fromArray('dates', $args);
}

test('a date facet counts the years of the post date', function () {
    $definition = dateDefinition(['annee' => ['source' => 'date'], 'maj' => ['source' => 'date:modified']]);

    expect($definition->facet('annee')->countField())->toBe('date_parts.post_date.year')
        ->and($definition->facet('maj')->countField())->toBe('date_parts.post_modified.year')
        ->and($definition->facet('annee')->label)->toBe('Year');
});

test('a year\'s clause is the date builder\'s', function () {
    $facet = dateDefinition(['annee' => ['source' => 'date']])->facet('annee');

    expect(FacetClauses::value($facet, '2025'))->toBe('(date_parts.post_date.year = 2025)');
});

test('several years are joined by OR', function () {
    $definition = dateDefinition(['annee' => ['source' => 'date']]);
    $facet = $definition->facet('annee');
    $state = new ListingState(['annee' => ['2025', '2026']]);

    expect(FacetClauses::clause($facet, $state))->toBe('((date_parts.post_date.year = 2025) OR (date_parts.post_date.year = 2026))')
        ->and(FacetClauses::args($facet, $state)['date_query'][0]['relation'])->toBe('OR');
});

test('a year in the URL is four digits', function () {
    $definition = dateDefinition(['annee' => ['source' => 'date']]);

    expect(UrlCodec::fromQueryString($definition, 'annee=2025,abc,25,2024')->valuesOf('annee'))->toBe(['2024', '2025']);
});

test('a date facet cannot combine its years with and', function () {
    $args = listingCases('definition.json');
    $args['facets'] = ['annee' => ['source' => 'date', 'logic' => 'and']];
    casesDefinition();

    expect(fn () => ListingDefinition::fromArray('dates', $args))->toThrow(\InvalidArgumentException::class);
});
