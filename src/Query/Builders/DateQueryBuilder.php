<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Validators\EnumValidator;

class DateQueryBuilder extends AbstractFilterBuilder
{
    protected function getQueryKey(): string
    {
        return 'date_query';
    }

    protected function buildSingleFilter(array $query): string
    {
        if (empty($query['column']) || !isset($query['value'])) {
            return '';
        }

        $key = "date.{$query['column']}";
        $value = is_array($query['value']) ? $this->formatArrayValues($query['value']) : $this->formatValue($query['value']);
        
        /** @var ComparisonOperator $operator */
        $operator = EnumValidator::getValidValueOrDefault(
            ComparisonOperator::class,
            $query['compare'] ?? ComparisonOperator::getDefault()->value,
            ComparisonOperator::getDefault()
        );

        // Vérifie si l'opérateur est autorisé pour les date queries
        if (!in_array($operator, ComparisonOperator::getDateOperators(), true)) {
            return '';
        }

        return match ($operator) {
            ComparisonOperator::EQUALS,
            ComparisonOperator::NOT_EQUALS,
            ComparisonOperator::GREATER_THAN,
            ComparisonOperator::GREATER_THAN_OR_EQUALS,
            ComparisonOperator::LESS_THAN,
            ComparisonOperator::LESS_THAN_OR_EQUALS => "$key {$operator->value} $value",
            
            ComparisonOperator::IN,
            ComparisonOperator::NOT_IN => is_array($query['value']) ? "$key {$operator->value} [$value]" : '',
            
            ComparisonOperator::BETWEEN,
            ComparisonOperator::NOT_BETWEEN => is_array($query['value']) && count($query['value']) === 2
                ? "$key {$operator->value} [{$query['value'][0]}, {$query['value'][1]}]"
                : '',
            
            default => '',
        };
    }
}
