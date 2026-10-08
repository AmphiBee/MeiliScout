<?php

declare(strict_types=1);

use Pollora\MeiliScout\Indexables\PostDates;

function utcDate(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

test('a date is a timestamp of the string read as UTC; no date is none', function () {
    expect(PostDates::timestamp('2024-03-04 10:20:30'))->toBe(utcDate('2024-03-04 10:20:30')->getTimestamp())
        ->and(PostDates::timestamp('0000-00-00 00:00:00'))->toBeNull()
        ->and(PostDates::timestamp(''))->toBeNull();
});

test('the parts are what MySQL\'s functions give', function () {
    expect(PostDates::parts('2024-03-04 09:05:07'))->toMatchArray([
        'year' => 2024, 'month' => 3, 'day' => 4, 'dayofyear' => 64,
        // A Monday: DAYOFWEEK() 2, WEEKDAY() + 1 1
        'dayofweek' => 2, 'dayofweek_iso' => 1,
        'hour' => 9, 'minute' => 5, 'second' => 7, 'hm' => 9.05, 'hms' => 9.0507, 'ms' => 0.0507,
    ]);
});

test('weeks are MySQL\'s WEEK() in modes 0 and 1', function (string $date, int $mode0, int $mode1) {
    expect(PostDates::mysqlWeek(utcDate($date), 0))->toBe($mode0)
        ->and(PostDates::mysqlWeek(utcDate($date), 1))->toBe($mode1);
})->with([
    // Checked against MySQL
    ['2019-12-20', 50, 51],
    ['2019-12-22', 51, 51],
    ['2019-12-23', 51, 52],
    ['2021-01-01', 0, 0],
    ['2021-01-03', 1, 0],
    ['2021-01-04', 1, 1],
    ['2024-01-01', 0, 1],
    ['2026-12-31', 52, 53],
]);

test('another first day of the week shifts the date, as _wp_mysql_week() does', function () {
    $date = utcDate('2024-03-04');

    expect(PostDates::wordPressWeek($date, 3))->toBe(PostDates::mysqlWeek($date->modify('-2 days'), 0));
});
