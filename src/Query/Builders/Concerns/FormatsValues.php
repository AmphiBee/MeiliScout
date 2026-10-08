<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders\Concerns;

use Pollora\MeiliScout\Query\UnsupportedQuery;

/**
 * Turns PHP values into Meilisearch filter literals.
 *
 * Strings are quoted, with backslashes and quotes escaped: a value ending in
 * a backslash would otherwise escape the closing quote, and let the rest of
 * the value be read as filter syntax. Booleans become 1 and 0, the way
 * WordPress stores them in metas and the way they are indexed.
 */
trait FormatsValues
{
    /**
     * Formats a value for use in a Meilisearch filter expression.
     *
     * @param  mixed  $value  A scalar, or a list of scalars
     */
    protected function formatValue($value): string
    {
        if (is_array($value)) {
            return '['.$this->formatArrayValues($value).']';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return $this->formatNumber($value);
        }

        if (! is_string($value)) {
            throw new UnsupportedQuery('unsupported_value:'.get_debug_type($value));
        }

        if (is_numeric($value)) {
            return $this->formatNumber($value);
        }

        return $this->quote($value);
    }

    /**
     * Formats an array of values for use in a Meilisearch filter expression.
     *
     * @param  array<mixed>  $values
     * @return string The formatted values as a comma-separated string
     */
    protected function formatArrayValues(array $values): string
    {
        return implode(', ', array_map(fn ($v) => $this->formatValue($v), $values));
    }

    /**
     * A string literal, whatever its content.
     */
    protected function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * A number literal; anything that is not a finite number is rejected.
     */
    protected function formatNumber(int|float|string $value): string
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new UnsupportedQuery('invalid_number');
        }

        if (is_string($value)) {
            $value = trim($value);

            // A plain decimal, never hexadecimal or exponent notation read differently by Meilisearch
            if (! preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value)) {
                return $this->formatNumber((float) $value);
            }

            return ltrim($value, '+');
        }

        return is_float($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : (string) $value;
    }
}
