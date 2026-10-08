<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\QueryVars;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\MissedMetaKeys;

/**
 * Translates meta_query, meta_key and meta_value into a Meilisearch filter.
 *
 * Clauses are read as WP_Meta_Query reads them: operators and types in any
 * case, '=' (or IN for a list) by default, a clause with a key and no value
 * asks for the key to exist, and '!=' or NOT IN for a key the post must have.
 *
 * Only indexed meta keys can be filtered on: any other key, and the
 * operators Meilisearch has no equivalent for (LIKE, REGEXP), send the
 * query to MySQL.
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

        if (in_array($compare, ['LIKE', 'NOT LIKE', 'REGEXP', 'NOT REGEXP', 'RLIKE'], true)) {
            throw new UnsupportedQuery('unsupported_compare:'.$compare);
        }

        $cast = $this->cast($clause['type'] ?? '');
        $value = $clause['value'];

        if (in_array($compare, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true)) {
            $value = is_array($value) ? array_values($value) : (preg_split('/[,\s]+/', (string) $value) ?: []);
        } elseif (is_array($value)) {
            throw new UnsupportedQuery('unsupported_meta_value');
        } elseif (is_string($value)) {
            $value = trim($value);
        }

        $literal = fn (mixed $v): string => $this->literal($v, $cast);

        return match ($compare) {
            // EXISTS with a value is '=' in WordPress
            '=', 'EXISTS' => "{$attribute} = {$literal($value)}",
            // The post must have the key, with another value
            '!=' => "({$attribute} EXISTS AND {$attribute} != {$literal($value)})",
            '>', '>=', '<', '<=' => "{$attribute} {$compare} {$literal($value)}",
            'IN' => "{$attribute} IN [".implode(', ', array_map($literal, $value)).']',
            'NOT IN' => "({$attribute} EXISTS AND {$attribute} NOT IN [".implode(', ', array_map($literal, $value)).'])',
            'BETWEEN' => "({$attribute} >= {$literal($value[0] ?? '')} AND {$attribute} <= {$literal($value[1] ?? '')})",
            'NOT BETWEEN' => "({$attribute} < {$literal($value[0] ?? '')} OR {$attribute} > {$literal($value[1] ?? '')})",
            default => throw new UnsupportedQuery('unsupported_compare:'.$compare),
        };
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
