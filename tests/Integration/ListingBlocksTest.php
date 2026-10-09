<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\Blocks\BlockListings;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use Pollora\MeiliScout\Listings\State\ListingState;

/*
 * The editor mode on the demo site: a page holding a meiliscout/listing block
 * with facets, the core's Post Template and pagination. Needs the module on
 * and realisation / project_type indexed.
 */

function blockListingContent(string $listingId): string
{
    return '<!-- wp:meiliscout/listing {"listingId":"'.$listingId.'","queryId":777,"postTypes":["realisation"],"perPage":4} -->'
        .'<!-- wp:meiliscout/facet {"source":"taxonomy:project_type","label":"Type de projet"} /-->'
        .'<!-- wp:meiliscout/listing-part {"part":"total"} /-->'
        .'<!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template -->'
        .'<!-- wp:query-pagination --><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->'
        .'<!-- /wp:meiliscout/listing -->';
}

beforeEach(function () {
    if (ListingsServiceProvider::unavailable() !== null) {
        $this->markTestSkipped('The listings module is off on this site.');
    }

    Listings::forget();
    $this->listingId = 'test'.wp_generate_password(6, false, false);
    $this->page = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Block listing test',
        'post_content' => wp_slash(blockListingContent(strtolower($this->listingId))),
    ]);
    $this->id = 'block-'.strtolower($this->listingId);
});

afterEach(function () {
    if (isset($this->page)) {
        wp_delete_post($this->page, true);
    }
    unset($_GET['query-777-page']);
});

test('saving the page keeps the listing\'s definition, with the page as its route', function () {
    $saved = BlockListings::saved()[$this->id] ?? null;

    expect($saved)->not->toBeNull()
        ->and($saved['post'])->toBe($this->page)
        ->and($saved['args']['route'])->toBe(['page' => $this->page])
        ->and(array_keys($saved['args']['facets']))->toBe(['project_type'])
        // project_type is a query var: the facet takes its title
        ->and($saved['args']['facets']['project_type']['param'])->toBe('type-de-projet');
});

test('the block renders its Post Template and pagination as router regions, with the listing\'s query and URLs', function () {
    $html = do_blocks(get_post($this->page)->post_content);
    $dom = 'meiliscout-listing-'.$this->id;

    expect($html)->toContain('id="'.$dom.'"')
        ->and($html)->toContain('data-wp-router-region="'.$dom.'"')
        ->and($html)->toContain('data-wp-router-region="'.$dom.'-pagination-1"')
        ->and(substr_count($html, '<form '))->toBe(1)
        ->and(substr_count($html, 'class="wp-block-post '))->toBe(4)
        ->and($html)->toContain('data-wp-on--click="meiliscout/listing::actions.navigate"')
        ->and($html)->toMatch('#href="[^"]+/page/2/"#')
        ->and($html)->not->toContain('query-777-page');
});

test('the fragment renders the block again for a state', function () {
    $term = get_terms(['taxonomy' => 'project_type', 'number' => 1, 'hide_empty' => true, 'use_meilisearch' => false])[0];
    $state = new ListingState(['project_type' => [$term->slug]]);

    $html = (string) BlockListings::fragment($this->id, $state, (string) get_permalink($this->page));
    $expected = min(4, (new WP_Query(['post_type' => 'realisation', 'post_status' => 'publish', 'tax_query' => [['taxonomy' => 'project_type', 'field' => 'slug', 'terms' => [$term->slug]]], 'fields' => 'ids', 'posts_per_page' => -1]))->post_count);

    expect(substr_count($html, 'class="wp-block-post '))->toBe($expected)
        ->and($html)->toContain('data-wp-router-region="meiliscout-listing-'.$this->id.'"');
});

test('deleting the page forgets its listing', function () {
    wp_delete_post($this->page, true);
    $page = $this->page;
    unset($this->page);

    expect(BlockListings::saved())->not->toHaveKey($this->id)
        ->and($page)->toBeInt();
});

test('with the module off, the block prints nothing, not the posts its Post Template would list', function () {
    // false would not short-circuit the option
    add_filter('pre_option_meiliscout/listings_enabled', '__return_zero');

    $html = trim(do_blocks(get_post($this->page)->post_content));

    remove_all_filters('pre_option_meiliscout/listings_enabled');

    expect($html)->toBe('');
});
