<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Enums\TaxonomyFields;
use Pollora\MeiliScout\Domain\Search\Validators\EnumValidator;

/**
 * Builder for taxonomy query filters.
 * 
 * Handles the conversion of WordPress tax_query parameters to MeiliSearch filter syntax.
 */
class TaxQueryBuilder extends AbstractFilterBuilder
{
    /**
     * {@inheritdoc}
     */
    protected function getQueryKey(): string
    {
        return 'tax_query';
    }

    /**
     * {@inheritdoc}
     * 
     * Builds a filter expression for a taxonomy query clause.
     * 
     * @param array $query The taxonomy query clause
     * @return string The MeiliSearch filter expression
     */
    protected function buildSingleFilter(array $query): string
    {
        if (empty($query['taxonomy'])) {
            return '';
        }

        $taxonomy = $query['taxonomy'];

        $field = EnumValidator::getValidValueOrDefault(
            TaxonomyFields::class,
            $query['field'] ?? TaxonomyFields::getDefault()->value,
            TaxonomyFields::getDefault()
        )->value;

        /** @var ComparisonOperator $operator */
        $operator = EnumValidator::getValidValueOrDefault(
            ComparisonOperator::class,
            $query['operator'] ?? ComparisonOperator::getTaxonomyDefault()->value,
            ComparisonOperator::getTaxonomyDefault()
        );

        if (!in_array($operator, ComparisonOperator::getTaxonomyOperators(), true)) {
            return '';
        }

        $fieldKey = "terms.{$field}";
        $taxonomyKey = "terms.taxonomy";

        // Handle EXISTS and NOT EXISTS cases
        if (in_array($operator, [ComparisonOperator::EXISTS, ComparisonOperator::NOT_EXISTS], true)) {
            return "{$taxonomyKey} {$operator->value} '{$taxonomy}'";
        }

        // Handle cases requiring values
        if (!array_key_exists('terms', $query)) {
            return '';
        }

        $terms = is_array($query['terms'])
            ? $this->formatArrayValues($query['terms'])
            : $this->formatValue($query['terms']);

        if (empty($terms)) {
            return '';
        }

        // Special case for NOT IN / != : use an enclosing NOT clause
        if (in_array($operator, [ComparisonOperator::NOT_IN, ComparisonOperator::NOT_EQUALS], true)) {
            return "NOT ({$taxonomyKey} = '{$taxonomy}' AND {$fieldKey} IN [{$terms}])";
        }

        // Default case: simple filter
        return "({$taxonomyKey} = '{$taxonomy}' AND {$fieldKey} {$operator->value} [{$terms}])";
    }
}
