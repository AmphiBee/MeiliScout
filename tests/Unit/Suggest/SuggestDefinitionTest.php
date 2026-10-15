<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Suggest;

use Pollora\MeiliScout\Listings\PublicFields;
use Pollora\MeiliScout\Suggest\Definition\InvalidSuggest;
use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Transport\SuggestTokens;

require_once dirname(__DIR__).'/Listings/wordpress.php';

beforeEach(function () {
    $GLOBALS['listings_wordpress'] = true;
    $GLOBALS['wp_options'] = [
        'meiliscout/indexed_post_types' => ['post', 'page'],
        'meiliscout/indexed_taxonomies' => ['category'],
        'meiliscout/indexed_meta_keys' => ['price'],
    ];
    SuggestRegistry::reset();
});

afterEach(function () {
    $GLOBALS['listings_wordpress'] = false;
    SuggestRegistry::reset();
});

/**
 * @return list<string>
 */
function suggestErrors(array $args, string $id = 'site'): array
{
    try {
        SuggestDefinition::fromArray($id, $args);
    } catch (InvalidSuggest $e) {
        return $e->errors;
    }

    return [];
}

test('a definition has defaults for what it leaves out', function () {
    $definition = SuggestDefinition::fromArray('site', ['sources' => ['posts' => ['post_types' => ['post']], 'topics' => ['type' => 'terms', 'taxonomies' => ['category']]]]);

    expect($definition->minChars)->toBe(2)
        ->and($definition->debounce)->toBe(120)
        ->and($definition->searchParam)->toBe('s')
        ->and($definition->resultsUrl)->toBeNull()
        ->and($definition->recent)->toBeTrue()
        ->and(array_keys($definition->sources))->toBe(['posts', 'topics'])
        ->and($definition->sources['posts']->limit)->toBe(5)
        ->and($definition->sources['topics']->limit)->toBe(3)
        ->and($definition->postTypes())->toBe(['post'])
        ->and($definition->taxonomies())->toBe(['category']);
});

test('every error is given at once', function () {
    $errors = suggestErrors([
        'sources' => [
            'posts' => ['post_types' => ['post', 'product'], 'limit' => 50],
            'Bad Key' => ['post_types' => ['post']],
            'topics' => ['type' => 'terms', 'taxonomies' => ['genre'], 'template' => 12, 'fields' => ['name', 'term_meta']],
        ],
        'min_chars' => 0,
        'public_metas' => ['price', 'secret'],
        'colour' => 'red',
    ], 'Site');

    expect($errors)->toContain('The id may only hold lowercase letters, digits, - and _.')
        ->toContain('The source "posts": the post type "product" is not indexed (Content › Post types).')
        ->toContain('The source "posts": limit must be a whole number from 1 to 20.')
        ->toContain('The source key "Bad Key" may only hold lowercase letters, digits, - and _.')
        ->toContain('The source "topics": the taxonomy "genre" is not indexed (Content › Taxonomies).')
        ->toContain('The source "topics": template must be a callable, a SuggestTemplate, or a string (HTML, blade:<view>, twig:<template>, js:<name> or a theme template part).')
        ->toContain('The source "topics": the field "term_meta" is not one browsers may read (term_id, term_taxonomy_id, taxonomy, name, slug, description, parent, count, tree_count, url).')
        ->toContain('min_chars must be a whole number from 1 to 5.')
        ->toContain('The meta key "secret" in public_metas is not indexed (Content › Custom fields).')
        ->toContain('Unknown option "colour".');
});

test('a field needs sources, and each source its objects', function () {
    expect(suggestErrors([]))->toContain('sources is required: at least one group of suggestions.')
        ->and(suggestErrors(['sources' => ['posts' => []]]))->toContain('The source "posts": post_types is required.')
        ->and(suggestErrors(['sources' => ['t' => ['type' => 'users']]]))->toContain('The source "t": type must be posts or terms.');
});

test('the registry validates on first use, and says why a field cannot be served', function () {
    SuggestRegistry::declare('site', ['sources' => ['posts' => ['post_types' => ['post']]]]);
    SuggestRegistry::declare('broken', ['sources' => []]);

    expect(SuggestRegistry::ids())->toBe(['site', 'broken'])
        ->and(SuggestRegistry::get('site')->id)->toBe('site')
        ->and(SuggestRegistry::errors()['site'])->toBe([])
        ->and(SuggestRegistry::errors()['broken'])->not->toBe([])
        ->and(fn () => SuggestRegistry::get('broken'))->toThrow(InvalidSuggest::class)
        ->and(fn () => SuggestRegistry::get('absent'))->toThrow(\OutOfBoundsException::class);
});

test('a source filters on its own objects', function () {
    $definition = SuggestDefinition::fromArray('site', ['sources' => [
        'posts' => ['post_types' => ['post', 'page']],
        'topics' => ['type' => 'terms', 'taxonomies' => ['category']],
    ]]);

    expect($definition->sources['posts']->filter())->toBe('post_type IN ["post", "page"]')
        ->and($definition->sources['topics']->filter())->toBe('taxonomy IN ["category"]');
});

test('a token filter holds only what the field reads', function () {
    $definition = SuggestDefinition::fromArray('site', ['sources' => [
        'posts' => ['post_types' => ['post', 'page']],
        'topics' => ['type' => 'terms', 'taxonomies' => ['category']],
    ]]);

    $posts = SuggestTokens::postsFilter($definition);

    expect($posts)->toContain('post_type')->toContain('post_status')->toContain('has_password')
        ->and(SuggestTokens::termsFilter($definition))->toBe('taxonomy IN ["category"]');
});

test('a declared field makes the public fields apply, with its metas, and never a term\'s metas', function () {
    SuggestRegistry::declare('site', ['sources' => ['posts' => ['post_types' => ['post']]], 'public_metas' => ['price']]);

    expect(PublicFields::active())->toBeTrue()
        ->and(PublicFields::allowlist())->toContain('thumbnail')->toContain('metas.price')
        ->and(PublicFields::restrictTerms(['*']))->toBe(PublicFields::TERM_FIELDS)
        ->and(PublicFields::TERM_FIELDS)->not->toContain('metas');
});
