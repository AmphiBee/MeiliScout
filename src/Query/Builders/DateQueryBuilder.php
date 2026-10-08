<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Indexables\PostDates;
use Pollora\MeiliScout\Query\Builders\Concerns\FormatsValues;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * Translates date_query, and the m, year, monthnum, w, day, hour, minute and second shortcuts.
 *
 * A port of WP_Date_Query: clauses inherit their column, compare and relation
 * from their parent, after and before are turned into a date the way
 * build_mysql_datetime() does, each part is compared the way MySQL's YEAR(),
 * WEEK(), HOUR()... would be, and times the way DATE_FORMAT() is. A date
 * compares as a timestamp of the 'Y-m-d H:i:s' string read as UTC, the way
 * the documents store the columns.
 */
class DateQueryBuilder implements QueryBuilderInterface
{
    use FormatsValues;

    /**
     * The arguments this builder translates, which need the fields of schema 3.
     */
    public const VARS = ['m', 'year', 'monthnum', 'w', 'day', 'hour', 'minute', 'second', 'date_query'];

    private const COMPARES = ['=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'];

    private const TIME_KEYS = ['after', 'before', 'year', 'month', 'monthnum', 'week', 'w', 'dayofyear', 'day', 'dayofweek', 'dayofweek_iso', 'hour', 'minute', 'second'];

    /**
     * Date parts, each with the clause keys that stand for it: the first one set is used.
     */
    private const UNITS = [
        'year' => ['year'],
        'month' => ['month', 'monthnum'],
        'week' => ['week', 'w'],
        'dayofyear' => ['dayofyear'],
        'day' => ['day'],
        'dayofweek' => ['dayofweek'],
        'dayofweek_iso' => ['dayofweek_iso'],
    ];

    /**
     * Date columns WP_Date_Query knows, outside the posts table.
     */
    private const OTHER_COLUMNS = ['comment_date', 'comment_date_gmt', 'user_registered', 'registered', 'last_updated'];

    /**
     * The compare of the date query being built, for clauses with an invalid one.
     */
    private string $compare = '=';

    /**
     * @param  array<string, mixed>  $searchParams
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $filters = array_values(array_filter([
            $this->month($query),
            $this->shortcuts($query),
            $this->dateQuery($query->get('date_query')),
        ]));

        if ($filters === []) {
            return;
        }

        if (IndexNames::activeSchema() < 3) {
            throw new UnsupportedQuery('schema_too_old');
        }

        foreach ($filters as $filter) {
            $searchParams['filter'][] = $filter;
        }
    }

    /**
     * m: a date of varying precision, YYYY to YYYYMMDDHHMMSS.
     */
    private function month(QueryInterface $query): string
    {
        $m = $query->get('m');
        $m = is_scalar($m) ? (string) preg_replace('/\D/', '', (string) $m) : '';

        if ($m === '' || $m === '0') {
            return '';
        }

        $filters = ['date_parts.post_date.year = '.(int) substr($m, 0, 4)];

        foreach ([5 => ['month', 4], 7 => ['day', 6], 9 => ['hour', 8], 11 => ['minute', 10], 13 => ['second', 12]] as $length => [$unit, $start]) {
            if (strlen($m) > $length) {
                $filters[] = "date_parts.post_date.{$unit} = ".(int) substr($m, $start, 2);
            }
        }

        return implode(' AND ', $filters);
    }

    /**
     * hour, minute, second, year, monthnum, w and day: one clause, as WP_Query::get_posts() makes it.
     */
    private function shortcuts(QueryInterface $query): string
    {
        $parameters = [];

        foreach (['hour' => 'hour', 'minute' => 'minute', 'second' => 'second'] as $var => $key) {
            $value = $query->get($var);
            if ($value !== null && $value !== '') {
                $parameters[$key] = abs((int) $value);
            }
        }

        foreach (['year' => 'year', 'monthnum' => 'monthnum', 'w' => 'week', 'day' => 'day'] as $var => $key) {
            $value = abs((int) $query->get($var));
            if ($value > 0) {
                $parameters[$key] = $value;
            }
        }

        return $parameters === [] ? '' : $this->dateQuery([$parameters]);
    }

    /**
     * A date_query, as WP_Date_Query::get_sql() reads it.
     */
    private function dateQuery(mixed $dateQuery, string $defaultColumn = 'post_date'): string
    {
        if (empty($dateQuery) || ! is_array($dateQuery)) {
            return '';
        }

        // Time keys at the top level: one clause
        if (! isset($dateQuery[0])) {
            $dateQuery = [$dateQuery];
        }

        $dateQuery['column'] = ! empty($dateQuery['column']) ? $dateQuery['column'] : $defaultColumn;
        $this->compare = $this->validCompare($dateQuery['compare'] ?? null) ?? '=';

        return $this->group($this->sanitize($dateQuery));
    }

