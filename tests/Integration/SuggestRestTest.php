<?php

declare(strict_types=1);

use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Suggest;

/*
 * The REST transport: WordPress runs a field's searches for a Meilisearch
 * no browser can reach, with the same limits a token puts on the browser's.
 */

beforeEach(function () {
    SuggestRegistry::declare('rest-test', [
        'transport' => 'rest',
        'sources' => [
            'posts' => ['post_types' => ['post'], 'limit' => 3],
            'topics' => ['type' => 'terms', 'taxonomies' => ['category'], 'limit' => 2],
        ],
    ]);
    SuggestRegistry::declare('direct-test', ['sources' => ['posts' => ['post_types' => ['post']]]]);
});

afterEach(function () {
    SuggestRegistry::remove('rest-test');
    SuggestRegistry::remove('direct-test');
});

function suggestRest(string $id, string $q): WP_REST_Response
{
    $request = new WP_REST_Request('GET', "/meiliscout/v1/suggest/{$id}/search");
    $request->set_param('q', $q);

    return rest_do_request($request);
}

test('the answers have the shape of Meilisearch\'s multi-search, with the typed part marked', function () {
    $response = suggestRest('rest-test', 'web');
    $results = $response->get_data()['results'];

    expect($response->get_status())->toBe(200)
        ->and($results)->toHaveCount(2)
        ->and(count($results[0]['hits']))->toBeLessThanOrEqual(3)
        ->and(count($results[1]['hits']))->toBeLessThanOrEqual(2)
        ->and($response->get_headers()['Cache-Control'])->toBe('public, max-age=60');

    $titles = array_column(array_column($results[0]['hits'], '_formatted'), 'post_title');
    expect($titles)->not->toBeEmpty()
        ->and(implode('', $titles))->toContain("\u{E000}");

    foreach ($results[0]['hits'] as $hit) {
        expect($hit['post_type'])->toBe('post')->and($hit)->not->toHaveKey('post_content')->not->toHaveKey('post_author');
    }

    foreach ($results[1]['hits'] as $hit) {
        expect($hit['taxonomy'])->toBe('category')->and($hit)->not->toHaveKey('metas');
    }
});

test('a field that searches Meilisearch directly has no search endpoint, and an unknown one neither', function () {
    expect(suggestRest('direct-test', 'web')->get_status())->toBe(404)
        ->and(suggestRest('absent-test', 'web')->get_status())->toBe(404);
});

test('a very long query is cut', function () {
    expect(suggestRest('rest-test', str_repeat('a', 5000))->get_status())->toBe(200);
});

test('a REST field is printed without a token, and tells the client where to ask', function () {
    $html = Suggest::render('rest-test');

    expect($html)->toContain('"transport":"rest"')->toContain('suggest\/rest-test\/search')
        ->not->toContain('eyJ');
});
