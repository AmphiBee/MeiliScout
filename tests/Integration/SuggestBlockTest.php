<?php

declare(strict_types=1);

use Pollora\MeiliScout\Suggest\Blocks\BlockSuggests;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;

/*
 * The editor's ways to a field: the meiliscout/suggest block, and the core
 * Search block naming a field declared in PHP.
 */

afterEach(function () {
    foreach (SuggestRegistry::ids() as $id) {
        if (str_starts_with($id, 'block-') || $id === 'named-test') {
            SuggestRegistry::remove($id);
        }
    }
});

test('a block\'s attributes make a definition, and two blocks alike are one field', function () {
    $attributes = ['postTypes' => ['post'], 'taxonomies' => ['category'], 'postsLimit' => 4, 'termsLimit' => 2, 'recent' => false, 'resultsUrl' => '/search/'];
    $args = BlockSuggests::args($attributes);

    expect($args['sources']['posts'])->toBe(['post_types' => ['post'], 'limit' => 4])
        ->and($args['sources']['terms'])->toBe(['type' => 'terms', 'taxonomies' => ['category'], 'limit' => 2])
        ->and($args['recent'])->toBeFalse()
        ->and($args['results_url'])->toBe('/search/')
        ->and(BlockSuggests::id($args))->toBe(BlockSuggests::id(BlockSuggests::args($attributes)))
        ->and(BlockSuggests::id($args))->not->toBe(BlockSuggests::id(BlockSuggests::args(['postTypes' => ['page']])))
        ->and(BlockSuggests::id($args))->toMatch('/^block-[0-9a-f]{12}$/');
});

test('the block renders the field in its wrapper, declared for this request', function () {
    $html = render_block(['blockName' => 'meiliscout/suggest', 'attrs' => ['postTypes' => ['post'], 'placeholder' => 'Find a post'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);

    expect($html)->toContain('wp-block-meiliscout-suggest')
        ->toContain('data-wp-interactive="meiliscout/suggest"')
        ->toContain('placeholder="Find a post"')
        ->toContain('role="combobox"');
});

test('saving a post keeps its blocks\' fields, so that another request knows them', function () {
    $id = wp_insert_post([
        'post_title' => 'Suggest block test',
        'post_status' => 'publish',
        'post_content' => '<!-- wp:meiliscout/suggest {"postTypes":["post"],"postsLimit":3} /-->',
    ]);
    $args = BlockSuggests::args(['postTypes' => ['post'], 'postsLimit' => 3]);
    $field = BlockSuggests::id($args);

    SuggestRegistry::reset();
    BlockSuggests::declareSaved();

    expect(SuggestRegistry::has($field))->toBeTrue()
        ->and(SuggestRegistry::get($field)->sources['posts']->limit)->toBe(3);

    wp_delete_post($id, true);
    SuggestRegistry::reset();
    BlockSuggests::declareSaved();

    expect(SuggestRegistry::has($field))->toBeFalse();
});

test('the core Search block becomes the field it names, and is left alone otherwise', function () {
    SuggestRegistry::declare('named-test', ['sources' => ['posts' => ['post_types' => ['post']]]]);
    $search = fn (string $name) => render_block(['blockName' => 'core/search', 'attrs' => ['meiliscoutSuggest' => $name, 'label' => 'Look up', 'placeholder' => 'Type here'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);

    $field = $search('named-test');

    expect($field)->toContain('data-wp-interactive="meiliscout/suggest"')
        ->toContain('placeholder="Type here"')
        ->toContain('>Look up<')
        ->toContain('wp-block-search');

    // Not declared: the core block as it is
    expect($search('absent-test'))->toContain('wp-block-search__input')->not->toContain('meiliscout/suggest');
});

test('the editor is offered the indexed content and the fields declared in PHP', function () {
    SuggestRegistry::declare('named-test', ['sources' => ['posts' => ['post_types' => ['post']]]]);
    SuggestRegistry::declare('broken-test', ['sources' => []]);
    wp_set_current_user(1);

    $data = rest_do_request(new WP_REST_Request('GET', '/meiliscout/v1/suggest/editor'))->get_data();

    expect(array_column($data['postTypes'], 'name'))->toContain('post')
        ->and(array_column($data['taxonomies'], 'name'))->toContain('category')
        ->and($data['suggests'])->toContain('named-test')->not->toContain('broken-test');

    wp_set_current_user(0);
    expect(rest_do_request(new WP_REST_Request('GET', '/meiliscout/v1/suggest/editor'))->get_status())->toBeGreaterThanOrEqual(401);
    SuggestRegistry::remove('broken-test');
});
