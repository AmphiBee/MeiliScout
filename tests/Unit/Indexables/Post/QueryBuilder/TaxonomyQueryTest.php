<?php

declare(strict_types=1);

namespace {
    if (! function_exists('is_taxonomy_hierarchical')) {
        function is_taxonomy_hierarchical($taxonomy) { return in_array($taxonomy, $GLOBALS['hierarchical'] ?? [], true); }
    }
    if (! function_exists('get_term_by')) {
        function get_term_by($field, $value, $taxonomy)
        {
            foreach ($GLOBALS['tax_terms'] ?? [] as $term) {
                if ($term->taxonomy === $taxonomy && (string) $term->{$field} === (string) $value) {
                    return $term;
                }
            }

            return false;
        }
    }
    if (! function_exists('get_term_children')) {
        function get_term_children($termId, $taxonomy) { return $GLOBALS['term_children'][$termId] ?? []; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder {

    use Pollora\MeiliScout\Query\MeiliQueryBuilder;

    function taxFilter(array $taxQuery): string
    {
        $filter = (new MeiliQueryBuilder)->build(new MockWPQuery(['tax_query' => $taxQuery]))['filter'];

        return substr($filter, strlen("post_type = 'post' AND post_status = 'publish' AND "));
    }

    function term(int $id, string $slug, string $taxonomy): \WP_Term
    {
        $term = new \WP_Term;
        $term->term_id = $id;
        $term->slug = $slug;
        $term->taxonomy = $taxonomy;

        return $term;
    }

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['hierarchical'] = [];
        $GLOBALS['tax_terms'] = [];
        $GLOBALS['term_children'] = [];
    });

    test('a term is matched within its own taxonomy only', function () {
        expect(taxFilter([['taxonomy' => 'category', 'field' => 'slug', 'terms' => ['news']]]))
            ->toBe("(taxonomies.category.slug IN ['news'])");
    });

    test('every field and operator maps to the taxonomy field', function () {
        expect(taxFilter([['taxonomy' => 'post_tag', 'terms' => [1, 2]]]))->toBe('(taxonomies.post_tag.term_id IN [1, 2])')
            ->and(taxFilter([['taxonomy' => 'post_tag', 'field' => 'name', 'terms' => 'Événements']]))->toBe("(taxonomies.post_tag.name IN ['Événements'])")
            ->and(taxFilter([['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => ['a', 'b'], 'operator' => 'NOT IN']]))->toBe("(taxonomies.post_tag.slug NOT IN ['a', 'b'])")
            ->and(taxFilter([['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => ['a', 'b'], 'operator' => 'AND']]))->toBe("((taxonomies.post_tag.slug = 'a' AND taxonomies.post_tag.slug = 'b'))")
            ->and(taxFilter([['taxonomy' => 'post_tag', 'operator' => 'EXISTS']]))->toBe('(taxonomies.post_tag.term_id EXISTS)')
            ->and(taxFilter([['taxonomy' => 'post_tag', 'operator' => 'NOT EXISTS']]))->toBe('(taxonomies.post_tag.term_id NOT EXISTS)');
    });

    test('relations and nested queries are kept', function () {
        expect(taxFilter([
            'relation' => 'OR',
            ['taxonomy' => 'category', 'field' => 'slug', 'terms' => ['news']],
            ['relation' => 'AND', ['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => ["Editor's pick"]], ['taxonomy' => 'genre', 'terms' => [4]]],
        ]))->toBe("(taxonomies.category.slug IN ['news'] OR (taxonomies.post_tag.slug IN ['Editor\\'s pick'] AND taxonomies.genre.term_id IN [4]))");
    });

    test('a term of a hierarchical taxonomy brings its children, as in WordPress', function () {
        $GLOBALS['hierarchical'] = ['category'];
        $GLOBALS['tax_terms'] = [term(3, 'news', 'category')];
        $GLOBALS['term_children'] = [3 => [8, 9]];

        expect(taxFilter([['taxonomy' => 'category', 'field' => 'slug', 'terms' => ['news']]]))
            ->toBe('(taxonomies.category.term_id IN [3, 8, 9])')
            ->and(taxFilter([['taxonomy' => 'category', 'field' => 'slug', 'terms' => ['news'], 'include_children' => false]]))
            ->toBe("(taxonomies.category.slug IN ['news'])");
    });

    test('a taxonomy name that could not be an attribute name is ignored', function () {
        expect((new MeiliQueryBuilder)->build(new MockWPQuery(['tax_query' => [['taxonomy' => "category = 1 OR x", 'terms' => [1]]]]))['filter'])
            ->toBe("post_type = 'post' AND post_status = 'publish'");
    });
}
