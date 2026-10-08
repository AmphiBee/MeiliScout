<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\QueryVars;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\ContainsFilter;
use Pollora\MeiliScout\Services\MetaValueFlags;
use Pollora\MeiliScout\Services\MissedMetaKeys;

/**
 * Translates meta_query, meta_key and meta_value into a Meilisearch filter.
 *
 * Clauses are read as WP_Meta_Query reads them: operators and types in any
 * case, '=' (or IN for a list) by default, a clause with a key and no value
 * asks for the key to exist, and '!=' or NOT IN for a key the post must have.
 *
 * Only indexed meta keys can be filtered on: any other key, and the
 * operators Meilisearch has no equivalent for (REGEXP), send the query to
 * MySQL. LIKE and NOT LIKE become CONTAINS when the instance has the feature
 * turned on (ContainsFilter), and run on MySQL otherwise.
 *
 * What the indexed values of a key are like (MetaValueFlags) sends the
 * comparisons Meilisearch would make otherwise to MySQL too: a negation on a
 * key with several values per post, any comparison on serialized values, a
 * numeric comparison on a key with values that are no numbers.
 */
class MetaQueryBuilder extends AbstractFilterBuilder
{
    /**
     * WordPress operators, and whether they compare values as numbers.
     */
    private const OPERATORS = [
        '=' => false, '!=' => false, 'IN' => false, 'NOT IN' => false, 'EXISTS' => false, 'NOT EXISTS' => false,
        'LIKE' => false, 'NOT LIKE' => false, 'REGEXP' => false, 'NOT REGEXP' => false, 'RLIKE' => false,
        '>' => true, '>=' => true, '<' => true, '<=' => true, 'BETWEEN' => true, 'NOT BETWEEN' => true,
    ];

    /**
     * Casts whose values are numbers.
     */
    private const NUMERIC_CASTS = ['SIGNED', 'UNSIGNED', 'DECIMAL'];

    protected function queries(QueryInterface $query): array
    {
        return QueryVars::metaQuery($query);
    }

    /**
     * As WP_Meta_Query::is_first_order_clause().
     */
    protected function isClause(array $entry): bool
    {
        return isset($entry['key']) || isset($entry['value']);
    }

    protected function buildSingleFilter(array $clause): string
    {
        if (! empty($clause['compare_key']) || ! empty($clause['type_key'])) {
            throw new UnsupportedQuery('unsupported_meta_compare_key');
        }

        $key = $clause['key'] ?? null;

        if (! is_string($key) || trim($key) === '') {
            // A list of keys, or a value under any key
            throw new UnsupportedQuery('unsupported_meta_key');
        }

        $key = trim($key);
        $this->assertIndexed($key);

        $attribute = "metas.{$key}";
        $compare = $this->compare($clause);

        if ($compare === 'NOT EXISTS') {
            return "{$attribute} NOT EXISTS";
        }

        // No value: the post has the key, whatever the operator
        if (! array_key_exists('value', $clause) || $clause['value'] === [] || $clause['value'] === null) {
            return "{$attribute} EXISTS";
        }

        if (in_array($compare, ['REGEXP', 'NOT REGEXP', 'RLIKE'], true)) {
            throw new UnsupportedQuery('unsupported_compare:'.$compare);
        }

        $cast = $this->cast($clause['type'] ?? '');
        $this->assertComparable($key, $compare, $cast, $clause['value']);

        if (in_array($compare, ['LIKE', 'NOT LIKE'], true)) {
            return $this->like($attribute, $compare, $clause['value']);
        }

        $value = $clause['value'];

        if (in_array($compare, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true)) {
            $value = is_array($value) ? array_values($value) : (preg_split('/[,\s]+/', (string) $value) ?: []);
        } elseif (is_array($value)) {
            throw new UnsupportedQuery('unsupported_meta_value');
        } elseif (is_string($value)) {
            $value = trim($value);
        }

        $literal = fn (mixed $v): string => $this->literal($v, $cast);

        $empty = $this->emptyValue($key, $attribute, $compare, $value, $literal);

        if ($empty !== null) {
            return $empty;
        }

        return match ($compare) {
            // EXISTS with a value is '=' in WordPress
            '=', 'EXISTS' => "{$attribute} = {$literal($value)}",
            // The post must have the key, with another value
            '!=' => "({$attribute} EXISTS AND {$attribute} != {$literal($value)})",
            '>', '>=', '<', '<=' => "{$attribute} {$compare} {$literal($value)}",
            'IN' => "{$attribute} IN [".implode(', ', array_map($literal, $value)).']',
            'NOT IN' => "({$attribute} EXISTS AND {$attribute} NOT IN [".implode(', ', array_map($literal, $value)).'])',
            // One value of a list in the range, as MySQL matches one row
            'BETWEEN' => MetaValueFlags::has($key, MetaValueFlags::MULTIPLE)
                ? "{$attribute} {$literal($value[0] ?? '')} TO {$literal($value[1] ?? '')}"
                : "({$attribute} >= {$literal($value[0] ?? '')} AND {$attribute} <= {$literal($value[1] ?? '')})",
            'NOT BETWEEN' => "({$attribute} < {$literal($value[0] ?? '')} OR {$attribute} > {$literal($value[1] ?? '')})",
            default => throw new UnsupportedQuery('unsupported_compare:'.$compare),
        };
    }

