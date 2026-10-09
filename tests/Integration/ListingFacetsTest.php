<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Diagnostics\CountParity;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use Pollora\MeiliScout\Listings\Query\FacetClauses;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\ReservedParameters;

/*
 * Phase 8's facets on the demo site: the authors of posts (a list of user
 * slugs, counted on post_author), and a facet's search field.
 */

beforeEach(function () {
    if (ListingsServiceProvider::unavailable() !== null) {
        $this->markTestSkipped('Needs the listings module on.');
    }

    DefinitionRegistry::declare('facets-test', [
        'post_types' => ['post'],
        'facets' => [
            'author' => ['source' => 'author', 'search' => true],
            'category' => ['source' => 'taxonomy:category'],
        ],
    ]);
});

afterEach(function () {
    DefinitionRegistry::remove('facets-test');
    Listings::forget();
});

test('an author facet is a list of user slugs, counted as MySQL counts them', function () {
    $definition = DefinitionRegistry::get('facets-test');
    $author = $definition->facet('author');
    $user = get_user_by('id', 1);

    expect($author->countField())->toBe('post_author')
        ->and($author->label)->toBe(__('Author', 'meiliscout'))
        // WordPress reads ?author=: the facet is named otherwise
        ->and(ReservedParameters::isReserved($author->param))->toBeFalse()
        ->and(FacetClauses::value($author, $user->user_nicename))->toBe('post_author IN [1]')
        ->and(FacetClauses::value($author, 'nobody-has-this-slug'))->toBe('post_author IN [0]');

    $parity = CountParity::compare($definition);
    $authors = array_filter($parity['rows'], fn (array $row) => $row['facet'] === 'author');

    expect($authors)->not->toBeEmpty()
        ->and($parity['diffs'])->toBe(0, wp_json_encode($parity['rows']));

    // A selection: what each other author adds
    $selected = CountParity::compare($definition, '', new ListingState(['author' => [$user->user_nicename]]));
    expect($selected['diffs'])->toBe(0, wp_json_encode($selected['rows']));
});

test('an author facet\'s options are named after the users', function () {
    $user = get_user_by('id', 1);
    $html = Listings::render('facets-test');

    expect($html)->toContain('value="'.$user->user_nicename.'"')
        ->and($html)->toContain(esc_html($user->display_name));
});

test('an author facet refuses what a post with one author cannot be', function (array $facet, string $error) {
    try {
        ListingDefinition::fromArray('facets-invalid', ['post_types' => ['post'], 'facets' => ['author' => $facet]]);
        $this->fail('Accepted');
    } catch (InvalidListing $e) {
        expect(implode(' ', $e->errors))->toContain($error);
    }
})->with([
    'all of two authors' => [['source' => 'author', 'logic' => 'and'], 'one author'],
    'a range' => [['source' => 'author', 'type' => 'range'], 'read a meta key'],
    'a search on a range' => [['source' => 'meta:_price', 'type' => 'range', 'search' => true], 'only a list'],
]);

test('a facet\'s search field is hidden without JavaScript and never sent', function () {
    $html = Listings::render('facets-test');

    preg_match('#<div class="meiliscout-facet__search"[^>]*>(.*?)</div>#s', $html, $search);

    expect($search)->not->toBeEmpty()
        ->and($search[0])->toContain('hidden')
        ->and($search[1])->not->toContain('name=')
        ->and($search[1])->toContain('aria-label=');
});
