<?php

declare(strict_types=1);

use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Render\Plan;
use Pollora\MeiliScout\Suggest\Suggest;

/*
 * A suggest field's markup on the demo site: a real search form, a combobox,
 * a plan the client replays.
 */

beforeEach(function () {
    SuggestRegistry::declare('field-test', [
        'sources' => [
            'posts' => ['post_types' => ['post'], 'label' => 'Posts'],
            'topics' => ['type' => 'terms', 'taxonomies' => ['category'], 'label' => 'Topics'],
        ],
        'results_url' => home_url('/'),
    ]);
});

afterEach(fn () => SuggestRegistry::remove('field-test'));

test('a field is a search form with a combobox over a list box', function () {
    $html = Suggest::render('field-test', ['value' => 'a "quoted" <b>query</b>']);

    expect($html)->toContain('role="search"')
        ->toContain('method="get"')
        ->toContain('name="s"')
        ->toContain('role="combobox"')
        ->toContain('aria-autocomplete="list"')
        ->toContain('role="listbox"')
        ->toContain('role="status"')
        // Controlled by the context: a re-render never empties what was typed
        ->toContain('data-wp-bind--value="context.query"')
        // What was typed is escaped, in the value and in the context
        ->not->toContain('<b>query</b>')
        // One group per source, each with its heading
        ->toContain('Posts')->toContain('Topics');
});

test('a field has a phone sheet, and recent searches unless it turns them off', function () {
    $html = Suggest::render('field-test');

    expect($html)->toContain('meiliscout-suggest__layer')
        ->toContain('data-wp-class--is-sheet="context.sheet"')
        ->toContain('actions.closeSheet')
        ->toContain('meiliscout-suggest__group--recent')
        ->toContain('actions.forget')
        ->toContain('"mobileLimit":3')
        ->toContain('"recentEnabled":true');

    SuggestRegistry::declare('field-test', ['sources' => ['posts' => ['post_types' => ['post']]], 'recent' => false]);
    $off = Suggest::render('field-test');

    expect($off)->not->toContain('meiliscout-suggest__group--recent')
        ->not->toContain('actions.forget')
        ->toContain('"recentEnabled":false');
});

test('the plan carries one search per source, with a filter of its own', function () {
    $plan = Plan::forDefinition(SuggestRegistry::get('field-test'));

    expect($plan['version'])->toBe(1)
        ->and($plan['sources'])->toHaveCount(2)
        ->and($plan['sources'][0]['search']['filter'])->toBe('post_type IN ["post"]')
        ->and($plan['sources'][0]['search']['attributesToRetrieve'])->toContain('thumbnail')->not->toContain('post_content')
        ->and($plan['sources'][1]['search']['filter'])->toBe('taxonomy IN ["category"]')
        ->and($plan['sources'][1]['search']['attributesToHighlight'])->toBe(['name']);
});

test('a field that cannot be served says why to an administrator, and nothing to a visitor', function () {
    SuggestRegistry::declare('broken-test', ['sources' => []]);

    wp_set_current_user(0);
    expect(Suggest::render('broken-test'))->toBe('')
        ->and(Suggest::render('absent-test'))->toBe('');

    wp_set_current_user(1);
    expect(Suggest::render('broken-test'))->toContain('sources is required');

    SuggestRegistry::remove('broken-test');
    wp_set_current_user(0);
});