    /**
     * A comparison with an empty value: Meilisearch filters never match '', IS EMPTY does.
     *
     * @param  callable(mixed): string  $literal
     * @return string|null The filter, or null when no empty value is compared
     *
     * @throws UnsupportedQuery
     */
    private function emptyValue(string $key, string $attribute, string $compare, mixed $value, callable $literal): ?string
    {
        if (! in_array($compare, ['=', 'EXISTS', '!=', 'IN', 'NOT IN'], true)) {
            return null;
        }

        $values = is_array($value) ? $value : [$value];
        $others = array_values(array_filter($values, static fn (mixed $v) => $v !== ''));

        if (count($others) === count($values)) {
            return null;
        }

        // IS EMPTY matches a list that is empty, not one that holds ''
        if (MetaValueFlags::has($key, MetaValueFlags::MULTIPLE)) {
            throw new UnsupportedQuery('multivalued_meta:'.$key);
        }

        $listed = static fn (array $values): string => '['.implode(', ', array_map($literal, $values)).']';

        return match ($compare) {
            '=', 'EXISTS' => "{$attribute} IS EMPTY",
            '!=' => "({$attribute} EXISTS AND {$attribute} IS NOT EMPTY)",
            'IN' => $others === [] ? "{$attribute} IS EMPTY" : "({$attribute} IS EMPTY OR {$attribute} IN {$listed($others)})",
            'NOT IN' => $others === []
                ? "({$attribute} EXISTS AND {$attribute} IS NOT EMPTY)"
                : "({$attribute} EXISTS AND {$attribute} IS NOT EMPTY AND {$attribute} NOT IN {$listed($others)})",
        };
    }

    /**
     * Whether Meilisearch compares the key's indexed values as MySQL compares its rows.
     *
     * @throws UnsupportedQuery
     */
    private function assertComparable(string $key, string $compare, string $cast, mixed $value): void
    {
        $flags = MetaValueFlags::of($key);

        // MySQL compares the serialized text
        if (isset($flags[MetaValueFlags::STRUCTURED])) {
            throw new UnsupportedQuery('structured_meta:'.$key);
        }

        // MySQL keeps a post for one row that differs; Meilisearch drops a list for one value that matches
        if (isset($flags[MetaValueFlags::MULTIPLE]) && in_array($compare, ['!=', 'NOT IN', 'NOT LIKE'], true)) {
            throw new UnsupportedQuery('multivalued_meta:'.$key);
        }

        // A range of text over several values, where TO only takes numbers
        if (isset($flags[MetaValueFlags::MULTIPLE]) && $compare === 'BETWEEN' && ! in_array($cast, self::NUMERIC_CASTS, true)) {
            throw new UnsupportedQuery('multivalued_meta:'.$key);
        }

        if (! isset($flags[MetaValueFlags::NON_NUMERIC])) {
            return;
        }

        // MySQL casts a value that is no number to 0
        if (in_array($cast, self::NUMERIC_CASTS, true) && ! in_array($compare, ['LIKE', 'NOT LIKE'], true)) {
            throw new UnsupportedQuery('meta_not_numeric:'.$key);
        }

        // MySQL orders text, numbers included, as text; Meilisearch compares a number to numbers only
        $ordering = in_array($compare, ['>', '>=', '<', '<=', 'BETWEEN', 'NOT BETWEEN'], true);
        $numberGiven = array_filter((array) $value, static fn (mixed $v) => is_bool($v) || is_numeric($v)) !== [];

        if ($ordering && ($numberGiven || isset($flags[MetaValueFlags::NUMERIC]))) {
            throw new UnsupportedQuery('meta_not_numeric:'.$key);
        }
    }

