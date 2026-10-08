<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Indexables;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The date fields of a post document, for date queries to filter on.
 *
 * WP_Date_Query compares a date column with a 'Y-m-d H:i:s' string, or a part
 * of it (YEAR(), WEEK(), HOUR()...) with a number. Documents carry both: the
 * column as a timestamp, read as UTC whatever the column's time zone (so that
 * the string WordPress compares to, read the same way, compares the same),
 * and each part MySQL would compute.
 */
final class PostDates
{
    /**
     * Date columns a document has a timestamp of.
     */
    public const COLUMNS = ['post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt'];

    /**
     * Date columns a document has the parts of.
     */
    public const PART_COLUMNS = ['post_date', 'post_modified'];

    /**
     * A 'Y-m-d H:i:s' string as a timestamp, read as UTC; null for no date.
     */
    public static function timestamp(string $datetime): ?int
    {
        $date = self::parse($datetime);

        return $date?->getTimestamp();
    }

    /**
     * The parts of a 'Y-m-d H:i:s' date, as MySQL's date functions give them.
     *
     * The week is given for each first day of the week WordPress may be set
     * to (week_0 to week_6), the way _wp_mysql_week() computes it.
     * hm, hms and ms are what WP_Date_Query compares a time to: DATE_FORMAT()
     * with '%H.%i', '%H.%i%s' and '0.%i%s', as numbers.
     *
     * @return array<string, int|float>|null
     */
    public static function parts(string $datetime): ?array
    {
        $date = self::parse($datetime);

        if ($date === null) {
            return null;
        }

        [$hour, $minute, $second] = [(int) $date->format('G'), (int) $date->format('i'), (int) $date->format('s')];

        $parts = [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'day' => (int) $date->format('j'),
            'dayofyear' => (int) $date->format('z') + 1,
            // DAYOFWEEK(): 1 for Sunday; WEEKDAY() + 1: 1 for Monday
            'dayofweek' => (int) $date->format('w') + 1,
            'dayofweek_iso' => (int) $date->format('N'),
            'hour' => $hour,
            'minute' => $minute,
            'second' => $second,
            'hm' => self::time($hour, $minute),
            'hms' => self::time($hour, $minute, $second),
            'ms' => self::time(0, $minute, $second),
        ];

        for ($startOfWeek = 0; $startOfWeek <= 6; $startOfWeek++) {
            $parts["week_{$startOfWeek}"] = self::wordPressWeek($date, $startOfWeek);
        }

        return $parts;
    }

    /**
     * A time as WP_Date_Query compares it: H.MM or H.MMSS, as a number.
     */
    public static function time(int $hour, int $minute, ?int $second = null): float
    {
        return (float) ($second === null
            ? sprintf('%d.%02d', $hour, $minute)
            : sprintf('%d.%02d%02d', $hour, $minute, $second));
    }

    /**
     * The week _wp_mysql_week() gives for a date, with this first day of the week.
     */
    public static function wordPressWeek(DateTimeImmutable $date, int $startOfWeek): int
    {
        return match ($startOfWeek) {
            1 => self::mysqlWeek($date, 1),
            2, 3, 4, 5, 6 => self::mysqlWeek($date->modify('-'.($startOfWeek - 1).' days'), 0),
            default => self::mysqlWeek($date, 0),
        };
    }

    /**
     * MySQL's WEEK(date, mode), for modes 0 and 1.
     *
     * Mode 0: weeks start on Sunday, week 1 is the first with a Sunday in the
     * year. Mode 1: weeks start on Monday, week 1 is the first with four days
     * or more in the year. Days before week 1 are in week 0.
     */
    public static function mysqlWeek(DateTimeImmutable $date, int $mode): int
    {
        $year = (int) $date->format('Y');
        $dayOfYear = (int) $date->format('z');
        $january1 = $date->setDate($year, 1, 1);

        if ($mode === 1) {
            // Days from the Monday of January 1st's week to January 1st
            $offset = ((int) $january1->format('N')) - 1;
            $firstWeekStart = $offset <= 3 ? -$offset : 7 - $offset;
        } else {
            // The first Sunday of the year
            $firstWeekStart = (7 - (int) $january1->format('w')) % 7;
        }

        if ($dayOfYear < $firstWeekStart) {
            return 0;
        }

        return intdiv($dayOfYear - $firstWeekStart, 7) + 1;
    }

    private static function parse(string $datetime): ?DateTimeImmutable
    {
        if ($datetime === '' || str_starts_with($datetime, '0000-00-00')) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $datetime, new DateTimeZone('UTC'));

        return $date ?: null;
    }
}
