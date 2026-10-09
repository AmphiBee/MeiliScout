<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\ListingsServiceProvider;

/*
 * Facets in the path on the demo site, over HTTP (the site's own requests
 * parse the path): a page holding a listing block whose project_type facet
 * goes in the path (prefix tp), and a child page whose slug starts with it.
 */

/**
 * @return array{status: int, location: string, html: string, robots: string, canonical: string}
 */
function pathRequest(string $url): array
{
    $response = wp_remote_get($url, ['sslverify' => false, 'redirection' => 0, 'timeout' => 30]);
    $html = (string) wp_remote_retrieve_body($response);

    return [
        'status' => (int) wp_remote_retrieve_response_code($response),
        'location' => (string) wp_remote_retrieve_header($response, 'location'),
        'html' => $html,
        'robots' => preg_match('#<meta[^>]+name=["\']robots["\'][^>]*content=["\']([^"\']*)#i', $html, $m) ? $m[1] : '',
        'canonical' => preg_match('#<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']*)#i', $html, $m) ? $m[1] : '',
    ];
}

beforeEach(function () {
    if (ListingsServiceProvider::unavailable() !== null || ! taxonomy_exists('project_type')) {
        $this->markTestSkipped('Needs the listings module on and the demo\'s realisations.');
    }

    $slug = 'path-test-'.strtolower(wp_generate_password(6, false, false));
    $this->page = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Path facets test',
        'post_name' => $slug,
        'post_content' => wp_slash('<!-- wp:meiliscout/listing {"listingId":"'.$slug.'","queryId":778,"postTypes":["realisation"],"perPage":4} -->'
            .'<!-- wp:meiliscout/facet {"source":"taxonomy:project_type","label":"Type","path":"tp"} /-->'
            .'<!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template -->'
            .'<!-- wp:query-pagination --><!-- wp:query-pagination-numbers /--><!-- /wp:query-pagination -->'
            .'<!-- /wp:meiliscout/listing -->'),
    ]);
    $this->child = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Child', 'post_name' => 'tp-child', 'post_parent' => $this->page, 'post_content' => 'The child page.']);
    $this->base = (string) get_permalink($this->page);

    $terms = get_terms(['taxonomy' => 'project_type', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'use_meilisearch' => false]);
    [$this->a, $this->b] = $terms;
});

afterEach(function () {
    foreach (['child', 'page'] as $post) {
        if (isset($this->{$post})) {
            wp_delete_post($this->{$post}, true);
        }
    }
});

test('a term in the path is an indexable view, canonical to itself', function () {
    $view = pathRequest($this->base.'tp-'.$this->a->slug.'/');

    expect($view['status'])->toBe(200)
        ->and($view['robots'])->not->toContain('noindex')
        ->and($view['canonical'])->toBe($this->base.'tp-'.$this->a->slug.'/')
        ->and(substr_count($view['html'], 'class="wp-block-post '))->toBe(min(4, $this->a->count));
});

test('other forms of a state redirect to its canonical URL', function (callable $from, callable $to) {
    $response = pathRequest($from($this));

    expect($response['status'])->toBe(301)
        ->and($response['location'])->toBe($to($this));
})->with([
    'a parameter' => [fn ($t) => $t->base.'?type='.$t->a->slug, fn ($t) => $t->base.'tp-'.$t->a->slug.'/'],
    'values unsorted' => [
        fn ($t) => $t->base.'tp-'.max($t->a->slug, $t->b->slug).','.min($t->a->slug, $t->b->slug).'/',
        fn ($t) => $t->base.'tp-'.min($t->a->slug, $t->b->slug).','.max($t->a->slug, $t->b->slug).'/',
    ],
    'the page first' => [fn ($t) => $t->base.'page/2/tp-'.$t->a->slug.'/', fn ($t) => $t->base.'tp-'.$t->a->slug.'/page/2/'],
]);

test('two values are a view search engines do not index', function () {
    $values = [$this->a->slug, $this->b->slug];
    sort($values);
    $view = pathRequest($this->base.'tp-'.implode(',', $values).'/');

    expect($view['status'])->toBe(200)
        ->and($view['robots'])->toContain('noindex')
        ->and($view['canonical'])->toBe('');
});

test('a value that is no term is a 404', function () {
    expect(pathRequest($this->base.'tp-no-such-term/')['status'])->toBe(404);
});

test('a child page whose slug starts with the prefix stays the page', function () {
    $child = pathRequest((string) get_permalink($this->child));

    expect($child['status'])->toBe(200)
        ->and($child['html'])->toContain('The child page.');
});

test('a value leading to an indexable view is a link to it', function () {
    expect(pathRequest($this->base)['html'])->toContain('href="'.$this->base.'tp-'.$this->a->slug.'/"');
});
