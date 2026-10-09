<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use Pollora\MeiliScout\Listings\Seo\SeoRule;
use Pollora\MeiliScout\Listings\Seo\SeoRules;

/*
 * The SEO of a listing's views with each SEO plugin (design §8.2, §14): the
 * plugin is turned on in the site's options, the pages are read over HTTP,
 * their <head> checked. Needs the demo's realisations-briques listing (a
 * route, a taxonomy facet) and the plugins installed; a missing one is skipped.
 */

const SEO_LISTING = 'realisations-briques';

/**
 * What a page tells search engines.
 *
 * @return array{status: int, robots: list<string>, canonical: list<string>, prev: list<string>, next: list<string>, title: string, description: list<string>, types: list<string>}
 */
function seoHead(string $url): array
{
    $response = wp_remote_get($url, ['sslverify' => false, 'redirection' => 0, 'timeout' => 30]);
    $html = (string) wp_remote_retrieve_body($response);
    $head = preg_match('#<head.*?</head>#is', $html, $m) ? $m[0] : '';

    $attribute = function (string $pattern) use ($head): array {
        preg_match_all($pattern, $head, $matches);

        return array_map(fn (string $value) => html_entity_decode($value, ENT_QUOTES), $matches[1]);
    };
    preg_match_all('#"@type":"(ItemList|BreadcrumbList|FAQPage)"#', $html, $types);

    return [
        'status' => (int) wp_remote_retrieve_response_code($response),
        'robots' => $attribute('#<meta[^>]+name=["\']robots["\'][^>]*content=["\']([^"\']*)#i'),
        'canonical' => $attribute('#<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']*)#i'),
        'prev' => $attribute('#<link[^>]+rel=["\']prev["\'][^>]*href=["\']([^"\']*)#i'),
        'next' => $attribute('#<link[^>]+rel=["\']next["\'][^>]*href=["\']([^"\']*)#i'),
        'title' => preg_match('#<title>([^<]*)#i', $head, $t) ? html_entity_decode($t[1], ENT_QUOTES) : '',
        'description' => $attribute('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)#i'),
        'types' => array_values(array_unique($types[1])),
    ];
}

beforeEach(function () {
    if (ListingsServiceProvider::unavailable() !== null || ! DefinitionRegistry::has(SEO_LISTING)) {
        $this->markTestSkipped('Needs the listings module on and the demo\'s '.SEO_LISTING.' listing.');
    }

    $this->activePlugins = get_option('active_plugins');
    SeoRules::install();
    $this->previousRule = SeoRules::find(SEO_LISTING, '', ['']);
    $this->rule = SeoRules::save(new SeoRule(SEO_LISTING, '', '', 0, 'SEO plugins test – {site}', 'The {total} projects of the test.', id: $this->previousRule?->id));
});

afterEach(function () {
    if (! isset($this->activePlugins)) {
        return;
    }

    update_option('active_plugins', $this->activePlugins);
    $this->previousRule !== null ? SeoRules::save($this->previousRule) : SeoRules::delete((int) $this->rule->id);
});

test('each SEO plugin is told the view: indexable pages with their canonical and adjacent pages, others in noindex, follow', function (?string $plugin) {
    if ($plugin !== null) {
        if (! is_file(WP_PLUGIN_DIR.'/'.$plugin)) {
            $this->markTestSkipped($plugin.' is not installed.');
        }
        // In the site's options only: the plugin runs in the requests, not in the tests
        update_option('active_plugins', array_values(array_unique([...(array) $this->activePlugins, $plugin])));
    }

    $definition = DefinitionRegistry::get(SEO_LISTING);
    $base = Listings::baseUrl($definition);
    $term = get_terms(['taxonomy' => 'project_type', 'number' => 1, 'hide_empty' => true, 'use_meilisearch' => false])[0];
    $param = $definition->facet('type')->param ?? 'type';

    $page2 = seoHead($base.'page/2/');
    expect($page2['status'])->toBe(200)
        ->and($page2['robots'])->toHaveCount(1)
        ->and($page2['robots'][0])->not->toContain('noindex')
        ->and($page2['robots'][0])->not->toContain('nofollow')
        ->and($page2['canonical'])->toBe([$base.'page/2/'])
        ->and($page2['prev'])->toBe([$base])
        ->and($page2['next'])->toBe([$base.'page/3/'])
        ->and($page2['title'])->toStartWith('SEO plugins test – ')
        ->and($page2['title'])->toContain('2')
        ->and($page2['description'])->toHaveCount(1)
        ->and($page2['description'][0])->toMatch('/^The \d+ projects of the test\.$/')
        ->and($page2['types'])->toContain('ItemList');

    $filtered = seoHead($base.'?'.$param.'='.$term->slug);
    expect($filtered['status'])->toBe(200)
        ->and($filtered['robots'])->toHaveCount(1)
        ->and($filtered['robots'][0])->toContain('noindex')
        ->and($filtered['robots'][0])->not->toContain('nofollow')
        ->and($filtered['canonical'])->toBe([])
        ->and($filtered['prev'])->toBe([])
        ->and($filtered['next'])->toBe([])
        ->and($filtered['types'])->not->toContain('ItemList');

    expect(seoHead($base.'page/999/')['status'])->toBe(404);
})->with([
    'WordPress alone' => [null],
    'Yoast SEO' => ['wordpress-seo/wp-seo.php'],
    'Rank Math' => ['seo-by-rank-math/rank-math.php'],
    'SEOPress' => ['wp-seopress/seopress.php'],
    'All in One SEO' => ['all-in-one-seo-pack/all_in_one_seo_pack.php'],
]);
