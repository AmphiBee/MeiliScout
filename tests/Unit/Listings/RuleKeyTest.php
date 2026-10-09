<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Seo\RuleKey;

require_once __DIR__.'/cases.php';

/**
 * Terms of the stand-in taxonomies: id by slug.
 */
function ruleTerms(): \Closure
{
    $terms = ['category' => ['news' => 3, 'events' => 7], 'post_tag' => ['laravel' => 12, 'php' => 15]];

    return function (string $taxonomy, string $value) use ($terms): ?int {
        if (ctype_digit($value)) {
            return in_array((int) $value, $terms[$taxonomy] ?? [], true) ? (int) $value : null;
        }

        return $terms[$taxonomy][$value] ?? null;
    };
}

test('a key names the taxonomy lists of the listing only', function () {
    expect(array_map(fn ($facet) => $facet->key, RuleKey::facets(casesDefinition())))->toBe(['category', 'tag']);
});

test('a key is put in the definition\'s order, terms by id, slugs accepted', function () {
    $definition = casesDefinition();

    expect(RuleKey::normalize($definition, '', ruleTerms()))->toBe('')
        ->and(RuleKey::normalize($definition, 'tag=laravel|category=*', ruleTerms()))->toBe('category=*|tag=12')
        ->and(RuleKey::normalize($definition, ' category = 3 ', ruleTerms()))->toBe('category=3');
});

test('a key with an unknown facet, term, a meta facet or too many facets is refused', function (string $key, string $message) {
    expect(fn () => RuleKey::normalize(casesDefinition(), $key, ruleTerms()))->toThrow(\InvalidArgumentException::class, $message);
})->with([
    'unknown facet' => ['size=3', 'no taxonomy facet "size"'],
    'meta facet' => ['color=red', 'no taxonomy facet "color"'],
    'unknown term' => ['category=sport', 'No term "sport"'],
    'twice' => ['category=3|category=7', 'named twice'],
    'not a pair' => ['category', 'not a facet=value pair'],
]);

test('a term weighs more than *, the first facet more than the second', function () {
    expect(RuleKey::specificity(''))->toBe(0)
        ->and(RuleKey::specificity('category=*'))->toBe(0)
        ->and(RuleKey::specificity('category=3'))->toBe(2)
        ->and(RuleKey::specificity('category=*|tag=12'))->toBe(1)
        ->and(RuleKey::specificity('category=3|tag=*'))->toBe(2)
        ->and(RuleKey::specificity('category=3|tag=12'))->toBe(3);
});

test('the keys of a view, most specific first', function () {
    expect(RuleKey::candidates([]))->toBe([''])
        ->and(RuleKey::candidates(['category' => 3]))->toBe(['category=3', 'category=*'])
        ->and(RuleKey::candidates(['category' => 3, 'tag' => 12]))->toBe(['category=3|tag=12', 'category=3|tag=*', 'category=*|tag=12', 'category=*|tag=*']);
});

test('a key as people read it', function () {
    $definition = casesDefinition();
    $names = fn (string $taxonomy, int $id) => [3 => 'News', 12 => 'Laravel'][$id] ?? null;

    expect(RuleKey::label($definition, '', $names))->toBe('Without filters')
        ->and(RuleKey::label($definition, 'category=3|tag=*', $names))->toBe('Category: News, Tag: any')
        ->and(RuleKey::label($definition, 'tag=99', $names))->toBe('Tag: #99');
});
