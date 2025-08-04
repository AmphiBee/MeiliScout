<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders\Concerns;

/**
 * Trait for formatting values for MeiliSearch filters.
 * 
 * Provides methods to format scalar and array values for use in MeiliSearch filter expressions.
 */
trait FormatsValues
{
    /**
     * Formats a value for use in a MeiliSearch filter expression.
     * 
     * @param mixed $value The value to format
     * @return string The formatted value
     */
    protected function formatValue($value): string
    {
        if (is_array($value)) {
            return '['.$this->formatArrayValues($value).']';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        // Only escape single quotes
        $value = str_replace("'", "\\'", $value);

        return '\''.$value.'\'';
    }

    /**
     * Formats an array of values for use in a MeiliSearch filter expression.
     * 
     * @param array $values The array of values to format
     * @return string The formatted values as a comma-separated string
     */
    protected function formatArrayValues(array $values): string
    {
        return implode(', ', array_map(fn ($v) => $this->formatValue($v), $values));
    }
}
