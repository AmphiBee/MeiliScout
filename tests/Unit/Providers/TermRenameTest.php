<?php

declare(strict_types=1);

namespace {
    if (! class_exists('WP_Term', false)) {
        class WP_Term
        {
            public int $term_id = 0;
            public string $name = '';
            public string $slug = '';
            public int $parent = 0;
            public string $description = '';
        }
    }
    if (! function_exists('get_term')) {
        function get_term($id, $taxonomy = '') { return $GLOBALS['terms'][$id] ?? null; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Providers {

    use Pollora\MeiliScout\Providers\SingleIndexingServiceProvider;
    use ReflectionMethod;

    function term(string $name, string $slug, int $parent = 0, string $description = ''): \WP_Term
    {
        $term = new \WP_Term;
        $term->term_id = 5;
        $term->name = $name;
        $term->slug = $slug;
        $term->parent = $parent;
        $term->description = $description;

        return $term;
    }

    /**
     * Saves term 5 from $before to $after and says whether its posts would be re-indexed.
     */
    function postsReindexedAfterEdit(?\WP_Term $before, \WP_Term $after): bool
    {
        $provider = new SingleIndexingServiceProvider;

        if ($before !== null) {
            $GLOBALS['terms'][5] = $before;
            $provider->rememberTermBeforeEdit(5, 'category');
        }

        $GLOBALS['terms'][5] = $after;

        return (new ReflectionMethod($provider, 'termFieldsChanged'))->invoke($provider, 5, 'category');
    }

    test('a term whose description changed leaves its posts alone', function () {
        expect(postsReindexedAfterEdit(term('News', 'news'), term('News', 'news', 0, 'New description')))->toBeFalse();
    });

    test('a renamed, re-slugged or moved term re-indexes its posts', function () {
        expect(postsReindexedAfterEdit(term('News', 'news'), term('Latest', 'news')))->toBeTrue()
            ->and(postsReindexedAfterEdit(term('News', 'news'), term('News', 'latest')))->toBeTrue()
            ->and(postsReindexedAfterEdit(term('News', 'news'), term('News', 'news', 3)))->toBeTrue();
    });

    test('a term edited without the edit_terms hook re-indexes its posts, as before', function () {
        expect(postsReindexedAfterEdit(null, term('News', 'news')))->toBeTrue();
    });
}
