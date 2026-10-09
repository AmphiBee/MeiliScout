<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Query\FacetClauses;
use Pollora\MeiliScout\Listings\Render\Hits;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;

/*
 * The listings' shared cases (tests/fixtures/listings) with WordPress's own
 * functions: sanitize_title() with remove_accents(), the site's rewrite rules,
 * the reserved parameters of its query vars, the builders reading real terms.
 */

function integrationCases(string $file): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/listings/'.$file), true, 512, JSON_THROW_ON_ERROR);
}

function integrationDefinition(): ListingDefinition
{
    // The fixture's meta keys, indexed or not on this site: only the URL and the clauses are tested
    add_filter('pre_option_meiliscout/indexed_post_types', fn () => ['post']);
    add_filter('pre_option_meiliscout/indexed_meta_keys', fn () => ['color', 'price', 'featured']);

    try {
        return ListingDefinition::fromArray('cases', integrationCases('definition.json'));
    } finally {
        remove_all_filters('pre_option_meiliscout/indexed_post_types');
        remove_all_filters('pre_option_meiliscout/indexed_meta_keys');
    }
}

test('every url case, accents included', function () {
    global $wp_rewrite;

    if (! $wp_rewrite->using_permalinks()) {
        $this->markTestSkipped('The cases write pretty permalinks.');
    }

    $definition = integrationDefinition();
    $fixture = integrationCases('url-cases.json');

    foreach ($fixture['cases'] as $case) {
        $state = UrlCodec::fromQueryString($definition, $case['query'], $case['page'] ?? 1);
        $read = ['values' => $state->values, 'ranges' => $state->ranges, 'sort' => $state->sort, 'page' => $state->page, 'search' => $state->search];

        expect($read)->toEqual($case['state'], $case['name'])
            ->and(UrlCodec::queryString($definition, $state))->toBe($case['canonical'], $case['name'])
            ->and(UrlCodec::url($definition, $state, $fixture['base']))->toBe($case['url'], $case['name'])
            ->and(UrlCodec::canonicalQuery($definition, $case['query']))->toBe($case['redirect'], $case['name']);
    }
});

test('a term\'s clause has the form the plan cases give it', function () {
    $definition = integrationDefinition();
    $template = integrationCases('plan-cases.json')['template'];

    foreach (['category', 'tag'] as $key) {
        $facet = $definition->facet($key);
        $term = get_terms(['taxonomy' => $facet->name, 'number' => 1, 'hide_empty' => true, 'use_meilisearch' => false])[0] ?? null;

        if (! $term instanceof WP_Term) {
            $this->markTestSkipped("No {$facet->name} term on this site.");
        }

        $entry = current(array_filter($template['facets'], fn (array $facet) => $facet['key'] === $key));
        $value = (string) array_key_first($entry['values']);
        $example = $entry['values'][$value];
        // A tree is filtered by id, a flat taxonomy by slug
        $expected = str_replace(['['.$example['id'].']', "['{$value}']"], ['['.$term->term_id.']', "['{$term->slug}']"], $example['clause']);

        expect(FacetClauses::value($facet, $term->slug))->toBe($expected)
            ->and(FacetClauses::clause($facet, new ListingState([$key => [$term->slug]])))->toBe($expected);
    }
});

test('a card\'s date is the one mysql2date() writes, with the site\'s names', function () {
    $fixture = integrationCases('card-cases.json');
    $names = Hits::dateNames();

    if (array_diff_key($fixture['names'], ['format' => true]) !== array_diff_key($names, ['format' => true])) {
        $this->markTestSkipped('The cases are written with the French names: switch the site to fr_FR.');
    }

    foreach ($fixture['dates'] as $case) {
        expect(mysql2date($case['format'], $case['date']))->toBe($case['label'], $case['format'])
            ->and(Hits::formatDate(['format' => $case['format']] + $names, $case['date']))->toBe($case['label'], $case['format']);
    }
});
