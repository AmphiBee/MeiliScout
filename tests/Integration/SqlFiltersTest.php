<?php

declare(strict_types=1);

use Pollora\MeiliScout\Query\SqlFilters;

/*
 * A plugin that restricts the posts through WP_Query's SQL filters sends the
 * query to MySQL, unless it is declared harmless; one that changes nothing
 * for the query costs nothing.
 */

function meiliscout_test_only_even_ids(string $where): string
{
    return $where.' AND ID % 2 = 0';
}

function meiliscout_test_unchanged(string $where): string
{
    return $where;
}

function servedWith(string $hook, callable $callback, array $ignored = []): array
{
    // Added to the site's own (a multilingual plugin's)
    $ignore = static fn (array $list) => [...$list, ...$ignored];
    add_filter($hook, $callback);
    add_filter('meiliscout/ignored_sql_filters', $ignore);

    try {
        $query = new WP_Query(['use_meilisearch' => true, 'suppress_filters' => false, 'posts_per_page' => 5]);
    } finally {
        remove_filter($hook, $callback);
        remove_filter('meiliscout/ignored_sql_filters', $ignore);
    }

    return $query->meiliscout;
}

test('a plugin restricting the posts in SQL sends the query to MySQL', function () {
    expect(servedWith('posts_where', 'meiliscout_test_only_even_ids'))
        ->toMatchArray(['served' => false, 'reason' => 'sql_filter:posts_where']);
})->group('integration');

test('a filter that changes nothing for the query lets Meilisearch serve it', function () {
    // A hook no multilingual plugin changes: WPML's posts_where would send any undeclared callback there to MySQL
    expect(servedWith('posts_groupby', 'meiliscout_test_unchanged')['served'])->toBeTrue();
})->group('integration');

test('a callback declared harmless, by name or by hook, lets Meilisearch serve the query', function () {
    expect(servedWith('posts_where', 'meiliscout_test_only_even_ids', ['meiliscout_test_only_even_ids'])['served'])->toBeTrue()
        ->and(servedWith('posts_where', 'meiliscout_test_only_even_ids', ['posts_where'])['served'])->toBeTrue();
})->group('integration');

test('posts_clauses and posts_request are watched too', function () {
    $clauses = static fn (array $clauses): array => ['where' => $clauses['where'].' AND 1 = 1'] + $clauses;
    $request = static fn (string $sql): string => $sql.' ';

    expect(servedWith('posts_clauses', $clauses)['reason'])->toBe('sql_filter:posts_clauses')
        ->and(servedWith('posts_request', $request)['reason'])->toBe('sql_filter:posts_request');
})->group('integration');

test('a query run with suppress_filters is not concerned', function () {
    add_filter('posts_where', 'meiliscout_test_only_even_ids');

    try {
        $query = new WP_Query(['use_meilisearch' => true, 'suppress_filters' => true, 'posts_per_page' => 5]);
    } finally {
        remove_filter('posts_where', 'meiliscout_test_only_even_ids');
    }

    expect($query->meiliscout['served'])->toBeTrue()
        ->and(SqlFilters::name('meiliscout_test_only_even_ids'))->toBe('meiliscout_test_only_even_ids')
        ->and(SqlFilters::name([new ArrayObject, 'count']))->toBe('ArrayObject::count');
})->group('integration');