    /**
     * WP_Date_Query::sanitize_query(): every level gets a column, a compare and a relation, from its parent by default.
     *
     * @param  array<int|string, mixed>  $queries
     * @param  array<int|string, mixed>|null  $parent
     * @return array<int|string, mixed>
     */
    private function sanitize(array $queries, ?array $parent = null): array
    {
        foreach ($queries as $key => $value) {
            if (is_numeric($key) && ! is_array($value)) {
                unset($queries[$key]);
            }
        }

        foreach (['column' => 'post_date', 'compare' => '=', 'relation' => 'AND'] as $key => $default) {
            if (! isset($queries[$key])) {
                $queries[$key] = $parent[$key] ?? $default;
            }
        }

        $queries['relation'] = strtoupper((string) $queries['relation']) === 'OR' ? 'OR' : 'AND';
        $cleaned = [];

        foreach ($queries as $key => $value) {
            if (! is_array($value) || in_array($key, self::TIME_KEYS, true)) {
                $cleaned[$key] = $value;
            } else {
                $cleaned[] = $this->sanitize($value, $queries);
            }
        }

        return $cleaned;
    }

    /**
     * @param  array<int|string, mixed>  $group
     */
    private function group(array $group): string
    {
        $filters = [];

        foreach ($group as $key => $entry) {
            if ($key === 'relation' || ! is_array($entry)) {
                continue;
            }

            $filter = $this->isClause($entry) ? $this->clause($entry) : $this->group($entry);

            if ($filter !== '') {
                $filters[] = $filter;
            }
        }

        if ($filters === []) {
            return '';
        }

        return '('.implode(' '.$group['relation'].' ', $filters).')';
    }

    /**
     * @param  array<int|string, mixed>  $entry
     */
    private function isClause(array $entry): bool
    {
        return array_intersect(self::TIME_KEYS, array_keys($entry)) !== [];
    }

