<?php

declare(strict_types=1);

use Pollora\MeiliScout\Query\SearchMemo;
use Pollora\MeiliScout\Search\SearchWeights;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Render\Plan;

/*
 * Weights against a real Meilisearch: a heavier title ranks title matches
 * first, a heavier content ranks the others, counts do not move, and a
 * search that is not by relevance is left alone.
 */

// What the REST test below saves: the site's own selection comes back afterwards
const WEIGHTS_TEST_OPTIONS = ['indexed_post_types', 'indexed_taxonomies', 'indexed_meta_keys', 'indexed_term_meta_keys', 'searchable_attributes'];

beforeEach(function () {
    $GLOBALS['weights_test_options'] = array_map(static fn (string $name) => get_option('meiliscout/'.$name, null), array_combine(WEIGHTS_TEST_OPTIONS, WEIGHTS_TEST_OPTIONS));
    // A visitor: an administrator's default statuses include private posts, which MySQL serves
    wp_set_current_user(0);
    delete_option('meiliscout/'.SearchWeights::SETTING);
});

afterEach(function () {
    foreach ($GLOBALS['weights_test_options'] as $name => $value) {
        $value === null ? delete_option('meiliscout/'.$name) : update_option('meiliscout/'.$name, $value);
    }
    wp_set_current_user(0);
    delete_option('meiliscout/'.SearchWeights::SETTING);
    SearchMemo::reset();
});

function weighted(array $weights, array $args = []): WP_Query
{
    SearchMemo::reset();
    SearchWeights::save(['post' => $weights]);

    return new WP_Query([
        's' => 'woocommerce', 'post_type' => 'post', 'use_meilisearch' => true,
        'posts_per_page' => 5, 'suppress_filters' => false, ...$args,
    ]);
}

test('the weights decide which matches rank first', function () {
    $title = weighted(['post_title' => 5, 'content_text' => 1]);
    $content = weighted(['post_title' => 1, 'content_text' => 5]);

    expect($title->meiliscout['served'])->toBeTrue()
        ->and($title->meiliscout['weighted'])->toBe(2)
        ->and($title->posts[0]->post_title)->toContain('WooCommerce')
        ->and(wp_list_pluck($title->posts, 'ID'))->not->toBe(wp_list_pluck($content->posts, 'ID'))
        // The posts with the word in their title lead in one and not in the other
        ->and(str_contains(mb_strtolower($content->posts[0]->post_title), 'woocommerce'))->toBeFalse();
});

test('weighting changes the order, not the number of results', function () {
    $a = weighted(['post_title' => 5, 'content_text' => 1]);
    $b = weighted(['post_title' => 1, 'content_text' => 5]);

    expect($a->found_posts)->toBe($b->found_posts)->and($a->found_posts)->toBeGreaterThan(5);
});

test('a field with weight 0 is not searched, in the hits and in the count', function () {
    $both = weighted(['post_title' => 3, 'content_text' => 1]);
    $titleOnly = weighted(['post_title' => 3, 'content_text' => 0]);

    expect($titleOnly->found_posts)->toBeLessThan($both->found_posts)
        // One weight left: nothing to merge, an ordinary search on the title
        ->and($titleOnly->meiliscout)->not->toHaveKey('weighted');
});

test('a query sorted by something other than relevance is not weighted', function () {
    $query = weighted(['post_title' => 5, 'content_text' => 1], ['orderby' => 'date', 'order' => 'DESC']);

    expect($query->meiliscout['served'])->toBeTrue()->and($query->meiliscout)->not->toHaveKey('weighted');
});

test('nothing is weighted until the admin sets weights', function () {
    SearchMemo::reset();
    $query = new WP_Query(['s' => 'woocommerce', 'post_type' => 'post', 'use_meilisearch' => true, 'suppress_filters' => false]);

    expect($query->meiliscout['served'])->toBeTrue()->and($query->meiliscout)->not->toHaveKey('weighted');
});

test('the preview ranks with weights that are not saved, and says what ranked each', function () {
    wp_set_current_user(1);
    $request = new WP_REST_Request('POST', '/meiliscout/v1/content/weights/preview');
    $request->set_body_params(['type' => 'post', 'q' => 'woocommerce', 'weights' => ['post_title' => 6, 'content_text' => 1]]);
    $hits = rest_do_request($request)->get_data()['hits'];

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]['fields'])->toBe(['post_title'])
        ->and($hits[0]['weight'])->toBe(6.0)
        ->and($hits[0]['title'])->toContain('WooCommerce')
        // Nothing was saved by asking
        ->and(SearchWeights::isActive())->toBeFalse();
});

test('weights are saved through the content route, and cleared with an empty list', function () {
    wp_set_current_user(1);
    $save = new WP_REST_Request('POST', '/meiliscout/v1/content');
    $save->set_body_params(['post_types' => ['post'], 'taxonomies' => [], 'meta_keys' => [], 'weights' => ['post' => ['post_title' => 7, 'content_text' => 2]]]);
    $payload = rest_do_request($save)->get_data();

    expect(SearchWeights::configured())->toBe(['post' => ['post_title' => 7.0, 'content_text' => 2.0]])
        ->and($payload['weights']['configured'])->toBeTrue();

    $clear = new WP_REST_Request('POST', '/meiliscout/v1/content');
    $clear->set_body_params(['post_types' => ['post'], 'taxonomies' => [], 'meta_keys' => [], 'weights' => []]);
    rest_do_request($clear);

    expect(SearchWeights::isActive())->toBeFalse();
});

test('a suggest field sends one search per weight for its posts, and one for its terms', function () {
    SearchWeights::save(['post' => ['post_title' => 6, 'content_text' => 1]]);
    SuggestRegistry::declare('weights-test', ['sources' => [
        'posts' => ['post_types' => ['post'], 'limit' => 4],
        'topics' => ['type' => 'terms', 'taxonomies' => ['category'], 'limit' => 2],
    ], 'results_url' => home_url('/')]);

    try {
        $sources = Plan::forDefinition(SuggestRegistry::get('weights-test'))['sources'];

        expect($sources[0]['searches'])->toHaveCount(2)
            ->and($sources[0]['searches'][0]['weight'])->toBe(6.0)
            ->and($sources[0]['searches'][0]['search']['attributesToSearchOn'])->toBe(['post_title'])
            ->and($sources[0]['searches'][0]['search']['showRankingScore'])->toBeTrue()
            ->and($sources[1])->not->toHaveKey('searches')
            ->and(Plan::searches($sources[0]))->toHaveCount(2)
            ->and(Plan::searches($sources[1]))->toHaveCount(1);
    } finally {
        SuggestRegistry::remove('weights-test');
    }
})->group('integration');