    /**
     * LIKE '%value%', as WordPress builds it: the value anywhere in the field's.
     */
    private function like(string $attribute, string $compare, mixed $value): string
    {
        if (! ContainsFilter::enabled()) {
            throw new UnsupportedQuery('unsupported_compare:'.$compare);
        }

        if (! is_scalar($value)) {
            throw new UnsupportedQuery('unsupported_meta_value');
        }

        $value = trim((string) $value);

        // '%%' matches any value, when the post has the key
        if ($value === '') {
            return $compare === 'LIKE' ? "{$attribute} EXISTS" : 'post_type IN []';
        }

        return $compare === 'LIKE'
            ? "{$attribute} CONTAINS {$this->quote($value)}"
            : "({$attribute} EXISTS AND {$attribute} NOT CONTAINS {$this->quote($value)})";
    }

    /**
     * The operator, as WP_Meta_Query::get_sql_for_clause() works it out.
     *
     * @param  array<string, mixed>  $clause
     */
    private function compare(array $clause): string
    {
        if (! isset($clause['compare'])) {
            return isset($clause['value']) && is_array($clause['value']) ? 'IN' : '=';
        }

        if (! is_string($clause['compare'])) {
            throw new UnsupportedQuery('unsupported_compare');
        }

        $compare = strtoupper(trim($clause['compare']));

        // An operator WordPress does not know is '='
        return isset(self::OPERATORS[$compare]) ? $compare : '=';
    }

    /**
     * The cast of a type, as WP_Meta_Query::get_cast_for_type() works it out.
     */
    private function cast(mixed $type): string
    {
        $type = is_string($type) ? strtoupper(trim($type)) : '';

        if ($type === '' || ! preg_match('/^(?:BINARY|CHAR|DATE|DATETIME|SIGNED|UNSIGNED|TIME|NUMERIC(?:\(\d+(?:,\s?\d+)?\))?|DECIMAL(?:\(\d+(?:,\s?\d+)?\))?)$/', $type)) {
            return 'CHAR';
        }

        if ($type === 'BINARY') {
            // Case-sensitive comparisons: Meilisearch filters ignore case
            throw new UnsupportedQuery('unsupported_meta_type:BINARY');
        }

        return match (true) {
            str_starts_with($type, 'NUMERIC') => 'SIGNED',
            str_starts_with($type, 'DECIMAL') => 'DECIMAL',
            default => $type,
        };
    }

    /**
     * A value as a filter literal: a number for numeric casts, else what it is.
     */
    private function literal(mixed $value, string $cast): string
    {
        if (in_array($cast, self::NUMERIC_CASTS, true)) {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            // MySQL casts anything to a number; Meilisearch compares numbers to numbers only
            if (! is_numeric($value)) {
                throw new UnsupportedQuery('invalid_number');
            }

            $number = $this->formatNumber(is_string($value) ? $value : (float) $value);

            return $cast === 'UNSIGNED' || $cast === 'SIGNED' ? (string) (int) $number : $number;
        }

        return $this->formatValue($value);
    }

    /**
     * Only indexed keys are in the documents; a missed key is remembered for the admin.
     */
    private function assertIndexed(string $key): void
    {
        if (! in_array($key, (array) Settings::get('indexed_meta_keys', []), true)) {
            MissedMetaKeys::record($key);

            throw new UnsupportedQuery('unindexed_meta:'.$key);
        }

        // The key becomes part of an attribute name: a dot would make it a nested one
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            throw new UnsupportedQuery('unsupported_meta_key');
        }
    }
}
