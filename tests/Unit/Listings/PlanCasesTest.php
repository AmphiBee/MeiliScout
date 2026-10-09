<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Query\FacetClauses;
use Pollora\MeiliScout\Listings\Query\FacetPlan;
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

test('a state is counted by the same searches', function (array $case) {
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

    expect($searches)->toBe($case['searches']);
})->with(planCases());
