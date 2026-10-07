<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;

test('single taxonomy query is correctly formatted', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            [
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => 'news',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.slug IN [\'news\']))');
});

test('multiple taxonomy terms are correctly formatted', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            [
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => ['news', 'events'],
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.slug IN [\'news\', \'events\']))');
});

test('multiple taxonomy queries are combined with AND by default', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            [
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => 'news',
            ],
            [
                'taxonomy' => 'post_tag',
                'field' => 'slug',
                'terms' => 'featured',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.slug IN [\'news\']) AND (terms.taxonomy = \'post_tag\' AND terms.slug IN [\'featured\']))');
});

test('taxonomy queries respect the relation parameter', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            'relation' => 'OR',
            [
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => 'news',
            ],
            [
                'taxonomy' => 'post_tag',
                'field' => 'slug',
                'terms' => 'featured',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.slug IN [\'news\']) OR (terms.taxonomy = \'post_tag\' AND terms.slug IN [\'featured\']))');
});

test('nested taxonomy queries are correctly formatted', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            'relation' => 'OR',
            [
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => ['news', 'events'],
            ],
            [
                'relation' => 'AND',
                [
                    'taxonomy' => 'post_tag',
                    'field' => 'slug',
                    'terms' => ['featured', 'trending'],
                ],
                [
                    'taxonomy' => 'genre',
                    'field' => 'slug',
                    'terms' => 'tech',
                ],
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.slug IN [\'news\', \'events\']) OR ((terms.taxonomy = \'post_tag\' AND terms.slug IN [\'featured\', \'trending\']) AND (terms.taxonomy = \'genre\' AND terms.slug IN [\'tech\'])))');
});

test('deeply nested taxonomy queries with special characters are correctly escaped', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            'relation' => 'AND',
            [
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => 'Breaking \'News\'',
            ],
            [
                'relation' => 'OR',
                [
                    'taxonomy' => 'post_tag',
                    'field' => 'slug',
                    'terms' => ['Editor\'s Pick', 'Today\'s \'Special\''],
                ],
                [
                    'relation' => 'AND',
                    [
                        'taxonomy' => 'genre',
                        'field' => 'slug',
                        'terms' => 'Tech & \'Innovation\'',
                    ],
                    [
                        'taxonomy' => 'region',
                        'field' => 'slug',
                        'terms' => ['North \'America\'', 'South \'America\''],
                    ],
                ],
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.slug IN [\'Breaking \\\'News\\\'\']) AND ((terms.taxonomy = \'post_tag\' AND terms.slug IN [\'Editor\\\'s Pick\', \'Today\\\'s \\\'Special\\\'\']) OR ((terms.taxonomy = \'genre\' AND terms.slug IN [\'Tech & \\\'Innovation\\\'\']) AND (terms.taxonomy = \'region\' AND terms.slug IN [\'North \\\'America\\\'\', \'South \\\'America\\\'\']))))');
});

test('taxonomy query with term_id field is correctly formatted', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => [1, 2, 3],
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.term_id IN [1, 2, 3]))');
});

test('taxonomy query with name field is correctly formatted', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            [
                'taxonomy' => 'category',
                'field' => 'name',
                'terms' => ['Actualités', 'Événements'],
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.name IN [\'Actualités\', \'Événements\']))');
});

test('taxonomy query with term_taxonomy_id field is correctly formatted', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            [
                'taxonomy' => 'category',
                'field' => 'term_taxonomy_id',
                'terms' => [10, 20],
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.term_taxonomy_id IN [10, 20]))');
});

test('taxonomy query with EXISTS operator is correctly formatted', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            [
                'taxonomy' => 'category',
                'operator' => 'EXISTS',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (terms.taxonomy = \'category\')');
});

test('taxonomy query with NOT EXISTS operator is correctly formatted', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            [
                'taxonomy' => 'category',
                'operator' => 'NOT EXISTS',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (NOT terms.taxonomy = \'category\')');
});

test('taxonomy query with AND operator is correctly formatted', function () {
    $query = new MockWPQuery([
        'tax_query' => [
            [
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => ['news', 'featured'],
                'operator' => 'AND',
            ],
        ],
    ]);

    $builder = new MeiliQueryBuilder;
    $params = $builder->build($query);

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND ((terms.taxonomy = \'category\' AND terms.slug = \'news\' AND terms.slug = \'featured\'))');
});

/**
 * Meilisearch flattens `terms` into one array per field: `terms.taxonomy` and
 * `terms.slug` are matched independently, so a post tagged `news` matches
 * category `news` as soon as it has any category. Fixing it needs one field
 * per taxonomy in the documents (`taxonomies.category.slug`), hence a re-index.
 */
test('a term is matched within its own taxonomy only', function () {
    $query = new MockWPQuery([
        'post_type' => 'post',
        'tax_query' => [['taxonomy' => 'category', 'field' => 'slug', 'terms' => ['news']]],
    ]);

    expect((new MeiliQueryBuilder)->build($query)['filter'])
        ->toContain("taxonomies.category.slug IN ['news']");
})->todo('Needs one field per taxonomy in the post documents (schema migration).');
