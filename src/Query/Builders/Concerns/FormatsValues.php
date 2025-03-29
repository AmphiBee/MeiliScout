<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders\Concerns;

trait FormatsValues
{
    protected function formatValue($value): string
    {
        if (is_array($value)) {
            return '['.$this->formatArrayValues($value).']';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        // N'échapper que les guillemets simples
        $value = str_replace("'", "\\'", $value);

        return '\''.$value.'\'';
    }

    protected function formatArrayValues(array $values): string
    {
        return implode(', ', array_map(fn ($v) => $this->formatValue($v), $values));
    }
}
