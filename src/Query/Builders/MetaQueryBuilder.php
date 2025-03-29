<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Enums\MetaType;
use Pollora\MeiliScout\Domain\Search\Validators\EnumValidator;
use Pollora\MeiliScout\Config\Settings;

use function get_option;
use function update_option;

class MetaQueryBuilder extends AbstractFilterBuilder
{
    private array $nonIndexableMetaKeys = [];

    protected function getQueryKey(): string
    {
        return 'meta_query';
    }

    protected function buildSingleFilter(array $query): string
    {
        // Gestion des requêtes simples (meta_key, meta_value)
        if (isset($query['meta_key'])) {
            $query['key'] = $query['meta_key'];
            $query['value'] = $query['meta_value'] ?? $query['meta_value_num'] ?? null;
            $query['compare'] = $query['meta_compare'] ?? '=';
        }

        // Vérification des paramètres requis
        if (empty($query['key'])) {
            return '';
        }

        // Vérifier si la clé méta est indexable
        if (!$this->isMetaKeyIndexable($query['key'])) {
            return '';
        }

        $key = "metas.{$query['key']}";

        // Gestion des opérateurs EXISTS et NOT EXISTS
        /** @var ComparisonOperator $operator */
        $operator = EnumValidator::getValidValueOrDefault(
            ComparisonOperator::class,
            $query['compare'] ?? ComparisonOperator::getDefault()->value,
            ComparisonOperator::getDefault()
        );

        // Vérifie si l'opérateur est autorisé pour les meta queries
        if (! in_array($operator, ComparisonOperator::getMetaOperators(), true)) {
            return '';
        }

        // Gestion des opérateurs EXISTS et NOT EXISTS
        if (in_array($operator, [ComparisonOperator::EXISTS, ComparisonOperator::NOT_EXISTS], true)) {
            return "$key {$operator->value}";
        }

        // Vérification de la présence d'une valeur pour les autres opérateurs
        if (! isset($query['value']) && ! in_array($operator, [ComparisonOperator::EXISTS, ComparisonOperator::NOT_EXISTS], true)) {
            return '';
        }

        // Formatage de la valeur en fonction du type
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

            ComparisonOperator::BETWEEN,
            ComparisonOperator::NOT_BETWEEN => is_array($query['value']) && count($query['value']) === 2
                ? "$key {$operator->value} [{$this->formatMetaValue($query['value'][0], $query['type'] ?? MetaType::getDefault()->value)}, {$this->formatMetaValue($query['value'][1], $query['type'] ?? MetaType::getDefault()->value)}]"
                : '',

            default => '',
        };
    }

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

    private function isMetaKeyIndexable(string $key): bool
    {
        $indexableMetaKeys = Settings::get('indexed_meta_keys', []);

        if (!in_array($key, $indexableMetaKeys, true)) {
            $this->nonIndexableMetaKeys[] = $key;
            $this->updateNonIndexableMetaKeys();
            return false;
        }

        return true;
    }

    private function updateNonIndexableMetaKeys(): void
    {
        if (!empty($this->nonIndexableMetaKeys)) {
            $existingKeys = Settings::get('non_indexable_meta_keys', []);
            $updatedKeys = array_unique(array_merge($existingKeys, $this->nonIndexableMetaKeys));
            Settings::save('non_indexable_meta_keys', $updatedKeys);
        }
    }

    public function hasNonIndexableMetaKeys(): bool
    {
        return !empty($this->nonIndexableMetaKeys);
    }
}