    /**
     * WP_Date_Query::get_sql_for_clause(): the parts of a clause, all to be true.
     *
     * @param  array<int|string, mixed>  $clause
     */
    private function clause(array $clause): string
    {
        $column = $this->column((string) ($clause['column'] ?? 'post_date'));
        $compare = $this->validCompare($clause['compare'] ?? null) ?? $this->compare;
        $inclusive = ! empty($clause['inclusive']);
        $parts = [];

        if (! empty($clause['after'])) {
            $parts[] = "{$column}_ts ".($inclusive ? '>=' : '>').' '.$this->timestamp($clause['after'], ! $inclusive);
        }

        if (! empty($clause['before'])) {
            $parts[] = "{$column}_ts ".($inclusive ? '<=' : '<').' '.$this->timestamp($clause['before'], $inclusive);
        }

        foreach (self::UNITS as $unit => $keys) {
            foreach ($keys as $key) {
                if (! isset($clause[$key])) {
                    continue;
                }

                $filter = $this->compareUnit($this->partAttribute($column, $unit), $compare, $clause[$key], true);

                if ($filter !== null) {
                    $parts[] = $filter;

                    break;
                }
            }
        }

        if (isset($clause['hour']) || isset($clause['minute']) || isset($clause['second'])) {
            $time = $this->time($column, $compare, $clause['hour'] ?? null, $clause['minute'] ?? null, $clause['second'] ?? null);

            if ($time !== null) {
                $parts[] = $time;
            }
        }

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            default => '('.implode(' AND ', $parts).')',
        };
    }

    /**
     * WP_Date_Query::build_time_query().
     */
    private function time(string $column, string $compare, mixed $hour, mixed $minute, mixed $second): ?string
    {
        // Each unit on its own for multi-value compares
        if (in_array($compare, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true)) {
            $parts = [];

            foreach (['hour' => $hour, 'minute' => $minute, 'second' => $second] as $unit => $value) {
                $filter = $this->compareUnit($this->partAttribute($column, $unit), $compare, $value, false);

                if ($filter !== null) {
                    $parts[] = $filter;
                }
            }

            return $parts === [] ? null : implode(' AND ', $parts);
        }

        $set = array_keys(array_filter(['hour' => $hour, 'minute' => $minute, 'second' => $second], static fn ($value) => $value !== null));

        if (count($set) === 1) {
            $unit = $set[0];
            $filter = $this->compareUnit($this->partAttribute($column, $unit), $compare, ${$unit}, false);

            if ($filter !== null) {
                return $filter;
            }
        }

        // Hour and second without a minute: WordPress compares nothing
        if ($minute === null) {
            return null;
        }

        foreach ([$hour, $minute, $second] as $value) {
            if ($value !== null && ! is_numeric($value)) {
                throw new UnsupportedQuery('unsupported_date_value');
            }
        }

        // DATE_FORMAT() with '%H.%i', '%H.%i%s' or '0.%i%s', compared as a number
        [$field, $value] = match (true) {
            $hour !== null && $second !== null => ['hms', PostDates::time((int) $hour, (int) $minute, (int) $second)],
            $hour !== null => ['hm', PostDates::time((int) $hour, (int) $minute)],
            $second !== null => ['ms', PostDates::time(0, (int) $minute, (int) $second)],
            default => ['minute', (int) $minute],
        };

        return "{$this->partAttribute($column, $field)} {$compare} {$this->formatNumber($value)}";
    }

    /**
     * A part compared with a value, as WP_Date_Query::build_value() reads it; null when it compares nothing.
     *
     * @param  bool  $zeroIsNothing  The date units skip a value of 0, the time units do not
     */
    private function compareUnit(string $attribute, string $compare, mixed $value, bool $zeroIsNothing): ?string
    {
        if ($value === null) {
            return null;
        }

        switch ($compare) {
            case 'IN':
            case 'NOT IN':
                $values = array_map('intval', array_filter((array) $value, 'is_numeric'));

                return $values === [] ? null : "{$attribute} {$compare} [".implode(', ', $values).']';

            case 'BETWEEN':
            case 'NOT BETWEEN':
                $values = is_array($value) && count($value) === 2 ? array_values($value) : [$value, $value];

                foreach ($values as $v) {
                    if (! is_numeric($v)) {
                        return null;
                    }
                }

                [$low, $high] = array_map('intval', $values);

                return $compare === 'BETWEEN'
                    ? "({$attribute} >= {$low} AND {$attribute} <= {$high})"
                    : "({$attribute} < {$low} OR {$attribute} > {$high})";

            default:
                if (! is_numeric($value) || ($zeroIsNothing && (int) $value === 0)) {
                    return null;
                }

                return "{$attribute} {$compare} ".(int) $value;
        }
    }

    /**
     * The attribute holding a part of a date column; only post_date and post_modified have parts.
     */
    private function partAttribute(string $column, string $unit): string
    {
        if (! in_array($column, PostDates::PART_COLUMNS, true)) {
            throw new UnsupportedQuery('unsupported_date_column:'.$column);
        }

        if ($unit === 'week') {
            $unit = 'week_'.((int) get_option('start_of_week', 1) % 7);
        }

        return "date_parts.{$column}.{$unit}";
    }

    /**
     * A column, as WP_Date_Query::validate_column() reads it.
     */
    private function column(string $column): string
    {
        global $wpdb;

        $postsTable = isset($wpdb->posts) ? $wpdb->posts.'.' : 'wp_posts.';

        if (str_starts_with($column, $postsTable)) {
            $column = substr($column, strlen($postsTable));
        }

        if (in_array($column, PostDates::COLUMNS, true)) {
            return $column;
        }

        $valid = function_exists('apply_filters')
            ? (array) apply_filters('date_query_valid_columns', [...PostDates::COLUMNS, ...self::OTHER_COLUMNS])
            : [...PostDates::COLUMNS, ...self::OTHER_COLUMNS];

        // Another table's column, or one a plugin made valid
        if (str_contains($column, '.') || in_array($column, $valid, true)) {
            throw new UnsupportedQuery('unsupported_date_column:'.$column);
        }

        return 'post_date';
    }

    /**
     * An operator WP_Date_Query::get_compare() accepts, as given (uppercase only), or null.
     */
    private function validCompare(mixed $compare): ?string
    {
        return is_string($compare) && in_array($compare, self::COMPARES, true) ? $compare : null;
    }

    /**
     * WP_Date_Query::build_mysql_datetime(), as a timestamp of the date read as UTC.
     */
    private function timestamp(mixed $datetime, bool $defaultToMax): int
    {
        if (! is_array($datetime)) {
            $datetime = (string) $datetime;
            $matched = match (true) {
                (bool) preg_match('/^(\d{4})$/', $datetime, $m) => ['year' => (int) $m[1]],
                (bool) preg_match('/^(\d{4})\-(\d{2})$/', $datetime, $m) => ['year' => (int) $m[1], 'month' => (int) $m[2]],
                (bool) preg_match('/^(\d{4})\-(\d{2})\-(\d{2})$/', $datetime, $m) => ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3]],
                (bool) preg_match('/^(\d{4})\-(\d{2})\-(\d{2}) (\d{2}):(\d{2})$/', $datetime, $m) => ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3], 'hour' => (int) $m[4], 'minute' => (int) $m[5]],
                default => null,
            };

            if ($matched === null) {
                $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
                $date = date_create($datetime, $timezone);

                $local = $date === false ? '1970-01-01 00:00:00' : $date->setTimezone($timezone)->format('Y-m-d H:i:s');

                return (int) PostDates::timestamp($local);
            }

            $datetime = $matched;
        }

        $datetime = array_map(static fn ($value) => abs((int) $value), $datetime);
        $year = $datetime['year'] ?? (int) (function_exists('current_time') ? current_time('Y') : gmdate('Y'));
        $month = $datetime['month'] ?? ($defaultToMax ? 12 : 1);
        $day = $datetime['day'] ?? ($defaultToMax ? (int) gmdate('t', (int) gmmktime(0, 0, 0, $month, 1, $year)) : 1);
        $hour = $datetime['hour'] ?? ($defaultToMax ? 23 : 0);
        $minute = $datetime['minute'] ?? ($defaultToMax ? 59 : 0);
        $second = $datetime['second'] ?? ($defaultToMax ? 59 : 0);

        // A date that does not exist (February 30th) moves on to the next one, where MySQL compares its digits
        return (int) gmmktime($hour, $minute, $second, $month, $day, $year);
    }
}
