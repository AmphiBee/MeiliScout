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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (taxonomies.category.slug IN [\'news\'])');
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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (taxonomies.category.slug IN [\'news\', \'events\'])');
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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (taxonomies.category.slug IN [\'news\'] AND taxonomies.post_tag.slug IN [\'featured\'])');
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

    expect($params['filter'])->toContain('taxonomies.category.slug IN [\'news\']')
        ->and($params['filter'])->toContain('OR')
        ->and($params['filter'])->toContain('taxonomies.post_tag.slug IN [\'featured\']');
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

    expect($params['filter'])->toBe(
        'post_type = \'post\' AND post_status = \'publish\' AND '.
        "(taxonomies.category.slug IN ['news', 'events'] OR ".
        "(taxonomies.post_tag.slug IN ['featured', 'trending'] AND taxonomies.genre.slug IN ['tech']))"
    );
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

    expect($params['filter'])->toBe(
        'post_type = \'post\' AND post_status = \'publish\' AND ' .
        '(taxonomies.category.slug IN [\'Breaking \\\'News\\\'\'] AND ' .
        '(taxonomies.post_tag.slug IN [\'Editor\\\'s Pick\', \'Today\\\'s \\\'Special\\\'\'] OR ' .
        '(taxonomies.genre.slug IN [\'Tech & \\\'Innovation\\\'\'] AND ' .
        'taxonomies.region.slug IN [\'North \\\'America\\\'\', \'South \\\'America\\\'\'])))'
    );
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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (taxonomies.category.term_id IN [1, 2, 3])');
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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (taxonomies.category.name IN [\'Actualités\', \'Événements\'])');
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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (taxonomies.category.term_taxonomy_id IN [10, 20])');
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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (taxonomies.category.term_id EXISTS)');
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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (taxonomies.category.term_id NOT EXISTS)');
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

    expect($params['filter'])->toBe('post_type = \'post\' AND post_status = \'publish\' AND (taxonomies.category.slug = [\'news\', \'featured\'])');
});
