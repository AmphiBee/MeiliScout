<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Query\UnsupportedQuery;

function dateFilter(array $vars): string
{
    $filter = (new MeiliQueryBuilder)->build(new MockWPQuery($vars))['filter'];

    return substr($filter, strlen("post_type = 'post' AND post_status = 'publish' AND "));
}

function utc(string $date): int
{
    return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp();
}

beforeEach(function () {
    $GLOBALS['wp_options'] = ['start_of_week' => 1];
});

test('m is a date of varying precision', function () {
    expect(dateFilter(['m' => '2024']))->toBe('date_parts.post_date.year = 2024')
        ->and(dateFilter(['m' => '202403']))->toBe('date_parts.post_date.year = 2024 AND date_parts.post_date.month = 3')
        ->and(dateFilter(['m' => '20240315']))->toBe('date_parts.post_date.year = 2024 AND date_parts.post_date.month = 3 AND date_parts.post_date.day = 15');
});

test('the shortcuts make one clause', function () {
    expect(dateFilter(['year' => 2024, 'monthnum' => 3, 'day' => 5]))
        ->toBe('((date_parts.post_date.year = 2024 AND date_parts.post_date.month = 3 AND date_parts.post_date.day = 5))');
});

test('w is the week WordPress computes, for the site\'s first day of the week', function () {
    expect(dateFilter(['year' => 2024, 'w' => 10]))->toContain('date_parts.post_date.week_1 = 10');

    update_option('start_of_week', 0);

    expect(dateFilter(['w' => 10]))->toBe('(date_parts.post_date.week_0 = 10)');
});

test('hour, minute and second, 0 included, the way DATE_FORMAT() compares them', function () {
    expect(dateFilter(['hour' => 0]))->toBe('(date_parts.post_date.hour = 0)')
        ->and(dateFilter(['hour' => 9, 'minute' => 30]))->toBe('(date_parts.post_date.hm = 9.3)')
        ->and(dateFilter(['date_query' => [['hour' => 9, 'minute' => 5, 'second' => 7, 'compare' => '>=']]]))->toBe('(date_parts.post_date.hms >= 9.0507)')
        ->and(dateFilter(['date_query' => [['minute' => 5, 'second' => 7]]]))->toBe('(date_parts.post_date.ms = 0.0507)');
});

test('hour and second without a minute compare nothing, as in WordPress', function () {
    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['date_query' => [['hour' => 9, 'second' => 7]]]))['filter'])
        ->toBe("post_type = 'post' AND post_status = 'publish'");
});

test('after and before: a date read as build_mysql_datetime() reads it', function () {
    expect(dateFilter(['date_query' => [['after' => '2024-01-01']]]))->toBe('(post_date_ts > '.utc('2024-01-01 23:59:59').')')
        ->and(dateFilter(['date_query' => [['after' => '2024-01-01', 'inclusive' => true]]]))->toBe('(post_date_ts >= '.utc('2024-01-01 00:00:00').')')
        ->and(dateFilter(['date_query' => [['before' => ['year' => 2024, 'month' => 2]]]]))->toBe('(post_date_ts < '.utc('2024-02-01 00:00:00').')')
        ->and(dateFilter(['date_query' => [['before' => ['year' => 2024, 'month' => 2], 'inclusive' => true]]]))->toBe('(post_date_ts <= '.utc('2024-02-29 23:59:59').')')
        ->and(dateFilter(['date_query' => [['after' => '2024-03-04 10:20:30']]]))->toBe('(post_date_ts > '.utc('2024-03-04 10:20:30').')');
});

test('a range and parts in a clause are all to be true', function () {
    expect(dateFilter(['date_query' => [['after' => '2024', 'before' => '2025', 'month' => 6]]]))
        ->toBe('((post_date_ts > '.utc('2024-12-31 23:59:59').' AND post_date_ts < '.utc('2025-01-01 00:00:00').' AND date_parts.post_date.month = 6))');
});

test('compare applies to every part; IN and BETWEEN take lists', function () {
    expect(dateFilter(['date_query' => [['dayofweek' => [1, 7], 'compare' => 'IN']]]))->toBe('(date_parts.post_date.dayofweek IN [1, 7])')
        ->and(dateFilter(['date_query' => [['month' => [3, 5], 'compare' => 'BETWEEN']]]))->toBe('((date_parts.post_date.month >= 3 AND date_parts.post_date.month <= 5))')
        ->and(dateFilter(['date_query' => [['dayofweek_iso' => 1, 'compare' => '!=']]]))->toBe('(date_parts.post_date.dayofweek_iso != 1)');
});

test('clauses inherit column and compare from their parent; relations nest', function () {
    expect(dateFilter(['date_query' => [
        'relation' => 'OR',
        'column' => 'post_modified',
        ['year' => 2024],
        ['relation' => 'AND', ['year' => 2025], ['month' => 1, 'column' => 'post_date']],
    ]]))->toBe('(date_parts.post_modified.year = 2024 OR (date_parts.post_modified.year = 2025 AND date_parts.post_date.month = 1))');
});

test('an operator WordPress does not accept (lowercase included) is the query\'s', function () {
    expect(dateFilter(['date_query' => [['year' => 2024, 'compare' => 'in']]]))->toBe('(date_parts.post_date.year = 2024)');
});

test('a column of another table, or the parts of a GMT column, run on MySQL', function () {
    expect(fn () => dateFilter(['date_query' => [['column' => 'comment_date', 'year' => 2024]]]))->toThrow(UnsupportedQuery::class, 'unsupported_date_column:comment_date')
        ->and(fn () => dateFilter(['date_query' => [['column' => 'post_date_gmt', 'year' => 2024]]]))->toThrow(UnsupportedQuery::class, 'unsupported_date_column:post_date_gmt')
        ->and(dateFilter(['date_query' => [['column' => 'post_date_gmt', 'after' => '2024-01-01', 'inclusive' => true]]]))->toBe('(post_date_gmt_ts >= '.utc('2024-01-01').')');
});

test('an unknown column is post_date, as in WordPress', function () {
    expect(dateFilter(['date_query' => [['column' => 'nonsense', 'year' => 2024]]]))->toBe('(date_parts.post_date.year = 2024)');
});

test('indexes built before schema 3 lack the dates: the query runs on MySQL', function () {
    update_option('meiliscout/schema_version', 2);

    expect(fn () => dateFilter(['year' => 2024]))->toThrow(UnsupportedQuery::class, 'schema_too_old');
});
