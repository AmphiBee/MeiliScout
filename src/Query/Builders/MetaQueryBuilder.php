<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Enums\MetaType;
use Pollora\MeiliScout\Domain\Search\Validators\EnumValidator;

/**
 * Builder for meta query filters.
 * 
 * Handles the conversion of WordPress meta_query parameters to MeiliSearch filter syntax.
 */
class MetaQueryBuilder extends AbstractFilterBuilder
{
    /**
     * List of meta keys that were found to be non-indexable during query building.
     *
     * @var array
     */
    private array $nonIndexableMetaKeys = [];

    /**
     * {@inheritdoc}
     */
    protected function getQueryKey(): string
    {
        return 'meta_query';
    }

    /**
     * {@inheritdoc}
     * 
     * Builds a filter expression for a meta query clause.
     */
    protected function buildSingleFilter(array $query): string
    {
        // Handle simple queries (meta_key, meta_value)
        if (isset($query['meta_key'])) {
            $query['key'] = $query['meta_key'];
            $query['value'] = $query['meta_value'] ?? $query['meta_value_num'] ?? null;
            $query['compare'] = $query['meta_compare'] ?? '=';
        }

        // Check required parameters
        if (empty($query['key'])) {
            return '';
        }

        // Check if the meta key is indexable
        if (! $this->isMetaKeyIndexable($query['key'])) {
            return '';
        }

        $key = "metas.{$query['key']}";

        // Handle EXISTS and NOT EXISTS operators
        /** @var ComparisonOperator $operator */
        $operator = EnumValidator::getValidValueOrDefault(
            ComparisonOperator::class,
            $query['compare'] ?? ComparisonOperator::getDefault()->value,
            ComparisonOperator::getDefault()
        );

        // Check if the operator is allowed for meta queries
        if (! in_array($operator, ComparisonOperator::getMetaOperators(), true)) {
            return '';
        }

        // Handle EXISTS and NOT EXISTS operators
        if (in_array($operator, [ComparisonOperator::EXISTS, ComparisonOperator::NOT_EXISTS], true)) {
            return "$key {$operator->value}";
        }

        // Check for value presence for other operators
        if (! isset($query['value']) && ! in_array($operator, [ComparisonOperator::EXISTS, ComparisonOperator::NOT_EXISTS], true)) {
            return '';
        }

        // Format value based on type
        $value = $this->formatMetaValue($query['value'], $query['type'] ?? MetaType::getDefault()->value);

        return match ($operator) {
            ComparisonOperator::EQUALS,
            ComparisonOperator::NOT_EQUALS,
            ComparisonOperator::GREATER_THAN,
            ComparisonOperator::GREATER_THAN_OR_EQUALS,
            ComparisonOperator::LESS_THAN,
            ComparisonOperator::LESS_THAN_OR_EQUALS,
            ComparisonOperator::LIKE,
            ComparisonOperator::NOT_LIKE,
            ComparisonOperator::REGEXP,
            ComparisonOperator::NOT_REGEXP,
            ComparisonOperator::RLIKE => "$key {$operator->value} $value",

            ComparisonOperator::IN,
            ComparisonOperator::NOT_IN => is_array($query['value'])
                ? "$key {$operator->value} [{$this->formatArrayValues($query['value'])}]"
                : '',

            ComparisonOperator::BETWEEN => is_array($query['value']) && count($query['value']) === 2
                ? "($key >= {$this->formatMetaValue($query['value'][0], $query['type'] ?? MetaType::getDefault()->value)} AND $key <= {$this->formatMetaValue($query['value'][1], $query['type'] ?? MetaType::getDefault()->value)})"
                : '',

            ComparisonOperator::NOT_BETWEEN => is_array($query['value']) && count($query['value']) === 2
                ? "($key < {$this->formatMetaValue($query['value'][0], $query['type'] ?? MetaType::getDefault()->value)} OR $key > {$this->formatMetaValue($query['value'][1], $query['type'] ?? MetaType::getDefault()->value)})"
                : '',

            default => '',
        };
    }

    /**
     * Formats a meta value based on its type.
     *
     * @param mixed $value The value to format
     * @param string $type The meta type
     * @return string The formatted value
     */
    private function formatMetaValue(mixed $value, string $type): string
    {
        /** @var MetaType $metaType */
        $metaType = EnumValidator::getValidValueOrDefault(
            MetaType::class,
            $type,
            MetaType::getDefault()
        );

        return match ($metaType) {
            MetaType::NUMERIC,
            MetaType::DECIMAL,
            MetaType::SIGNED,
            MetaType::UNSIGNED => is_array($value) ? implode(', ', $value) : (string) $value,

            MetaType::DATE,
            MetaType::DATETIME,
            MetaType::TIME => is_array($value)
                ? $this->formatArrayValues($value)
                : $this->formatValue($value),

            default => $this->formatValue($value),
        };

    }

    /**
     * Checks if a meta key is indexable.
     *
     * @param string $key The meta key to check
     * @return bool True if the meta key is indexable, false otherwise
     */
    private function isMetaKeyIndexable(string $key): bool
    {
        $indexableMetaKeys = Settings::get('indexed_meta_keys', []);

        if (! in_array($key, $indexableMetaKeys, true)) {
            $this->nonIndexableMetaKeys[] = $key;
            $this->updateNonIndexableMetaKeys();

            return false;
        }

        return true;
    }

    /**
     * Updates the list of non-indexable meta keys in the settings.
     *
     * @return void
     */
    private function updateNonIndexableMetaKeys(): void
    {
        if (! empty($this->nonIndexableMetaKeys)) {
            $existingKeys = Settings::get('non_indexable_meta_keys', []);
            $updatedKeys = array_unique(array_merge($existingKeys, $this->nonIndexableMetaKeys));
            Settings::save('non_indexable_meta_keys', $updatedKeys);
        }
    }

    /**
     * Checks if the query contains meta keys that are not indexed.
     *
     * @return bool True if there are non-indexable meta keys, false otherwise
     */
    public function hasNonIndexableMetaKeys(): bool
    {
        return ! empty($this->nonIndexableMetaKeys);
    }
}
