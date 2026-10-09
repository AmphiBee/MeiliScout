<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Query\FacetClauses;
use Pollora\MeiliScout\Listings\Query\FacetPlan;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Render\Hits;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Services\IndexNames;

require_once __DIR__.'/cases.php';

// The cases resources/listings/test/plan.test.js runs too: the client counts with the server's searches

/**
 * Each value's clause, by facet, as the template gives it.
 *
 * @return array<string, array<string, string>>
 */
function knownClauses(array $template): array
{
    $known = [];

    foreach ($template['facets'] as $facet) {
        foreach ($facet['values'] as $value => $entry) {
            $known[$facet['key']][(string) $value] = $entry['clause'];
        }
    }

    return $known;
}

function planCases(): array
{
    $cases = [];

    foreach (listingCases('plan-cases.json')['cases'] as $case) {
        $cases[$case['name']] = [$case];
    }

    return $cases;
}

test('the template\'s meta clauses and bounds are the builders\'', function () {
    $definition = casesDefinition();
    $template = listingCases('plan-cases.json')['template'];

    expect($template['perPage'])->toBe($definition->perPage)
        ->and($template['defaultSort'])->toBe($definition->defaultSort)
        ->and($template['sorts'])->toBe(FacetPlan::sorts($definition))
        ->and($template['fields'])->toBe(Hits::fields($definition))
        ->and($template['excerptLength'])->toBe(Hits::excerptLength());

    foreach (listingCases('plan-cases.json')['template']['facets'] as $entry) {
        $facet = $definition->facet($entry['key']);

        expect($entry['field'])->toBe($facet->countField())
            ->and($entry['type'])->toBe($facet->type)
            ->and($entry['logic'])->toBe($facet->logic);

        if (isset($entry['bounds'])) {
            expect($entry['bounds'])->toBe(FacetClauses::bounds($facet));
        }

        if (! $facet->isTaxonomy()) {
            foreach ($entry['values'] as $value => $known) {
                expect($known['clause'])->toBe(FacetClauses::value($facet, (string) $value));
            }
        }
    }
});

test('a state is counted, and its results searched, the same way', function (array $case) {
    $definition = casesDefinition();
    $fixture = listingCases('plan-cases.json');
    $state = new ListingState(
        $case['state']['values'],
        array_map(fn (array $range) => array_map('floatval', $range), $case['state']['ranges']),
        $case['state']['sort'],
        $case['state']['page'],
        $case['state']['search']
    );

    $searches = FacetPlan::counts($definition, $state, null, knownClauses($fixture['template']));

    foreach ($searches as $i => $search) {
        expect($search['indexUid'])->toBe(IndexNames::active('posts'));
        $searches[$i]['indexUid'] = $fixture['template']['index'];
    }

    $results = FacetPlan::results($definition, $state, null, knownClauses($fixture['template']));
    $results['indexUid'] = $fixture['template']['index'];

    expect($searches)->toBe($case['searches'])
        ->and($results)->toBe($case['results']);
})->with(planCases());

test('the values past a facet\'s limit are the ones the client folds', function () {
    foreach (listingCases('plan-cases.json')['overflow'] as $case) {
        expect(array_column(ListingQuery::withOverflow($case['options'], $case['limit']), 'overflow'))->toBe($case['overflow'], 'limit '.$case['limit']);
    }
});
