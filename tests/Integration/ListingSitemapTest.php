<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use Pollora\MeiliScout\Listings\Seo\SeoRule;
use Pollora\MeiliScout\Listings\Seo\SeoRules;
use Pollora\MeiliScout\Listings\Seo\Sitemap\SitemapEntries;
use Pollora\MeiliScout\Listings\Seo\Sitemap\Sitemaps;

/*
 * The listings' views in the sitemaps (design §8.6): which views are listed,
 * and each SEO plugin's index naming them, over HTTP (the plugin turned on in
 * the site's options, its rewrite rules built again by the first request).
 */

beforeEach(function () {
    if (ListingsServiceProvider::unavailable() !== null || ! DefinitionRegistry::has('realisations')) {
        $this->markTestSkipped('Needs the listings module on and the demo\'s listings.');
    }

    SeoRules::install();
    $this->page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Sitemap test', 'post_name' => 'sitemap-test-'.strtolower(wp_generate_password(6, false, false))]);
    $this->activePlugins = get_option('active_plugins');
    $this->rules = [];
});

afterEach(function () {
    if (! isset($this->activePlugins)) {
        return;
    }

    foreach ($this->rules as $rule) {
        SeoRules::delete((int) $rule->id);
    }
    wp_delete_post($this->page, true);
    DefinitionRegistry::remove('sitemap-test');
    update_option('active_plugins', $this->activePlugins);
    delete_option('rewrite_rules');
    SitemapEntries::forget();
});

/**
 * The two most used categories and tags with a post in both.
 *
 * @return array{0: WP_Term, 1: WP_Term}
 */
function sitemapTerms(): array
{
    $posts = get_posts(['post_type' => 'post', 'posts_per_page' => -1, 'fields' => 'ids', 'use_meilisearch' => false, 'tag__exists' => true]);

    foreach ($posts as $post) {
        $categories = wp_get_post_terms($post, 'category');
        $tags = wp_get_post_terms($post, 'post_tag');
        if ($categories !== [] && $tags !== []) {
            return [$categories[0], $tags[0]];
        }
    }

    return [null, null];
}

test('each term of a facet of the path with enough results, and the views of two facets a rule names', function () {
    [$category, $tag] = sitemapTerms();
    if ($category === null) {
        $this->markTestSkipped('Needs a post with a category and a tag.');
    }

    DefinitionRegistry::declare('sitemap-test', [
        'post_types' => ['post'],
        'route' => ['page' => $this->page],
        'seo' => ['min_results' => 1],
        'facets' => [
            'cat' => ['source' => 'taxonomy:category', 'path' => 'cat', 'hierarchy' => 'flat', 'param' => 'rubrique'],
            'tag' => ['source' => 'taxonomy:post_tag', 'path' => 'tag', 'param' => 'mot'],
        ],
    ]);
    $this->rules[] = SeoRules::save(new SeoRule('sitemap-test', '', 'cat='.$category->term_id.'|tag='.$tag->term_id, 0, 'Both'));
    $this->rules[] = SeoRules::save(new SeoRule('sitemap-test', '', 'cat='.$category->term_id.'|tag=*', 0, 'Any tag'));

    $base = Listings::baseUrl(DefinitionRegistry::get('sitemap-test'));
    $locs = array_column(array_filter(SitemapEntries::build(), fn (array $entry) => $entry['listing'] === 'sitemap-test'), 'loc');

    expect($locs)->toContain($base.'cat-'.$category->slug.'/')
        ->and($locs)->toContain($base.'tag-'.$tag->slug.'/')
        ->and($locs)->toContain($base.'cat-'.$category->slug.'/tag-'.$tag->slug.'/')
        // The listing's first page is a page of the site, in its own sitemap
        ->and($locs)->not->toContain($base)
        ->and(count($locs))->toBe(count(array_unique($locs)));

    // Two terms nobody named: no view of two facets
    foreach ($locs as $loc) {
        if (str_contains($loc, '/tag-') && str_contains($loc, '/cat-')) {
            expect($loc)->toStartWith($base.'cat-'.$category->slug.'/');
        }
    }
});

test('a listing whose SEO is off, or a site closed to search engines, lists nothing', function () {
    DefinitionRegistry::declare('sitemap-test', [
        'post_types' => ['post'],
        'route' => ['page' => $this->page],
        'seo' => false,
        'facets' => ['cat' => ['source' => 'taxonomy:category', 'path' => 'cat', 'param' => 'rubrique']],
    ]);

    expect(array_filter(SitemapEntries::build(), fn (array $entry) => $entry['listing'] === 'sitemap-test'))->toBe([]);

    add_filter('pre_option_blog_public', '__return_zero');
    try {
        expect(SitemapEntries::build())->toBe([]);
    } finally {
        remove_filter('pre_option_blog_public', '__return_zero');
    }
});

test('each SEO plugin\'s sitemap index names the listings\' views', function (?string $plugin, string $index) {
    if ($plugin !== null) {
        if (! is_file(WP_PLUGIN_DIR.'/'.$plugin)) {
            $this->markTestSkipped($plugin.' is not installed.');
        }
        update_option('active_plugins', array_values(array_unique([...(array) $this->activePlugins, $plugin])));
    }
    // Built again by the next request, with the plugin's rules
    delete_option('rewrite_rules');

    $get = fn (string $url) => wp_remote_get($url, ['sslverify' => false, 'timeout' => 30]);
    $get(home_url('/'));
    $xml = (string) wp_remote_retrieve_body($get(home_url($index)));

    preg_match_all('#<loc>(?:<!\[CDATA\[)?([^<\]]*(?:meiliscout-listing|wp-sitemap-listings)[^<\]]*)#', $xml, $sitemaps);
    expect($sitemaps[1])->not->toBeEmpty($plugin ?? 'WordPress');

    $urls = '';
    foreach ($sitemaps[1] as $sitemap) {
        $urls .= (string) wp_remote_retrieve_body($get($sitemap));
    }

    $definition = DefinitionRegistry::get('realisations');
    expect($urls)->toContain(Listings::baseUrl($definition).$definition->facet('type')->prefix().'-');
})->with([
    'WordPress' => [null, '/wp-sitemap.xml'],
    'Yoast SEO' => ['wordpress-seo/wp-seo.php', '/sitemap_index.xml'],
    'Rank Math' => ['seo-by-rank-math/rank-math.php', '/sitemap_index.xml'],
    'SEOPress' => ['wp-seopress/seopress.php', '/sitemaps.xml'],
    'All in One SEO' => ['all-in-one-seo-pack/all_in_one_seo_pack.php', '/sitemap.xml'],
]);

test('MeiliScout\'s own sitemap answers whatever the plugin', function () {
    $response = wp_remote_get(Sitemaps::url(), ['sslverify' => false, 'timeout' => 30]);

    expect(wp_remote_retrieve_response_code($response))->toBe(200)
        ->and(wp_remote_retrieve_header($response, 'content-type'))->toContain('xml')
        ->and(substr_count((string) wp_remote_retrieve_body($response), '<url>'))->toBeGreaterThan(0);
});
