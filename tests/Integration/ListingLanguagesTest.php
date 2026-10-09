<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\Blocks\BlockListings;
use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Language\NoLanguage;
use Pollora\MeiliScout\Listings\Language\Translations;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Seo\SeoRule;
use Pollora\MeiliScout\Listings\Seo\SeoRules;
use Pollora\MeiliScout\Listings\Seo\SeoRulesService;
use Pollora\MeiliScout\Listings\State\ListingState;

/*
 * Listings in two languages (design §9): the demo with Polylang or WPML,
 * French by default, English under /en/, the realisations listing's page and
 * its project types translated, its type facet in the path with an English
 * prefix (scripts/languages-polylang.php or languages-wpml.php in the demo).
 */

/**
 * @return array{status: int, location: string, html: string}
 */
function languageRequest(string $url): array
{
    $response = wp_remote_get($url, ['sslverify' => false, 'redirection' => 0, 'timeout' => 30]);

    return [
        'status' => (int) wp_remote_retrieve_response_code($response),
        'location' => (string) wp_remote_retrieve_header($response, 'location'),
        'html' => (string) wp_remote_retrieve_body($response),
    ];
}

beforeEach(function () {
    Languages::use(null);
    $adapter = Languages::adapter();

    if (ListingsServiceProvider::unavailable() !== null || $adapter instanceof NoLanguage || ! DefinitionRegistry::has('realisations')) {
        $this->markTestSkipped('Needs the listings module and a multilingual plugin on the demo.');
    }

    $this->definition = DefinitionRegistry::get('realisations');
    $this->type = $this->definition->facet('type');
    if ($this->type?->prefix('en') === $this->type?->prefix('fr')) {
        $this->markTestSkipped('Needs the type facet in the path with an English prefix.');
    }

    $this->adapter = $adapter;
    $this->fr = $adapter->findTerm('refonte', 'project_type');
    $this->en = $this->fr === null ? null : $adapter->translateTerm($this->fr, 'en');
    if ($this->en === null || $adapter->post(756, 'en') === null) {
        $this->markTestSkipped('Needs the demo\'s translations.');
    }

    $this->baseFr = $adapter->in('fr', fn () => Listings::baseUrl($this->definition));
    $this->baseEn = $adapter->in('en', fn () => Listings::baseUrl($this->definition));
});

afterEach(fn () => Translations::forget());

test('each language has its page and its prefix', function () {
    expect($this->baseEn)->not->toBe($this->baseFr)
        ->and($this->type->prefix('fr'))->toBe('type')
        ->and($this->type->prefix('en'))->toBe('kind');

    $view = languageRequest($this->baseEn.'kind-'.$this->en->slug.'/');
    expect($view['status'])->toBe(200)
        ->and($view['html'])->toContain('rel="canonical" href="'.$this->baseEn.'kind-'.$this->en->slug.'/"');
});

test('another language\'s prefix, or its terms under this language\'s page, redirect to the right view', function () {
    $prefix = languageRequest($this->baseEn.'type-'.$this->en->slug.'/');
    $page = languageRequest($this->baseFr.'type-'.$this->en->slug.'/');

    expect($prefix['status'])->toBe(301)
        ->and($prefix['location'])->toBe($this->baseEn.'kind-'.$this->en->slug.'/')
        ->and($page['status'])->toBe(301)
        ->and($page['location'])->toBe($this->baseEn.'kind-'.$this->en->slug.'/');
});

test('terms of two languages at once are a 404', function () {
    $values = [$this->en->slug, $this->fr->slug];
    sort($values);

    expect(languageRequest($this->baseEn.'kind-'.implode(',', $values).'/')['status'])->toBe(404);
});

test('counts and results are the language\'s: as MySQL in that language', function (string $language) {
    $this->adapter->in($language, function () use ($language) {
        $result = ListingQuery::run($this->definition, new ListingState);
        $args = ListingQuery::wpQueryArgs($this->definition, new ListingState);
        $mysql = new WP_Query(['use_meilisearch' => false, 'fields' => 'ids', 'posts_per_page' => -1, 'lang' => $language] + $args);

        $sum = array_sum(array_column($result->facets['secteur']['options'] ?? [], 'count'));

        expect($result->total())->toBe(count($mysql->posts))
            // Every post has one sector: the sector counts add up to the total
            ->and($sum)->toBe(count($mysql->posts));
    });
})->with(['fr', 'en']);

test('an indexable view points to its indexable translation (hreflang), with its terms translated', function () {
    $html = languageRequest($this->baseFr.'type-'.$this->fr->slug.'/')['html'];

    // Polylang writes href then hreflang, WPML the other way
    $alternate = fn (string $url, string $language) => '#<link rel="alternate" (href="'.preg_quote($url, '#').'" hreflang="'.$language.'"|hreflang="'.$language.'" href="'.preg_quote($url, '#').'")#';

    expect($html)->toMatch($alternate($this->baseEn.'kind-'.$this->en->slug.'/', 'en'))
        ->and($html)->toMatch($alternate($this->baseFr.'type-'.$this->fr->slug.'/', 'fr'));
});

test('a view that may not be indexed has no hreflang', function () {
    expect(languageRequest($this->baseFr.'?sort=prix')['html'])->not->toContain('hreflang=');
});

test('a rule is copied to the other language, its terms translated', function () {
    // A term the demo has no rule for
    $fr = $this->adapter->findTerm('site-e-commerce', 'project_type');
    $en = $fr === null ? null : $this->adapter->translateTerm($fr, 'en');
    if ($en === null) {
        $this->markTestSkipped('Needs site-e-commerce and its translation.');
    }

    $rule = SeoRules::save(new SeoRule('realisations', 'fr_FR', 'type='.$fr->term_id, 2, 'Copie de test'));

    try {
        $report = SeoRulesService::duplicate((int) $rule->id);
        $copy = SeoRules::find('realisations', 'en_US', ['type='.$en->term_id]);

        expect($report['created'])->toBe(['en_US'])
            ->and($copy?->title)->toBe('Copie de test');
    } finally {
        foreach (SeoRules::all('realisations') as $saved) {
            if ($saved->title === 'Copie de test') {
                SeoRules::delete((int) $saved->id);
            }
        }
    }
});

test('a listing block copied into a translation is the same listing, each language its own block', function () {
    $saved = BlockListings::saved();
    $entry = null;
    foreach ($saved as $candidate) {
        if (count($candidate['posts'] ?? []) > 1) {
            $entry = $candidate;
        }
    }

    if ($entry === null) {
        $this->markTestSkipped('No listing block translated on this site.');
    }

    expect($entry['posts'][$this->adapter->default()])->toBe($entry['post']);
});
