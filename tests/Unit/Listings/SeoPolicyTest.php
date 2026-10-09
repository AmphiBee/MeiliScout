<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Query\ListingResult;
use Pollora\MeiliScout\Listings\Seo\Adapters\AioseoAdapter;
use Pollora\MeiliScout\Listings\Seo\Adapters\RankMathAdapter;
use Pollora\MeiliScout\Listings\Seo\Adapters\SeoPressAdapter;
use Pollora\MeiliScout\Listings\Seo\Adapters\YoastAdapter;
use Pollora\MeiliScout\Listings\Seo\SeoPolicy;
use Pollora\MeiliScout\Listings\Seo\SeoRule;
use Pollora\MeiliScout\Listings\Seo\SeoView;
use Pollora\MeiliScout\Listings\State\ListingState;

require_once __DIR__.'/cases.php';

const BASE = 'https://example.test/projects/';

/**
 * The cases' listing in a state, with that many results (6 per page).
 */
function seoResult(ListingState $state, int $total): ListingResult
{
    return new ListingResult(casesDefinition(), $state, null, [], null, null, [], $total);
}

/**
 * Makes a view the request's, as SeoPolicy::resolve() does.
 */
function currentView(?SeoView $view): void
{
    \Closure::bind(fn () => SeoPolicy::$current = $view === null ? null : [$view, seoResult(new ListingState, 0)], null, SeoPolicy::class)();
}

afterEach(fn () => currentView(null));

test('the listing without filters is indexable at every page, canonical to itself, with its adjacent pages', function () {
    $first = SeoPolicy::evaluate(seoResult(new ListingState, 20), BASE);
    $second = SeoPolicy::evaluate(seoResult(new ListingState(page: 2), 20), BASE);
    $last = SeoPolicy::evaluate(seoResult(new ListingState(page: 4), 20), BASE);

    expect($first->indexable)->toBeTrue()
        ->and($first->canonical)->toBe(BASE)
        ->and($first->prev)->toBeNull()
        ->and($first->next)->toBe(BASE.'page/2/')
        ->and($first->robots())->toBe(['index' => true, 'follow' => true])
        ->and($second->canonical)->toBe(BASE.'page/2/')
        ->and($second->prev)->toBe(BASE)
        ->and($second->next)->toBe(BASE.'page/3/')
        ->and($last->next)->toBeNull()
        ->and($last->pages)->toBe(4);
});

test('a view with parameters is noindex, follow, without canonical or adjacent pages', function (ListingState $state, int $total, string $reason) {
    $view = SeoPolicy::evaluate(seoResult($state, $total), BASE);

    expect($view->indexable)->toBeFalse()
        ->and($view->reason)->toBe($reason)
        ->and($view->canonical)->toBeNull()
        ->and($view->prev)->toBeNull()
        ->and($view->next)->toBeNull()
        ->and($view->robots())->toBe(['index' => false, 'follow' => true]);
})->with([
    'a facet' => [new ListingState(['category' => ['news']], page: 2), 20, 'filters'],
    'a range' => [new ListingState(ranges: ['price' => ['min' => 10.0]]), 20, 'filters'],
    'a search' => [new ListingState(search: 'shop'), 20, 'search'],
    'another sort' => [new ListingState(sort: 'title'), 20, 'sort'],
    'no results' => [new ListingState, 0, 'empty'],
]);

test('the default sort spelled out is still the listing without parameters', function () {
    expect(SeoPolicy::evaluate(seoResult(new ListingState(sort: 'date'), 3), BASE)->indexable)->toBeTrue();
});

test('a site closed to search engines keeps WordPress\'s robots', function () {
    $view = SeoPolicy::evaluate(seoResult(new ListingState, 20), BASE, null, [], false);

    expect($view->indexable)->toBeTrue()
        ->and($view->forcesIndex())->toBeFalse()
        ->and($view->robots())->toBeNull();
});

test('a rule\'s fields with their variables; past page 1, the title says which page, without text or questions', function () {
    $rule = new SeoRule('cases', '', 'category=3', 2, '{category} news – {site}', '{total} posts in {category}.', '{category}', 'About {category}', [['question' => 'How many?', 'answer' => '{total}, {unknown}.']]);
    $vars = ['site' => 'Example', 'sep' => '|', 'category' => 'Events'];

    $first = SeoPolicy::evaluate(seoResult(new ListingState(['category' => ['events']]), 9), BASE, $rule, $vars);
    $second = SeoPolicy::evaluate(seoResult(new ListingState(['category' => ['events']], page: 2), 9), BASE, $rule, $vars);

    expect($first->title)->toBe('Events news – Example')
        ->and($first->description)->toBe('9 posts in Events.')
        ->and($first->h1)->toBe('Events')
        ->and($first->intro)->toBe('About Events')
        ->and($first->faq)->toBe([['question' => 'How many?', 'answer' => '9, {unknown}.']])
        ->and($second->title)->toBe('Events news – Example | Page 2')
        ->and($second->intro)->toBe('')
        ->and($second->faq)->toBe([])
        ->and($second->h1)->toBe('Events');
});

test('a title holding {page} places it itself', function () {
    $rule = new SeoRule('cases', '', '', 0, 'Projects, page {page} of {pages}');

    expect(SeoPolicy::evaluate(seoResult(new ListingState(page: 3), 20), BASE, $rule)->title)->toBe('Projects, page 3 of 4');
});

test('Yoast\'s robots string: forced to index, follow on an indexable view, noindex, follow otherwise', function () {
    $adapter = new YoastAdapter;
    currentView(SeoPolicy::evaluate(seoResult(new ListingState(page: 2), 20), BASE));
    $indexable = $adapter->robots('noindex, nofollow, max-snippet:-1');

    currentView(SeoPolicy::evaluate(seoResult(new ListingState(search: 'x'), 20), BASE));

    expect($indexable)->toBe('index, follow, max-snippet:-1')
        ->and($adapter->robots('index, follow, max-image-preview:large'))->toBe('noindex, follow, max-image-preview:large')
        ->and($adapter->canonical('https://example.test/projects/'))->toBeFalse();

    currentView(null);
    expect($adapter->robots('index, follow'))->toBe('index, follow');
});

test('Rank Math\'s, All in One SEO\'s and SEOPress\'s robots', function () {
    currentView(SeoPolicy::evaluate(seoResult(new ListingState(page: 2), 20), BASE));

    expect((new RankMathAdapter)->robots(['index' => 'noindex', 'follow' => 'nofollow', 'max-snippet' => 'max-snippet:-1']))->toBe(['index' => 'index', 'follow' => 'follow', 'max-snippet' => 'max-snippet:-1'])
        ->and((new AioseoAdapter)->robots(['noindex' => 'noindex', 'nofollow' => 'nofollow', 'noarchive' => '']))->toBe(['noindex' => '', 'nofollow' => '', 'noarchive' => ''])
        ->and((new SeoPressAdapter)->noindex('yes'))->toBe('')
        ->and((new SeoPressAdapter)->nofollow('yes'))->toBe('');

    currentView(SeoPolicy::evaluate(seoResult(new ListingState(sort: 'title'), 20), BASE));

    expect((new RankMathAdapter)->robots(['index' => 'index', 'follow' => 'follow']))->toBe(['index' => 'noindex', 'follow' => 'follow'])
        ->and((new AioseoAdapter)->robots(['noindex' => '', 'nofollow' => '']))->toBe(['noindex' => 'noindex', 'nofollow' => ''])
        ->and((new SeoPressAdapter)->noindex(''))->toBe('yes')
        ->and((new AioseoAdapter)->canonical('https://example.test/projects/'))->toBe('');
});
