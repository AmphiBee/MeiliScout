<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Enums\TaxonomyFields;
use Pollora\MeiliScout\Domain\Search\Validators\EnumValidator;

class TaxQueryBuilder extends AbstractFilterBuilder
{
    protected function getQueryKey(): string
    {
        return 'tax_query';
    }

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

        // Vérifie si l'opérateur est autorisé pour les tax queries
        if (!in_array($operator, ComparisonOperator::getTaxonomyOperators(), true)) {
            return '';
        }

        // Mapping des opérateurs
        $operator = $operator === ComparisonOperator::AND ? ComparisonOperator::EQUALS : $operator;

        // Définition de la clé en fonction du champ
        $key = "taxonomies.{$taxonomy}.{$field}";

        // Gestion des opérateurs EXISTS et NOT EXISTS (pas besoin de valeurs)
        if (in_array($operator, [ComparisonOperator::EXISTS, ComparisonOperator::NOT_EXISTS], true)) {
            return "$key {$operator->value}";
        }

        // Vérification de l'existence de 'terms' avant traitement
        if (!array_key_exists('terms', $query)) {
            return '';
        }

        $terms = is_array($query['terms'])
            ? $this->formatArrayValues($query['terms'])
            : $this->formatValue($query['terms']);

        return !empty($terms) ? "$key {$operator->value} [$terms]" : '';
    }
}
