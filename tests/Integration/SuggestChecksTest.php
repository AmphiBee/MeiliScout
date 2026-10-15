<?php

declare(strict_types=1);

use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Diagnostics\SuggestChecks;
use Pollora\MeiliScout\Suggest\Transport\SuggestKey;

/*
 * What the Suggestions screen and `wp meiliscout check-suggest` say.
 */

beforeEach(function () {
    SuggestRegistry::declare('checks-test', [
        'sources' => [
            'posts' => ['post_types' => ['post'], 'template' => 'js:checks-hit'],
            'topics' => ['type' => 'terms', 'taxonomies' => ['category']],
        ],
    ]);
});

afterEach(function () {
    SuggestRegistry::remove('checks-test');
    SuggestRegistry::remove('checks-broken');
    SuggestRegistry::remove('checks-rest');
    wp_set_current_user(0);
});

function suggestCheckCodes(array $report): array
{
    return array_column($report['checks'], 'code');
}

test('a working field reads documents, and a JavaScript template is said to need the theme', function () {
    $report = SuggestChecks::report('checks-test');

    expect($report['valid'])->toBeTrue()
        ->and($report['source'])->toBe('php')
        ->and($report['sources'][0])->toMatchArray(['key' => 'posts', 'type' => 'posts', 'template' => 'js'])
        ->and($report['sources'][0]['documents'])->toBeGreaterThan(0)
        ->and($report['sources'][1]['documents'])->toBeGreaterThan(0)
        ->and(suggestCheckCodes($report))->toContain('js_template')
        // A search with its token worked: nothing is said about it
        ->not->toContain('token_refused')->not->toContain('token')
        ->and(SuggestChecks::level($report))->not->toBe('error');
});

test('a field that cannot be served says every reason', function () {
    SuggestRegistry::declare('checks-broken', ['sources' => ['posts' => ['post_types' => ['nothing']]], 'min_chars' => 0]);
    $report = SuggestChecks::report('checks-broken');

    expect($report['valid'])->toBeFalse()
        ->and(count($report['checks']))->toBeGreaterThanOrEqual(2)
        ->and(SuggestChecks::level($report))->toBe('error');
});

test('a source with nothing to read is a warning', function () {
    $report = SuggestChecks::report('checks-test', ['post' => 0], ['category' => 0]);

    expect(suggestCheckCodes($report))->toContain('empty_source')
        ->and(SuggestChecks::level($report))->toBe('warning');
});

test('the REST transport needs no token and says it is slower', function () {
    SuggestRegistry::declare('checks-rest', ['transport' => 'rest', 'sources' => ['posts' => ['post_types' => ['post']]]]);

    expect(suggestCheckCodes(SuggestChecks::report('checks-rest')))->toBe(['transport']);
});

test('the screen\'s endpoints are for administrators, and replace the key', function () {
    wp_set_current_user(0);
    expect(rest_do_request(new WP_REST_Request('GET', '/meiliscout/v1/suggest'))->get_status())->toBeGreaterThanOrEqual(401);

    wp_set_current_user(1);
    $data = rest_do_request(new WP_REST_Request('GET', '/meiliscout/v1/suggest'))->get_data();

    expect(array_column($data['suggests'], 'id'))->toContain('checks-test')
        ->and($data)->toHaveKeys(['key', 'public_host', 'effective_host']);

    $before = SuggestKey::saved()['uid'];
    $rotated = rest_do_request(new WP_REST_Request('POST', '/meiliscout/v1/suggest/key'));

    expect($rotated->get_status())->toBe(200)
        ->and($rotated->get_data()['key']['uid'])->not->toBe($before);

    // A public URL that is no URL is refused
    $request = new WP_REST_Request('POST', '/meiliscout/v1/suggest/host');
    $request->set_param('public_host', 'not a url');
    expect(rest_do_request($request)->get_status())->toBe(400);
});
