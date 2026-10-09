<?php

declare(strict_types=1);

namespace {
    if (! function_exists('is_taxonomy_hierarchical')) {
        function is_taxonomy_hierarchical($taxonomy) { return in_array($taxonomy, $GLOBALS['hierarchical'] ?? [], true); }
    }
    if (! function_exists('get_ancestors')) {
        function get_ancestors($id, $type = '', $resourceType = '') { return $GLOBALS['ancestors'][$id] ?? []; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post {

    use Pollora\MeiliScout\Indexables\PostIndexable;

    function termEntry(\WP_Term $term): array
    {
        $method = new \ReflectionMethod(PostIndexable::class, 'termEntry');

        return $method->invoke(new PostIndexable, $term);
    }

    function aTerm(int $id, string $taxonomy, int $parent = 0): \WP_Term
    {
        $term = new \WP_Term;
        $term->term_id = $id;
        $term->name = "Term {$id}";
        $term->slug = "term-{$id}";
        $term->taxonomy = $taxonomy;
        $term->term_taxonomy_id = $id + 100;
        $term->parent = $parent;

        return $term;
    }

    beforeEach(function () {
        $GLOBALS['hierarchical'] = ['category'];
        $GLOBALS['ancestors'] = [9 => [3, 1]];
    });

    test('a term of a hierarchical taxonomy carries itself and its ancestors, nearest first', function () {
        expect(termEntry(aTerm(9, 'category', 3))['tree'])->toBe([9, 3, 1])
            ->and(termEntry(aTerm(1, 'category'))['tree'])->toBe([1]);
    });

    test('a term of a flat taxonomy carries no tree', function () {
        expect(termEntry(aTerm(5, 'post_tag')))->not->toHaveKey('tree')
            ->and(termEntry(aTerm(5, 'post_tag')))->toBe([
                'term_id' => 5, 'name' => 'Term 5', 'slug' => 'term-5', 'taxonomy' => 'post_tag', 'term_taxonomy_id' => 105, 'parent' => 0,
            ]);
    });
}
